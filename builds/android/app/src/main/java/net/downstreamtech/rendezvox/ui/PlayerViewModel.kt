@file:Suppress("DEPRECATION")

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
import android.media.audiofx.Equalizer
import android.media.audiofx.Virtualizer
import android.media.audiofx.Visualizer
import kotlinx.coroutines.*
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import net.downstreamtech.rendezvox.data.*
import net.downstreamtech.rendezvox.service.PlaybackService
import java.util.*

class PlayerViewModel(private val context: Context, private val baseUrl: String) : ViewModel() {

    companion object {
        const val APP_VERSION = "1.0.1"
    }

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

    // Schedule state
    private val _scheduleItems = MutableStateFlow<List<ScheduleItem>>(emptyList())
    val scheduleItems: StateFlow<List<ScheduleItem>> = _scheduleItems.asStateFlow()

    // VU meter state
    private var visualizer: Visualizer? = null
    private val _vuBands = MutableStateFlow(FloatArray(16))
    val vuBands: StateFlow<FloatArray> = _vuBands.asStateFlow()

    // EQ state
    private val eqPrefs = EqPrefs(context)
    private var equalizer: Equalizer? = null
    private var virtualizer: Virtualizer? = null
    private val _eqState = MutableStateFlow(EqState())
    val eqState: StateFlow<EqState> = _eqState.asStateFlow()

