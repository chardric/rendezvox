# ==========================================================
# iRadio — Liquidsoap Audio Processing Engine
# ==========================================================
# Build context: docker/liquidsoap/
# Compose mounts:
#   ../liquidsoap  → /etc/liquidsoap        (radio.liq, ro)
#   iradio_music   → /var/lib/iradio/music  (audio files, ro)
#   iradio_logs    → /var/log/iradio        (logs, rw)
# ==========================================================

# ── Stage 1: Generate silence file ───────────────────────
# Uses a separate image so we don't upgrade ffmpeg libs in
# the Liquidsoap image (which would break OCaml bindings).
FROM debian:bookworm-slim AS silence-gen
RUN apt-get update && \
    apt-get install -y --no-install-recommends ffmpeg && \
    ffmpeg -f lavfi -i anullsrc=r=44100:cl=stereo -t 10 \
        -c:a libmp3lame -q:a 9 /silence.mp3 && \
    rm -rf /var/lib/apt/lists/*

# ── Stage 2: Liquidsoap runtime ──────────────────────────
FROM savonet/liquidsoap:v2.3.1

LABEL maintainer="iRadio Project"
LABEL description="Liquidsoap audio engine for iRadio automation"

# ── System packages ─────────────────────────────────────
# curl   — HTTP API requests from .liq scripts
# jq     — JSON parsing in .liq shell commands
# netcat — healthcheck socket probe (used by compose)
USER root
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        curl \
        jq \
        netcat-openbsd && \
    rm -rf /var/lib/apt/lists/*

# ── Directory structure ─────────────────────────────────
# Match the paths declared in docker-compose.yml volumes
RUN mkdir -p \
        /var/lib/iradio/music \
        /var/lib/iradio/jingles \
        /var/log/iradio \
        /run/liquidsoap \
        /etc/liquidsoap && \
    chown -R liquidsoap:liquidsoap \
        /var/lib/iradio \
        /var/log/iradio \
        /run/liquidsoap \
        /etc/liquidsoap

# ── Copy silence file from build stage ───────────────────
# 10s silent MP3 used for idle polling (keeps source alive
# so Liquidsoap detects schedule changes automatically).
# Placed in /opt/iradio/ (not /etc/liquidsoap/ which is a bind mount).
COPY --from=silence-gen /silence.mp3 /opt/iradio/silence.mp3

# ── Runtime configuration ──────────────────────────────
# Expose the telnet control port for admin commands
EXPOSE 1234

# Drop back to non-root
USER liquidsoap

# Healthcheck matches docker-compose.yml expectation:
#   test -S /run/liquidsoap/main.sock
HEALTHCHECK --interval=15s --timeout=5s --retries=5 --start-period=30s \
    CMD test -S /run/liquidsoap/main.sock || exit 1

# ── Entrypoint ──────────────────────────────────────────
# Run the radio script mounted at /etc/liquidsoap/radio.liq
# --force-start   : start even if some sources are unavailable
# --verbose       : verbose logging
# Script path must come last.
ENTRYPOINT ["liquidsoap"]
CMD ["--verbose", "--force-start", "/etc/liquidsoap/radio.liq"]
