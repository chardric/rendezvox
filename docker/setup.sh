#!/bin/bash
# ==========================================================
# RendezVox — One-Command Server Setup
# ==========================================================
# Generates .env with secure random credentials.
# Run once before first `docker compose up -d`.
#
# Usage:
#   cd docker/
#   bash setup.sh
#   docker compose up -d
# ==========================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ENV_FILE="${SCRIPT_DIR}/.env"
EXAMPLE_FILE="${SCRIPT_DIR}/.env.example"

# ── Check if .env already exists ──────────────────────────
if [ -f "$ENV_FILE" ]; then
    echo "[RendezVox] .env already exists — skipping credential generation."
    echo "         Delete .env and re-run this script to regenerate."
    exit 0
fi

# ── Verify .env.example exists ────────────────────────────
if [ ! -f "$EXAMPLE_FILE" ]; then
    echo "[RendezVox] ERROR: .env.example not found in ${SCRIPT_DIR}"
    exit 1
fi

# ── Generate random passwords ─────────────────────────────
gen_password() {
    openssl rand -hex 16
}

POSTGRES_PASSWORD=$(gen_password)
ICECAST_SOURCE_PASSWORD=$(gen_password)
ICECAST_ADMIN_PASSWORD=$(gen_password)
ICECAST_RELAY_PASSWORD=$(gen_password)
RENDEZVOX_JWT_SECRET=$(openssl rand -hex 32)
RENDEZVOX_INTERNAL_SECRET=$(openssl rand -hex 32)

# ── Create .env from template ─────────────────────────────
cp "$EXAMPLE_FILE" "$ENV_FILE"

# Write generated passwords
sed -i "s|^POSTGRES_PASSWORD=.*|POSTGRES_PASSWORD=${POSTGRES_PASSWORD}|" "$ENV_FILE"
sed -i "s|^ICECAST_SOURCE_PASSWORD=.*|ICECAST_SOURCE_PASSWORD=${ICECAST_SOURCE_PASSWORD}|" "$ENV_FILE"
sed -i "s|^ICECAST_ADMIN_PASSWORD=.*|ICECAST_ADMIN_PASSWORD=${ICECAST_ADMIN_PASSWORD}|" "$ENV_FILE"
sed -i "s|^ICECAST_RELAY_PASSWORD=.*|ICECAST_RELAY_PASSWORD=${ICECAST_RELAY_PASSWORD}|" "$ENV_FILE"
sed -i "s|^RENDEZVOX_JWT_SECRET=.*|RENDEZVOX_JWT_SECRET=${RENDEZVOX_JWT_SECRET}|" "$ENV_FILE"
sed -i "s|^RENDEZVOX_INTERNAL_SECRET=.*|RENDEZVOX_INTERNAL_SECRET=${RENDEZVOX_INTERNAL_SECRET}|" "$ENV_FILE"

# ── Done ──────────────────────────────────────────────────
echo ""
echo "  ╔══════════════════════════════════════════════════╗"
echo "  ║         RendezVox — Credentials Generated!          ║"
echo "  ╠══════════════════════════════════════════════════╣"
echo "  ║  All passwords saved to docker/.env              ║"
echo "  ║                                                  ║"
echo "  ║  Next steps:                                     ║"
echo "  ║    1. (Optional) Edit .env to set TZ, hostname   ║"
echo "  ║    2. docker compose up -d                       ║"
echo "  ║    3. Open http://your-server/admin              ║"
echo "  ║       First visit = setup wizard for admin user  ║"
echo "  ╚══════════════════════════════════════════════════╝"
echo ""
