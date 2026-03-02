# RendezVox — Audio Output Container
# Plays Icecast stream through the server's audio hardware via mpv.
# Provides IPC socket for real-time EQ control from the admin panel.

FROM debian:bookworm-slim

RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        mpv \
        socat \
        jq \
    && rm -rf /var/lib/apt/lists/*

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
