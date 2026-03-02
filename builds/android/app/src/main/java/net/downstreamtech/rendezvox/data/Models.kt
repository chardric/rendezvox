package net.downstreamtech.rendezvox.data

data class StationConfig(
    val station_name: String = "RendezVox",
    val tagline: String = "Online Radio",
    val accent_color: String = "#ff7800"
)

data class SongInfo(
    val id: Int = 0,
    val title: String = "",
    val artist: String = "",
    val duration_ms: Long = 0,
    val has_cover_art: Boolean = false,
    val started_at: String? = null
)

data class RequestInfo(
    val listener_name: String? = null,
    val message: String? = null
)

data class NowPlayingData(
    val song: SongInfo? = null,
    val started_at: String? = null,
    val next_track: SongInfo? = null,
    val request: RequestInfo? = null,
    val is_emergency: Boolean = false
)

data class SearchSong(
    val id: Int = 0,
    val title: String = "",
    val artist: String = ""
)

data class SearchResult(
    val songs: List<SearchSong> = emptyList(),
    val resolved: Boolean = false
)

data class RequestBody(
    val title: String? = null,
    val artist: String? = null,
    val listener_name: String? = null,
    val message: String? = null
)

data class RequestResponse(
    val song: SearchSong? = null,
    val error: String? = null,
    val suggestions: List<SearchSong>? = null
)

data class RecentPlay(
    val title: String = "",
    val artist: String = "",
    val ended_at: String = ""
)

data class RecentPlaysResponse(
    val plays: List<RecentPlay> = emptyList()
)

data class VersionInfo(
    val version: String = "",
    val changelog: String = "",
    val downloads: Map<String, String> = emptyMap()
)

data class NowPlayingState(
    val stationName: String = "RendezVox",
    val tagline: String = "Online Radio",
    val songTitle: String = "\u2014",
    val songArtist: String = "",
    val songId: Int = 0,
    val hasCoverArt: Boolean = false,
    val durationMs: Long = 0,
    val startedAtMs: Long = 0,
    val nextTitle: String = "\u2014",
    val nextArtist: String = "",
    val listenerCount: Int = 0,
    val isEmergency: Boolean = false,
    val dedicationName: String? = null,
    val dedicationMessage: String? = null,
    val isPlaying: Boolean = false,
    val isConnecting: Boolean = false,
    val isBuffering: Boolean = false,
    val isOffline: Boolean = false,
    val volume: Float = 0.8f,
    val baseUrl: String = ServerPrefs.DEFAULT_URL,
    val accentColor: String = "#ff7800",
    val recentPlays: List<RecentPlay> = emptyList(),
    val showHistory: Boolean = false,
    val updateAvailable: Boolean = false,
    val updateVersion: String = "",
    val updateChangelog: String = ""
) {
    val coverArtUrl: String
        get() = if (hasCoverArt && songId > 0) "$baseUrl/api/cover?id=$songId" else ""
}
