package net.downstreamtech.iradio.ui

import androidx.compose.animation.core.*
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.VolumeOff
import androidx.compose.material.icons.automirrored.filled.VolumeUp
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.rotate
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import coil.request.ImageRequest
import kotlinx.coroutines.delay
import net.downstreamtech.iradio.data.NowPlayingState
import net.downstreamtech.iradio.ui.theme.*

@Composable
fun PlayerScreen(
    state: NowPlayingState,
    onTogglePlayback: () -> Unit,
    onVolumeChange: (Float) -> Unit,
    onRequestSong: () -> Unit
) {
    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(
                Brush.verticalGradient(
                    colors = listOf(Color(0xFF0D0D1A), BgDark, BgDark)
                )
            )
    ) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 20.dp)
                .padding(top = 40.dp, bottom = 20.dp),
            horizontalAlignment = Alignment.CenterHorizontally
        ) {
            // Station header
            Text(
                state.stationName,
                color = TextPrimary,
                fontSize = 20.sp,
                fontWeight = FontWeight.Bold,
                letterSpacing = 0.5.sp
            )
            Text(
                state.tagline,
                color = TextDim,
                fontSize = 12.sp,
                modifier = Modifier.padding(top = 2.dp, bottom = 16.dp)
            )

            // Cover art / vinyl
            CoverArtOrVinyl(coverArtUrl = state.coverArtUrl, isPlaying = state.isPlaying)

            Spacer(Modifier.height(14.dp))

            // Now playing
            if (state.isConnecting && !state.isPlaying) {
                Text("Connecting\u2026", color = TextSecondary, fontSize = 15.sp)
                LinearProgressIndicator(
                    modifier = Modifier
                        .width(80.dp)
                        .padding(top = 4.dp)
                        .height(2.dp)
                        .clip(RoundedCornerShape(1.dp)),
                    color = Accent
                )
            } else {
                Text(
                    state.songTitle,
                    color = TextPrimary,
                    fontSize = 19.sp,
                    fontWeight = FontWeight.Bold,
                    textAlign = TextAlign.Center,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                    lineHeight = 24.sp
                )
                if (state.songArtist.isNotBlank()) {
                    Text(
                        state.songArtist,
                        color = AccentLight,
                        fontSize = 14.sp,
                        textAlign = TextAlign.Center,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                        modifier = Modifier.padding(top = 3.dp)
                    )
                }
            }

            // Progress
            if (state.durationMs > 0 && state.startedAtMs > 0) {
                ProgressSection(
                    startedAtMs = state.startedAtMs,
                    durationMs = state.durationMs,
                    modifier = Modifier.padding(top = 12.dp)
                )
            }

            // Controls row: volume + play button
            Spacer(Modifier.height(14.dp))
            Row(
                modifier = Modifier.fillMaxWidth(),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.Center
            ) {
                // Volume left side
                Icon(
                    if (state.volume == 0f) Icons.AutoMirrored.Filled.VolumeOff
                    else Icons.AutoMirrored.Filled.VolumeUp,
                    contentDescription = null,
                    tint = TextDim,
                    modifier = Modifier.size(16.dp)
                )
                Slider(
                    value = state.volume,
                    onValueChange = onVolumeChange,
                    modifier = Modifier.weight(1f),
                    colors = SliderDefaults.colors(
                        thumbColor = Accent,
                        activeTrackColor = Accent,
                        inactiveTrackColor = Color(0xFF333355)
                    )
                )

                Spacer(Modifier.width(8.dp))

                // Play/Stop
                Box(
                    modifier = Modifier
                        .size(60.dp)
                        .shadow(12.dp, CircleShape, ambientColor = Accent.copy(alpha = 0.35f))
                        .clip(CircleShape)
                        .background(Brush.radialGradient(listOf(AccentLight, Accent))),
                    contentAlignment = Alignment.Center
                ) {
                    IconButton(onClick = onTogglePlayback, modifier = Modifier.fillMaxSize()) {
                        if (state.isBuffering && !state.isPlaying) {
                            CircularProgressIndicator(
                                modifier = Modifier.size(24.dp),
                                color = Color.White,
                                strokeWidth = 2.dp
                            )
                        } else {
                            Icon(
                                if (state.isPlaying) Icons.Default.Stop else Icons.Default.PlayArrow,
                                contentDescription = if (state.isPlaying) "Stop" else "Play",
                                tint = Color.White,
                                modifier = Modifier.size(30.dp)
                            )
                        }
                    }
                }

                Spacer(Modifier.width(8.dp))

                // Request button (right side, icon only)
                IconButton(
                    onClick = onRequestSong,
                    enabled = !state.isEmergency,
                    modifier = Modifier
                        .size(40.dp)
                        .clip(CircleShape)
                        .background(
                            if (state.isEmergency) Color(0xFF222233)
                            else Accent.copy(alpha = 0.12f)
                        )
                ) {
                    Icon(
                        Icons.Default.MusicNote,
                        contentDescription = "Request a Song",
                        tint = if (state.isEmergency) TextDim else Accent,
                        modifier = Modifier.size(18.dp)
                    )
                }

                // Balance spacer to match left volume icon width
                Spacer(Modifier.width(16.dp))
            }

            Spacer(Modifier.height(12.dp))

            // Info card: up next + listeners
            Card(
                modifier = Modifier.fillMaxWidth(),
                shape = RoundedCornerShape(12.dp),
                colors = CardDefaults.cardColors(containerColor = BgCard)
            ) {
                Column(modifier = Modifier.padding(horizontal = 14.dp, vertical = 10.dp)) {
                    // Up next
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Default.SkipNext, null, tint = TextDim, modifier = Modifier.size(14.dp))
                        Spacer(Modifier.width(6.dp))
                        Text(
                            if (state.nextTitle != "\u2014") "${state.nextTitle} \u2014 ${state.nextArtist}" else "\u2014",
                            color = TextSecondary,
                            fontSize = 12.sp,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis,
                            modifier = Modifier.weight(1f)
                        )
                    }
                    HorizontalDivider(modifier = Modifier.padding(vertical = 6.dp), color = Color(0xFF2A2A40))
                    // Listeners
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Default.Headphones, null, tint = TextDim, modifier = Modifier.size(14.dp))
                        Spacer(Modifier.width(6.dp))
                        Text("${state.listenerCount} listening", color = TextSecondary, fontSize = 12.sp)
                    }
                }
            }

            // Dedication
            if (state.dedicationName != null || state.dedicationMessage != null) {
                DedicationCard(
                    name = state.dedicationName,
                    message = state.dedicationMessage,
                    modifier = Modifier.padding(top = 8.dp)
                )
            }

            // Footer
            Spacer(Modifier.height(16.dp))
            Text(
                "\u00A9 2026 RendezVox \u2022 Engr. Richard R. Ayuyang, PhD \u2022 Professor II, CSU",
                color = TextDim.copy(alpha = 0.35f),
                fontSize = 9.sp,
                textAlign = TextAlign.Center,
                lineHeight = 13.sp
            )
        }
    }
}

