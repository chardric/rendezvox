package net.downstreamtech.iradio

import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import net.downstreamtech.iradio.ui.*
import net.downstreamtech.iradio.ui.theme.BgDark
import net.downstreamtech.iradio.ui.theme.IRadioTheme

class MainActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()

        // Request notification permission on Android 13+
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            requestPermissions(arrayOf(android.Manifest.permission.POST_NOTIFICATIONS), 1)
        }

        setContent {
            IRadioTheme {
                val viewModel: PlayerViewModel = viewModel(
                    factory = PlayerViewModel.Factory(this@MainActivity)
                )
                val state by viewModel.state.collectAsStateWithLifecycle()

                var showRequest by remember { mutableStateOf(false) }
                var showSettings by remember { mutableStateOf(false) }

                Box(
                    modifier = Modifier
                        .fillMaxSize()
                        .background(BgDark)
                ) {
                    if (state.serverUrl.isBlank()) {
                        // First-run: show setup screen
                        SetupScreen(
                            onConnect = { url -> viewModel.setServerUrl(url) }
                        )
                    } else if (showSettings) {
                        // Settings screen
                        SettingsScreen(
                            currentUrl = state.serverUrl,
                            onSave = { url ->
                                viewModel.setServerUrl(url)
                                showSettings = false
                            },
                            onBack = { showSettings = false }
                        )
                    } else {
                        // Main player
                        PlayerScreen(
                            state = state,
                            onTogglePlayback = { viewModel.togglePlayback() },
                            onVolumeChange = { viewModel.setVolume(it) },
                            onRequestSong = { showRequest = true },
                            onOpenSettings = { showSettings = true }
                        )
                    }

                    if (showRequest) {
                        RequestDialog(
                            onDismiss = { showRequest = false },
                            onSearch = { title, artist -> viewModel.searchSongs(title, artist) },
                            onSubmit = { body -> viewModel.submitSongRequest(body) }
                        )
                    }
                }
            }
        }
    }
}
