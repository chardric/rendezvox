#!/bin/bash
# ==========================================================
# iRadio — Standard Server Automated Installer (Docker)
# ==========================================================
# Fully automated Docker-based installation for standard
# Linux servers (VPS, dedicated, cloud).
#
# Supported OS:
#   - Ubuntu 22.04 / 24.04 LTS
#   - Debian 12 (Bookworm)
#
# This script will:
#   1. Check and install system prerequisites
#   2. Install Docker Engine (if not installed)
#   3. Install Docker Compose plugin (if not installed)
#   4. Apply system optimizations
#   5. Generate secure passwords
#   6. Create .env configuration
#   7. Build and start all Docker containers
#   8. Create the initial admin user
#
# Usage:
#   git clone https://github.com/chardric/iRadio.git
#   cd iRadio
#   chmod +x deploy/server/install.sh
#   sudo ./deploy/server/install.sh
# ==========================================================

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

log()  { echo -e "${GREEN}[iRadio]${NC} $1"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
err()  { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }
info() { echo -e "${CYAN}[INFO]${NC} $1"; }

# ── Check root ──
[ "$(id -u)" -eq 0 ] || err "Run this script with sudo"

# ── Detect OS ──
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS_ID="$ID"
    OS_VERSION="$VERSION_ID"
else
    err "Cannot detect OS. This script requires Ubuntu 22.04+ or Debian 12+"
fi

log "Detected OS: $PRETTY_NAME"

case "$OS_ID" in
    ubuntu)
        [ "$OS_VERSION" = "22.04" ] || [ "$OS_VERSION" = "24.04" ] || \
            warn "Tested on Ubuntu 22.04/24.04. Your version ($OS_VERSION) may work but is untested."
        ;;
    debian)
        [ "$OS_VERSION" = "12" ] || \
            warn "Tested on Debian 12. Your version ($OS_VERSION) may work but is untested."
        ;;
    *)
        warn "Untested OS: $OS_ID. Continuing — Docker should work on most Linux distros."
        ;;
esac

# ── Detect project directory ──
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
IRADIO_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"

# Verify project structure
[ -f "$IRADIO_DIR/docker/docker-compose.yml" ] || err "Cannot find docker-compose.yml. Run this script from the iRadio project root."

echo ""
log "=========================================="
log " iRadio — Server Installer (Docker)"
log "=========================================="
log " OS:           $PRETTY_NAME"
log " Architecture: $(uname -m)"
log " Project dir:  $IRADIO_DIR"
log " RAM:          $(free -h | awk '/Mem:/{print $2}')"
log " CPU cores:    $(nproc)"
log "=========================================="
echo ""

# ==========================================================
# 1. System prerequisites
# ==========================================================
log "Step 1/8: Checking system prerequisites..."

PREREQS=(curl wget git ca-certificates gnupg lsb-release apt-transport-https openssl)
MISSING_PKGS=()
for pkg in "${PREREQS[@]}"; do
    if ! dpkg -s "$pkg" &>/dev/null; then
        MISSING_PKGS+=("$pkg")
    fi
done

