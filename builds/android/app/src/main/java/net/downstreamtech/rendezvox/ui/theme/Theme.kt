package net.downstreamtech.rendezvox.ui.theme

import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

val BgDark = Color(0xFF0F0F0F)
val BgCard = Color(0xFF1A1A2E)
val Accent = Color(0xFF6C63FF)
val AccentLight = Color(0xFF7C74FF)
val TextPrimary = Color(0xFFE0E0E0)
val TextSecondary = Color(0xFF9CA3AF)
val TextDim = Color(0xFF777777)
val SurfaceElevated = Color(0xFF2D2D44)
val SuccessGreen = Color(0xFF4ADE80)
val ErrorRed = Color(0xFFF87171)
val DedicationText = Color(0xFFC4B5FD)

fun parseHexColor(hex: String): Color {
    val cleaned = hex.removePrefix("#")
    if (cleaned.length != 6) return Accent
    return try {
        Color(0xFF000000 or cleaned.toLong(16))
    } catch (_: Exception) {
        Accent
    }
}

fun lightenColor(color: Color, pct: Float): Color {
    val r = (color.red + (1f - color.red) * pct).coerceIn(0f, 1f)
    val g = (color.green + (1f - color.green) * pct).coerceIn(0f, 1f)
    val b = (color.blue + (1f - color.blue) * pct).coerceIn(0f, 1f)
    return Color(r, g, b, color.alpha)
}

fun dedicationBg(accent: Color): Color = accent.copy(alpha = 0.10f)
fun dedicationBorder(accent: Color): Color = accent.copy(alpha = 0.25f)

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
fun RendezVoxTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = DarkColorScheme,
        content = content
    )
}
