package net.downstreamtech.iradio.data

data class StationConfig(
    val station_name: String = "iRadio",
    val tagline: String = "Online Radio"
)

data class SongInfo(
    val id: Int = 0,
    val title: String = "",
    val artist: String = "",
    val duration_ms: Long = 0
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
    val title: String,
    val artist: String? = null,
    val listener_name: String? = null,
    val message: String? = null
)

data class RequestResponse(
    val song: SearchSong? = null,
    val error: String? = null,
    val suggestions: List<SearchSong>? = null
)

data class NowPlayingState(
    val stationName: String = "iRadio",
    val tagline: String = "Online Radio",
    val songTitle: String = "\u2014",
    val songArtist: String = "",
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
    val serverUrl: String = "",
    val volume: Float = 0.8f
)
