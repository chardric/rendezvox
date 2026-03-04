package net.downstreamtech.rendezvox.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.rotate
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import net.downstreamtech.rendezvox.ui.theme.*

data class EqState(
    val preset: String = "flat",
    val spatialMode: String = "off",
    val bands: List<Int> = List(10) { 0 },
    val isAvailable: Boolean = false,
    val bandCount: Int = 10,
    val freqLabels: List<String> = listOf("32","64","125","250","500","1K","2K","4K","8K","16K")
)

@Suppress("DEPRECATION")
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EqualizerDialog(
    eqState: EqState,
    accentColor: Color = Accent,
    onPresetChange: (String) -> Unit,
    onSpatialChange: (String) -> Unit,
    onBandChange: (Int, Int) -> Unit,
    onReset: () -> Unit,
    onDismiss: () -> Unit
) {
    val presets = listOf(
        "flat" to "Flat",
        "bass_boost" to "Bass Boost",
        "treble_boost" to "Treble Boost",
        "vocal" to "Vocal",
        "rock" to "Rock",
        "pop" to "Pop",
        "jazz" to "Jazz",
        "classical" to "Classical",
        "loudness" to "Loudness",
        "small_speakers" to "Small Speakers",
        "earphones" to "Earphones",
        "headphones" to "Headphones",
        "custom" to "Custom"
    )

    val spatialModes = listOf(
        "off" to "Off",
        "stereo_wide" to "Stereo Wide",
        "surround" to "Surround",
        "crossfeed" to "Crossfeed"
    )

    Dialog(onDismissRequest = onDismiss) {
        Surface(
            shape = RoundedCornerShape(16.dp),
            color = BgCard,
            modifier = Modifier.fillMaxWidth().padding(8.dp)
        ) {
            Box {
                Column(
                    modifier = Modifier
                        .padding(20.dp)
                        .verticalScroll(rememberScrollState())
                ) {
                    Text(
                        "Equalizer",
                        color = TextPrimary,
                        fontSize = 16.sp,
                        fontWeight = FontWeight.Bold,
                        modifier = Modifier.padding(bottom = 14.dp)
                    )

                    if (!eqState.isAvailable) {
                        Text(
                            "EQ requires audio playback to be active.",
                            color = TextDim,
                            fontSize = 12.sp,
                            fontStyle = FontStyle.Italic,
                            textAlign = TextAlign.Center,
                            modifier = Modifier.fillMaxWidth().padding(vertical = 16.dp)
                        )
                    } else {
                        // Preset dropdown
                        var presetExpanded by remember { mutableStateOf(false) }
                        Text("PRESET", color = TextDim, fontSize = 10.sp, fontWeight = FontWeight.SemiBold, letterSpacing = 0.5.sp)
                        Spacer(Modifier.height(4.dp))
                        ExposedDropdownMenuBox(
                            expanded = presetExpanded,
                            onExpandedChange = { presetExpanded = it }
                        ) {
                            OutlinedTextField(
                                value = presets.find { it.first == eqState.preset }?.second ?: "Flat",
                                onValueChange = {},
                                readOnly = true,
                                trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(presetExpanded) },
                                modifier = Modifier.menuAnchor().fillMaxWidth(),
                                singleLine = true,
                                colors = OutlinedTextFieldDefaults.colors(
                                    focusedBorderColor = accentColor,
                                    unfocusedBorderColor = Color(0xFF333355),
                                    focusedTextColor = TextPrimary,
                                    unfocusedTextColor = TextPrimary
                                ),
                                textStyle = LocalTextStyle.current.copy(fontSize = 13.sp)
                            )
                            ExposedDropdownMenu(
                                expanded = presetExpanded,
                                onDismissRequest = { presetExpanded = false }
                            ) {
                                presets.forEach { (key, label) ->
                                    DropdownMenuItem(
                                        text = { Text(label, fontSize = 13.sp) },
                                        onClick = {
                                            onPresetChange(key)
                                            presetExpanded = false
                                        }
                                    )
                                }
                            }
                        }

                        Spacer(Modifier.height(12.dp))

                        // Spatial dropdown
                        var spatialExpanded by remember { mutableStateOf(false) }
                        Text("SPATIAL", color = TextDim, fontSize = 10.sp, fontWeight = FontWeight.SemiBold, letterSpacing = 0.5.sp)
                        Spacer(Modifier.height(4.dp))
                        ExposedDropdownMenuBox(
                            expanded = spatialExpanded,
                            onExpandedChange = { spatialExpanded = it }
                        ) {
                            OutlinedTextField(
                                value = spatialModes.find { it.first == eqState.spatialMode }?.second ?: "Off",
                                onValueChange = {},
                                readOnly = true,
                                trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(spatialExpanded) },
                                modifier = Modifier.menuAnchor().fillMaxWidth(),
                                singleLine = true,
                                colors = OutlinedTextFieldDefaults.colors(
                                    focusedBorderColor = accentColor,
                                    unfocusedBorderColor = Color(0xFF333355),
                                    focusedTextColor = TextPrimary,
                                    unfocusedTextColor = TextPrimary
                                ),
                                textStyle = LocalTextStyle.current.copy(fontSize = 13.sp)
                            )
                            ExposedDropdownMenu(
                                expanded = spatialExpanded,
                                onDismissRequest = { spatialExpanded = false }
                            ) {
                                spatialModes.forEach { (key, label) ->
                                    DropdownMenuItem(
                                        text = { Text(label, fontSize = 13.sp) },
                                        onClick = {
                                            onSpatialChange(key)
                                            spatialExpanded = false
                                        }
                                    )
                                }
                            }
                        }

                        Spacer(Modifier.height(16.dp))

                        // Band sliders
                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.SpaceEvenly
                        ) {
                            eqState.bands.forEachIndexed { idx, gain ->
                                Column(
                                    horizontalAlignment = Alignment.CenterHorizontally,
                                    modifier = Modifier.width(30.dp)
                                ) {
                                    // Gain value label
                                    Text(
                                        if (gain > 0) "+$gain" else "$gain",
                                        color = TextDim,
                                        fontSize = 9.sp,
                                        textAlign = TextAlign.Center
                                    )

                                    // Vertical slider (rotated horizontal slider)
                                    Box(
                                        modifier = Modifier
                                            .height(100.dp)
                                            .width(30.dp),
                                        contentAlignment = Alignment.Center
                                    ) {
                                        Slider(
                                            value = gain.toFloat(),
                                            onValueChange = { onBandChange(idx, it.toInt()) },
                                            valueRange = -12f..12f,
                                            steps = 23,
                                            modifier = Modifier
                                                .width(100.dp)
                                                .rotate(270f),
                                            colors = SliderDefaults.colors(
                                                thumbColor = accentColor,
                                                activeTrackColor = accentColor,
                                                inactiveTrackColor = Color(0xFF333355)
                                            )
                                        )
                                    }

                                    // Frequency label
                                    Text(
                                        eqState.freqLabels.getOrElse(idx) { "" },
                                        color = TextDim,
                                        fontSize = 8.sp,
                                        textAlign = TextAlign.Center
                                    )
                                }
                            }
                        }

                        Spacer(Modifier.height(12.dp))

                        // Reset button
                        OutlinedButton(
                            onClick = onReset,
                            modifier = Modifier.fillMaxWidth(),
                            shape = RoundedCornerShape(8.dp),
                            colors = ButtonDefaults.outlinedButtonColors(contentColor = TextSecondary),
                            border = androidx.compose.foundation.BorderStroke(1.dp, Color(0xFF333333))
                        ) {
                            Text("Reset to Flat", fontSize = 12.sp)
                        }
                    }
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
