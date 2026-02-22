package net.downstreamtech.iradio.ui

import android.content.ComponentName
import android.content.Context
import android.content.SharedPreferences
import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import androidx.media3.common.MediaItem
import androidx.media3.common.MediaMetadata
import androidx.media3.common.Player
import androidx.media3.session.MediaController
import androidx.media3.session.SessionToken
import com.google.common.util.concurrent.ListenableFuture
import com.google.common.util.concurrent.MoreExecutors
import kotlinx.coroutines.*
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import net.downstreamtech.iradio.data.*
import net.downstreamtech.iradio.service.PlaybackService
import java.text.SimpleDateFormat
import java.util.*

class PlayerViewModel(private val context: Context) : ViewModel() {

    private val prefs: SharedPreferences =
        context.getSharedPreferences("iradio_prefs", Context.MODE_PRIVATE)

    private val _state = MutableStateFlow(NowPlayingState())
    val state: StateFlow<NowPlayingState> = _state.asStateFlow()

    private var api: RadioApi? = null
    private var controllerFuture: ListenableFuture<MediaController>? = null
    private var controller: MediaController? = null
    private var pollingJob: Job? = null
    private var sseJob: Job? = null
    private var listenerJob: Job? = null

    private val playerListener = object : Player.Listener {
        override fun onIsPlayingChanged(isPlaying: Boolean) {
            _state.update { it.copy(isPlaying = isPlaying) }
        }
        override fun onPlaybackStateChanged(playbackState: Int) {
            _state.update {
                it.copy(
                    isBuffering = playbackState == Player.STATE_BUFFERING,
                    isConnecting = playbackState == Player.STATE_BUFFERING && !it.isPlaying
                )
            }
        }
    }

    init {
        val savedUrl = prefs.getString("server_url", null)
        if (!savedUrl.isNullOrBlank()) {
            _state.update { it.copy(serverUrl = savedUrl) }
            connectToServer(savedUrl)
        }
    }

    fun setServerUrl(url: String) {
        val cleanUrl = url.trimEnd('/')
        prefs.edit().putString("server_url", cleanUrl).apply()
        _state.update { it.copy(serverUrl = cleanUrl) }
        connectToServer(cleanUrl)
    }

    private fun connectToServer(url: String) {
        api = RadioApi(url)

        // Fetch station config
        viewModelScope.launch {
            val config = api?.fetchConfig() ?: return@launch
            _state.update {
                it.copy(
                    stationName = config.station_name,
                    tagline = config.tagline
                )
            }
        }

        // Connect to media session
        connectMediaController()

        // Start polling
        startPolling()
        startSSE()
        startListenerPolling()
    }

    private fun connectMediaController() {
        val token = SessionToken(context, ComponentName(context, PlaybackService::class.java))
        controllerFuture = MediaController.Builder(context, token).buildAsync()
        controllerFuture?.addListener({
            try {
                controller = controllerFuture?.get()
                controller?.addListener(playerListener)
                // Sync initial state
                _state.update {
                    it.copy(
                        isPlaying = controller?.isPlaying == true,
                        isBuffering = controller?.playbackState == Player.STATE_BUFFERING
                    )
                }
            } catch (_: Exception) {}
        }, MoreExecutors.directExecutor())
    }

    fun togglePlayback() {
        val ctrl = controller ?: return
        val currentApi = api ?: return

        if (ctrl.isPlaying || ctrl.playbackState == Player.STATE_BUFFERING) {
            ctrl.stop()
            ctrl.clearMediaItems()
            _state.update { it.copy(isPlaying = false, isBuffering = false, isConnecting = false) }
        } else {
            val s = _state.value
            val mediaItem = PlaybackService.buildMediaItem(
                streamUrl = currentApi.streamUrl,
                title = if (s.songTitle != "\u2014") s.songTitle else s.stationName,
                artist = s.songArtist.ifBlank { "Online Radio" },
                stationName = s.stationName
            )
            ctrl.setMediaItem(mediaItem)
            ctrl.prepare()
            ctrl.play()
            _state.update { it.copy(isConnecting = true) }
        }
    }

