import SwiftUI

@main
struct iRadioApp: App {
    @StateObject private var viewModel = PlayerViewModel()

    var body: some Scene {
        WindowGroup {
            ContentView()
                .environmentObject(viewModel)
                .preferredColorScheme(.dark)
        }
    }
}
