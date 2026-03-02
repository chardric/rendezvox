# ==========================================================
# RendezVox — Liquidsoap Radio Script
# ==========================================================
# API-driven automation:
#   GET  /api/next-track    → returns JSON with song file_path
#   POST /api/track-started → stamps current_song_id/started_at
#   POST /api/track-played  → stamps ended_at on play_history
#
# Music files at: /var/lib/rendezvox/music/
# ==========================================================

# ── Settings ────────────────────────────────────────────

settings.server.socket       := true
settings.server.socket.path  := "/run/liquidsoap/main.sock"
settings.server.telnet            := true
settings.server.telnet.port       := 1234
settings.server.telnet.bind_addr  := "0.0.0.0"

# ── Logging ─────────────────────────────────────────────

settings.log.file      := true
settings.log.file.path := "/var/log/rendezvox/liquidsoap.log"
settings.log.stdout    := true
settings.log.level     := 3

# ── Environment ─────────────────────────────────────────

icecast_host     = environment.get("RENDEZVOX_ICECAST_HOST",            default="icecast")
icecast_port     = int_of_string(environment.get("RENDEZVOX_ICECAST_PORT", default="8000"))
icecast_mount    = environment.get("RENDEZVOX_ICECAST_MOUNT",           default="/live")
icecast_password = environment.get("RENDEZVOX_ICECAST_SOURCE_PASSWORD", default="")
api_base         = environment.get("RENDEZVOX_API_URL",                 default="http://rendezvox-nginx/api")
music_dir        = "/var/lib/rendezvox/music"
silence_file     = "/opt/rendezvox/silence.mp3"
internal_secret  = environment.get("RENDEZVOX_INTERNAL_SECRET", default="")

# ── Startup security checks ────────────────────────────
if icecast_password == "" then
  log.critical("RENDEZVOX_ICECAST_SOURCE_PASSWORD is not set — refusing to start")
  shutdown()
end

# ── Station config (fetched from API at startup) ──────────

crossfade_str = string.trim(process.read(
    "curl -sf -H 'X-Internal-Secret: #{internal_secret}' '#{api_base}/config' | jq -r '.crossfade_ms // 3000'"
))
crossfade_sec = float_of_string(crossfade_str) / 1000.0
log.important("Crossfade duration: #{string(crossfade_sec)}s")

# ── Track state ─────────────────────────────────────────
# playing_id: song_id of the track currently audible to listeners.
# The song_id for each track is embedded as request metadata
# ("song_id" key) so it stays in sync even if a file fails to load.

playing_id  = ref("")

# ── Request next track from API ─────────────────────────
# Called by request.dynamic when it needs a new track.
# The song_id is attached as metadata on the request itself,
# so on_metadata always reads the correct id for the track
# that actually plays — no queue sync issues.

def get_next_track() =
    log.info("Requesting next track from API...")

    let result = string.trim(process.read(
        "resp=$(curl -sf -H 'X-Internal-Secret: #{internal_secret}' '#{api_base}/next-track') && " ^
        "echo \"$resp\" | jq -r 'if .song == null then \"\" else (.song.file_path + \"\\n\" + (.song.id | tostring) + \"\\n\" + (.song.gain_db // 0 | tostring) + \"\\n\" + (.source // \"rotation\") + \"\\n\" + (.request_id // 0 | tostring) + \"\\n\" + (.song.cue_in // \"\" | tostring) + \"\\n\" + (.song.cue_out // \"\" | tostring)) end'"
    ))

    let parts = string.split(separator="\n", result)
    let file_path = string.trim(list.nth(default="", parts, 0))
    let song_id = string.trim(list.nth(default="", parts, 1))
    let gain_db = string.trim(list.nth(default="0", parts, 2))
    let source = string.trim(list.nth(default="rotation", parts, 3))
    let request_id = string.trim(list.nth(default="0", parts, 4))
    let cue_in = string.trim(list.nth(default="", parts, 5))
    let cue_out = string.trim(list.nth(default="", parts, 6))

    if file_path == "" or file_path == "null" then
        log.info("No active schedule — playing silence, will retry in 10s")
        [request.create(silence_file)]
    else
        # file_path may be absolute (starts with /) or relative — handle both
        let full_path = if string.sub(file_path, start=0, length=1) == "/" then file_path else "#{music_dir}/#{file_path}" end
        let cue_annotations =
            if cue_in != "" and cue_out != "" then
                ",liq_cue_in=\"#{cue_in}\",liq_cue_out=\"#{cue_out}\""
            else "" end
        log.important("Queued: #{full_path} (song_id=#{song_id}, gain=#{gain_db}dB, source=#{source}, cue_in=#{cue_in}, cue_out=#{cue_out})")
        [request.create("annotate:song_id=\"#{song_id}\",liq_amplify=\"#{gain_db}dB\",rendezvox_source=\"#{source}\",rendezvox_request_id=\"#{request_id}\"#{cue_annotations}:#{full_path}")]
    end
end

# ── Audio sources ───────────────────────────────────────

radio = request.dynamic.list(get_next_track)
radio = amplify(override="liq_amplify", 1., radio)

