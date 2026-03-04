package net.downstreamtech.rendezvox.ui

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import net.downstreamtech.rendezvox.data.ScheduleItem
import net.downstreamtech.rendezvox.ui.theme.*
import java.text.SimpleDateFormat
import java.util.*

@Composable
fun ScheduleDialog(
    schedules: List<ScheduleItem>,
    accentColor: Color = Accent,
    onDismiss: () -> Unit
) {
    val tz = TimeZone.getTimeZone("Asia/Manila")
    val cal = Calendar.getInstance(tz)
    val todayDow = cal.get(Calendar.DAY_OF_WEEK) - 1 // Calendar.SUNDAY=1, we want 0-based

    // Group by day
    val byDay = (0..6).associateWith { mutableListOf<ScheduleItem>() }
    schedules.forEach { s ->
        val days = s.days_of_week
        if (days == null) {
            for (d in 0..6) byDay[d]?.add(s)
        } else {
            days.forEach { d -> byDay[d]?.add(s) }
        }
    }

    val dayNames = listOf("Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday")

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
                        "Weekly Schedule",
                        color = TextPrimary,
                        fontSize = 16.sp,
                        fontWeight = FontWeight.Bold,
                        modifier = Modifier.padding(bottom = 14.dp)
                    )

                    if (schedules.isEmpty()) {
                        Text(
                            "No schedules configured.",
                            color = TextDim,
                            fontSize = 12.sp,
                            fontStyle = FontStyle.Italic
                        )
                    } else {
                        for (i in 0..6) {
                            val dow = (todayDow + i) % 7
                            val isToday = i == 0
                            val items = byDay[dow] ?: emptyList()

                            // Day title
                            Row(
                                verticalAlignment = Alignment.CenterVertically,
                                modifier = Modifier.padding(bottom = 6.dp, top = if (i > 0) 10.dp else 0.dp)
                            ) {
                                Text(
                                    dayNames[dow].uppercase(),
                                    color = TextPrimary,
                                    fontSize = 11.sp,
                                    fontWeight = FontWeight.Bold,
                                    letterSpacing = 0.5.sp
                                )
                                if (isToday) {
                                    Spacer(Modifier.width(6.dp))
                                    Box(
                                        modifier = Modifier
                                            .background(accentColor, RoundedCornerShape(8.dp))
                                            .padding(horizontal = 6.dp, vertical = 1.dp)
                                    ) {
                                        Text("TODAY", color = Color.White, fontSize = 9.sp, fontWeight = FontWeight.Bold)
                                    }
                                }
                            }

                            if (items.isEmpty()) {
                                Text(
                                    "No scheduled programs",
                                    color = TextDim,
                                    fontSize = 11.sp,
                                    fontStyle = FontStyle.Italic,
                                    modifier = Modifier.padding(bottom = 4.dp)
                                )
                            } else {
                                items.forEach { s ->
                                    val color = parseHexColor(s.playlist_color ?: "#666666")
                                    val showNow = isToday && isNowActive(s.start_time, s.end_time, tz)

                                    Row(
                                        verticalAlignment = Alignment.CenterVertically,
                                        modifier = Modifier.padding(vertical = 3.dp)
                                    ) {
                                        Canvas(modifier = Modifier.size(8.dp)) {
                                            drawCircle(color)
                                        }
                                        Spacer(Modifier.width(8.dp))
                                        Text(
                                            "${fmtTime(s.start_time)} \u2013 ${fmtTime(s.end_time)}",
                                            color = TextSecondary,
                                            fontSize = 11.sp
                                        )
                                        Spacer(Modifier.width(8.dp))
                                        Text(
                                            s.name.ifBlank { s.playlist_name },
                                            color = TextPrimary,
                                            fontSize = 11.sp,
                                            modifier = Modifier.weight(1f)
                                        )
                                        if (showNow) {
                                            Spacer(Modifier.width(6.dp))
                                            Box(
                                                modifier = Modifier
                                                    .background(accentColor, RoundedCornerShape(6.dp))
                                                    .padding(horizontal = 5.dp, vertical = 1.dp)
                                            ) {
                                                Text("NOW", color = Color.White, fontSize = 8.sp, fontWeight = FontWeight.Bold)
                                            }
                                        }
                                    }
                                }
                            }
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

private fun fmtTime(t: String): String {
    if (t.isBlank()) return ""
    val parts = t.split(":")
    var h = parts.getOrNull(0)?.toIntOrNull() ?: return t
    val m = parts.getOrNull(1) ?: "00"
    val ampm = if (h >= 12) "PM" else "AM"
    if (h == 0) h = 12
    else if (h > 12) h -= 12
    return "$h:$m $ampm"
}

private fun isNowActive(start: String, end: String, tz: TimeZone): Boolean {
    val cal = Calendar.getInstance(tz)
    val nowMin = cal.get(Calendar.HOUR_OF_DAY) * 60 + cal.get(Calendar.MINUTE)

    val sParts = start.split(":")
    val sMin = (sParts.getOrNull(0)?.toIntOrNull() ?: 0) * 60 + (sParts.getOrNull(1)?.toIntOrNull() ?: 0)
    val eParts = end.split(":")
    val eMin = (eParts.getOrNull(0)?.toIntOrNull() ?: 0) * 60 + (eParts.getOrNull(1)?.toIntOrNull() ?: 0)

    return if (eMin <= sMin) nowMin >= sMin || nowMin < eMin
    else nowMin in sMin until eMin
}
