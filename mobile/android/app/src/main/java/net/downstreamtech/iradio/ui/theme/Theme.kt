package net.downstreamtech.iradio.ui.theme

import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

val BgDark = Color(0xFF0F0F0F)
val BgCard = Color(0xFF1A1A2E)
val Accent = Color(0xFF6C63FF)
val AccentLight = Color(0xFF7C74FF)
val TextPrimary = Color(0xFFE0E0E0)
val TextSecondary = Color(0xFF9CA3AF)
val TextDim = Color(0xFF555555)
val SurfaceElevated = Color(0xFF2D2D44)
val SuccessGreen = Color(0xFF4ADE80)
val ErrorRed = Color(0xFFF87171)
val DedicationBg = Color(0x1A6C63FF)
val DedicationBorder = Color(0x406C63FF)
val DedicationText = Color(0xFFC4B5FD)

private val DarkColorScheme = darkColorScheme(
    primary = Accent,
    onPrimary = Color.White,
    secondary = AccentLight,
    background = BgDark,
    surface = BgCard,
    onBackground = TextPrimary,
    onSurface = TextPrimary,
    surfaceVariant = SurfaceElevated,
    onSurfaceVariant = TextSecondary,
    outline = Color(0xFF333333),
    error = ErrorRed,
)

@Composable
fun IRadioTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = DarkColorScheme,
        content = content
    )
}
