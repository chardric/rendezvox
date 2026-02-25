package net.downstreamtech.rendezvox.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import net.downstreamtech.rendezvox.data.RequestBody
import net.downstreamtech.rendezvox.data.SearchSong
import net.downstreamtech.rendezvox.ui.theme.*

@Composable
fun RequestDialog(
    onDismiss: () -> Unit,
    onSearch: suspend (String, String?) -> net.downstreamtech.rendezvox.data.SearchResult,
    onSubmit: suspend (RequestBody) -> Pair<Int, net.downstreamtech.rendezvox.data.RequestResponse>
) {
    var title by remember { mutableStateOf("") }
    var artist by remember { mutableStateOf("") }
    var listenerName by remember { mutableStateOf("") }
    var message by remember { mutableStateOf("") }
    var suggestions by remember { mutableStateOf<List<SearchSong>>(emptyList()) }
    var resolvedSong by remember { mutableStateOf<SearchSong?>(null) }
    var statusMsg by remember { mutableStateOf("") }
    var isSuccess by remember { mutableStateOf(false) }
    var isSubmitting by remember { mutableStateOf(false) }

    val scope = rememberCoroutineScope()
    var searchJob by remember { mutableStateOf<Job?>(null) }

    fun doSearch() {
        searchJob?.cancel()
        searchJob = scope.launch {
            delay(350)
            val t = title.trim()
            val a = artist.trim()
            if (t.length >= 2 || a.length >= 2) {
                val result = onSearch(t.ifBlank { "" }, a.ifBlank { null })
                if (result.resolved && result.songs.isNotEmpty()) {
                    resolvedSong = result.songs.first()
                    suggestions = emptyList()
                } else {
                    suggestions = result.songs
                }
            } else {
                suggestions = emptyList()
                resolvedSong = null
            }
        }
    }

    Dialog(onDismissRequest = onDismiss) {
        Surface(
            shape = RoundedCornerShape(16.dp),
            color = BgCard,
            modifier = Modifier
                .fillMaxWidth()
                .padding(16.dp)
        ) {
            Column(modifier = Modifier.padding(20.dp)) {
                Text(
                    "Request a Song",
                    color = TextPrimary,
                    fontSize = 18.sp,
                    modifier = Modifier.padding(bottom = 16.dp)
                )

                // Status message
                if (statusMsg.isNotBlank()) {
                    Text(
                        statusMsg,
                        color = if (isSuccess) SuccessGreen else ErrorRed,
                        fontSize = 14.sp,
                        modifier = Modifier.padding(bottom = 8.dp)
                    )
                }

                // Resolved song indicator
                if (resolvedSong != null) {
                    Row(
                        modifier = Modifier
                            .fillMaxWidth()
                            .clip(RoundedCornerShape(8.dp))
                            .background(SuccessGreen.copy(alpha = 0.12f))
                            .padding(10.dp),
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        Text("\u2713 ", color = SuccessGreen, fontSize = 16.sp)
                        Text(
                            "${resolvedSong!!.title} \u2014 ${resolvedSong!!.artist}",
                            color = SuccessGreen,
                            fontSize = 14.sp
                        )
                    }
                    Spacer(Modifier.height(12.dp))
                }

                // Song title
                Text("Song Title", color = TextSecondary, fontSize = 13.sp)
                Spacer(Modifier.height(4.dp))
                OutlinedTextField(
                    value = title,
                    onValueChange = {
                        title = it
                        resolvedSong = null
                        doSearch()
                    },
                    placeholder = { Text("Start typing a song title...", color = TextDim) },
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

                // Suggestions dropdown
                if (suggestions.isNotEmpty()) {
                    LazyColumn(
                        modifier = Modifier
                            .fillMaxWidth()
                            .heightIn(max = 160.dp)
                            .clip(RoundedCornerShape(8.dp))
                            .background(BgDark)
                    ) {
                        items(suggestions) { song ->
                            Column(
                                modifier = Modifier
                                    .fillMaxWidth()
                                    .clickable {
                                        resolvedSong = song
                                        title = song.title
                                        artist = song.artist
                                        suggestions = emptyList()
                                    }
                                    .padding(horizontal = 12.dp, vertical = 8.dp)
                            ) {
                                Text(song.title, color = TextPrimary, fontSize = 14.sp)
                                Text(song.artist, color = TextDim, fontSize = 12.sp)
                            }
                            HorizontalDivider(color = MaterialTheme.colorScheme.outline.copy(alpha = 0.3f))
                        }
                    }
                }

                Spacer(Modifier.height(12.dp))

                // Artist
                Text("Artist", color = TextSecondary, fontSize = 13.sp)
                Spacer(Modifier.height(4.dp))
                OutlinedTextField(
                    value = artist,
                    onValueChange = {
                        artist = it
                        resolvedSong = null
                        doSearch()
                    },
                    placeholder = { Text("Artist name", color = TextDim) },
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

                Spacer(Modifier.height(12.dp))

                // Your Name
                Text("Your Name (optional)", color = TextSecondary, fontSize = 13.sp)
                Spacer(Modifier.height(4.dp))
                OutlinedTextField(
                    value = listenerName,
                    onValueChange = { listenerName = it },
                    placeholder = { Text("Listener name", color = TextDim) },
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

                Spacer(Modifier.height(12.dp))

                // Message
                Text("Message (optional)", color = TextSecondary, fontSize = 13.sp)
                Spacer(Modifier.height(4.dp))
                OutlinedTextField(
                    value = message,
                    onValueChange = { message = it },
                    placeholder = { Text("Dedication or note", color = TextDim) },
                    modifier = Modifier.fillMaxWidth(),
                    minLines = 2,
                    maxLines = 3,
                    colors = OutlinedTextFieldDefaults.colors(
                        focusedBorderColor = Accent,
                        unfocusedBorderColor = MaterialTheme.colorScheme.outline,
                        cursorColor = Accent,
                        focusedTextColor = TextPrimary,
                        unfocusedTextColor = TextPrimary
                    )
                )

                Spacer(Modifier.height(20.dp))

                // Buttons
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.End,
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    TextButton(onClick = onDismiss) {
                        Text("Cancel", color = TextSecondary)
                    }
                    Spacer(Modifier.width(8.dp))
                    Button(
                        onClick = {
                            if (title.trim().isBlank() && artist.trim().isBlank()) {
                                statusMsg = "Enter a song title or artist name"
                                isSuccess = false
                                return@Button
                            }
                            isSubmitting = true
                            scope.launch {
                                val body = RequestBody(
                                    title = title.trim().ifBlank { null },
                                    artist = artist.trim().ifBlank { null },
                                    listener_name = listenerName.trim().ifBlank { null },
                                    message = message.trim().ifBlank { null }
                                )
                                val (code, response) = onSubmit(body)
                                isSubmitting = false

                                if (code in 200..299 && response.song != null) {
                                    statusMsg = "Requested: ${response.song.title} \u2014 ${response.song.artist}"
                                    isSuccess = true
                                    delay(2000)
                                    onDismiss()
                                } else if (code == 422 && !response.suggestions.isNullOrEmpty()) {
                                    statusMsg = "Multiple matches \u2014 please select one:"
                                    isSuccess = false
                                    suggestions = response.suggestions
                                } else {
                                    statusMsg = response.error ?: "Request failed"
                                    isSuccess = false
                                }
                            }
                        },
                        enabled = !isSubmitting,
                        colors = ButtonDefaults.buttonColors(containerColor = Accent)
                    ) {
                        if (isSubmitting) {
                            CircularProgressIndicator(
                                modifier = Modifier.size(16.dp),
                                strokeWidth = 2.dp,
                                color = TextPrimary
                            )
                        } else {
                            Text("Submit")
                        }
                    }
                }
            }
        }
    }
}
