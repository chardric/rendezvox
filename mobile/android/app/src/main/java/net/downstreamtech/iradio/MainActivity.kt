package net.downstreamtech.iradio

import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.animation.*
import androidx.compose.animation.core.tween
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

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            requestPermissions(arrayOf(android.Manifest.permission.POST_NOTIFICATIONS), 1)
        }

        setContent {
            IRadioTheme {
                val viewModel: PlayerViewModel = viewModel(
                    factory = PlayerViewModel.Factory(this@MainActivity)
                )
                val state by viewModel.state.collectAsStateWithLifecycle()
                var showSplash by remember { mutableStateOf(true) }
                var showRequest by remember { mutableStateOf(false) }

                Box(
                    modifier = Modifier
                        .fillMaxSize()
                        .background(BgDark)
                ) {
                    AnimatedVisibility(
                        visible = !showSplash,
                        enter = fadeIn(animationSpec = tween(400))
                    ) {
                        PlayerScreen(
                            state = state,
                            onTogglePlayback = { viewModel.togglePlayback() },
                            onVolumeChange = { viewModel.setVolume(it) },
                            onRequestSong = { showRequest = true }
                        )
                    }

                    AnimatedVisibility(
                        visible = showSplash,
                        exit = fadeOut(animationSpec = tween(400))
                    ) {
                        SplashScreen(onFinished = { showSplash = false })
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