if [ ${#MISSING_PKGS[@]} -gt 0 ]; then
    log "Installing missing packages: ${MISSING_PKGS[*]}"
    apt-get update -qq
    apt-get install -y -qq "${MISSING_PKGS[@]}"
else
    log "All prerequisites already installed — skipping"
fi

# ==========================================================
# 2. Install Docker Engine
# ==========================================================
if command -v docker &>/dev/null; then
    log "Step 2/8: Docker already installed: $(docker --version) — skipping"
else
    log "Step 2/8: Installing Docker Engine..."
    curl -fsSL https://get.docker.com | sh
    # Add the invoking user to the docker group
    if [ -n "${SUDO_USER:-}" ]; then
        usermod -aG docker "$SUDO_USER"
        info "Added user '$SUDO_USER' to docker group"
    fi
    systemctl enable docker
    systemctl start docker
    log "Docker installed: $(docker --version)"
fi

# ==========================================================
# 3. Install Docker Compose plugin
# ==========================================================
if docker compose version &>/dev/null; then
    log "Step 3/8: Docker Compose already installed: $(docker compose version) — skipping"
else
    log "Step 3/8: Installing Docker Compose plugin..."
    apt-get install -y -qq docker-compose-plugin 2>/dev/null || {
        # Fallback: install from GitHub
        mkdir -p /usr/local/lib/docker/cli-plugins
        COMPOSE_VERSION=$(curl -fsSL https://api.github.com/repos/docker/compose/releases/latest | grep -oP '"tag_name": "\K[^"]+')
        curl -fsSL "https://github.com/docker/compose/releases/download/${COMPOSE_VERSION}/docker-compose-linux-$(uname -m)" \
            -o /usr/local/lib/docker/cli-plugins/docker-compose
        chmod +x /usr/local/lib/docker/cli-plugins/docker-compose
    }
    log "Docker Compose installed: $(docker compose version)"
fi

# ==========================================================
# 4. System optimizations
# ==========================================================
log "Step 4/8: Checking system optimizations..."

if [ -f /etc/sysctl.d/99-iradio.conf ]; then
    log "System optimizations already applied — skipping"
else
    log "Applying system optimizations..."
    cat > /etc/sysctl.d/99-iradio.conf << 'SYSCTL'
# iRadio: optimize for audio streaming workloads
vm.swappiness=10
# Increase file watchers for Liquidsoap
fs.inotify.max_user_watches=65536
# Increase max connections for high listener counts
net.core.somaxconn=1024
SYSCTL
    sysctl --system >/dev/null 2>&1
fi

# ==========================================================
# 5. Generate secure passwords
# ==========================================================
log "Step 5/8: Generating secure passwords..."

DB_PASS=$(openssl rand -hex 16)
ICECAST_SOURCE_PASS=$(openssl rand -hex 12)
ICECAST_RELAY_PASS=$(openssl rand -hex 12)
ICECAST_ADMIN_PASS=$(openssl rand -hex 12)
JWT_SECRET=$(openssl rand -hex 32)
ADMIN_PASS=$(openssl rand -base64 12 | tr -d '/+=' | head -c 12)

# ==========================================================
# 6. Create directories and .env configuration
# ==========================================================
log "Step 6/8: Creating directories and configuration..."

mkdir -p "$IRADIO_DIR"/{data/postgres,data/avatars,data/logs,music,jingles}
chown -R "${SUDO_USER:-1000}":"${SUDO_USER:-1000}" "$IRADIO_DIR"/data 2>/dev/null || true
chown -R 1000:1000 "$IRADIO_DIR"/data/postgres 2>/dev/null || true

# Generate .env — skip if already exists (re-running installer)
if [ -f "$IRADIO_DIR/docker/.env" ]; then
    warn ".env already exists — preserving existing configuration"
    warn "To regenerate, delete $IRADIO_DIR/docker/.env and re-run this script"
    # Read existing passwords from .env for credential file
    DB_PASS=$(grep '^POSTGRES_PASSWORD=' "$IRADIO_DIR/docker/.env" | cut -d'=' -f2)
    ICECAST_SOURCE_PASS=$(grep '^ICECAST_SOURCE_PASSWORD=' "$IRADIO_DIR/docker/.env" | cut -d'=' -f2)
    ICECAST_ADMIN_PASS=$(grep '^ICECAST_ADMIN_PASSWORD=' "$IRADIO_DIR/docker/.env" | cut -d'=' -f2)
else
    log "Generating .env with secure passwords..."
    cat > "$IRADIO_DIR/docker/.env" << ENV
# ==========================================================
# iRadio — Environment Variables (auto-generated)
# ==========================================================
# Generated on: $(date -Iseconds)
# ==========================================================

# ── PostgreSQL ───────────────────────────────────────────
POSTGRES_DB=iradio
POSTGRES_USER=iradio
POSTGRES_PASSWORD=$DB_PASS

# ── Icecast ──────────────────────────────────────────────
ICECAST_SOURCE_PASSWORD=$ICECAST_SOURCE_PASS
ICECAST_RELAY_PASSWORD=$ICECAST_RELAY_PASS
ICECAST_ADMIN_PASSWORD=$ICECAST_ADMIN_PASS
ICECAST_ADMIN_USER=admin
ICECAST_HOSTNAME=localhost
ICECAST_MAX_CLIENTS=100
ICECAST_MAX_SOURCES=5

# ── Application ─────────────────────────────────────────
IRADIO_APP_ENV=production
IRADIO_APP_DEBUG=false
IRADIO_JWT_SECRET=$JWT_SECRET
IRADIO_ICECAST_MOUNT=/live
TZ=UTC

# ── Host Port Overrides ─────────────────────────────────
IRADIO_HTTP_PORT=80
IRADIO_ICECAST_PUBLIC_PORT=8000
ENV
    log ".env created with secure passwords"
fi

# ==========================================================
# 7. Build and start Docker containers
# ==========================================================
log "Step 7/8: Building and starting containers..."

cd "$IRADIO_DIR/docker"
docker compose up -d --build 2>&1 | while read -r line; do
    echo -e "  ${CYAN}>${NC} $line"
done

# Wait for PostgreSQL to be healthy
log "Waiting for database to be ready..."
for i in $(seq 1 60); do
    if docker exec iradio-postgres pg_isready -U iradio -d iradio >/dev/null 2>&1; then
        log "Database is ready"
        break
    fi
    sleep 2
    [ "$i" -eq 60 ] && warn "Database health check timed out — it may still be starting"
done

# ==========================================================
# 8. Create admin user
# ==========================================================
log "Step 8/8: Creating admin user..."

sleep 3  # Give PHP-FPM a moment to initialize

docker exec iradio-php php -r "
    require '/var/www/html/src/core/Database.php';
    \$db = Database::get();
    \$hash = password_hash('$ADMIN_PASS', PASSWORD_BCRYPT);
    \$stmt = \$db->prepare(\"SELECT id FROM users WHERE username = 'admin'\");
    \$stmt->execute();
    if (\$stmt->rowCount() > 0) {
        echo \"Admin user already exists — skipping\n\";
    } else {
        \$db->prepare(\"INSERT INTO users (username, email, password_hash, role) VALUES ('admin', 'admin@iradio.local', :hash, 'super_admin')\")->execute(['hash' => \$hash]);
        echo \"Admin user created.\n\";
    }
" 2>/dev/null || warn "Could not create admin user — create it manually (see README)"

# ── Save credentials ──
CREDS_FILE="$IRADIO_DIR/.credentials"
SERVER_IP=$(hostname -I | awk '{print $1}')

cat > "$CREDS_FILE" << CREDS
# ==========================================================
# iRadio — Installation Credentials
# ==========================================================
# Generated: $(date)
# KEEP THIS FILE SECURE. Delete after noting the passwords.
# ==========================================================

Admin Panel:
  URL:      http://$SERVER_IP/admin/
  Username: admin
  Password: $ADMIN_PASS

Database:
  Host:     localhost (inside Docker: postgres)
  Port:     5432
  Name:     iradio
  User:     iradio
  Password: $DB_PASS

Icecast:
  Stream URL:     http://$SERVER_IP/stream/live
  Admin URL:      http://$SERVER_IP:8000/admin/
  Admin User:     admin
  Admin Password: $ICECAST_ADMIN_PASS
  Source Password: $ICECAST_SOURCE_PASS

Docker Commands:
  Start:    cd $IRADIO_DIR/docker && docker compose up -d
  Stop:     cd $IRADIO_DIR/docker && docker compose down
  Logs:     cd $IRADIO_DIR/docker && docker compose logs -f
  Restart:  cd $IRADIO_DIR/docker && docker compose restart
CREDS

chmod 600 "$CREDS_FILE"

# ── Final status ──
echo ""
log "=========================================="
log " iRadio installation complete!"
log "=========================================="
echo ""
log " Services:"

for svc in postgres php nginx icecast liquidsoap; do
    status=$(docker inspect -f '{{.State.Health.Status}}' "iradio-$svc" 2>/dev/null || echo "unknown")
    running=$(docker inspect -f '{{.State.Running}}' "iradio-$svc" 2>/dev/null || echo "false")
    if [ "$running" = "true" ]; then
        log "   $svc: RUNNING ($status)"
    else
        warn "   $svc: STOPPED"
    fi
done

echo ""
log " Credentials saved to: $CREDS_FILE"
log "   View with: sudo cat $CREDS_FILE"
echo ""
log " Admin panel:  http://$SERVER_IP/admin/"
log " Stream URL:   http://$SERVER_IP/stream/live"
log " Listener page: http://$SERVER_IP/"
echo ""
log " Login: admin / $ADMIN_PASS"
warn " Change your password after first login!"
echo ""
log " Place music files in: $IRADIO_DIR/music/"
log " Then import via the Media page in the admin panel."
log "=========================================="