# ── Cue-point trimming ──────────────────────────────
# Apply pre-computed cue_in/cue_out from silence detection.
# liq_cue_in/liq_cue_out metadata trims leading/trailing silence precisely.

radio = cue_cut(radio)

# ── Strip trailing silence (safety net) ──────────────
# Fallback for songs without cue points. Ends tracks
# when silence exceeds 2s at -40dB.

radio = blank.skip(radio, max_blank=2.0, min_noise=0.5, threshold=-40.0)

# ── Crossfade ─────────────────────────────────────────

radio = crossfade(duration=crossfade_sec, radio)

# ── Track lifecycle via on_metadata ─────────────────────
# Fires at the START of crossfade (new track fading in).
# The song_id is read from the track's own metadata, so it
# always matches the audio that's actually playing.
# API notifications are delayed by crossfade_sec so the
# dashboard/listener page shows the audible track.

radio = source.on_metadata(radio, fun (m) -> begin
    let prev = playing_id()
    let sid = m["song_id"]
    let src = m["rendezvox_source"]
    let rid = m["rendezvox_request_id"]

    if sid != "" then
        playing_id := sid

        # Delay API calls until crossfade completes
        thread.run(delay=crossfade_sec, fun () -> begin
            # Stamp previous track as ended
            if prev != "" and prev != "0" then
                log.info("Track ended (song_id=#{prev}), notifying API...")
                ignore(process.read(
                    "curl -sf -X POST -H 'Content-Type: application/json' -H 'X-Internal-Secret: #{internal_secret}' " ^
                    "-d '{\"song_id\": #{prev}}' '#{api_base}/track-played' 2>/dev/null || true"
                ))
            end

            # Tell API this track is now playing (include source + request_id from metadata)
            if sid != "0" then
                let artist = m["artist"]
                let title  = m["title"]
                log.important("Now playing: #{artist} — #{title} (song_id=#{sid}, source=#{src})")
                ignore(process.read(
                    "curl -sf -X POST -H 'Content-Type: application/json' -H 'X-Internal-Secret: #{internal_secret}' " ^
                    "-d '{\"song_id\": #{sid}, \"source\": \"#{src}\", \"request_id\": #{rid}}' " ^
                    "'#{api_base}/track-started' 2>/dev/null || true"
                ))
            end
        end)
    else
        # Silence or unknown track — clear playing state
        playing_id := ""
    end
end)

# Fallback to silence if source fails entirely
radio = mksafe(radio)

# ── Station ID overlay (exactly one per hour at minute 0) ───
stationids_dir = "/var/lib/rendezvox/stationids"
stationid_state = "/tmp/stationid_last_index"

# Fires true exactly once when the clock enters minute 0 each hour
hourly_activate = predicate.activates({0m})

def pick_station_id() =
    if hourly_activate() then
        # Sequential round-robin: list files sorted alphabetically,
        # read last-played index from state file, pick the next one.
        path = string.trim(process.read(
            "files=$(find '#{stationids_dir}' -maxdepth 1 -type f " ^
            "\\( -iname '*.mp3' -o -iname '*.ogg' -o -iname '*.wav' " ^
            "-o -iname '*.flac' -o -iname '*.aac' -o -iname '*.m4a' \\) " ^
            "2>/dev/null | sort) && " ^
            "count=$(echo \"$files\" | grep -c . 2>/dev/null || echo 0) && " ^
            "[ \"$count\" -gt 0 ] || exit 0 && " ^
            "last=$(cat '#{stationid_state}' 2>/dev/null || echo -1) && " ^
            "next=$(( (last + 1) % count )) && " ^
            "echo \"$next\" > '#{stationid_state}' && " ^
            "echo \"$files\" | sed -n \"$((next + 1))p\""
        ))
        if path == "" then
            log.info("Hourly station ID: no files found, skipping")
            []
        else
            log.important("Hourly station ID (sequential): #{path}")
            [request.create(path)]
        end
    else
        []
    end
end

# Plays exactly one station ID per hourly activation, then goes silent
station_ids = request.dynamic.list(pick_station_id, retry_delay=30.0)

# Duck main audio to 15% while station ID plays, fade over 0.5s
radio = smooth_add(
    duration=0.5,
    p=0.15,
    normal=radio,
    special=station_ids
)

# ── Stream on/off control ─────────────────────────────
# Controllable via telnet:
#   var.set stream_active = false   (stop broadcasting)
#   var.set stream_active = true    (resume broadcasting)

stream_active = interactive.bool("stream_active", true)
radio_out = switch(track_sensitive=false, [({stream_active()}, radio)])

# ── Output to Icecast ──────────────────────────────────

output.icecast(
    id                  = "stream",
    fallible            = true,
    %mp3(bitrate=192, samplerate=44100, stereo=true),
    host                = icecast_host,
    port                = icecast_port,
    password            = icecast_password,
    mount               = icecast_mount,
    name                = "RendezVox",
    description         = "RendezVox Automation Stream",
    genre               = "Various",
    url                 = "http://localhost",
    public              = false,
    on_connect          = fun () -> log.important("Connected to Icecast at #{icecast_host}:#{string(icecast_port)}#{icecast_mount}"),
    on_disconnect       = fun () -> log.severe("Disconnected from Icecast — will auto-reconnect"),
    radio_out
)
