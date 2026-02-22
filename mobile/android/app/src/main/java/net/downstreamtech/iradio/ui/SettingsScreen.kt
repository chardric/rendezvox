package net.downstreamtech.iradio.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import net.downstreamtech.iradio.ui.theme.*

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun SettingsScreen(
    currentUrl: String,
    onSave: (String) -> Unit,
    onBack: () -> Unit
) {
    var url by remember { mutableStateOf(currentUrl) }
    var error by remember { mutableStateOf("") }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .background(BgDark)
    ) {
        TopAppBar(
            title = { Text("Settings", color = TextPrimary) },
            navigationIcon = {
                IconButton(onClick = onBack) {
                    Icon(
                        Icons.AutoMirrored.Filled.ArrowBack,
                        contentDescription = "Back",
                        tint = TextPrimary
                    )
                }
            },
            colors = TopAppBarDefaults.topAppBarColors(containerColor = BgCard)
        )

        Column(
            modifier = Modifier
                .fillMaxWidth()
                .padding(24.dp)
        ) {
            Text("Server Connection", color = TextPrimary, fontSize = 18.sp)
            Spacer(Modifier.height(16.dp))

            Text("Server URL", color = TextSecondary, fontSize = 13.sp)
            Spacer(Modifier.height(4.dp))
            OutlinedTextField(
                value = url,
                onValueChange = {
                    url = it
                    error = ""
                },
                placeholder = { Text("radio.example.com", color = TextDim) },
                modifier = Modifier.fillMaxWidth(),
                singleLine = true,
                colors = OutlinedTextFieldDefaults.colors(
                    focusedBorderColor = Accent,
                    unfocusedBorderColor = MaterialTheme.colorScheme.outline,
                    cursorColor = Accent,
                    focusedTextColor = TextPrimary,
                    unfocusedTextColor = TextPrimary
                )
            )

            if (error.isNotBlank()) {
                Spacer(Modifier.height(8.dp))
                Text(error, color = ErrorRed, fontSize = 13.sp)
            }

            Spacer(Modifier.height(8.dp))
            Text(
                "Enter a hostname or IP address",
                color = TextDim,
                fontSize = 12.sp
            )

            Spacer(Modifier.height(24.dp))

            Button(
                onClick = {
                    var cleaned = url.trim().trimEnd('/')
                    if (cleaned.isBlank() || cleaned == "http://" || cleaned == "https://") {
                        error = "Enter a valid server URL"
                        return@Button
                    }
                    if (!cleaned.startsWith("http://") && !cleaned.startsWith("https://")) {
                        cleaned = "https://$cleaned"
                    }
                    onSave(cleaned)
                },
                modifier = Modifier.fillMaxWidth(),
                colors = ButtonDefaults.buttonColors(containerColor = Accent),
                shape = RoundedCornerShape(12.dp)
            ) {
                Text("Save & Reconnect", fontSize = 16.sp, modifier = Modifier.padding(vertical = 4.dp))
            }

            Spacer(Modifier.height(48.dp))

            // App info
            Text("iRadio for Android", color = TextDim, fontSize = 13.sp)
            Text("Version 1.0.0", color = TextDim.copy(alpha = 0.6f), fontSize = 12.sp)
            Spacer(Modifier.height(4.dp))
            Text(
                "\u00A9 2026 DownStreamTech",
                color = TextDim.copy(alpha = 0.6f),
                fontSize = 12.sp
            )
        }
    }
}