@Composable
fun CoverArtOrVinyl(coverArtUrl: String, isPlaying: Boolean) {
    val artSize = 180.dp
    val corner = 16.dp
    var loaded by remember(coverArtUrl) { mutableStateOf(false) }
    var failed by remember(coverArtUrl) { mutableStateOf(false) }

    Box(modifier = Modifier.size(artSize), contentAlignment = Alignment.Center) {
        if (coverArtUrl.isEmpty() || failed || !loaded) {
            VinylDisc(isPlaying = isPlaying, size = artSize.value.toInt())
        }
        if (coverArtUrl.isNotEmpty()) {
            AsyncImage(
                model = ImageRequest.Builder(LocalContext.current)
                    .data(coverArtUrl)
                    .crossfade(true)
                    .build(),
                contentDescription = "Cover Art",
                contentScale = ContentScale.Crop,
                onSuccess = { loaded = true; failed = false },
                onError = { failed = true; loaded = false },
                modifier = Modifier
                    .size(artSize)
                    .shadow(20.dp, RoundedCornerShape(corner))
                    .clip(RoundedCornerShape(corner))
                    .then(if (loaded && !failed) Modifier else Modifier.size(0.dp))
            )
        }
    }
}

@Composable
fun VinylDisc(isPlaying: Boolean, size: Int = 180) {
    val rotation by rememberInfiniteTransition(label = "v")
        .animateFloat(0f, 360f, infiniteRepeatable(tween(4000, easing = LinearEasing)), label = "r")

    Box(
        modifier = Modifier
            .size(size.dp)
            .shadow(12.dp, CircleShape, ambientColor = Accent.copy(alpha = 0.15f))
            .clip(CircleShape)
            .background(Brush.radialGradient(listOf(Color(0xFF1C1C30), Color(0xFF0F0F1A), Color(0xFF1C1C30))))
            .rotate(if (isPlaying) rotation else 0f),
        contentAlignment = Alignment.Center
    ) {
        for ((ring, a) in listOf(
            (size * 0.85f).toInt() to 0.07f,
            (size * 0.68f).toInt() to 0.05f,
            (size * 0.52f).toInt() to 0.04f
        )) {
            Canvas(modifier = Modifier.size(ring.dp).align(Alignment.Center)) {
                drawCircle(Color.White.copy(alpha = a), style = Stroke(width = 1f))
            }
        }
        Box(
            modifier = Modifier
                .size((size * 0.30f).dp)
                .clip(CircleShape)
                .background(Brush.radialGradient(listOf(AccentLight, Accent))),
            contentAlignment = Alignment.Center
        ) {
            Box(Modifier.size((size * 0.06f).dp).clip(CircleShape).background(BgDark))
        }
    }
}

