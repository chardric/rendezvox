#!/bin/bash
# ==========================================================
# iRadio — Raspberry Pi Automated Installer
# ==========================================================
# Fully automated installation for Raspberry Pi with Docker.
# Run on a fresh Raspberry Pi OS 64-bit (Bookworm or later).
#
# Minimum:     Raspberry Pi 3B (1 GB RAM)
# Recommended: Raspberry Pi 4/5 (4 GB RAM)
#
# This script will:
#   1. Update system packages
#   2. Install Docker Engine and Docker Compose
#   3. Install git, curl, and other prerequisites
#   4. Configure swap (2 GB) for low-RAM boards
#   5. Apply I/O and memory optimizations
#   6. Generate secure passwords
#   7. Create .env configuration
#   8. Build and start all Docker containers
#   9. Create the initial admin user
#
# Usage:
#   git clone https://github.com/chardric/iRadio.git
#   cd iRadio
#   chmod +x deploy/rpi/install.sh
#   sudo ./deploy/rpi/install.sh
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

# ── Detect project directory ──
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
IRADIO_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"

# Verify project structure
[ -f "$IRADIO_DIR/docker/docker-compose.yml" ] || err "Cannot find docker-compose.yml. Run this script from the iRadio project root."
[ -f "$IRADIO_DIR/deploy/rpi/docker-compose.override.yml" ] || err "Cannot find RPi override. Run this script from the iRadio project root."

# ── Detect architecture ──
ARCH=$(uname -m)
log "=========================================="
log " iRadio — Raspberry Pi Installer"
log "=========================================="
log " Architecture: $ARCH"
log " Project dir:  $IRADIO_DIR"
log " RAM:          $(free -h | awk '/Mem:/{print $2}')"
log "=========================================="
echo ""

if [ "$ARCH" != "aarch64" ] && [ "$ARCH" != "armv7l" ]; then
    warn "Expected ARM architecture (aarch64/armv7l), detected: $ARCH"
    warn "Continuing — Docker images must support your architecture."
fi

# ==========================================================
# 1. System updates & prerequisites
# ==========================================================
log "Step 1/9: Checking and installing prerequisites..."

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
if ! command -v docker &>/dev/null; then
    log "Step 2/9: Installing Docker Engine..."
    curl -fsSL https://get.docker.com | sh
    # Add the invoking user to the docker group
    if [ -n "${SUDO_USER:-}" ]; then
        usermod -aG docker "$SUDO_USER"
        info "Added user '$SUDO_USER' to docker group"
    fi
    systemctl enable docker
    systemctl start docker
    log "Docker installed: $(docker --version)"
else
    log "Step 2/9: Docker already installed: $(docker --version)"
fi

