#!/bin/sh
set -e

# ── Substitute env vars into the Icecast config template ──
# Only substitute the variables we explicitly define,
# so other XML content with '$' is left untouched.
envsubst '${ICECAST_SOURCE_PASSWORD}
${ICECAST_RELAY_PASSWORD}
${ICECAST_ADMIN_PASSWORD}
${ICECAST_ADMIN_USERNAME}
${ICECAST_HOSTNAME}
${ICECAST_MAX_CLIENTS}
${ICECAST_MAX_SOURCES}' \
    < /etc/icecast2/icecast.xml.tpl \
    > /etc/icecast2/icecast.xml

chown icecast2:icecast /etc/icecast2/icecast.xml

# ── Fix bind-mount permissions ────────────────────────────
# The host log directory may be owned by a different UID.
# Icecast needs write access to create log files.
mkdir -p /var/log/rendezvox
chown -R icecast2:icecast /var/log/rendezvox

echo "[RendezVox] Icecast config generated — starting server..."

# ── Start Icecast as the icecast2 user ────────────────────
exec su -s /bin/sh icecast2 -c "icecast2 -c /etc/icecast2/icecast.xml"