    private val EQ_PRESETS = mapOf(
        "flat"           to listOf(0,0,0,0,0,0,0,0,0,0),
        "bass_boost"     to listOf(6,5,4,2,0,0,0,0,0,0),
        "treble_boost"   to listOf(0,0,0,0,0,2,3,4,5,6),
        "vocal"          to listOf(-2,-1,0,2,4,4,3,1,0,-1),
        "rock"           to listOf(4,3,1,-1,-2,1,3,4,4,3),
        "pop"            to listOf(-1,1,3,4,3,0,-1,-1,1,2),
        "jazz"           to listOf(3,2,0,2,-2,-2,0,2,3,4),
        "classical"      to listOf(4,3,2,1,0,0,0,1,2,3),
        "loudness"       to listOf(6,4,0,0,-2,0,-1,-4,4,2),
        "small_speakers" to listOf(5,4,3,1,0,1,2,3,3,2),
        "earphones"      to listOf(-1,-1,0,1,2,2,1,0,-1,-2),
        "headphones"     to listOf(2,1,0,0,-1,0,1,2,2,1)
    )
    private val EQ_FREQS = listOf(32, 64, 125, 250, 500, 1000, 2000, 4000, 8000, 16000)
    private val FREQ_LABELS = listOf("32","64","125","250","500","1K","2K","4K","8K","16K")

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
        checkForUpdate()
    }

    private fun checkForUpdate() {
        viewModelScope.launch {
            val info = api.fetchVersion() ?: return@launch
            if (info.version.isNotBlank() && compareVersions(APP_VERSION, info.version) < 0) {
                _state.update {
                    it.copy(
                        updateAvailable = true,
                        updateVersion = info.version,
                        updateChangelog = info.changelog
                    )
                }
            }
        }
    }

    private fun compareVersions(a: String, b: String): Int {
        val pa = a.split(".").map { it.toIntOrNull() ?: 0 }
        val pb = b.split(".").map { it.toIntOrNull() ?: 0 }
        for (i in 0 until 3) {
            val va = pa.getOrElse(i) { 0 }
            val vb = pb.getOrElse(i) { 0 }
            if (va < vb) return -1
            if (va > vb) return 1
        }
        return 0
    }

    fun dismissUpdate() {
        _state.update { it.copy(updateAvailable = false) }
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

    // ── Schedule ──────────────────────────────────────────
    fun fetchSchedule() {
        viewModelScope.launch {
            val items = api.fetchSchedule()
            _scheduleItems.value = items
        }
    }

    // ── VU Meter ─────────────────────────────────────────
    fun initVisualizer() {
        val sessionId = PlaybackService.audioSessionId
        if (sessionId == 0 || visualizer != null) return
        try {
            visualizer = Visualizer(sessionId).apply {
                captureSize = Visualizer.getCaptureSizeRange()[0] // smallest FFT
                setDataCaptureListener(object : Visualizer.OnDataCaptureListener {
                    override fun onWaveFormDataCapture(v: Visualizer?, data: ByteArray?, rate: Int) {}
                    override fun onFftDataCapture(v: Visualizer?, fft: ByteArray?, rate: Int) {
                        if (fft == null) return
                        val bands = FloatArray(16)
                        val binCount = fft.size / 2
                        for (i in 0 until 16) {
                            val binIdx = (Math.pow(i.toDouble() / 16, 1.5) * binCount).toInt()
                                .coerceIn(0, binCount - 1)
                            val re = fft[binIdx * 2].toFloat()
                            val im = fft[binIdx * 2 + 1].toFloat()
                            val mag = Math.sqrt((re * re + im * im).toDouble()).toFloat() / 128f
                            bands[i] = mag.coerceIn(0f, 1f)
                        }
                        _vuBands.value = bands
                    }
                }, Visualizer.getMaxCaptureRate(), false, true)
                enabled = true
            }
        } catch (_: Exception) {}
    }

    fun releaseVisualizer() {
        visualizer?.release()
        visualizer = null
        _vuBands.value = FloatArray(16)
    }

    // ── Equalizer ────────────────────────────────────────
    fun initEqualizer() {
        val sessionId = PlaybackService.audioSessionId
        if (sessionId == 0) {
            _eqState.update { it.copy(isAvailable = false) }
            return
        }
        try {
            if (equalizer == null) {
                equalizer = Equalizer(0, sessionId).apply { enabled = true }
            }
            if (virtualizer == null) {
                virtualizer = Virtualizer(0, sessionId).apply { enabled = true }
            }

            val eq = equalizer ?: return
            val bandCount = eq.numberOfBands.toInt()

            // Build freq labels from Android's actual bands
            val actualFreqLabels = (0 until bandCount).map { i ->
                val freqHz = eq.getCenterFreq(i.toShort()) / 1000 // milliHz to Hz
                if (freqHz >= 1000) "${freqHz / 1000}K" else "$freqHz"
            }

            // Load saved state
            val savedPreset = eqPrefs.preset
            val savedSpatial = eqPrefs.spatialMode
            val savedCustom = eqPrefs.customBands

            val bands = if (savedPreset == "custom") {
                mapBandsToDevice(savedCustom, bandCount, eq)
            } else {
                val presetBands = EQ_PRESETS[savedPreset] ?: EQ_PRESETS["flat"]!!
                mapBandsToDevice(presetBands, bandCount, eq)
            }

            // Apply bands
            applyEqBands(bands)
            applySpatialMode(savedSpatial)

            _eqState.update {
                it.copy(
                    preset = savedPreset,
                    spatialMode = savedSpatial,
                    bands = bands,
                    isAvailable = true,
                    bandCount = bandCount,
                    freqLabels = actualFreqLabels
                )
            }
        } catch (_: Exception) {
            _eqState.update { it.copy(isAvailable = false) }
        }
    }

    private fun mapBandsToDevice(srcBands: List<Int>, deviceBandCount: Int, eq: Equalizer): List<Int> {
        if (deviceBandCount == srcBands.size) return srcBands.toList()

        // Map our 10-band presets to Android's bands by nearest frequency
        return (0 until deviceBandCount).map { i ->
            val deviceFreq = eq.getCenterFreq(i.toShort()) / 1000
            val nearestIdx = EQ_FREQS.indices.minByOrNull {
                kotlin.math.abs(EQ_FREQS[it] - deviceFreq)
            } ?: 0
            srcBands.getOrElse(nearestIdx) { 0 }
        }
    }

    private fun applyEqBands(bands: List<Int>) {
        val eq = equalizer ?: return
        val range = eq.bandLevelRange
        val minLevel = range[0].toInt()
        val maxLevel = range[1].toInt()

        bands.forEachIndexed { i, gain ->
            // Convert our -12..+12 dB to Android's millibel range
            val millibels = (gain * 100).coerceIn(minLevel, maxLevel)
            eq.setBandLevel(i.toShort(), millibels.toShort())
        }
    }

    private fun applySpatialMode(mode: String) {
        val virt = virtualizer ?: return
        val strength = when (mode) {
            "stereo_wide" -> 500
            "surround" -> 800
            "crossfeed" -> 300
            else -> 0
        }.toShort()
        try {
            virt.setStrength(strength)
        } catch (_: Exception) {}
    }

    fun setEqPreset(preset: String) {
        val bands = if (preset == "custom") {
            val custom = eqPrefs.customBands
            val eq = equalizer ?: return
            mapBandsToDevice(custom, _eqState.value.bandCount, eq)
        } else {
            val presetBands = EQ_PRESETS[preset] ?: EQ_PRESETS["flat"]!!
            val eq = equalizer ?: return
            mapBandsToDevice(presetBands, _eqState.value.bandCount, eq)
        }
        applyEqBands(bands)
        eqPrefs.preset = preset
        _eqState.update { it.copy(preset = preset, bands = bands) }
    }

    fun setEqBand(index: Int, gain: Int) {
        val eq = equalizer ?: return
        val range = eq.bandLevelRange
        val millibels = (gain * 100).coerceIn(range[0].toInt(), range[1].toInt())
        eq.setBandLevel(index.toShort(), millibels.toShort())

        val newBands = _eqState.value.bands.toMutableList()
        newBands[index] = gain
        eqPrefs.preset = "custom"
        eqPrefs.customBands = newBands
        _eqState.update { it.copy(preset = "custom", bands = newBands) }
    }

    fun setSpatialMode(mode: String) {
        applySpatialMode(mode)
        eqPrefs.spatialMode = mode
        _eqState.update { it.copy(spatialMode = mode) }
    }

    fun resetEq() {
        setEqPreset("flat")
        setSpatialMode("off")
    }

    fun releaseEqualizer() {
        equalizer?.release()
        equalizer = null
        virtualizer?.release()
        virtualizer = null
        _eqState.update { it.copy(isAvailable = false) }
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
        releaseEqualizer()
        releaseVisualizer()
    }

    class Factory(private val context: Context, private val baseUrl: String) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T =
            PlayerViewModel(context.applicationContext, baseUrl) as T
    }
}
