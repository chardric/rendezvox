package net.downstreamtech.rendezvox.ui

import androidx.compose.animation.core.*
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
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
import androidx.compose.ui.platform.LocalUriHandler
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import coil.compose.AsyncImage
import coil.request.ImageRequest
import kotlinx.coroutines.delay
import net.downstreamtech.rendezvox.data.NowPlayingState
import net.downstreamtech.rendezvox.ui.theme.*

@Composable
fun PlayerScreen(
    state: NowPlayingState,
    onTogglePlayback: () -> Unit,
    onVolumeChange: (Float) -> Unit,
    onRequestSong: () -> Unit,
    onChangeServer: () -> Unit = {}
) {
    val accent = parseHexColor(state.accentColor)
    val accentLight = lightenColor(accent, 0.18f)
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
            // Offline banner
            if (state.isOffline) {
                Card(
                    modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp),
                    shape = RoundedCornerShape(8.dp),
                    colors = CardDefaults.cardColors(containerColor = Color(0x1FF87171)),
                    border = androidx.compose.foundation.BorderStroke(1.dp, Color(0x40F87171))
                ) {
                    Row(
                        modifier = Modifier.padding(horizontal = 14.dp, vertical = 8.dp),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.Center
                    ) {
                        Icon(
                            Icons.Default.Warning,
                            contentDescription = null,
                            tint = Color(0xFFF87171),
                            modifier = Modifier.size(14.dp)
                        )
                        Spacer(Modifier.width(6.dp))
                        Text(
                            "Server offline \u2014 Retrying\u2026",
                            color = Color(0xFFF87171),
                            fontSize = 12.sp
                        )
                    }
                }
            }

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
            CoverArtOrVinyl(coverArtUrl = state.coverArtUrl, isPlaying = state.isPlaying, accentColor = accent, accentLight = accentLight)

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
                    color = accent
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
                        color = accentLight,
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
                    accentColor = accent,
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
                        thumbColor = accent,
                        activeTrackColor = accent,
                        inactiveTrackColor = Color(0xFF333355)
                    )
                )

                Spacer(Modifier.width(8.dp))

                // Play/Stop
                Box(
                    modifier = Modifier
                        .size(60.dp)
                        .shadow(12.dp, CircleShape, ambientColor = accent.copy(alpha = 0.35f))
                        .clip(CircleShape)
                        .background(Brush.radialGradient(listOf(accentLight, accent))),
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
                            else accent.copy(alpha = 0.12f)
                        )
                ) {
                    Icon(
                        Icons.Default.MusicNote,
                        contentDescription = "Request a Song",
                        tint = if (state.isEmergency) TextDim else accent,
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
                    accentColor = accent,
                    modifier = Modifier.padding(top = 8.dp)
                )
            }

            // Footer
            Spacer(Modifier.height(16.dp))
            var showAbout by remember { mutableStateOf(false) }
            val uriHandler = LocalUriHandler.current
            Row(
                horizontalArrangement = Arrangement.Center,
                verticalAlignment = Alignment.CenterVertically,
                modifier = Modifier.fillMaxWidth()
            ) {
                val footerText = buildAnnotatedString {
                    withStyle(SpanStyle(color = TextSecondary)) { append("\u00A9 2026 ") }
                    pushStringAnnotation("URL", "https://downstreamtech.net")
                    withStyle(SpanStyle(color = TextSecondary, textDecoration = TextDecoration.Underline)) {
                        append("DownStreamTech")
                    }
                    pop()
                    withStyle(SpanStyle(color = TextSecondary)) { append(". All rights reserved.") }
                }
                @Suppress("DEPRECATION")
                androidx.compose.foundation.text.ClickableText(
                    text = footerText,
                    style = androidx.compose.ui.text.TextStyle(fontSize = 10.sp, textAlign = TextAlign.Center),
                    onClick = { offset ->
                        footerText.getStringAnnotations("URL", offset, offset).firstOrNull()?.let {
                            uriHandler.openUri(it.item)
                        }
                    }
                )
                Spacer(Modifier.width(6.dp))
                Box(
                    contentAlignment = Alignment.Center,
                    modifier = Modifier
                        .size(20.dp)
                        .clip(CircleShape)
                        .background(accent)
                        .clickable { showAbout = true }
                ) {
                    Text("i", color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold, fontStyle = FontStyle.Italic)
                }
            }

            if (showAbout) {
                AboutDialog(
                    baseUrl = state.baseUrl,
                    accentColor = accent,
                    onDismiss = { showAbout = false },
                    onChangeServer = {
                        showAbout = false
                        onChangeServer()
                    }
                )
            }
        }
    }
}

