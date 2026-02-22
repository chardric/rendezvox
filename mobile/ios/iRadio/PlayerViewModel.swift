import Foundation
import Combine

@MainActor
class PlayerViewModel: ObservableObject {
    @Published var state = PlayerState()
    @Published var audioManager = AudioManager()

    private var api: RadioAPI?
    private var sseClient: SSEClient?
    private var pollingTimer: Timer?
    private var listenerTimer: Timer?
    private var progressTimer: Timer?

    private let defaults = UserDefaults.standard
    private let serverURLKey = "iradio_server_url"

    init() {
        if let saved = defaults.string(forKey: serverURLKey), !saved.isEmpty {
            state.serverUrl = saved
            connectToServer(saved)
        }
    }

    func setServerURL(_ url: String) {
        let cleaned = url.trimmingCharacters(in: .whitespacesAndNewlines)
            .trimmingCharacters(in: CharacterSet(charactersIn: "/"))
        defaults.set(cleaned, forKey: serverURLKey)
        state.serverUrl = cleaned
        connectToServer(cleaned)
    }

    private func connectToServer(_ url: String) {
        // Cleanup existing connections
        sseClient?.disconnect()
        pollingTimer?.invalidate()
        listenerTimer?.invalidate()

        api = RadioAPI(baseURL: url)

        Task {
            // Fetch station config
            if let config = await api?.fetchConfig() {
                state.stationName = config.station_name ?? "iRadio"
                state.tagline = config.tagline ?? "Online Radio"
            }

            // Set stream URL
            if let streamURL = await api?.streamURL {
                audioManager.setStreamURL(streamURL)
                audioManager.stationName = state.stationName
            }

            // Initial now-playing fetch
            await fetchNowPlaying()
        }

        // Start polling
        startPolling()
        startListenerPolling()
        startSSE(url)
    }

    func togglePlayback() {
        audioManager.toggle()
    }

    func setVolume(_ volume: Float) {
        state.volume = volume
        audioManager.setVolume(volume)
    }

    // MARK: - Now Playing

    private func startPolling() {
        pollingTimer = Timer.scheduledTimer(withTimeInterval: 30, repeats: true) { [weak self] _ in
            Task { @MainActor [weak self] in
                await self?.fetchNowPlaying()
            }
        }
    }

    private func startListenerPolling() {
        listenerTimer = Timer.scheduledTimer(withTimeInterval: 15, repeats: true) { [weak self] _ in
            Task { @MainActor [weak self] in
                guard let self = self else { return }
                let count = await self.api?.fetchListenerCount() ?? 0
                self.state.listenerCount = count
            }
        }
        // Initial fetch
        Task {
            state.listenerCount = await api?.fetchListenerCount() ?? 0
        }
    }

    private func startSSE(_ url: String) {
        guard let sseURL = URL(string: "\(url)/api/sse/now-playing") else { return }
        sseClient = SSEClient(url: sseURL)
        sseClient?.connect { [weak self] data in
            Task { @MainActor [weak self] in
                self?.handleNowPlayingData(data)
            }
        }
    }

    private func fetchNowPlaying() async {
        guard let data = await api?.fetchNowPlaying() else { return }
        handleNowPlayingData(data)
    }

    private func handleNowPlayingData(_ data: NowPlayingData) {
        if let song = data.song {
            state.songTitle = song.title
            state.songArtist = song.artist
            state.durationMs = song.duration_ms ?? 0

            if let startedAt = data.started_at {
                state.startedAtMs = parseISO8601(startedAt)
            }

            audioManager.updateMetadata(
                title: song.title,
                artist: song.artist,
                stationName: state.stationName
            )
        }

        if let next = data.next_track {
            state.nextTitle = next.title
            state.nextArtist = next.artist
        } else {
            state.nextTitle = "\u{2014}"
            state.nextArtist = ""
        }

        state.isEmergency = data.is_emergency ?? false
        state.dedicationName = data.request?.listener_name
        state.dedicationMessage = data.request?.message
    }

    private func parseISO8601(_ str: String) -> TimeInterval {
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        if let date = formatter.date(from: str) {
            return date.timeIntervalSince1970 * 1000
        }
        formatter.formatOptions = [.withInternetDateTime]
        if let date = formatter.date(from: str) {
            return date.timeIntervalSince1970 * 1000
        }

        let df = DateFormatter()
        df.dateFormat = "yyyy-MM-dd HH:mm:ss"
        df.timeZone = TimeZone(identifier: "UTC")
        if let date = df.date(from: str) {
            return date.timeIntervalSince1970 * 1000
        }
        return 0
    }

    // MARK: - Song Requests

    func searchSongs(title: String, artist: String?) async -> SearchResult {
        return await api?.searchSong(title: title, artist: artist)
            ?? SearchResult(songs: nil, resolved: nil)
    }

    func submitRequest(body: SongRequestBody) async -> (Int, RequestResponse) {
        return await api?.submitRequest(body: body)
            ?? (0, RequestResponse(song: nil, error: "Not connected", suggestions: nil))
    }

    deinit {
        sseClient?.disconnect()
        pollingTimer?.invalidate()
        listenerTimer?.invalidate()
    }
}
