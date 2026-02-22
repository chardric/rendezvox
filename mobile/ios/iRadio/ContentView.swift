import SwiftUI

struct ContentView: View {
    @EnvironmentObject var viewModel: PlayerViewModel

    @State private var showSettings = false
    @State private var showRequest = false

    var body: some View {
        ZStack {
            Color.bgDark.ignoresSafeArea()

            if viewModel.state.serverUrl.isEmpty {
                SetupView { url in
                    viewModel.setServerURL(url)
                }
            } else if showSettings {
                SettingsView(
                    currentURL: viewModel.state.serverUrl,
                    onSave: { url in
                        viewModel.setServerURL(url)
                        showSettings = false
                    },
                    onBack: { showSettings = false }
                )
            } else {
                PlayerView(
                    state: viewModel.state,
                    isPlaying: viewModel.audioManager.isPlaying,
                    isBuffering: viewModel.audioManager.isBuffering,
                    onToggle: { viewModel.togglePlayback() },
                    onVolumeChange: { viewModel.setVolume($0) },
                    onRequest: { showRequest = true },
                    onSettings: { showSettings = true }
                )
            }
        }
        .sheet(isPresented: $showRequest) {
            RequestView(
                onSearch: { title, artist in
                    await viewModel.searchSongs(title: title, artist: artist)
                },
                onSubmit: { body in
                    await viewModel.submitRequest(body: body)
                }
            )
            .presentationDetents([.large])
        }
    }
}

// MARK: - Color Extensions

extension Color {
    static let bgDark = Color(red: 0.059, green: 0.059, blue: 0.059)
    static let bgCard = Color(red: 0.102, green: 0.102, blue: 0.180)
    static let accent = Color(red: 0.424, green: 0.388, blue: 1.0)
    static let accentLight = Color(red: 0.486, green: 0.455, blue: 1.0)
    static let textPrimary = Color(red: 0.878, green: 0.878, blue: 0.878)
    static let textSecondary = Color(red: 0.612, green: 0.639, blue: 0.686)
    static let textDim = Color(red: 0.333, green: 0.333, blue: 0.333)
    static let surfaceElevated = Color(red: 0.176, green: 0.176, blue: 0.267)
    static let successGreen = Color(red: 0.290, green: 0.871, blue: 0.502)
    static let errorRed = Color(red: 0.973, green: 0.443, blue: 0.443)
    static let dedicationBg = Color(red: 0.424, green: 0.388, blue: 1.0).opacity(0.1)
    static let dedicationText = Color(red: 0.769, green: 0.710, blue: 0.992)
}
