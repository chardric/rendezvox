# iRadio

**Online FM Radio Automation System**

iRadio is a self-hosted, fully automated internet radio station built with PHP, Liquidsoap, Icecast, and PostgreSQL. It provides a complete solution for managing music libraries, scheduling playlists, streaming live audio, and accepting listener song requests — all from a modern dark-themed admin panel. Includes native Android and iOS mobile apps for listeners.

---

## Table of Contents

- [Features](#features)
- [Architecture](#architecture)
- [Tech Stack](#tech-stack)
- [System Requirements](#system-requirements)
- [Quick Start — Server](#quick-start--server)
- [Deployment](#deployment)
  - [Standard Server (Docker)](#standard-server-docker)
  - [Raspberry Pi (Docker)](#raspberry-pi-docker)
- [Mobile Apps](#mobile-apps)
  - [Android](#android-app)
  - [iOS](#ios-app)
- [Project Structure](#project-structure)
- [API Overview](#api-overview)
- [Database Schema](#database-schema)
- [Cloudflare Zero Trust Support](#cloudflare-zero-trust-support)
- [Environment Variables](#environment-variables)
- [Changelog](#changelog)
- [License](#license)

---

## Features

### Audio Streaming & Automation
- **Liquidsoap** audio processing engine with API-driven track selection
- **Icecast 2** streaming server with configurable mount points
- Smart rotation engine with artist/song repeat blocking
- Crossfade support with configurable duration
- Jingle/sweeper/liner insertion at configurable intervals
- Emergency mode with dedicated fallback playlist
- EBU R128 loudness normalization

### Music Library Management
- Drag-and-drop media file browser with folder navigation
- Automatic metadata extraction (title, artist, album, year, duration)
- Audio fingerprinting via AcoustID for song identification
- Automatic genre tagging from online databases
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
- Playlist shuffle and drag-to-reorder

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
- Station settings panel (jingle interval, repeat blocks, request limits, etc.)
- Weather widget integration

### Public Listener Page (Web)
- Clean single-page player with album art placeholder
- Play/stop, volume controls
- Real-time now-playing and up-next display (SSE)
- Progress bar with elapsed/remaining time
- Listener count display
- Song request modal with fuzzy search and suggestions
- Dedication/request-by display for current song
- Auto-reconnect on stream interruption
- PWA support (installable, service worker)

### Mobile Apps (Android & iOS)
- Native apps matching the web listener experience
- Background audio playback with media notification controls
- Real-time now-playing via SSE with polling fallback
- Animated vinyl disc player visualization
- Song request with autocomplete search
- Progress bar, volume slider, listener count
- Dedication card display
- Configurable server URL (LAN or public)
- Dark theme matching the web UI

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
  ┌────▼─────────────────────────────────────────┐
  │  Browser (Admin Panel / Listener Page)       │
  │  Android App (Kotlin/Compose + Media3)       │
  │  iOS App (SwiftUI + AVPlayer)                │
  └──────────────────────────────────────────────┘

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

### Server
- **Backend:** PHP 8.3 (no framework, custom router), PostgreSQL 16
- **Frontend:** Vanilla JavaScript, CSS3 (no build tools, no dependencies)
- **Audio:** Liquidsoap 2.x, Icecast 2
- **Infrastructure:** Docker Compose, Nginx
- **Authentication:** JWT tokens, bcrypt password hashing
- **Real-time:** Server-Sent Events (SSE)

### Android App
- **Language:** Kotlin 2.0
- **UI:** Jetpack Compose with Material Design 3
- **Audio:** Media3 ExoPlayer with MediaSessionService
- **Networking:** OkHttp 4 + Gson
- **Min SDK:** Android 8.0 (API 26)

### iOS App
- **Language:** Swift 5.9
- **UI:** SwiftUI
- **Audio:** AVPlayer with MPNowPlayingInfoCenter
- **Networking:** URLSession (native)
- **Min OS:** iOS 16.0

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

### Android App Build

| Requirement | Version |
|-------------|---------|
| **JDK** | OpenJDK 17 or 21 |
| **Android SDK** | Platform 35 (Android 15), Build-Tools 34+ |
| **Gradle** | 8.10+ (included via wrapper) |
| **Disk** | ~2 GB for SDK + Gradle cache |

### iOS App Build

| Requirement | Version |
|-------------|---------|
| **macOS** | macOS 14 Sonoma or later |
| **Xcode** | 15.0 or later |
| **XcodeGen** | 2.38+ (optional, for project generation) |
| **CocoaPods/SPM** | Not required (no external dependencies) |

### Mobile Device Requirements

| Platform | Minimum |
|----------|---------|
| **Android** | Android 8.0 (Oreo, API 26) — ~95% of active devices |
| **iOS** | iOS 16.0 — iPhone 8 and later |
| **Network** | WiFi or cellular access to the iRadio server |

---

## Quick Start — Server

### Prerequisites
- Docker and Docker Compose
- Git

### Installation

```bash
# Clone the repository
git clone https://github.com/chardric/iRadio.git
cd iRadio

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
git clone https://github.com/chardric/iRadio.git
cd iRadio
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
sudo cat /path/to/iRadio/.credentials
```

#### Management Commands

```bash
cd /path/to/iRadio/docker

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
git clone https://github.com/chardric/iRadio.git
cd iRadio
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
cd /path/to/iRadio/docker

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

## Mobile Apps

### Android App

The Android app is a native Kotlin application using Jetpack Compose and Media3 ExoPlayer, located in `mobile/android/`.

#### Building the APK

```bash
cd mobile/android

# Build debug APK (for testing)
./gradlew assembleDebug

# APK output location:
# app/build/outputs/apk/debug/app-debug.apk
```

Transfer the APK to your Android device and install it (enable "Install from unknown sources" in settings).

#### Building a Release APK

A signing keystore is included in the build config. To build:

```bash
./gradlew assembleRelease
# Output: app/build/outputs/apk/release/iRadio-1.0.0.apk
```

#### Usage

1. Open the app — you'll see the **Connect to Station** screen
2. Enter your iRadio server address:
   - **Internet:** `radio.example.com` (auto-prepends `https://`)
   - **LAN:** `http://192.168.1.100` (type `http://` prefix for local servers)
3. Tap **Connect** — the player loads station info and starts streaming
4. Audio plays in the background with notification controls
5. Use the **Request a Song** button to submit listener requests
6. Access **Settings** (gear icon) to change the server URL

#### Features
- Background audio with media notification (play/pause/stop from notification bar or lock screen)
- Real-time now-playing updates via SSE
- Animated vinyl disc visualization
- Song request with autocomplete search
- Progress bar with elapsed/remaining time
- Volume control, listener count, up-next display

---

### iOS App

The iOS app is a native SwiftUI application using AVPlayer, located in `mobile/ios/`.

#### Prerequisites
- macOS 14+ with Xcode 15+
- [XcodeGen](https://github.com/yonaskolb/XcodeGen) (recommended for project generation)

#### Building with XcodeGen

```bash
cd mobile/ios

# Install XcodeGen (if not installed)
brew install xcodegen

# Generate Xcode project
xcodegen generate

# Open in Xcode
open iRadio.xcodeproj
```

Then build and run in Xcode (Cmd+R) targeting a simulator or physical device.

#### Building without XcodeGen

1. Open Xcode and create a new iOS App project named **iRadio**
2. Set bundle identifier to `net.downstreamtech.iradio`
3. Replace the generated source files with the files from `mobile/ios/iRadio/`
4. Enable **Audio, AirPlay, and Picture in Picture** in Background Modes
5. Set **App Transport Security** to allow arbitrary loads (for HTTP/LAN)
6. Build and run

#### Usage

Same flow as Android — enter server URL on first launch, then enjoy the player with background audio and lock screen controls.

---

## Project Structure

```
iRadio/
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
│   │   ├── RotationEngine.php   # Smart track selection
│   │   ├── SongResolver.php     # Fuzzy song matching
│   │   ├── MetadataExtractor.php # Audio file metadata
│   │   └── ArtistNormalizer.php # Artist name cleanup
│   ├── api/
│   │   ├── index.php            # Route definitions
│   │   └── handlers/            # 70+ API endpoint handlers
│   ├── cli/                     # CLI scripts (cron jobs)
│   └── scripts/                 # Background processing scripts
├── public/
│   ├── index.php                # Front controller
│   ├── index.html               # Public listener page (web)
│   └── admin/                   # Admin panel
│       ├── css/admin.css        # Stylesheet (~2000 lines)
│       ├── js/                  # Page-specific JavaScript
│       └── *.html               # Admin pages
├── liquidsoap/
│   └── radio.liq                # Liquidsoap radio script
├── mobile/
│   ├── android/                 # Android app (Kotlin + Compose)
│   │   ├── build.gradle.kts     # Root build config
│   │   ├── settings.gradle.kts  # Gradle settings
│   │   ├── gradlew              # Gradle wrapper
│   │   └── app/
│   │       ├── build.gradle.kts # App module config
│   │       └── src/main/
│   │           ├── AndroidManifest.xml
│   │           ├── java/net/downstreamtech/iradio/
│   │           │   ├── RadioApp.kt
│   │           │   ├── MainActivity.kt
│   │           │   ├── data/Models.kt
│   │           │   ├── data/RadioApi.kt
│   │           │   ├── service/PlaybackService.kt
│   │           │   └── ui/
│   │           │       ├── theme/Theme.kt
│   │           │       ├── PlayerScreen.kt
│   │           │       ├── PlayerViewModel.kt
│   │           │       ├── RequestDialog.kt
│   │           │       ├── SetupScreen.kt
│   │           │       └── SettingsScreen.kt
│   │           └── res/
│   └── ios/                     # iOS app (SwiftUI)
│       ├── project.yml          # XcodeGen project definition
│       └── iRadio/
│           ├── iRadioApp.swift
│           ├── ContentView.swift
│           ├── PlayerView.swift
│           ├── PlayerViewModel.swift
│           ├── RequestView.swift
│           ├── SetupView.swift
│           ├── SettingsView.swift
│           ├── Models.swift
│           ├── RadioAPI.swift
│           ├── AudioManager.swift
│           ├── Info.plist
│           └── Assets.xcassets/
├── music/                       # Music library (not tracked)
├── jingles/                     # Jingle files
└── README.md                    # This file
```

---

## API Overview

The backend exposes 70+ RESTful JSON endpoints used by both the web interface and mobile apps:

| Group | Endpoints | Auth |
|-------|----------|------|
| **Public** | `GET /now-playing`, `GET /sse/now-playing`, `GET /search-song`, `POST /request`, `GET /config` | No |
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

PostgreSQL 16 with 15 tables:

- `users` — Admin accounts with roles, avatars, display name, login IP tracking
- `artists` — Normalized artist names with trigram search
- `categories` — Music/jingle/sweeper/liner/emergency types
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

---

## Cloudflare Zero Trust Support

iRadio is designed to run behind Cloudflare Zero Trust tunnels. Key integrations:

- **Real client IP:** Nginx uses a `map` directive on `CF-Connecting-IP` to resolve the true client IP into `REMOTE_ADDR`. PHP reads `REMOTE_ADDR` only — `X-Forwarded-For` and `X-Real-IP` are never trusted (prevents IP spoofing).
- **Stream proxy:** The audio stream is proxied through Nginx at `/stream/live` (port 80), so it works through Cloudflare tunnels without exposing Icecast port 8000. No extra ports needed.
- **Single port:** Only port 80 (HTTP) needs to be exposed. Cloudflare handles HTTPS termination.

When not behind Cloudflare (local/LAN), everything works the same — `REMOTE_ADDR` falls back to the TCP peer address.

---

## Security

### IP Spoofing Prevention
PHP `Request::clientIp()` reads only `REMOTE_ADDR` (set by Nginx from the resolved real IP). Attacker-controllable headers (`X-Forwarded-For`, `X-Real-IP`) are ignored.

### Internal Endpoint Protection
Liquidsoap-facing API endpoints (`/next-track`, `/track-started`, `/track-played`) are protected by a shared secret (`IRADIO_INTERNAL_SECRET`). Liquidsoap sends it as `X-Internal-Secret` header; the Router validates it. Without a valid secret, these endpoints return 403. In dev mode (no secret configured), access is unrestricted.

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
| `POSTGRES_DB` | `iradio` | Database name |
| `POSTGRES_USER` | `iradio` | Database user |
| `POSTGRES_PASSWORD` | *(required)* | Database password |
| `ICECAST_SOURCE_PASSWORD` | *(required)* | Liquidsoap → Icecast auth |
| `ICECAST_ADMIN_PASSWORD` | *(required)* | Icecast admin panel |
| `ICECAST_HOSTNAME` | `localhost` | Icecast public hostname |
| `ICECAST_MAX_CLIENTS` | `100` | Max concurrent listeners |
| `IRADIO_APP_ENV` | `production` | App environment |
| `IRADIO_APP_DEBUG` | `false` | Show PHP errors |
| `IRADIO_JWT_SECRET` | *(auto-derived)* | JWT signing key (auto-derived from DB password if empty) |
| `IRADIO_INTERNAL_SECRET` | *(auto-generated)* | Shared secret for Liquidsoap → API internal endpoints |
| `TZ` | `UTC` | Station timezone |
| `IRADIO_HTTP_PORT` | `80` | Host HTTP port |
| `IRADIO_ICECAST_PUBLIC_PORT` | `8000` | Host Icecast port (internal, proxied via Nginx at `/stream/live`) |

---

## Changelog

### v1.0.0 — 2026-02-24

**Radio Automation**
- Liquidsoap audio engine with API-driven track selection and crossfade
- Icecast 2 streaming server proxied through Nginx at `/stream/live`
- Smart rotation engine with artist/song repeat blocking and full cycle tracking
- Jingle/sweeper/liner insertion at configurable intervals
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
- Single-page player with play/stop, volume, progress bar
- Real-time now-playing and up-next display via SSE
- Song request modal with fuzzy search and suggestions
- Listener count, dedication display, auto-reconnect
- PWA support (installable, service worker)

**Mobile Apps**
- Native Android app (Kotlin + Jetpack Compose + Media3 ExoPlayer)
- Native iOS app (SwiftUI + AVPlayer)
- Background audio with notification / lock screen controls
- Real-time SSE now-playing with polling fallback
- Song request with autocomplete search
- Smart server URL input (bare hostnames auto-prepend `https://`)

**Security**
- IP spoofing prevention: `REMOTE_ADDR` only (attacker headers ignored)
- Internal endpoint protection via shared secret (`IRADIO_INTERNAL_SECRET`)
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

## License

Copyright 2026 [DownStreamTech](https://downstreamtech.net). All rights reserved.
