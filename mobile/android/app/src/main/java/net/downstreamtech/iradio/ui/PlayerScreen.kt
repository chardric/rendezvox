package net.downstreamtech.iradio.ui

import androidx.compose.animation.core.*
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.rotate
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.delay
import net.downstreamtech.iradio.data.NowPlayingState
import net.downstreamtech.iradio.ui.theme.*

@Composable
fun PlayerScreen(
    state: NowPlayingState,
    onTogglePlayback: () -> Unit,
    onVolumeChange: (Float) -> Unit,
    onRequestSong: () -> Unit,
    onOpenSettings: () -> Unit
) {
    val scrollState = rememberScrollState()

    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(BgDark)
    ) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .verticalScroll(scrollState)
                .padding(horizontal = 24.dp, vertical = 16.dp),
            horizontalAlignment = Alignment.CenterHorizontally
        ) {
            // Top bar with settings
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.End
            ) {
                IconButton(onClick = onOpenSettings) {
                    Icon(
                        Icons.Default.Settings,
                        contentDescription = "Settings",
                        tint = TextDim
                    )
                }
            }

            Spacer(Modifier.height(8.dp))

            // Station name & tagline
            Text(
                state.stationName,
                color = TextPrimary,
                fontSize = 26.sp,
                fontWeight = FontWeight.Bold
            )
            Text(
                state.tagline,
                color = TextDim,
                fontSize = 14.sp,
                modifier = Modifier.padding(top = 4.dp, bottom = 28.dp)
            )

            // Album art placeholder — animated vinyl
            VinylDisc(isPlaying = state.isPlaying)

            Spacer(Modifier.height(28.dp))

            // Now playing
            if (state.isConnecting && !state.isPlaying) {
                Text("Connecting...", color = TextSecondary, fontSize = 16.sp)
                Spacer(Modifier.height(4.dp))
                LinearProgressIndicator(
                    modifier = Modifier
                        .width(120.dp)
                        .clip(RoundedCornerShape(2.dp)),
                    color = Accent
                )
            } else {
                Text(
                    state.songTitle,
                    color = TextPrimary,
                    fontSize = 20.sp,
                    fontWeight = FontWeight.SemiBold,
                    textAlign = TextAlign.Center,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis
                )
                if (state.songArtist.isNotBlank()) {
                    Text(
                        state.songArtist,
                        color = TextSecondary,
                        fontSize = 15.sp,
                        modifier = Modifier.padding(top = 4.dp),
                        textAlign = TextAlign.Center,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis
                    )
                }
            }

            Spacer(Modifier.height(20.dp))

            // Progress bar
            if (state.durationMs > 0 && state.startedAtMs > 0) {
                ProgressSection(
                    startedAtMs = state.startedAtMs,
                    durationMs = state.durationMs
                )
                Spacer(Modifier.height(20.dp))
            }

            // Play/Stop button
            IconButton(
                onClick = onTogglePlayback,
                modifier = Modifier
                    .size(72.dp)
                    .clip(CircleShape)
                    .background(Accent)
            ) {
                if (state.isBuffering && !state.isPlaying) {
                    CircularProgressIndicator(
                        modifier = Modifier.size(32.dp),
                        color = Color.White,
                        strokeWidth = 3.dp
                    )
                } else {
                    Icon(
                        if (state.isPlaying) Icons.Default.Stop else Icons.Default.PlayArrow,
                        contentDescription = if (state.isPlaying) "Stop" else "Play",
                        tint = Color.White,
                        modifier = Modifier.size(36.dp)
                    )
                }
            }

            Spacer(Modifier.height(24.dp))

            // Volume slider
            Row(
                verticalAlignment = Alignment.CenterVertically,
                modifier = Modifier.padding(horizontal = 16.dp)
            ) {
                Icon(
                    if (state.volume == 0f) Icons.Default.VolumeOff else Icons.Default.VolumeUp,
                    contentDescription = "Volume",
                    tint = TextSecondary,
                    modifier = Modifier.size(20.dp)
                )
                Spacer(Modifier.width(8.dp))
                Slider(
                    value = state.volume,
                    onValueChange = onVolumeChange,
                    modifier = Modifier.weight(1f),
                    colors = SliderDefaults.colors(
                        thumbColor = Accent,
                        activeTrackColor = Accent,
                        inactiveTrackColor = MaterialTheme.colorScheme.outline
                    )
                )
            }

            Spacer(Modifier.height(20.dp))

            // Up next
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.Center
            ) {
                Text("Up Next: ", color = TextDim, fontSize = 13.sp)
                Text(
                    if (state.nextTitle != "\u2014") "${state.nextTitle} \u2014 ${state.nextArtist}" else "\u2014",
                    color = TextSecondary,
                    fontSize = 13.sp,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis
                )
            }

            // Dedication
            if (state.dedicationName != null || state.dedicationMessage != null) {
                Spacer(Modifier.height(12.dp))
                DedicationCard(
                    name = state.dedicationName,
                    message = state.dedicationMessage
                )
            }

            Spacer(Modifier.height(12.dp))

            // Listeners
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.Center
            ) {
                Text("Listeners: ", color = TextDim, fontSize = 13.sp)
                Text(
                    "${state.listenerCount}",
                    color = TextSecondary,
                    fontSize = 13.sp
                )
            }

            Spacer(Modifier.height(24.dp))

            // Request button
            Button(
                onClick = onRequestSong,
                enabled = !state.isEmergency,
                modifier = Modifier.fillMaxWidth(),
                colors = ButtonDefaults.buttonColors(
                    containerColor = SurfaceElevated,
                    contentColor = TextSecondary,
                    disabledContainerColor = SurfaceElevated.copy(alpha = 0.4f),
                    disabledContentColor = TextDim
                ),
                shape = RoundedCornerShape(12.dp)
            ) {
                Text(
                    if (state.isEmergency) "Requests Unavailable" else "Request a Song",
                    fontSize = 15.sp,
                    modifier = Modifier.padding(vertical = 4.dp)
                )
            }

            Spacer(Modifier.height(24.dp))

            // Footer
            Text(
                "\u00A9 2026 DownStreamTech. All rights reserved.",
                color = TextDim.copy(alpha = 0.6f),
                fontSize = 11.sp
            )

            Spacer(Modifier.height(16.dp))
        }
    }
}

