# RendezVox

**Online FM Radio Automation System**

RendezVox is a self-hosted, fully automated internet radio station built with PHP, Liquidsoap, Icecast, and PostgreSQL. It provides a complete solution for managing music libraries, scheduling playlists, streaming live audio, and accepting listener song requests — all from a modern dark-themed admin panel. Includes desktop apps for Linux, Windows, and macOS, plus a native Android mobile app.

---

## Downloads

| Platform | Download |
|----------|----------|
| **Android** | [RendezVox-1.0.0.apk](https://github.com/chardric/rendezvox/releases/download/v1.0.0/RendezVox-1.0.0.apk) |
| **Linux (x64 DEB)** | [rendezvox_1.0.0_amd64.deb](https://github.com/chardric/rendezvox/releases/download/v1.0.0/rendezvox_1.0.0_amd64.deb) |
| **Linux (ARM64 DEB)** | [rendezvox_1.0.0_arm64.deb](https://github.com/chardric/rendezvox/releases/download/v1.0.0/rendezvox_1.0.0_arm64.deb) |
| **Linux (x64 AppImage)** | [RendezVox-1.0.0.AppImage](https://github.com/chardric/rendezvox/releases/download/v1.0.0/RendezVox-1.0.0.AppImage) |
| **Linux (ARM64 AppImage)** | [RendezVox-1.0.0-arm64.AppImage](https://github.com/chardric/rendezvox/releases/download/v1.0.0/RendezVox-1.0.0-arm64.AppImage) |
| **Windows** | [RendezVox Setup 1.0.0.exe](https://github.com/chardric/rendezvox/releases/download/v1.0.0/RendezVox.Setup.1.0.0.exe) |

---

## Table of Contents

- [Downloads](#downloads)
- [Features](#features)
- [Architecture](#architecture)
- [Tech Stack](#tech-stack)
- [System Requirements](#system-requirements)
- [Quick Start — Server](#quick-start--server)
- [Deployment](#deployment)
  - [Standard Server (Docker)](#standard-server-docker)
  - [Raspberry Pi (Docker)](#raspberry-pi-docker)
- [Desktop App](#desktop-app)
- [Mobile App](#mobile-app)
  - [Android](#android-app)
- [Project Structure](#project-structure)
- [API Overview](#api-overview)
- [Database Schema](#database-schema)
- [Cloudflare Zero Trust Support](#cloudflare-zero-trust-support)
- [Environment Variables](#environment-variables)
- [Changelog](#changelog)

---

## Features

### Audio Streaming & Automation
- **Liquidsoap** audio processing engine with API-driven track selection
- **Icecast 2** streaming server with configurable mount points
- Smart rotation engine with multi-pass artist separation (gap 6) and title separation (gap 3)
- Cross-playlist dedup — same song and title variations blocked for 24 hours
- Crossfade support with configurable duration
- Station ID/sweeper/liner insertion at configurable intervals
- Emergency mode with dedicated fallback playlist
- EBU R128 loudness normalization

### Music Library Management
- Drag-and-drop media file browser with folder navigation
- Automatic metadata extraction (title, artist, album, year, duration)
- Audio fingerprinting via AcoustID for song identification
- AI-powered genre tagging via Gemini 2.5 Flash + Ollama fallback
- Duplicate detection using audio fingerprints (SHA-256)
- Artist deduplication and normalization
- Library sync from filesystem with batch import
- Per-song rotation weight control

### Playlist & Schedule System
- Manual, auto-generated, and emergency playlist types
- Auto-playlists with category/weight rules (JSONB)
- Visual calendar schedule builder with day-of-week, date range, and time slots
- Priority-based schedule overlap resolution
- Full cycle tracking — no-repeat-until-all-played guarantee
- Smart playlist shuffle with artist/title collision avoidance
- Playlist drag-to-reorder

### Song Request System
- Public listener request page with fuzzy song search
- Configurable request limits per IP (rolling window)
- Rate limiting (min seconds between requests)
- Auto-approve mode or manual moderation queue
- Automatic request expiration
- IP ban system with optional expiry
- Real client IP detection behind Cloudflare Zero Trust tunnels

### Admin Panel
- Modern dark-themed responsive UI
- Real-time SSE (Server-Sent Events) for now-playing updates
- Dashboard with DJ booth monitor, live progress bar, stream controls
- Collapsible sidebar with icon-only rail mode
- Profile/Account modal with avatar upload, display name, login IP tracking
- Password change with strength meter
- Role-based access control (Super Admin, Admin, Editor, Viewer)
- User management (create, edit, disable, delete)
- Analytics: listener stats, popular songs, popular requests
- Station settings panel (station ID interval, repeat blocks, request limits, etc.)
- Weather widget integration

### Public Listener Page (Web)
- Vinyl turntable player with animated tonearm as progress indicator
- Click turntable to play/stop — tonearm swings from parked to record position
- Blurred cover art background with time-of-day ambient color overlay
- Equalizer visualizer bars with accent color theming
- Real-time now-playing and up-next display (SSE)
- Song change animation with marquee for long titles
- Listener count with live pulse badge
- Song request modal with fuzzy search and suggestions
- Dedication/request-by display for current song
- Recently played history with expand/collapse
- Auto-reconnect on stream interruption
- PWA support (installable, service worker)
- Server-synced accent color

### Desktop App (Electron)
- Native app for Linux (DEB + AppImage, x64 + ARM64), Windows (NSIS), and macOS (DMG)
- Vinyl turntable player matching the web UI/UX
- System tray integration with minimize-to-tray
- Autostart on login with `--hidden` flag
- Media key bindings (play/pause, stop)
- Single-instance lock — second launch focuses existing window
- Page visibility API — pauses polling when minimized
- Song request with fuzzy search
- Volume control, listener count, dedication display

### Mobile App (Android)
- Native Kotlin app with Jetpack Compose and Material Design 3
- Vinyl turntable player matching the web UI/UX
- Background audio playback with media notification controls
- Real-time now-playing via SSE with polling fallback
- Song request with autocomplete search
- Volume control, listener count, up-next display
- Dedication card display
- Configurable server URL (LAN or public)
- Server selection screen with connection validation
- Offline detection with auto-retry and visual banner

---

## Architecture

```
                         ┌──────────┐     ┌────────────┐
                    ┌───▶│  PHP-FPM │────▶│ PostgreSQL │
                    │    │  8.3     │     │  16        │
┌─────────────┐     │    └────┬─────┘     └────────────┘
│   Nginx     │─────┤         │
│  (reverse   │     │    ┌────▼──────┐     ┌────────────┐
│   proxy)    │─────┼───▶│ Liquidsoap│────▶│  Icecast 2 │
└──────┬──────┘     │    │ (engine)  │     │  (stream)  │
       │            │    └───────────┘     └──────┬─────┘
       │            │                             │
       │            └──── /stream/* ──────────────┘
       │                  (audio proxy)
  ┌────▼─────────────────────────────────────────────────┐
  │  Browser (Admin Panel / Listener Page)               │
  │  Desktop App (Electron — Linux, Windows, macOS)      │
  │  Android App (Kotlin/Compose + Media3)               │
  └──────────────────────────────────────────────────────┘

All traffic flows through Nginx on port 80 — including the audio
stream at /stream/live. No need to expose Icecast port 8000.
```

| Service | Technology | Purpose |
|---------|-----------|---------|
| **Nginx** | nginx:1.27 | Reverse proxy, static files, Icecast stream proxy, Cloudflare IP forwarding |
| **PHP-FPM** | PHP 8.3 | REST API, admin panel backend, SSE |
| **PostgreSQL** | PostgreSQL 16 | All application data, playlist state, play history |
| **Liquidsoap** | Liquidsoap 2.x | Audio processing, crossfade, stream encoding |
| **Icecast** | Icecast 2 | HTTP audio streaming (proxied through Nginx at `/stream/live`) |

---

## Tech Stack

| Layer | Stack |
|-------|-------|
| Backend | PHP 8.3, PostgreSQL 16 |
| Audio | Liquidsoap 2.x, Icecast 2 |
| Frontend | Vanilla JavaScript, CSS3 |
| Desktop App | Electron 33, electron-builder |
| Android App | Kotlin 2.0, Jetpack Compose, Media3 ExoPlayer |
| Infrastructure | Docker Compose, Nginx |

---

## System Requirements

### Server (Docker Host)

| Requirement | Minimum | Recommended |
|-------------|---------|-------------|
| **OS** | Linux (Ubuntu 22.04+, Debian 12+), macOS 13+, or Windows 11 with WSL2 | Ubuntu 24.04 LTS |
| **CPU** | 2 cores | 4+ cores |
| **RAM** | 2 GB | 4+ GB |
| **Storage** | 10 GB + music library size | SSD, 50+ GB |
| **Software** | Docker Engine 24+, Docker Compose v2 | Docker Engine 27+, Docker Compose v2.29+ |
| **Network** | Open port 80 (HTTP) | Static IP or Cloudflare Tunnel |

### Desktop App Build

| Requirement | Version |
|-------------|---------|
| **Node.js** | 18+ |
| **npm** | 9+ |

### Android App Build

| Requirement | Version |
|-------------|---------|
| **JDK** | OpenJDK 17 or 21 |
| **Android SDK** | Platform 35 (Android 15), Build-Tools 34+ |
| **Gradle** | 8.10+ (included via wrapper) |
| **Disk** | ~2 GB for SDK + Gradle cache |

### Client Device Requirements

| Platform | Minimum |
|----------|---------|
| **Android** | Android 8.0 (Oreo, API 26) — ~95% of active devices |
| **Linux** | x64 or ARM64 with GTK 3 |
| **Windows** | Windows 10+ (x64) |
| **macOS** | macOS 13+ (x64 or ARM64) |
| **Web** | Any modern browser |

---

## Quick Start — Server

### Prerequisites
- Docker and Docker Compose
- Git

### Installation

```bash
# Clone the repository
git clone https://github.com/chardric/RendezVox.git
cd RendezVox

# Auto-generate secure passwords and create .env
cd docker
bash setup.sh

# Start all services
docker compose up -d

# Check logs
docker compose logs -f
```

### First Login

1. Open `http://localhost/admin/` in your browser
2. The **Setup Wizard** appears on first visit — create your admin account
3. Login and change your password if needed

### Adding Music

Place your music files (MP3, FLAC, OGG, WAV) in the `music/` directory, then use the **Media** page in the admin panel to browse and import them into the library.

---

## Deployment

Both deployment options use Docker for consistent, reproducible installations. Each includes a fully automated install script that checks for existing dependencies before installing.

### Standard Server (Docker)

For VPS, dedicated servers, or cloud instances running Ubuntu/Debian.

#### Requirements

| Requirement | Minimum | Recommended |
|-------------|---------|-------------|
| **OS** | Ubuntu 22.04 / Debian 12 | Ubuntu 24.04 LTS |
| **CPU** | 2 cores | 4+ cores |
| **RAM** | 2 GB | 4+ GB |
| **Storage** | 10 GB + music library | SSD, 50+ GB |
| **Network** | Port 80 open | Static IP or Cloudflare Tunnel |

#### Automated Installation

```bash
git clone https://github.com/chardric/RendezVox.git
cd RendezVox
chmod +x deploy/server/install.sh
sudo ./deploy/server/install.sh
```

The installer will:
1. Check and install prerequisites (curl, git, openssl, etc.) — skips already-installed packages
2. Install Docker Engine — skips if already installed
3. Install Docker Compose plugin — skips if already installed
4. Apply system optimizations (swappiness, file watchers, max connections)
5. Generate secure random passwords for all services
6. Create `.env` configuration — preserves existing if re-running
7. Build and start all Docker containers
8. Create the initial admin user with a secure password

Credentials are saved to `.credentials` in the project root. View them with:
```bash
sudo cat /path/to/RendezVox/.credentials
```

#### Management Commands

```bash
cd /path/to/RendezVox/docker

# Start all services
docker compose up -d

# Stop all services
docker compose down

# View logs
docker compose logs -f

# Restart services
docker compose restart
```

---

### Raspberry Pi (Docker)

For Raspberry Pi 3B and later running Raspberry Pi OS 64-bit (Bookworm or later).

#### Requirements

| Requirement | RPi 3B (Minimum) | RPi 4/5 (Recommended) |
|-------------|-------------------|------------------------|
| **Board** | Raspberry Pi 3B | Raspberry Pi 4 or 5 |
| **RAM** | 1 GB | 4+ GB |
| **Storage** | 16 GB microSD + music | USB SSD, 64+ GB |
| **OS** | RPi OS 64-bit (Bookworm) | RPi OS 64-bit (Bookworm) |
| **Network** | Ethernet or WiFi | Ethernet recommended |

#### Listener Capacity

| Board | Concurrent Listeners |
|-------|---------------------|
| RPi 3B (1 GB) | ~10–15 |
| RPi 4 (4 GB) | ~30–50 |
| RPi 5 (8 GB) | ~100+ |

#### Automated Installation

```bash
git clone https://github.com/chardric/RendezVox.git
cd RendezVox
chmod +x deploy/rpi/install.sh
sudo ./deploy/rpi/install.sh
```

The installer will:
1. Check and install prerequisites — skips already-installed packages
2. Install Docker Engine — skips if already installed
3. Install Docker Compose plugin — skips if already installed
4. Configure 2 GB swap for low-RAM boards — skips if swap is already adequate
5. Apply I/O and memory optimizations — skips if already applied
6. Generate secure random passwords for all services
7. Create `.env` configuration — preserves existing if re-running
8. Build and start Docker containers with RPi-optimized resource limits (may take 10–20 min on RPi 3B)
9. Create the initial admin user with a secure password

The RPi deployment uses a Docker Compose override (`deploy/rpi/docker-compose.override.yml`) that:
- Limits container memory (PostgreSQL 256 MB, PHP 256 MB, Nginx 64 MB, Icecast 64 MB, Liquidsoap 384 MB)
- Tunes PostgreSQL for low RAM (shared_buffers=64MB, max_connections=30)
- Reduces PHP-FPM workers for limited CPU
- Caps Icecast to 30 max clients

#### Management Commands

```bash
cd /path/to/RendezVox/docker

# Start (with RPi override)
docker compose -f docker-compose.yml -f ../deploy/rpi/docker-compose.override.yml up -d

# Stop
docker compose -f docker-compose.yml -f ../deploy/rpi/docker-compose.override.yml down

# View logs
docker compose logs -f

# Restart
docker compose -f docker-compose.yml -f ../deploy/rpi/docker-compose.override.yml restart
```

#### Tips
- Use a USB SSD instead of a microSD card for significantly better performance
- Keep the music library on an external USB drive if storage is limited
- The first build takes the longest — subsequent starts are fast

---

## Desktop App

The desktop app is an Electron application located in `builds/desktop/`. Pre-built installers are available on the [Releases](https://github.com/chardric/rendezvox/releases) page.

### Installing

| Platform | Install |
|----------|---------|
| **Ubuntu/Debian** | `sudo dpkg -i rendezvox_1.0.0_amd64.deb` |
| **RPi/ARM64** | `sudo dpkg -i rendezvox_1.0.0_arm64.deb` |
| **Linux (any)** | `chmod +x RendezVox-1.0.0.AppImage && ./RendezVox-1.0.0.AppImage` |
| **Windows** | Run `RendezVox Setup 1.0.0.exe` |
| **macOS** | Open `RendezVox-1.0.0.dmg` and drag to Applications |

### Building from Source

```bash
cd builds/desktop
npm install

# Build for your platform
npm run build:linux    # DEB + AppImage (x64 + ARM64)
npm run build:win      # Windows NSIS
npm run build:mac      # macOS DMG
npm run build:all      # All platforms
```

### Features
- Vinyl turntable player — click to play/stop, tonearm shows song progress
- System tray with minimize-to-tray and autostart on login
- Media key support (play/pause, stop)
- Single-instance lock — launching again focuses existing window
- Song request with fuzzy search and suggestions
- Volume control, listener count, dedication display
- Recently played history
- Server-synced accent color and time-of-day ambient overlay

---

## Mobile App

### Android App

The Android app is a native Kotlin application using Jetpack Compose and Media3 ExoPlayer, located in `builds/android/`. Pre-built APKs are available on the [Releases](https://github.com/chardric/rendezvox/releases) page.

#### Building the APK

```bash
cd builds/android

# Build debug APK (for testing)
./gradlew assembleDebug

# Build signed release APK
./gradlew assembleRelease
# Output: app/build/outputs/apk/release/RendezVox-1.0.0.apk
```

Transfer the APK to your Android device and install it (enable "Install from unknown sources" in settings).

#### Usage

1. Open the app — you'll see the **Connect to Station** screen
2. Enter your RendezVox server address:
   - **Internet:** `radio.example.com` (auto-prepends `https://`)
   - **LAN:** `http://192.168.1.100` (type the `http://` prefix for local servers)
3. Tap **Connect** — the player loads station info and starts streaming
4. Tap the vinyl turntable to play/stop
5. Audio plays in the background with notification controls
6. Use the **Request a Song** button to submit listener requests

#### Features
- Vinyl turntable player — tap to play/stop, tonearm shows song progress
- Background audio with media notification (play/pause/stop from notification bar or lock screen)
- Real-time now-playing updates via SSE
- Equalizer visualizer with accent color theming
- Song request with autocomplete search
- Volume control, listener count, up-next display
- Recently played history
- Offline detection with auto-retry and visual banner

---

## Project Structure

```
RendezVox/
├── docker/                      # Docker configuration
│   ├── docker-compose.yml       # Service orchestration (5 services)
│   ├── .env.example             # Environment template
│   ├── nginx/                   # Nginx config (CF IP forwarding)
│   ├── php/                     # PHP-FPM Dockerfile & config
│   ├── icecast/                 # Icecast Dockerfile & config
│   └── liquidsoap/              # Liquidsoap Dockerfile
├── deploy/                      # Deployment scripts
│   ├── server/                  # Standard server (Docker)
│   │   └── install.sh           # Automated installer
│   └── rpi/                     # Raspberry Pi (Docker)
│       ├── install.sh           # Automated installer
│       ├── docker-compose.override.yml  # RPi resource limits
│       └── fpm-pool.conf        # Reduced PHP-FPM workers
├── docs/
│   └── schema.sql               # PostgreSQL schema (auto-runs on first start)
├── src/
│   ├── core/                    # Core PHP classes
│   │   ├── Auth.php             # JWT authentication
│   │   ├── Database.php         # PDO database singleton
│   │   ├── Request.php          # Client IP detection (CF/XFF/XRI)
│   │   ├── Response.php         # JSON response helper
│   │   ├── Router.php           # URL router
│   │   ├── RotationEngine.php   # Smart track selection with separation
│   │   ├── SongResolver.php     # Fuzzy song matching
│   │   ├── MetadataExtractor.php # Audio file metadata
│   │   ├── MetadataLookup.php   # AI genre tagging (Gemini/Ollama)
│   │   └── ArtistNormalizer.php # Artist name cleanup
│   ├── api/
│   │   ├── index.php            # Route definitions
│   │   └── handlers/            # 110+ API endpoint handlers
│   ├── cli/                     # CLI scripts (cron jobs)
│   └── scripts/                 # Background processing scripts
├── public/
│   ├── index.php                # Front controller
│   ├── index.html               # Public listener page (web)
│   └── admin/                   # Admin panel
│       ├── css/admin.css        # Stylesheet (~2000 lines)
│       ├── js/                  # Page-specific JavaScript
│       │   ├── utils.js         # Shared utilities (escHtml, toast, formatters)
│       │   ├── shuffle.js       # Shared shuffle & separation logic
│       │   └── ...              # Page-specific modules
│       └── *.html               # Admin pages
├── liquidsoap/
│   └── radio.liq                # Liquidsoap radio script
├── builds/
│   ├── desktop/                 # Desktop app (Electron)
│   │   ├── main.js              # Electron main process
│   │   ├── preload.js           # Context bridge
│   │   ├── package.json         # Build config
│   │   └── src/                 # Renderer (HTML/CSS/JS)
│   │       ├── index.html
│   │       ├── renderer.js
│   │       └── style.css
│   └── android/                 # Android app (Kotlin + Compose)
│       ├── build.gradle.kts     # Root build config
│       ├── settings.gradle.kts  # Gradle settings
│       ├── gradlew              # Gradle wrapper
│       └── app/
│           ├── build.gradle.kts # App module config
│           └── src/main/
│               ├── AndroidManifest.xml
│               └── java/net/downstreamtech/rendezvox/
│                   ├── RadioApp.kt
│                   ├── MainActivity.kt
│                   ├── data/Models.kt
│                   ├── data/RadioApi.kt
│                   ├── service/PlaybackService.kt
│                   └── ui/
│                       ├── theme/Theme.kt
│                       ├── PlayerScreen.kt
│                       ├── PlayerViewModel.kt
│                       ├── RequestDialog.kt
│                       └── SetupScreen.kt
├── installers/                  # Pre-built installers
│   ├── desktop/
│   │   ├── linux/               # DEB + AppImage (x64 + ARM64)
│   │   └── windows/             # NSIS installer
│   └── mobile/
│       └── release/             # Signed APK
├── music/                       # Music library (not tracked)
├── stationids/                  # Station ID files
└── README.md                    # This file
```

---

## API Overview

The backend exposes 110+ RESTful JSON endpoints used by the web interface, desktop, and mobile apps:

| Group | Endpoints | Auth |
|-------|----------|------|
| **Public** | `GET /now-playing`, `GET /sse/now-playing`, `GET /search-song`, `POST /request`, `GET /config`, `GET /recent-plays` | No |
| **Auth** | `POST /admin/login`, `GET /admin/me` | JWT |
| **Media** | Songs CRUD, artists, categories, media browser, import | JWT |
| **Playlists** | CRUD, song management, reorder, shuffle | JWT |
| **Schedules** | CRUD, reload | JWT |
| **Requests** | List, approve, reject | JWT |
| **Streaming** | Stream control, skip track, emergency toggle | JWT |
| **Users** | CRUD, password change, avatar upload, profile update | JWT |
| **Settings** | List, update per-key | JWT |
| **Analytics** | Listener stats, popular songs, popular requests | JWT |
| **Background** | Genre scan, library sync, artist dedup, normalize | JWT |

---

## Database Schema

PostgreSQL 16 with 18 tables:

- `users` — Admin accounts with roles, avatars, display name, login IP tracking
- `artists` — Normalized artist names with trigram search
- `categories` — Music/station_id/sweeper/liner/emergency types
- `songs` — Full metadata, rotation weights, loudness data
- `playlists` — Manual, auto, emergency with JSONB rules
- `playlist_songs` — Ordered songs with cycle tracking
- `schedules` — Time-based playlist scheduling
- `play_history` — Complete playback log
- `rotation_state` — Resume-safe playback state (singleton)
- `song_requests` — Listener requests with rate limiting
- `request_queue` — Ordered playback queue for approved requests
- `listener_stats` — Periodic listener count snapshots
- `station_logs` — Application event log
- `settings` — Key-value station configuration
- `banned_ips` — IP ban list with optional expiry
- `password_reset_tokens` — Password reset tokens with expiry
- `organizer_queue` — Media organizer job queue
- `organizer_hashes` — Audio fingerprint dedup hashes

---

## Cloudflare Zero Trust Support

RendezVox is designed to run behind Cloudflare Zero Trust tunnels. Key integrations:

- **Real client IP:** Nginx uses a `map` directive on `CF-Connecting-IP` to resolve the true client IP into `REMOTE_ADDR`. PHP reads `REMOTE_ADDR` only — `X-Forwarded-For` and `X-Real-IP` are never trusted (prevents IP spoofing).
- **Stream proxy:** The audio stream is proxied through Nginx at `/stream/live` (port 80), so it works through Cloudflare tunnels without exposing Icecast port 8000. No extra ports needed.
- **Single port:** Only port 80 (HTTP) needs to be exposed. Cloudflare handles HTTPS termination.

When not behind Cloudflare (local/LAN), everything works the same — `REMOTE_ADDR` falls back to the TCP peer address.

---

## Security

### IP Spoofing Prevention
PHP `Request::clientIp()` reads only `REMOTE_ADDR` (set by Nginx from the resolved real IP). Attacker-controllable headers (`X-Forwarded-For`, `X-Real-IP`) are ignored.

### Internal Endpoint Protection
Liquidsoap-facing API endpoints (`/next-track`, `/track-started`, `/track-played`) are protected by a shared secret (`RENDEZVOX_INTERNAL_SECRET`). Liquidsoap sends it as `X-Internal-Secret` header; the Router validates it. Without a valid secret, these endpoints return 403. In dev mode (no secret configured), access is unrestricted.

### Path Traversal Protection
Avatar file serving validates the path with `basename()` check, null byte rejection, and `realpath()` verification against the avatars directory.

### Input Validation
- Song request fields: title/artist max 255 chars, listener name max 100, message max 500
- Song search: title/artist max 255 chars
- Internal-only settings (e.g., `emergency_auto_activated`) are blacklisted from admin updates

### Rate Limiting
Nginx rate limits on sensitive endpoints:
- Login: 5 req/min per IP
- Forgot password: 5 req/min per IP
- Setup: 5 req/min per IP
- Song search: 5 req/sec per IP
- Public API: 10 req/sec per IP
- PHP-level APCu rate limiting as additional layer

### Information Disclosure Prevention
- SMTP debug logs removed from test-email API responses (logged server-side only)
- Nginx hides server version and PHP headers
- External HTTP calls (weather, geocoding) have 5-second timeouts to prevent hanging

---

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `POSTGRES_DB` | `rendezvox` | Database name |
| `POSTGRES_USER` | `rendezvox` | Database user |
| `POSTGRES_PASSWORD` | *(required)* | Database password |
| `ICECAST_SOURCE_PASSWORD` | *(required)* | Liquidsoap → Icecast auth |
| `ICECAST_ADMIN_PASSWORD` | *(required)* | Icecast admin panel |
| `ICECAST_HOSTNAME` | `localhost` | Icecast public hostname |
| `ICECAST_MAX_CLIENTS` | `100` | Max concurrent listeners |
| `RENDEZVOX_APP_ENV` | `production` | App environment |
| `RENDEZVOX_APP_DEBUG` | `false` | Show PHP errors |
| `RENDEZVOX_JWT_SECRET` | *(auto-derived)* | JWT signing key (auto-derived from DB password if empty) |
| `RENDEZVOX_INTERNAL_SECRET` | *(auto-generated)* | Shared secret for Liquidsoap → API internal endpoints |
| `TZ` | `UTC` | Station timezone |
| `RENDEZVOX_HTTP_PORT` | `80` | Host HTTP port |
| `RENDEZVOX_DEFAULT_CATEGORY_ID` | `48` | Default genre category for imported songs |
| `RENDEZVOX_ICECAST_PUBLIC_PORT` | `8000` | Host Icecast port (internal, proxied via Nginx at `/stream/live`) |

---

## Changelog

### v1.0.2 — 2026-03-13

**AI & Metadata**
- Upgraded Gemini AI model from 2.0 Flash to 2.5 Flash (500 RPM / 25K req/day free tier)
- Disabled Gemini thinking tokens for genre classification (fixes empty responses)
- Added `RENDEZVOX_DEFAULT_CATEGORY_ID` env var to prevent genre misclassification on import

**Shuffle & Rotation**
- Multi-pass artist separation algorithm (gap 6, 3 cascading passes) in both PHP and JS
- Title separation — same base title blocked within gap of 3 positions
- `baseTitle()` normalizer strips rendition suffixes (Remix, Acoustic, Live, feat., etc.)
- Consolidated shuffle logic into shared `shuffle.js` (client) and `RotationEngine.php` (server)
- Cross-playlist dedup: same song blocked for 24 hours across all playlists
- Cross-playlist title dedup: same base title (and variations) blocked for 24 hours
- Requested songs bypass dedup checks (listeners always get what they asked for)

**Streaming**
- Fixed songs cutting off mid-playback during quiet passages (blank.skip too aggressive)
- Empty playlists excluded from schedule resolution (no more stuck-on-empty errors)

**Admin UI**
- New button hierarchy: `btn-primary` > `btn-outline` > `btn-outline-danger` > `btn-danger` > `btn-ghost`
- Fixed grayed-out/disabled-looking buttons across all admin pages
- Consolidated shared JS utilities into `utils.js` — removed duplicates from 12 files
- Playlist song search — filter songs by title, artist, or genre within a playlist
- Empty playlists hidden from schedule palette

**Infrastructure**
- Fixed Alpine edge package conflict (libplacebo/libglslang) in PHP Dockerfile
- Daily play_history pruning (90 days retention) to prevent unbounded DB growth
- Reduced admin JS codebase by ~140 lines through deduplication

### v1.0.0 — 2026-02-24

**Radio Automation**
- Liquidsoap audio engine with API-driven track selection and crossfade
- Icecast 2 streaming server proxied through Nginx at `/stream/live`
- Smart rotation engine with artist/title separation and full cycle tracking
- Station ID/sweeper/liner insertion at configurable intervals
- Emergency mode with dedicated fallback playlist
- EBU R128 loudness normalization

**Admin Panel**
- Modern dark-themed responsive UI with collapsible sidebar
- Dashboard with DJ booth monitor, live progress bar, stream controls
- Real-time SSE (Server-Sent Events) for now-playing updates
- Music library management with drag-and-drop media browser
- Drag-and-drop file and folder uploads with auto-detection
- Parallel file uploads (4 concurrent) for faster bulk importing
- Folder upload preserves directory structure for playlist creation
- Automatic metadata extraction, audio fingerprinting, genre tagging
- Full tagging pipeline: AcoustID fingerprint → MusicBrainz → TheAudioDB fallback
- Tag clearing and rewriting with fresh metadata from external sources
- Cover art preservation through the tag rewrite cycle
- Duplicate detection moves files to `_duplicates/` instead of deleting
- Unidentified files moved to `_untagged/` with embedded metadata preserved
- Playlist system (manual, auto-generated, emergency) with JSONB rules
- Batch playlist import from filesystem folders
- Visual calendar schedule builder with priority-based overlap resolution
- Song request moderation queue with auto-approve option
- Role-based access control (Super Admin, Admin, Editor, Viewer)
- User management with avatar upload and login IP tracking
- Analytics: listener stats, popular songs, popular requests
- Station settings panel with weather widget integration
- Disk space monitoring with low-space warnings

**Public Listener Page**
- Vinyl turntable player with animated tonearm progress indicator
- Blurred cover art background, EQ visualizer, time-of-day ambient overlay
- Real-time now-playing and up-next display via SSE
- Song request modal with fuzzy search and suggestions
- Listener count with live pulse badge, dedication display, auto-reconnect
- Recently played history, marquee for long titles, song change animation
- PWA support (installable, service worker)

**Desktop App**
- Electron app for Linux (DEB + AppImage, x64 + ARM64), Windows (NSIS), macOS (DMG)
- Vinyl turntable player matching web UI/UX
- System tray with minimize-to-tray and autostart on login
- Media key bindings, single-instance lock, page visibility API
- Song request, volume control, listener count, dedication display

**Mobile App**
- Native Android app (Kotlin + Jetpack Compose + Media3 ExoPlayer)
- Vinyl turntable player matching web UI/UX
- Background audio with notification / lock screen controls
- Real-time SSE now-playing with polling fallback
- Song request with autocomplete search
- Server selection screen with connection validation
- Offline detection with auto-retry

**Security**
- IP spoofing prevention: `REMOTE_ADDR` only (attacker headers ignored)
- Internal endpoint protection via shared secret (`RENDEZVOX_INTERNAL_SECRET`)
- Path traversal protection on avatar serving and folder uploads
- Input length limits on all public-facing endpoints
- Rate limiting on login, setup, search, and public API endpoints
- SMTP log scrubbed from API responses
- Settings key blacklist for internal-only values
- External HTTP call timeouts (5s) to prevent hanging

**Deployment**
- Docker Compose orchestration (5 services: Nginx, PHP-FPM, PostgreSQL, Icecast, Liquidsoap)
- Automated installer for standard servers (`deploy/server/install.sh`)
- Automated installer for Raspberry Pi (`deploy/rpi/install.sh`)
- RPi resource limits (memory caps, reduced workers, tuned PostgreSQL)
- Auto-generated secure passwords and credential file
- Cloudflare Zero Trust tunnel support (single port 80, stream proxied)

---

## Roadmap

### Planned Features
- **iOS App** — Native Swift/SwiftUI listener app with background audio and AirPlay support
- **Live DJ Mode** — Real-time microphone input mixing with Liquidsoap for live broadcasts
- **Multi-Station Support** — Manage multiple radio stations from a single admin panel
- **Podcast Integration** — Schedule and stream podcast episodes alongside music rotation
- **Advanced Analytics** — Listening trends, peak hours, geographic listener distribution
- **API Webhooks** — Notify external services on song changes, requests, and station events
- **Theme Customization** — Admin-configurable listener page themes (colors, layout, branding)
- **Playlist Sharing** — Public shareable playlist links for listeners
- **Song Voting** — Listeners upvote/downvote songs to influence rotation weights
- **Scheduled Announcements** — Text-to-speech or pre-recorded announcements at scheduled times
- **Remote DJ Panel** — Web-based DJ interface for remote hosts to manage live shows

### Infrastructure
- **Kubernetes Helm Chart** — Production-grade deployment with horizontal scaling
- **S3/MinIO Storage** — Cloud-compatible music library storage backend
- **Prometheus + Grafana** — Observability stack for monitoring stream health and performance
- **WebSocket Support** — Lower-latency alternative to SSE for real-time updates

---

© 2024–2026 [DownStreamTech](https://downstreamtech.net) — Licensed under the [Apache License 2.0](LICENSE)
Developed by **Engr. Richard R. Ayuyang, PhD** — Professor II, CSU | [chadlinuxtech.net](https://chadlinuxtech.net)
