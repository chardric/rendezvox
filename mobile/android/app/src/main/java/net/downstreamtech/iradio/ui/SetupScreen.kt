package net.downstreamtech.iradio.ui

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CellTower
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import net.downstreamtech.iradio.ui.theme.*

@Composable
fun SetupScreen(onConnect: (String) -> Unit) {
    var url by remember { mutableStateOf("") }
    var error by remember { mutableStateOf("") }

    Box(
        modifier = Modifier
            .fillMaxSize()
            .padding(32.dp),
        contentAlignment = Alignment.Center
    ) {
        Card(
            shape = RoundedCornerShape(20.dp),
            colors = CardDefaults.cardColors(containerColor = BgCard),
            elevation = CardDefaults.cardElevation(defaultElevation = 8.dp)
        ) {
            Column(
                modifier = Modifier.padding(28.dp),
                horizontalAlignment = Alignment.CenterHorizontally
            ) {
                Icon(
                    Icons.Default.CellTower,
                    contentDescription = null,
                    tint = Accent,
                    modifier = Modifier.size(56.dp)
                )

                Spacer(Modifier.height(16.dp))

                Text(
                    "Connect to Station",
                    color = TextPrimary,
                    fontSize = 22.sp
                )

                Spacer(Modifier.height(8.dp))

                Text(
                    "Enter your iRadio server address to start listening",
                    color = TextSecondary,
                    fontSize = 14.sp,
                    textAlign = TextAlign.Center
                )

                Spacer(Modifier.height(24.dp))

                OutlinedTextField(
                    value = url,
                    onValueChange = {
                        url = it
                        error = ""
                    },
                    label = { Text("Server URL") },
                    placeholder = { Text("radio.example.com", color = TextDim) },
                    modifier = Modifier.fillMaxWidth(),
                    singleLine = true,
                    colors = OutlinedTextFieldDefaults.colors(
                        focusedBorderColor = Accent,
                        unfocusedBorderColor = MaterialTheme.colorScheme.outline,
                        cursorColor = Accent,
                        focusedTextColor = TextPrimary,
                        unfocusedTextColor = TextPrimary,
                        focusedLabelColor = Accent,
                        unfocusedLabelColor = TextSecondary
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
                    fontSize = 12.sp,
                    textAlign = TextAlign.Center
                )

                Spacer(Modifier.height(24.dp))

                Button(
                    onClick = {
                        var cleaned = url.trim().trimEnd('/')
                        if (cleaned.isBlank() || cleaned == "http://" || cleaned == "https://") {
                            error = "Enter a valid server URL"
                            return@Button
                        }
                        // Auto-prepend https:// for bare hostnames
                        if (!cleaned.startsWith("http://") && !cleaned.startsWith("https://")) {
                            cleaned = "https://$cleaned"
                        }
                        onConnect(cleaned)
                    },
                    modifier = Modifier.fillMaxWidth(),
                    colors = ButtonDefaults.buttonColors(containerColor = Accent),
                    shape = RoundedCornerShape(12.dp)
                ) {
                    Text("Connect", fontSize = 16.sp, modifier = Modifier.padding(vertical = 4.dp))
                }
            }
        }
    }
}
