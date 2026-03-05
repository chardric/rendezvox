#!/bin/bash
# ==========================================================
# RendezVox — One-Command Setup
# ==========================================================
# Generates .env with secure random credentials and detects
# available ports. Run once before `docker compose up -d`.
#
# Usage:
#   cd docker/
#   bash setup.sh
#   docker compose up -d
# ==========================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ENV_FILE="${SCRIPT_DIR}/.env"

# ── Check if .env already exists ──────────────────────────
if [ -f "$ENV_FILE" ]; then
    echo "[RendezVox] .env already exists — skipping setup."
    echo "            Delete .env and re-run to regenerate."
    exit 0
fi

# ── Find available port ──────────────────────────────────
find_available_port() {
    local port=$1
    local max_attempts=20
    for _ in $(seq 1 $max_attempts); do
        if ! ss -tlnH "sport = :$port" 2>/dev/null | grep -q ":$port"; then
            echo "$port"
            return 0
        fi
        port=$((port + 1))
    done
    echo "$1"
}

# ── Generate credentials and detect port ──────────────────
DB_PASS=$(openssl rand -hex 16)
ICECAST_SOURCE_PASS=$(openssl rand -hex 12)
ICECAST_ADMIN_PASS=$(openssl rand -hex 12)
INTERNAL_SECRET=$(openssl rand -hex 32)
HTTP_PORT=$(find_available_port 8888)

# ── Write .env ────────────────────────────────────────────
cat > "$ENV_FILE" << ENV
# ==========================================================
# RendezVox — Environment Variables (auto-generated)
# ==========================================================
# Generated on: $(date -Iseconds)
#
# Credentials below are auto-generated. The only settings
# you might want to change are at the bottom.
# ==========================================================

# ── Auto-generated credentials (do not share) ────────────
POSTGRES_PASSWORD=${DB_PASS}
ICECAST_SOURCE_PASSWORD=${ICECAST_SOURCE_PASS}
ICECAST_ADMIN_PASSWORD=${ICECAST_ADMIN_PASS}
RENDEZVOX_INTERNAL_SECRET=${INTERNAL_SECRET}

# ── User settings (edit these as needed) ──────────────────
RENDEZVOX_APP_ENV=production
TZ=UTC
RENDEZVOX_HTTP_PORT=${HTTP_PORT}
ENV

# ── Done ──────────────────────────────────────────────────
echo ""
echo "  ╔══════════════════════════════════════════════╗"
echo "  ║       RendezVox — Setup Complete!            ║"
echo "  ╠══════════════════════════════════════════════╣"
echo "  ║  Credentials auto-generated in .env          ║"
echo "  ║  HTTP port: ${HTTP_PORT}                           ║"
echo "  ║                                              ║"
echo "  ║  Next steps:                                 ║"
echo "  ║    1. (Optional) Edit .env to set TZ         ║"
echo "  ║    2. docker compose up -d                   ║"
echo "  ║    3. Open http://localhost:${HTTP_PORT}/admin     ║"
echo "  ╚══════════════════════════════════════════════╝"
echo ""