@Composable
fun ProgressSection(startedAtMs: Long, durationMs: Long, modifier: Modifier = Modifier) {
    var elapsedMs by remember { mutableLongStateOf(0L) }
    LaunchedEffect(startedAtMs, durationMs) {
        while (true) {
            elapsedMs = (System.currentTimeMillis() - startedAtMs).coerceIn(0, durationMs)
            delay(1000)
        }
    }
    val pct = if (durationMs > 0) (elapsedMs.toFloat() / durationMs).coerceIn(0f, 1f) else 0f

    Column(modifier = modifier.fillMaxWidth()) {
        LinearProgressIndicator(
            progress = { pct },
            modifier = Modifier.fillMaxWidth().height(2.dp).clip(RoundedCornerShape(1.dp)),
            color = Accent,
            trackColor = Color(0xFF333355)
        )
        Row(modifier = Modifier.fillMaxWidth().padding(top = 3.dp), horizontalArrangement = Arrangement.SpaceBetween) {
            Text(fmtMs(elapsedMs), color = TextDim, fontSize = 10.sp)
            Text("-${fmtMs((durationMs - elapsedMs).coerceAtLeast(0))}", color = TextDim, fontSize = 10.sp)
        }
    }
}

@Composable
fun DedicationCard(name: String?, message: String?, modifier: Modifier = Modifier) {
    Card(
        modifier = modifier.fillMaxWidth(),
        shape = RoundedCornerShape(10.dp),
        colors = CardDefaults.cardColors(containerColor = DedicationBg),
        border = androidx.compose.foundation.BorderStroke(1.dp, DedicationBorder)
    ) {
        Column(modifier = Modifier.padding(10.dp)) {
            Text("REQUESTED BY", color = TextDim, fontSize = 9.sp, letterSpacing = 0.4.sp, fontWeight = FontWeight.Medium)
            Text(name ?: "A listener", color = TextPrimary, fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 2.dp))
            if (!message.isNullOrBlank()) {
                Text("\u201C$message\u201D", color = DedicationText, fontSize = 12.sp, fontStyle = FontStyle.Italic, lineHeight = 16.sp, modifier = Modifier.padding(top = 4.dp))
            }
        }
    }
}

private fun fmtMs(ms: Long): String {
    val s = (ms / 1000).toInt()
    return "${s / 60}:${(s % 60).toString().padStart(2, '0')}"
}
