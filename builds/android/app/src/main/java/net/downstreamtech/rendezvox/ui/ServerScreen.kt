package net.downstreamtech.rendezvox.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeoutOrNull
import net.downstreamtech.rendezvox.data.RadioApi
import net.downstreamtech.rendezvox.data.ServerPrefs
import net.downstreamtech.rendezvox.ui.theme.*

@Composable
fun ServerScreen(onConnected: (String) -> Unit) {
    var choice by remember { mutableStateOf("official") }
    var customUrl by remember { mutableStateOf("") }
    var error by remember { mutableStateOf("") }
    var isConnecting by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()

    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(
                Brush.verticalGradient(
                    colors = listOf(Color(0xFF0D0D1A), BgDark, BgDark)
                )
            ),
        contentAlignment = Alignment.Center
    ) {
        Column(
            modifier = Modifier
                .widthIn(max = 340.dp)
                .padding(horizontal = 28.dp),
            horizontalAlignment = Alignment.CenterHorizontally
        ) {
            Text(
                "RendezVox",
                color = TextPrimary,
                fontSize = 30.sp,
                fontWeight = FontWeight.Bold,
                letterSpacing = 0.5.sp
            )
            Text(
                "Connect to a server",
                color = TextSecondary,
                fontSize = 14.sp,
                modifier = Modifier.padding(top = 4.dp, bottom = 28.dp)
            )

            // Official Server option
            ServerOption(
                label = "Official Server",
                subtitle = "radio.chadlinuxtech.net",
                selected = choice == "official",
                onClick = { choice = "official" }
            )

            Spacer(Modifier.height(8.dp))

            // Custom Server option
            ServerOption(
                label = "Custom Server",
                subtitle = null,
                selected = choice == "custom",
                onClick = { choice = "custom" }
            )

            // Custom URL input
            if (choice == "custom") {
                Spacer(Modifier.height(8.dp))
                OutlinedTextField(
                    value = customUrl,
                    onValueChange = { customUrl = it; error = "" },
                    placeholder = { Text("https://your-server.com", color = TextDim, fontSize = 13.sp) },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                    colors = OutlinedTextFieldDefaults.colors(
                        focusedBorderColor = Accent,
                        unfocusedBorderColor = Color(0xFF333355),
                        cursorColor = Accent,
                        focusedTextColor = TextPrimary,
                        unfocusedTextColor = TextPrimary
                    ),
                    shape = RoundedCornerShape(10.dp)
                )
            }

            Spacer(Modifier.height(16.dp))

            // Connect button
            Button(
                onClick = {
                    val url = if (choice == "official") ServerPrefs.DEFAULT_URL
                    else customUrl.trim().trimEnd('/')

                    if (choice == "custom" && url.isBlank()) {
                        error = "Please enter a server URL"
                        return@Button
                    }
                    if (choice == "custom" && !url.matches(Regex("^https?://.+", RegexOption.IGNORE_CASE))) {
                        error = "URL must start with http:// or https://"
                        return@Button
                    }

                    isConnecting = true
                    error = ""
                    scope.launch {
                        val success = testConnection(url)
                        isConnecting = false
                        if (success) {
                            onConnected(url)
                        } else {
                            error = "Could not connect to server — check the URL and try again"
                        }
                    }
                },
                enabled = !isConnecting,
                modifier = Modifier.fillMaxWidth().height(48.dp),
                shape = RoundedCornerShape(12.dp),
                colors = ButtonDefaults.buttonColors(containerColor = Accent)
            ) {
                if (isConnecting) {
                    CircularProgressIndicator(
                        modifier = Modifier.size(20.dp),
                        color = Color.White,
                        strokeWidth = 2.dp
                    )
                } else {
                    Text("Connect", fontSize = 15.sp, fontWeight = FontWeight.SemiBold)
                }
            }

            // Error
            if (error.isNotEmpty()) {
                Text(
                    error,
                    color = Color(0xFFF87171),
                    fontSize = 12.sp,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.padding(top = 10.dp)
                )
            }
        }
    }
}

@Composable
private fun ServerOption(
    label: String,
    subtitle: String?,
    selected: Boolean,
    onClick: () -> Unit
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(10.dp))
            .border(
                1.dp,
                if (selected) Accent else Color(0xFF2A2A40),
                RoundedCornerShape(10.dp)
            )
            .background(BgCard)
            .clickable(onClick = onClick)
            .padding(horizontal = 14.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically
    ) {
        RadioButton(
            selected = selected,
            onClick = onClick,
            colors = RadioButtonDefaults.colors(
                selectedColor = Accent,
                unselectedColor = TextDim
            ),
            modifier = Modifier.size(20.dp)
        )
        Spacer(Modifier.width(10.dp))
        Column {
            Text(label, color = TextPrimary, fontSize = 14.sp, fontWeight = FontWeight.SemiBold)
            if (subtitle != null) {
                Text(subtitle, color = TextDim, fontSize = 11.sp, modifier = Modifier.padding(top = 2.dp))
            }
        }
    }
}

private suspend fun testConnection(url: String): Boolean = withContext(Dispatchers.IO) {
    try {
        val result = withTimeoutOrNull(5000L) {
            val api = RadioApi(url)
            api.fetchConfig()
        }
        result != null
    } catch (_: Exception) {
        false
    }
}
