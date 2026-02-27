package net.downstreamtech.rendezvox.ui

import androidx.compose.animation.*
import androidx.compose.animation.core.*
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.basicMarquee
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
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.blur
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.rotate
import androidx.compose.ui.draw.scale
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
import net.downstreamtech.rendezvox.data.RecentPlay
import net.downstreamtech.rendezvox.ui.theme.*
import java.util.Calendar

@Composable
fun PlayerScreen(
    state: NowPlayingState,
    onTogglePlayback: () -> Unit,
    onVolumeChange: (Float) -> Unit,
    onRequestSong: () -> Unit,
    onChangeServer: () -> Unit = {},
    onToggleHistory: () -> Unit = {}
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
        // B7: Time-of-day ambient overlay
        AmbientOverlay()

        // B1: Blurred cover art background
        if (state.coverArtUrl.isNotEmpty()) {
            Crossfade(targetState = state.coverArtUrl, label = "bgArt") { url ->
                AsyncImage(
                    model = ImageRequest.Builder(LocalContext.current)
                        .data(url)
                        .crossfade(true)
                        .build(),
                    contentDescription = null,
                    contentScale = ContentScale.Crop,
                    modifier = Modifier
                        .fillMaxSize()
                        .scale(1.3f)
                        .blur(40.dp)
                        .alpha(0.3f)
                )
            }
        }

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

            // Cover art / turntable
            Turntable(
                coverArtUrl = state.coverArtUrl,
                isPlaying = state.isPlaying,
                isBuffering = state.isBuffering,
                durationMs = state.durationMs,
                startedAtMs = state.startedAtMs,
                accentColor = accent,
                accentLight = accentLight,
                onTogglePlayback = onTogglePlayback
            )

            // B2: Equalizer bars
            EqualizerBars(isPlaying = state.isPlaying, accentColor = accent)

            Spacer(Modifier.height(6.dp))

            // Now playing with B4 song change animation
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
                // B4: Song change animation
                AnimatedContent(
                    targetState = state.songId,
                    transitionSpec = {
                        (fadeIn(tween(400)) + slideInVertically(tween(400)) { it / 4 })
                            .togetherWith(fadeOut(tween(200)))
                    },
                    label = "songChange"
                ) { _ ->
                    Column(horizontalAlignment = Alignment.CenterHorizontally) {
                        // B3: Marquee for long titles
                        Text(
                            state.songTitle,
                            color = TextPrimary,
                            fontSize = 19.sp,
                            fontWeight = FontWeight.Bold,
                            textAlign = TextAlign.Center,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis,
                            lineHeight = 24.sp,
                            modifier = Modifier.basicMarquee(
                                iterations = Int.MAX_VALUE,
                                velocity = 30.dp
                            )
                        )
                        if (state.songArtist.isNotBlank()) {
                            Text(
                                state.songArtist,
                                color = accentLight,
                                fontSize = 14.sp,
                                textAlign = TextAlign.Center,
                                maxLines = 1,
                                overflow = TextOverflow.Ellipsis,
                                modifier = Modifier
                                    .padding(top = 3.dp)
                                    .basicMarquee(
                                        iterations = Int.MAX_VALUE,
                                        velocity = 30.dp
                                    )
                            )
                        }
                    }
                }
            }

            // Volume row
            Spacer(Modifier.height(14.dp))
            Row(
                modifier = Modifier.fillMaxWidth(),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.Center
            ) {
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
                    // Listeners + B6: Live badge
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Default.Headphones, null, tint = TextDim, modifier = Modifier.size(14.dp))
                        Spacer(Modifier.width(6.dp))
                        if (state.isPlaying) {
                            LiveBadge()
                            Spacer(Modifier.width(4.dp))
                        }
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

            // B5: Recently played
            RecentlyPlayedSection(
                recentPlays = state.recentPlays,
                isExpanded = state.showHistory,
                onToggle = onToggleHistory,
                modifier = Modifier.padding(top = 8.dp)
            )

            // Request a Song button
            Button(
                onClick = onRequestSong,
                enabled = !state.isEmergency,
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(top = 8.dp),
                shape = RoundedCornerShape(8.dp),
                colors = ButtonDefaults.buttonColors(
                    containerColor = BgCard,
                    contentColor = TextSecondary,
                    disabledContainerColor = BgCard.copy(alpha = 0.4f),
                    disabledContentColor = TextDim
                ),
                border = androidx.compose.foundation.BorderStroke(1.dp, Color(0xFF232326))
            ) {
                Text(
                    if (state.isEmergency) "Requests Unavailable" else "Request a Song",
                    fontSize = 13.sp
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

// B2: Equalizer bars
@Composable
fun EqualizerBars(isPlaying: Boolean, accentColor: Color) {
    val transition = rememberInfiniteTransition(label = "eq")
    val durations = listOf(700, 500, 600, 800, 550)
    val offsets = listOf(50, 100, 0, 75, 25)
    val animated = durations.mapIndexed { i, dur ->
        transition.animateFloat(
            initialValue = 0.2f,
            targetValue = 1.0f,
            animationSpec = infiniteRepeatable(
                tween(dur, easing = FastOutSlowInEasing),
                RepeatMode.Reverse,
                StartOffset(offsets[i])
            ),
            label = "eq$i"
        )
    }

    Row(
        horizontalArrangement = Arrangement.spacedBy(3.dp),
        verticalAlignment = Alignment.Bottom,
        modifier = Modifier
            .height(16.dp)
            .padding(top = 4.dp)
    ) {
        animated.forEach { anim ->
            val height = if (isPlaying) anim.value else 0.2f
            Box(
                Modifier
                    .width(3.dp)
                    .fillMaxHeight(height)
                    .clip(RoundedCornerShape(1.dp))
                    .alpha(0.7f)
                    .background(accentColor)
            )
        }
    }
}

// B6: Live listener pulse badge
@Composable
fun LiveBadge() {
    val pulse by rememberInfiniteTransition(label = "live")
        .animateFloat(
            1f, 0.3f,
            infiniteRepeatable(tween(1500, easing = LinearEasing), RepeatMode.Reverse),
            label = "livePulse"
        )
    Row(verticalAlignment = Alignment.CenterVertically) {
        Canvas(modifier = Modifier.size(6.dp).alpha(pulse)) {
            drawCircle(Color(0xFFFF4444))
        }
        Spacer(Modifier.width(3.dp))
        Text("LIVE", color = Color(0xFFFF4444), fontSize = 9.sp, fontWeight = FontWeight.Bold, letterSpacing = 0.5.sp)
    }
}

// B5: Recently played section
@Composable
fun RecentlyPlayedSection(
    recentPlays: List<RecentPlay>,
    isExpanded: Boolean,
    onToggle: () -> Unit,
    modifier: Modifier = Modifier
) {
    Column(modifier = modifier.fillMaxWidth()) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .clickable(onClick = onToggle)
                .padding(vertical = 6.dp),
            horizontalArrangement = Arrangement.Center,
            verticalAlignment = Alignment.CenterVertically
        ) {
            Text("Recently Played", color = TextDim, fontSize = 11.sp)
            Spacer(Modifier.width(4.dp))
            val rotation by animateFloatAsState(
                targetValue = if (isExpanded) 180f else 0f,
                animationSpec = tween(300),
                label = "arrow"
            )
            Text(
                "\u25BE",
                color = TextDim,
                fontSize = 8.sp,
                modifier = Modifier.rotate(rotation)
            )
        }

        AnimatedVisibility(
            visible = isExpanded,
            enter = expandVertically(tween(400)) + fadeIn(tween(400)),
            exit = shrinkVertically(tween(300)) + fadeOut(tween(200))
        ) {
            Column {
                if (recentPlays.isEmpty()) {
                    Text(
                        "No recent tracks",
                        color = TextDim,
                        fontSize = 11.sp,
                        modifier = Modifier.padding(vertical = 4.dp).fillMaxWidth(),
                        textAlign = TextAlign.Center
                    )
                } else {
                    recentPlays.forEach { play ->
                        Column(
                            modifier = Modifier
                                .fillMaxWidth()
                                .padding(vertical = 4.dp)
                        ) {
                            Text(play.title, color = TextSecondary, fontSize = 11.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            Text(play.artist, color = TextDim, fontSize = 10.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        }
                        HorizontalDivider(color = Color(0xFF2A2A40), thickness = 0.5.dp)
                    }
                }
            }
        }
    }
}

// B7: Time-of-day ambient color overlay
@Composable
fun AmbientOverlay() {
    var ambientColor by remember { mutableStateOf(getAmbientColor()) }
    LaunchedEffect(Unit) {
        while (true) {
            ambientColor = getAmbientColor()
            delay(1_800_000)
        }
    }
    if (ambientColor != Color.Transparent) {
        Box(
            Modifier
                .fillMaxSize()
                .background(
                    Brush.radialGradient(
                        colors = listOf(ambientColor, Color.Transparent),
                        radius = 800f
                    )
                )
        )
    }
}

private fun getAmbientColor(): Color {
    val h = Calendar.getInstance().get(Calendar.HOUR_OF_DAY)
    return when {
        h >= 20 || h < 5 -> Color(0x26141E50)   // Night: blue
        h in 5..10       -> Color(0x1F503C14)    // Morning: amber
        h in 11..15      -> Color.Transparent     // Afternoon: none
        else             -> Color(0x1F50281E)     // Evening: warm orange
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
fun Turntable(
    coverArtUrl: String,
    isPlaying: Boolean,
    isBuffering: Boolean = false,
    durationMs: Long = 0,
    startedAtMs: Long = 0,
    accentColor: Color = Accent,
    accentLight: Color = AccentLight,
    onTogglePlayback: () -> Unit = {}
) {
    val vinylSize = 150
    val turntableWidth = 195

    // Vinyl rotation
    val vinylRotation by rememberInfiniteTransition(label = "cv")
        .animateFloat(0f, 360f, infiniteRepeatable(tween(4000, easing = LinearEasing)), label = "cr")

    // Tonearm angle: -20 when parked, 0..20 based on song progress
    val tonearmActive = isPlaying || isBuffering
    var tonearmAngle by remember { mutableFloatStateOf(-20f) }
    LaunchedEffect(tonearmActive, startedAtMs, durationMs) {
        if (!tonearmActive || startedAtMs <= 0 || durationMs <= 0) {
            tonearmAngle = -20f
            return@LaunchedEffect
        }
        while (true) {
            val elapsed = (System.currentTimeMillis() - startedAtMs).coerceIn(0, durationMs)
            val pct = elapsed.toFloat() / durationMs
            tonearmAngle = pct * 20f
            delay(1000)
        }
    }
    val animatedTonearm by animateFloatAsState(
        targetValue = tonearmAngle,
        animationSpec = tween(250, easing = FastOutSlowInEasing),
        label = "tonearm"
    )

    // Play hint alpha
    val playHintAlpha by animateFloatAsState(
        targetValue = if (isPlaying) 0f else 0.6f,
        animationSpec = tween(300),
        label = "playHint"
    )

    val labelSize = (vinylSize * 0.44f).dp
    val holeSize = 7.dp

    Box(
        modifier = Modifier
            .width(turntableWidth.dp)
            .height(vinylSize.dp)
            .clickable(onClick = onTogglePlayback),
        contentAlignment = Alignment.TopCenter
    ) {
        // Vinyl disc (spinning)
        Box(
            modifier = Modifier
                .size(vinylSize.dp)
                .align(Alignment.TopCenter)
                .shadow(8.dp, CircleShape, ambientColor = Color.Black.copy(alpha = 0.3f))
                .clip(CircleShape)
                .background(
                    Brush.radialGradient(
                        listOf(Color(0xFF222222), Color(0xFF181818), Color(0xFF111111), Color(0xFF0A0A0A))
                    )
                )
                .rotate(if (isPlaying) vinylRotation else 0f),
            contentAlignment = Alignment.Center
        ) {
            // Groove rings
            for ((ring, a) in listOf(
                (vinylSize * 0.93f).toInt() to 0.04f,
                (vinylSize * 0.85f).toInt() to 0.04f,
                (vinylSize * 0.75f).toInt() to 0.03f,
                (vinylSize * 0.65f).toInt() to 0.04f,
                (vinylSize * 0.55f).toInt() to 0.03f,
                (vinylSize * 0.48f).toInt() to 0.05f
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
                    var loaded by remember(coverArtUrl) { mutableStateOf(false) }
                    var failed by remember(coverArtUrl) { mutableStateOf(false) }
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

        // Specular sheen (stationary light band over spinning vinyl)
        Canvas(
            modifier = Modifier
                .size(vinylSize.dp)
                .align(Alignment.TopCenter)
        ) {
            drawCircle(
                Brush.linearGradient(
                    colorStops = arrayOf(
                        0.35f to Color.Transparent,
                        0.46f to Color.White.copy(alpha = 0.08f),
                        0.50f to Color.White.copy(alpha = 0.16f),
                        0.54f to Color.White.copy(alpha = 0.08f),
                        0.65f to Color.Transparent
                    )
                ),
                radius = size.minDimension / 2f
            )
        }

        // Play hint (triangle icon)
        if (playHintAlpha > 0.01f) {
            Icon(
                Icons.Default.PlayArrow,
                contentDescription = null,
                tint = Color.White.copy(alpha = playHintAlpha),
                modifier = Modifier
                    .size(36.dp)
                    .align(Alignment.Center)
            )
        }

        // Tonearm
        TonearmComposable(
            angle = animatedTonearm,
            modifier = Modifier.align(Alignment.TopEnd)
        )
    }
}

@Composable
fun TonearmComposable(angle: Float, modifier: Modifier = Modifier) {
    Canvas(
        modifier = modifier
            .width(36.dp)
            .height(120.dp)
            .offset(x = (-2).dp, y = (-2).dp)
    ) {
        val pivotX = size.width * 0.5f
        val pivotY = size.height * 0.1f

        // Save and rotate around pivot
        val canvas = drawContext.canvas
        canvas.save()
        canvas.translate(pivotX, pivotY)
        canvas.rotate(angle)
        canvas.translate(-pivotX, -pivotY)

        // Base plate (ellipse)
        drawOval(
            Color(0xFF444444),
            topLeft = androidx.compose.ui.geometry.Offset(pivotX - 8f, pivotY - 6f),
            size = androidx.compose.ui.geometry.Size(16f, 12f)
        )

        // Pivot hub
        drawCircle(Color(0xFF666666), radius = 5f, center = androidx.compose.ui.geometry.Offset(pivotX, pivotY + 10f))
        drawCircle(Color(0xFF555555), radius = 3.5f, center = androidx.compose.ui.geometry.Offset(pivotX, pivotY + 10f))

        // Arm shaft
        val armLeft = pivotX - 1.5f
        drawRect(
            Color(0xFFBBBBBB),
            topLeft = androidx.compose.ui.geometry.Offset(armLeft, pivotY + 12f),
            size = androidx.compose.ui.geometry.Size(3f, 80f)
        )

        // Headshell angle
        val shaftBottom = pivotY + 92f
        drawLine(
            Color(0xFFAAAAAA),
            start = androidx.compose.ui.geometry.Offset(pivotX, shaftBottom),
            end = androidx.compose.ui.geometry.Offset(pivotX - 8f, shaftBottom + 12f),
            strokeWidth = 3f,
            cap = androidx.compose.ui.graphics.StrokeCap.Round
        )

        // Cartridge body
        drawRect(
            Color(0xFF333333),
            topLeft = androidx.compose.ui.geometry.Offset(pivotX - 13f, shaftBottom + 10f),
            size = androidx.compose.ui.geometry.Size(10f, 5.5f)
        )

        // Stylus tip (red dot)
        drawCircle(
            Color(0xFFEE3333),
            radius = 1.8f,
            center = androidx.compose.ui.geometry.Offset(pivotX - 8f, shaftBottom + 18f)
        )

        canvas.restore()
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