@Composable
fun VinylDisc(isPlaying: Boolean) {
    val infiniteTransition = rememberInfiniteTransition(label = "vinyl")
    val rotation by infiniteTransition.animateFloat(
        initialValue = 0f,
        targetValue = 360f,
        animationSpec = infiniteRepeatable(
            animation = tween(3000, easing = LinearEasing),
            repeatMode = RepeatMode.Restart
        ),
        label = "vinylRotation"
    )

    Box(
        modifier = Modifier
            .size(180.dp)
            .clip(CircleShape)
            .background(
                Brush.radialGradient(
                    colors = listOf(
                        BgCard,
                        Color(0xFF16162A),
                        BgCard
                    )
                )
            )
            .rotate(if (isPlaying) rotation else 0f),
        contentAlignment = Alignment.Center
    ) {
        // Outer ring
        Box(
            modifier = Modifier
                .size(160.dp)
                .clip(CircleShape)
                .background(Color.Transparent)
        ) {
            // Grooves (decorative rings)
            for (size in listOf(150, 130, 110)) {
                Box(
                    modifier = Modifier
                        .size(size.dp)
                        .align(Alignment.Center)
                        .clip(CircleShape)
                        .background(Color.Transparent)
                        .then(
                            Modifier.background(
                                Brush.radialGradient(
                                    colors = listOf(
                                        Color.Transparent,
                                        Color(0x15FFFFFF),
                                        Color.Transparent
                                    )
                                )
                            )
                        )
                )
            }
        }

        // Center label
        Box(
            modifier = Modifier
                .size(60.dp)
                .clip(CircleShape)
                .background(Accent),
            contentAlignment = Alignment.Center
        ) {
            Box(
                modifier = Modifier
                    .size(10.dp)
                    .clip(CircleShape)
                    .background(BgDark)
            )
        }
    }
}

@Composable
fun ProgressSection(startedAtMs: Long, durationMs: Long) {
    var elapsedMs by remember { mutableLongStateOf(0L) }

    LaunchedEffect(startedAtMs, durationMs) {
        while (true) {
            val now = System.currentTimeMillis()
            val elapsed = (now - startedAtMs).coerceIn(0, durationMs)
            elapsedMs = elapsed
            delay(1000)
        }
    }

    val progress = if (durationMs > 0) (elapsedMs.toFloat() / durationMs) else 0f
    val remainMs = (durationMs - elapsedMs).coerceAtLeast(0)

    Column(modifier = Modifier.fillMaxWidth().padding(horizontal = 8.dp)) {
        LinearProgressIndicator(
            progress = { progress.coerceIn(0f, 1f) },
            modifier = Modifier
                .fillMaxWidth()
                .height(4.dp)
                .clip(RoundedCornerShape(2.dp)),
            color = Accent,
            trackColor = MaterialTheme.colorScheme.outline,
        )
        Spacer(Modifier.height(4.dp))
        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.SpaceBetween
        ) {
            Text(formatMs(elapsedMs), color = TextDim, fontSize = 12.sp)
            Text("-${formatMs(remainMs)}", color = TextDim, fontSize = 12.sp)
        }
    }
}

@Composable
fun DedicationCard(name: String?, message: String?) {
    Card(
        shape = RoundedCornerShape(10.dp),
        colors = CardDefaults.cardColors(containerColor = DedicationBg),
        border = androidx.compose.foundation.BorderStroke(1.dp, DedicationBorder),
        modifier = Modifier.fillMaxWidth()
    ) {
        Column(modifier = Modifier.padding(12.dp)) {
            Text(
                "REQUESTED BY",
                color = TextDim,
                fontSize = 10.sp,
                letterSpacing = 0.5.sp
            )
            Spacer(Modifier.height(4.dp))
            Text(
                name ?: "A listener",
                color = TextPrimary,
                fontSize = 14.sp,
                fontWeight = FontWeight.SemiBold
            )
            if (!message.isNullOrBlank()) {
                Spacer(Modifier.height(6.dp))
                Text(
                    "\u201C$message\u201D",
                    color = DedicationText,
                    fontSize = 13.sp,
                    fontStyle = FontStyle.Italic
                )
            }
        }
    }
}

private fun formatMs(ms: Long): String {
    val totalSec = (ms / 1000).toInt()
    val m = totalSec / 60
    val s = totalSec % 60
    return "$m:${s.toString().padStart(2, '0')}"
}