# ==========================================================
# 3. Install Docker Compose plugin
# ==========================================================
if ! docker compose version &>/dev/null; then
    log "Step 3/9: Installing Docker Compose plugin..."
    apt-get install -y -qq docker-compose-plugin 2>/dev/null || {
        # Fallback: install from GitHub
        COMPOSE_VERSION=$(curl -fsSL https://api.github.com/repos/docker/compose/releases/latest | grep -oP '"tag_name": "\K[^"]+')
        curl -fsSL "https://github.com/docker/compose/releases/download/${COMPOSE_VERSION}/docker-compose-linux-$(uname -m)" \
            -o /usr/local/lib/docker/cli-plugins/docker-compose
        chmod +x /usr/local/lib/docker/cli-plugins/docker-compose
    }
    log "Docker Compose installed: $(docker compose version)"
else
    log "Step 3/9: Docker Compose already installed: $(docker compose version)"
fi

# ==========================================================
# 4. Configure swap (critical for RPi 3B with 1 GB RAM)
# ==========================================================
log "Step 4/9: Checking swap configuration..."
SWAP_MB=$(free -m | awk '/Swap:/{print $2}')
if [ "$SWAP_MB" -lt 1024 ]; then
    if [ -f /etc/dphys-swapfile ]; then
        dphys-swapfile swapoff 2>/dev/null || true
        sed -i 's/^CONF_SWAPSIZE=.*/CONF_SWAPSIZE=2048/' /etc/dphys-swapfile
        dphys-swapfile setup
        dphys-swapfile swapon
    else
        swapoff -a 2>/dev/null || true
        rm -f /swapfile
        fallocate -l 2G /swapfile
        chmod 600 /swapfile
        mkswap /swapfile
        swapon /swapfile
        grep -q '/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
    fi
    log "Swap configured: 2 GB"
else
    log "Swap already adequate: ${SWAP_MB} MB"
fi

# ==========================================================
# 5. System optimizations
# ==========================================================
log "Step 5/9: Checking system optimizations..."

CURRENT_SWAPPINESS=$(sysctl -n vm.swappiness 2>/dev/null || echo "60")
if [ "$CURRENT_SWAPPINESS" -gt 10 ] || [ ! -f /etc/sysctl.d/99-iradio.conf ]; then
    log "Applying system optimizations..."
    sysctl -w vm.swappiness=10 >/dev/null
    cat > /etc/sysctl.d/99-iradio.conf << 'SYSCTL'
# iRadio: reduce swap aggressiveness for audio workloads
vm.swappiness=10
# Increase file watchers for Liquidsoap
fs.inotify.max_user_watches=65536
SYSCTL
    sysctl --system >/dev/null 2>&1
else
    log "System optimizations already applied — skipping"
fi

# ==========================================================
# 6. Generate secure passwords
# ==========================================================
log "Step 6/9: Generating secure passwords..."

DB_PASS=$(openssl rand -hex 16)
ICECAST_SOURCE_PASS=$(openssl rand -hex 12)
ICECAST_RELAY_PASS=$(openssl rand -hex 12)
ICECAST_ADMIN_PASS=$(openssl rand -hex 12)
JWT_SECRET=$(openssl rand -hex 32)
INTERNAL_SECRET=$(openssl rand -hex 32)
ADMIN_PASS=$(openssl rand -base64 12 | tr -d '/+=' | head -c 12)

# ==========================================================
# 7. Create directories and .env configuration
# ==========================================================
log "Step 7/9: Creating directories and configuration..."

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
ICECAST_MAX_CLIENTS=30
ICECAST_MAX_SOURCES=3

# ── Application ─────────────────────────────────────────
IRADIO_APP_ENV=production
IRADIO_APP_DEBUG=false
IRADIO_JWT_SECRET=$JWT_SECRET
IRADIO_INTERNAL_SECRET=$INTERNAL_SECRET
IRADIO_ICECAST_MOUNT=/live
TZ=UTC

# ── Host Port Overrides ─────────────────────────────────
IRADIO_HTTP_PORT=80
IRADIO_ICECAST_PUBLIC_PORT=8000
ENV
    log ".env created with secure passwords"
fi

# ==========================================================
# 8. Build and start Docker containers
# ==========================================================
log "Step 8/9: Building and starting containers (this may take 10-20 min on RPi)..."

cd "$IRADIO_DIR/docker"
docker compose -f docker-compose.yml \
    -f "$IRADIO_DIR/deploy/rpi/docker-compose.override.yml" \
    up -d --build 2>&1 | while read -r line; do
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
# 9. Create admin user
# ==========================================================
log "Step 9/9: Creating admin user..."

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
  Start:    cd $IRADIO_DIR/docker && docker compose -f docker-compose.yml -f ../deploy/rpi/docker-compose.override.yml up -d
  Stop:     cd $IRADIO_DIR/docker && docker compose -f docker-compose.yml -f ../deploy/rpi/docker-compose.override.yml down
  Logs:     cd $IRADIO_DIR/docker && docker compose logs -f
  Restart:  cd $IRADIO_DIR/docker && docker compose -f docker-compose.yml -f ../deploy/rpi/docker-compose.override.yml restart
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
echo ""
info " Hardware recommendations:"
info "   - Use a USB SSD instead of SD card for better performance"
info "   - RPi 3B (1 GB): supports ~10-15 concurrent listeners"
info "   - RPi 4 (4 GB): supports ~30-50 concurrent listeners"
info "   - RPi 5 (8 GB): supports ~100+ concurrent listeners"
log "=========================================="
