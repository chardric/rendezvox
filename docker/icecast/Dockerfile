# ==========================================================
# iRadio — Icecast2 Streaming Server
# ==========================================================
# Build context: docker/icecast/
# Compose mounts:
#   icecast.xml  → /etc/icecast2/icecast.xml.tpl  (template, ro)
#   iradio_logs  → /var/log/iradio                (logs, rw)
# ==========================================================

FROM debian:bookworm-slim

LABEL maintainer="iRadio Project"
LABEL description="Icecast2 streaming server for iRadio"

RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        icecast2 \
        gettext-base \
        wget && \
    rm -rf /var/lib/apt/lists/*

# ── Directory setup ─────────────────────────────────────
RUN mkdir -p /var/log/iradio && \
    chown -R icecast2:icecast /var/log/iradio && \
    chown -R icecast2:icecast /etc/icecast2

EXPOSE 8000

# ── Entrypoint: envsubst passwords into config ─────────
# The template at icecast.xml.tpl uses ${VAR} placeholders.
# envsubst replaces them with actual env-var values at boot,
# writing the live config to a non-mounted path.
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
