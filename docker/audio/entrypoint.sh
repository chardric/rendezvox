#!/bin/bash
set -euo pipefail

ICECAST_URL="${ICECAST_URL:-http://icecast:8000/live}"
AUDIO_DEVICE="${AUDIO_DEVICE:-auto}"
MPV_SOCKET="/var/log/rendezvox/mpv.sock"
EQ_FILE="/var/log/rendezvox/eq.json"

# Extract host:port and path from URL
ICECAST_HOST="${ICECAST_URL#http://}"
ICECAST_PATH="/${ICECAST_HOST#*/}"
ICECAST_HOST="${ICECAST_HOST%%/*}"

apply_eq() {
    if [ ! -f "$EQ_FILE" ] || [ ! -S "$MPV_SOCKET" ]; then
        return
    fi

    local filter
    filter=$(jq -r '
        (.bands | to_entries | map("equalizer=f=\(.key):t=o:w=1:g=\(.value)") | join(",")) as $eq |
        (if .spatial == "stereo_wide" then ",stereowiden=delay=20:feedback=0.3:crossfeed=0.3:drymix=0.8"
         elif .spatial == "surround" then ",haas=level_in=1:level_out=1:side_gain=0.4:middle_source=mid:middle_phase=false,extrastereo=m=1.5"
         elif .spatial == "crossfeed" then ",crossfeed=strength=0.7:range=8000:level_in=1:level_out=1"
         else "" end) as $sp |
        $eq + $sp
    ' "$EQ_FILE" 2>/dev/null) || return

    if [ -n "$filter" ] && [ "$filter" != "null" ]; then
        printf '{"command": ["af", "set", "%s"]}\n' "$filter" \
            | socat - UNIX-CONNECT:"$MPV_SOCKET" 2>/dev/null || true
    fi
}

wait_for_stream() {
    echo "Waiting for Icecast stream at $ICECAST_URL ..."
    while true; do
        local response
        response=$(printf "GET %s HTTP/1.0\r\nHost: %s\r\n\r\n" "$ICECAST_PATH" "${ICECAST_HOST%%:*}" \
            | socat -T2 - "TCP:$ICECAST_HOST" 2>/dev/null | head -1) || true
        if echo "$response" | grep -q "200"; then
            echo "Icecast stream is live."
            return
        fi
        sleep 3
    done
}

echo "RendezVox Audio — starting mpv..."
echo "  Stream:  $ICECAST_URL"
echo "  Device:  $AUDIO_DEVICE"
echo "  Socket:  $MPV_SOCKET"

while true; do
    # Always wait for a live stream before starting mpv
    wait_for_stream

    rm -f "$MPV_SOCKET"

    MPV_ARGS=(
        --no-video
        --no-terminal
        --idle=yes
        --input-ipc-server="$MPV_SOCKET"
        --cache=yes
        --demuxer-max-bytes=512KiB
        --demuxer-readahead-secs=10
        # Auto-reconnect on stream drop (lavf/ffmpeg handles it internally)
        --demuxer-lavf-o=reconnect=1,reconnect_streamed=1,reconnect_delay_max=30
        --network-timeout=30
    )

    if [ "$AUDIO_DEVICE" != "auto" ]; then
        MPV_ARGS+=(--audio-device="$AUDIO_DEVICE")
    fi

    mpv "${MPV_ARGS[@]}" "$ICECAST_URL" &
    MPV_PID=$!

    # Wait for IPC socket to appear
    for i in $(seq 1 30); do
        if [ -S "$MPV_SOCKET" ]; then
            chmod 666 "$MPV_SOCKET" 2>/dev/null || true
            echo "mpv IPC socket ready."
            break
        fi
        sleep 0.5
    done

    # Apply saved EQ settings on startup
    apply_eq && echo "EQ applied from saved settings." || true

    # Wait for mpv to exit (stream drop, error, etc.)
    wait $MPV_PID || true

    echo "mpv exited, restarting in 5 seconds..."
    sleep 5
done
