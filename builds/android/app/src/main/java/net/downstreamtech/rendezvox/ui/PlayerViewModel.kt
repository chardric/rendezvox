package net.downstreamtech.rendezvox.ui

import android.content.ComponentName
import android.content.Context
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
import net.downstreamtech.rendezvox.data.*
import net.downstreamtech.rendezvox.service.PlaybackService
import java.util.*

class PlayerViewModel(private val context: Context, private val baseUrl: String) : ViewModel() {

    private val _state = MutableStateFlow(NowPlayingState(baseUrl = baseUrl))
    val state: StateFlow<NowPlayingState> = _state.asStateFlow()

    private val api = RadioApi(baseUrl)
    private var controllerFuture: ListenableFuture<MediaController>? = null
    private var controller: MediaController? = null
    private var pollingJob: Job? = null
    private var sseJob: Job? = null
    private var listenerJob: Job? = null
    private var historyJob: Job? = null
    private var failCount = 0
    private val offlineThreshold = 3

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
        connectMediaController()
        viewModelScope.launch {
            val config = api.fetchConfig()
            _state.update {
                it.copy(
                    stationName = config.station_name.ifBlank { "RendezVox" },
                    tagline = config.tagline,
                    accentColor = config.accent_color.ifBlank { "#ff7800" }
                )
            }
        }
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

        if (ctrl.isPlaying || ctrl.playbackState == Player.STATE_BUFFERING) {
            ctrl.stop()
            ctrl.clearMediaItems()
            _state.update { it.copy(isPlaying = false, isBuffering = false, isConnecting = false) }
        } else {
            val s = _state.value
            val mediaItem = PlaybackService.buildMediaItem(
                streamUrl = api.streamUrl,
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
                    api.connectSSE { data ->
                        handleNowPlayingData(data)
                        markOnline()
                    }
                } catch (_: Exception) {}
                markFailure()
                delay(5_000)
            }
        }
    }

    private fun startListenerPolling() {
        listenerJob?.cancel()
        listenerJob = viewModelScope.launch {
            while (isActive) {
                val count = api.fetchListenerCount()
                _state.update { it.copy(listenerCount = count) }
                delay(15_000)
            }
        }
    }

    private suspend fun fetchNowPlaying() {
        val data = api.fetchNowPlaying()
        if (data != null) {
            handleNowPlayingData(data)
            markOnline()
        } else {
            markFailure()
        }
    }

    private fun markFailure() {
        failCount++
        if (failCount >= offlineThreshold) {
            _state.update { it.copy(isOffline = true) }
        }
    }

    private fun markOnline() {
        failCount = 0
        _state.update { it.copy(isOffline = false) }
    }

    private fun handleNowPlayingData(data: NowPlayingData) {
        _state.update { current ->
            val song = data.song
            val startedAt = data.started_at ?: song?.started_at
            val startedMs = startedAt?.let { parseIsoDate(it) } ?: current.startedAtMs

            current.copy(
                songTitle = song?.title ?: "\u2014",
                songArtist = song?.artist ?: "",
                songId = song?.id ?: 0,
                hasCoverArt = song?.has_cover_art ?: false,
                durationMs = song?.duration_ms ?: 0,
                startedAtMs = startedMs,
                nextTitle = data.next_track?.title ?: "\u2014",
                nextArtist = data.next_track?.artist ?: "",
                isEmergency = data.is_emergency,
                dedicationName = data.request?.listener_name,
                dedicationMessage = data.request?.message
            )
        }

        val song = data.song
        if (song != null && controller?.isPlaying == true) {
            val s = _state.value
            val metadata = MediaMetadata.Builder()
                .setTitle(song.title)
                .setArtist(song.artist)
                .setStation(s.stationName)
                .build()
            controller?.let { ctrl ->
                if (ctrl.mediaItemCount > 0) {
                    val currentItem = ctrl.currentMediaItem
                    if (currentItem != null) {
                        ctrl.replaceMediaItem(0, currentItem.buildUpon().setMediaMetadata(metadata).build())
                    }
                }
            }
        }
    }

    private fun parseIsoDate(dateStr: String): Long {
        // Regex parser matching web app's parseTs() — handles PostgreSQL timestamps
        // e.g. "2026-02-27 06:41:14.996164+00" or "2026-02-27T06:41:14Z"
        val m = Regex("""(\d+)-(\d+)-(\d+)\D+(\d+):(\d+):(\d+)""").find(dateStr) ?: return 0L
        val (yr, mo, dy, hr, mn, sc) = m.destructured
        val cal = Calendar.getInstance(TimeZone.getTimeZone("UTC"))
        cal.set(yr.toInt(), mo.toInt() - 1, dy.toInt(), hr.toInt(), mn.toInt(), sc.toInt())
        cal.set(Calendar.MILLISECOND, 0)
        var ms = cal.timeInMillis
        // Apply timezone offset if present (e.g. +00, +08, -05)
        val tz = Regex("""([+-])(\d{2})\s*$""").find(dateStr)
        if (tz != null) {
            val sign = if (tz.groupValues[1] == "+") 1 else -1
            ms -= sign * tz.groupValues[2].toInt() * 3600000L
        }
        return ms
    }

    fun toggleHistory() {
        val show = !_state.value.showHistory
        _state.update { it.copy(showHistory = show) }
        if (show) {
            fetchRecentPlays()
            historyJob?.cancel()
            historyJob = viewModelScope.launch {
                while (isActive) {
                    delay(60_000)
                    fetchRecentPlays()
                }
            }
        } else {
            historyJob?.cancel()
            historyJob = null
        }
    }

    private fun fetchRecentPlays() {
        viewModelScope.launch {
            val plays = api.fetchRecentPlays()
            _state.update { it.copy(recentPlays = plays) }
        }
    }

    suspend fun searchSongs(title: String, artist: String?): SearchResult =
        api.searchSong(title, artist)

    suspend fun submitSongRequest(body: RequestBody): Pair<Int, RequestResponse> =
        api.submitRequest(body)

    override fun onCleared() {
        super.onCleared()
        pollingJob?.cancel()
        sseJob?.cancel()
        listenerJob?.cancel()
        historyJob?.cancel()
        controller?.removeListener(playerListener)
        controllerFuture?.let { MediaController.releaseFuture(it) }
    }

    class Factory(private val context: Context, private val baseUrl: String) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T =
            PlayerViewModel(context.applicationContext, baseUrl) as T
    }
}