@Composable
fun AboutDialog(
    baseUrl: String,
    accentColor: Color = Accent,
    onDismiss: () -> Unit,
    onChangeServer: () -> Unit
) {
    val uriHandler = LocalUriHandler.current
    Dialog(onDismissRequest = onDismiss) {
        Surface(
            shape = RoundedCornerShape(16.dp),
            color = BgCard,
            modifier = Modifier.fillMaxWidth().padding(16.dp)
        ) {
            Box {
                Column(modifier = Modifier.padding(20.dp)) {
                    Text(
                        "About RendezVox",
                        color = TextPrimary,
                        fontSize = 18.sp,
                        fontWeight = FontWeight.Bold,
                        modifier = Modifier.fillMaxWidth(),
                        textAlign = TextAlign.Center
                    )
                    Spacer(Modifier.height(12.dp))
                    Text(
                        "Your personal online FM radio station \u2014 stream music, request songs, and listen live from any device, anywhere.",
                        color = TextSecondary,
                        fontSize = 13.sp,
                        lineHeight = 20.sp,
                        textAlign = TextAlign.Center
                    )
                    Spacer(Modifier.height(16.dp))

                    // Links
                    Text("LINKS", color = TextDim, fontSize = 10.sp, fontWeight = FontWeight.SemiBold)
                    Spacer(Modifier.height(4.dp))
                    Text("downstreamtech.net", color = accentColor, fontSize = 13.sp,
                        modifier = Modifier.clickable { uriHandler.openUri("https://downstreamtech.net") })
                    Text("radio.chadlinuxtech.net", color = accentColor, fontSize = 13.sp,
                        modifier = Modifier.clickable { uriHandler.openUri("https://radio.chadlinuxtech.net") })
                    Spacer(Modifier.height(12.dp))

                    // Support
                    Text("SUPPORT", color = TextDim, fontSize = 10.sp, fontWeight = FontWeight.SemiBold)
                    Spacer(Modifier.height(4.dp))
                    Text("Phone: +639177927953", color = TextSecondary, fontSize = 13.sp)
                    Text("Email: support@downstreamtech.net", color = TextSecondary, fontSize = 13.sp)
                    Spacer(Modifier.height(12.dp))

                    // Developer
                    Text("DEVELOPER", color = TextDim, fontSize = 10.sp, fontWeight = FontWeight.SemiBold)
                    Spacer(Modifier.height(4.dp))
                    Text("Engr. Richard R. Ayuyang, PhD", color = TextSecondary, fontSize = 13.sp)
                    Text("Professor II, CSU", color = TextDim, fontSize = 13.sp)
                    Spacer(Modifier.height(12.dp))

                    // Server
                    Text("SERVER", color = TextDim, fontSize = 10.sp, fontWeight = FontWeight.SemiBold)
                    Spacer(Modifier.height(4.dp))
                    Text(baseUrl, color = TextSecondary, fontSize = 13.sp)
                    Text(
                        "Change Server",
                        color = accentColor,
                        fontSize = 13.sp,
                        modifier = Modifier
                            .clickable(onClick = onChangeServer)
                            .padding(top = 2.dp)
                    )
                }
                IconButton(
                    onClick = onDismiss,
                    modifier = Modifier.align(Alignment.TopEnd).padding(4.dp)
                ) {
                    Text("\u00D7", color = Color(0xFFF87171), fontSize = 22.sp, fontWeight = FontWeight.Bold)
                }
            }
        }
    }
}

@Composable
fun CoverArtOrVinyl(coverArtUrl: String, isPlaying: Boolean, accentColor: Color = Accent, accentLight: Color = AccentLight) {
    val size = 180
    var loaded by remember(coverArtUrl) { mutableStateOf(false) }
    var failed by remember(coverArtUrl) { mutableStateOf(false) }

    val rotation by rememberInfiniteTransition(label = "cv")
        .animateFloat(0f, 360f, infiniteRepeatable(tween(4000, easing = LinearEasing)), label = "cr")

    val labelSize = (size * 0.44f).dp
    val holeSize = (size * 0.055f).dp

    Box(
        modifier = Modifier
            .size(size.dp)
            .shadow(12.dp, CircleShape, ambientColor = accentColor.copy(alpha = 0.15f))
            .clip(CircleShape)
            .background(
                Brush.radialGradient(
                    listOf(Color(0xFF1A1A1A), Color(0xFF111111), Color(0xFF0D0D0D), Color(0xFF080808))
                )
            )
            .rotate(if (isPlaying) rotation else 0f),
        contentAlignment = Alignment.Center
    ) {
        // Groove rings
        for ((ring, a) in listOf(
            (size * 0.93f).toInt() to 0.04f,
            (size * 0.85f).toInt() to 0.04f,
            (size * 0.75f).toInt() to 0.03f,
            (size * 0.65f).toInt() to 0.04f,
            (size * 0.55f).toInt() to 0.03f
        )) {
            Canvas(modifier = Modifier.size(ring.dp).align(Alignment.Center)) {
                drawCircle(Color.White.copy(alpha = a), style = Stroke(width = 1f))
            }
        }

        // Center label (cover art or accent gradient)
        Box(
            modifier = Modifier
                .size(labelSize)
                .clip(CircleShape)
                .background(Brush.radialGradient(listOf(accentLight, accentColor))),
            contentAlignment = Alignment.Center
        ) {
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
                        .size(labelSize)
                        .clip(CircleShape)
                        .then(if (loaded && !failed) Modifier else Modifier.size(0.dp))
                )
            }
        }

        // Spindle hole
        Box(
            Modifier
                .size(holeSize)
                .clip(CircleShape)
                .background(Color.Black)
        )
    }
}

@Composable
fun ProgressSection(startedAtMs: Long, durationMs: Long, accentColor: Color = Accent, modifier: Modifier = Modifier) {
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
            color = accentColor,
            trackColor = Color(0xFF333355)
        )
        Row(modifier = Modifier.fillMaxWidth().padding(top = 3.dp), horizontalArrangement = Arrangement.SpaceBetween) {
            Text(fmtMs(elapsedMs), color = TextDim, fontSize = 10.sp)
            Text("-${fmtMs((durationMs - elapsedMs).coerceAtLeast(0))}", color = TextDim, fontSize = 10.sp)
        }
    }
}

@Composable
fun DedicationCard(name: String?, message: String?, accentColor: Color = Accent, modifier: Modifier = Modifier) {
    Card(
        modifier = modifier.fillMaxWidth(),
        shape = RoundedCornerShape(10.dp),
        colors = CardDefaults.cardColors(containerColor = dedicationBg(accentColor)),
        border = androidx.compose.foundation.BorderStroke(1.dp, dedicationBorder(accentColor))
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