    fun setVolume(volume: Float) {
        controller?.volume = volume
        _state.update { it.copy(volume = volume) }
    }

    private fun startPolling() {
        pollingJob?.cancel()
        pollingJob = viewModelScope.launch {
            while (isActive) {
                fetchNowPlaying()
                delay(30_000)
            }
        }
    }

    private fun startSSE() {
        sseJob?.cancel()
        sseJob = viewModelScope.launch {
            while (isActive) {
                try {
                    api?.connectSSE { data ->
                        handleNowPlayingData(data)
                    }
                } catch (_: Exception) {}
                delay(5_000) // Reconnect delay
            }
        }
    }

    private fun startListenerPolling() {
        listenerJob?.cancel()
        listenerJob = viewModelScope.launch {
            while (isActive) {
                val count = api?.fetchListenerCount() ?: 0
                _state.update { it.copy(listenerCount = count) }
                delay(15_000)
            }
        }
    }

    private suspend fun fetchNowPlaying() {
        val data = api?.fetchNowPlaying() ?: return
        handleNowPlayingData(data)
    }

    private fun handleNowPlayingData(data: NowPlayingData) {
        _state.update { current ->
            val song = data.song
            val startedMs = if (data.started_at != null) {
                parseIsoDate(data.started_at)
            } else if (song != null) {
                // Use SSE song started_at if available
                0L
            } else 0L

            current.copy(
                songTitle = song?.title ?: "\u2014",
                songArtist = song?.artist ?: "",
                durationMs = song?.duration_ms ?: 0,
                startedAtMs = startedMs,
                nextTitle = data.next_track?.title ?: "\u2014",
                nextArtist = data.next_track?.artist ?: "",
                isEmergency = data.is_emergency,
                dedicationName = data.request?.listener_name,
                dedicationMessage = data.request?.message
            )
        }

        // Update media session metadata
        val song = data.song
        if (song != null && controller?.isPlaying == true) {
            val s = _state.value
            val metadata = MediaMetadata.Builder()
                .setTitle(song.title)
                .setArtist(song.artist)
                .setStation(s.stationName)
                .build()
            // Update current media item metadata
            controller?.let { ctrl ->
                if (ctrl.mediaItemCount > 0) {
                    val currentItem = ctrl.currentMediaItem
                    if (currentItem != null) {
                        val updated = currentItem.buildUpon()
                            .setMediaMetadata(metadata)
                            .build()
                        ctrl.replaceMediaItem(0, updated)
                    }
                }
            }
        }
    }

    private fun parseIsoDate(dateStr: String): Long {
        return try {
            val formats = arrayOf(
                "yyyy-MM-dd'T'HH:mm:ss.SSS'Z'",
                "yyyy-MM-dd'T'HH:mm:ss'Z'",
                "yyyy-MM-dd'T'HH:mm:ssXXX",
                "yyyy-MM-dd HH:mm:ss"
            )
            for (fmt in formats) {
                try {
                    val sdf = SimpleDateFormat(fmt, Locale.US)
                    sdf.timeZone = TimeZone.getTimeZone("UTC")
                    return sdf.parse(dateStr)?.time ?: 0L
                } catch (_: Exception) {}
            }
            0L
        } catch (_: Exception) {
            0L
        }
    }

    // --- Song Request ---

    suspend fun searchSongs(title: String, artist: String?): SearchResult {
        return api?.searchSong(title, artist) ?: SearchResult()
    }

    suspend fun submitSongRequest(body: RequestBody): Pair<Int, RequestResponse> {
        return api?.submitRequest(body) ?: Pair(0, RequestResponse(error = "Not connected"))
    }

    override fun onCleared() {
        super.onCleared()
        pollingJob?.cancel()
        sseJob?.cancel()
        listenerJob?.cancel()
        controller?.removeListener(playerListener)
        controllerFuture?.let { MediaController.releaseFuture(it) }
    }

    class Factory(private val context: Context) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T {
            return PlayerViewModel(context.applicationContext) as T
        }
    }
}
