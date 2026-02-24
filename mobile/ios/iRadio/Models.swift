import Foundation

let BASE_URL = "https://radio.chadlinuxtech.net"

// MARK: - API Response Models

struct StationConfig: Codable {
    let station_name: String?
    let tagline: String?
}

struct SongInfo: Codable {
    let id: Int?
    let title: String
    let artist: String
    let duration_ms: Int?
    let has_cover_art: Bool?
}

struct RequestInfo: Codable {
    let listener_name: String?
    let message: String?
}

struct NowPlayingData: Codable {
    let song: SongInfo?
    let started_at: String?
    let next_track: SongInfo?
    let request: RequestInfo?
    let is_emergency: Bool?
}

struct SearchSong: Codable, Identifiable {
    let id: Int
    let title: String
    let artist: String
}

struct SearchResult: Codable {
    let songs: [SearchSong]?
    let resolved: Bool?
}

struct SongRequestBody: Encodable {
    let title: String
    var artist: String?
    var listener_name: String?
    var message: String?
}

struct RequestResponse: Codable {
    let song: SearchSong?
    let error: String?
    let suggestions: [SearchSong]?
}

// MARK: - App State

struct PlayerState {
    var stationName: String = "iRadio"
    var tagline: String = "Online Radio"
    var songTitle: String = "\u{2014}"
    var songArtist: String = ""
    var songId: Int = 0
    var hasCoverArt: Bool = false
    var durationMs: Int = 0
    var startedAtMs: TimeInterval = 0
    var nextTitle: String = "\u{2014}"
    var nextArtist: String = ""
    var listenerCount: Int = 0
    var isEmergency: Bool = false
    var dedicationName: String?
    var dedicationMessage: String?
    var volume: Float = 0.8

    var coverArtUrl: String {
        guard hasCoverArt, songId > 0 else { return "" }
        return "\(BASE_URL)/api/cover?id=\(songId)"
    }
}
