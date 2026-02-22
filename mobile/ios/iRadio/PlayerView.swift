import SwiftUI

struct PlayerView: View {
    let state: PlayerState
    let isPlaying: Bool
    let isBuffering: Bool
    let onToggle: () -> Void
    let onVolumeChange: (Float) -> Void
    let onRequest: () -> Void
    let onSettings: () -> Void

    @State private var volume: Float = 0.8

    var body: some View {
        ScrollView {
            VStack(spacing: 0) {
                // Settings button
                HStack {
                    Spacer()
                    Button(action: onSettings) {
                        Image(systemName: "gearshape")
                            .foregroundColor(.textDim)
                            .font(.title3)
                    }
                    .padding(.trailing, 4)
                }
                .padding(.top, 8)

                // Station name
                Text(state.stationName)
                    .font(.title)
                    .fontWeight(.bold)
                    .foregroundColor(.textPrimary)

                Text(state.tagline)
                    .font(.subheadline)
                    .foregroundColor(.textDim)
                    .padding(.top, 2)
                    .padding(.bottom, 28)

                // Vinyl disc
                VinylDisc(isPlaying: isPlaying)
                    .padding(.bottom, 28)

                // Now playing info
                if isBuffering && !isPlaying {
                    Text("Connecting...")
                        .foregroundColor(.textSecondary)
                        .font(.body)
                    ProgressView()
                        .tint(.accent)
                        .padding(.top, 4)
                } else {
                    Text(state.songTitle)
                        .font(.title2)
                        .fontWeight(.semibold)
                        .foregroundColor(.textPrimary)
                        .multilineTextAlignment(.center)
                        .lineLimit(2)

                    if !state.songArtist.isEmpty {
                        Text(state.songArtist)
                            .font(.body)
                            .foregroundColor(.textSecondary)
                            .padding(.top, 2)
                            .lineLimit(1)
                    }
                }

                // Progress bar
                if state.durationMs > 0 && state.startedAtMs > 0 {
                    ProgressSection(
                        startedAtMs: state.startedAtMs,
                        durationMs: state.durationMs
                    )
                    .padding(.top, 20)
                }

                // Play/Stop button
                Button(action: onToggle) {
                    ZStack {
                        Circle()
                            .fill(Color.accent)
                            .frame(width: 72, height: 72)

                        if isBuffering && !isPlaying {
                            ProgressView()
                                .tint(.white)
                                .scaleEffect(1.2)
                        } else {
                            Image(systemName: isPlaying ? "stop.fill" : "play.fill")
                                .font(.title)
                                .foregroundColor(.white)
                                .offset(x: isPlaying ? 0 : 2)
                        }
                    }
                }
                .padding(.top, 24)

                // Volume
                HStack(spacing: 8) {
                    Image(systemName: volume == 0 ? "speaker.slash.fill" : "speaker.wave.2.fill")
                        .foregroundColor(.textSecondary)
                        .font(.caption)
                        .frame(width: 20)

                    Slider(value: Binding(
                        get: { Double(volume) },
                        set: { val in
                            volume = Float(val)
                            onVolumeChange(Float(val))
                        }
                    ), in: 0...1)
                    .tint(.accent)
                }
                .padding(.horizontal, 32)
                .padding(.top, 24)

                // Up Next
                HStack(spacing: 4) {
                    Text("Up Next:")
                        .foregroundColor(.textDim)
                    Text(state.nextTitle != "\u{2014}" ? "\(state.nextTitle) \u{2014} \(state.nextArtist)" : "\u{2014}")
                        .foregroundColor(.textSecondary)
                        .lineLimit(1)
                }
                .font(.caption)
                .padding(.top, 20)

                // Dedication
                if state.dedicationName != nil || state.dedicationMessage != nil {
                    DedicationCard(name: state.dedicationName, message: state.dedicationMessage)
                        .padding(.top, 12)
                        .padding(.horizontal, 4)
                }

                // Listeners
                HStack(spacing: 4) {
                    Text("Listeners:")
                        .foregroundColor(.textDim)
                    Text("\(state.listenerCount)")
                        .foregroundColor(.textSecondary)
                }
                .font(.caption)
                .padding(.top, 12)

                // Request button
                Button(action: onRequest) {
                    Text(state.isEmergency ? "Requests Unavailable" : "Request a Song")
                        .font(.body)
                        .foregroundColor(state.isEmergency ? .textDim : .textSecondary)
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 12)
                        .background(Color.surfaceElevated.opacity(state.isEmergency ? 0.4 : 1))
                        .clipShape(RoundedRectangle(cornerRadius: 12))
                }
                .disabled(state.isEmergency)
                .padding(.top, 24)

                // Footer
                Text("\u{00A9} 2026 DownStreamTech. All rights reserved.")
                    .font(.system(size: 11))
                    .foregroundColor(.textDim.opacity(0.6))
                    .padding(.top, 24)
                    .padding(.bottom, 16)
            }
            .padding(.horizontal, 24)
        }
        .background(Color.bgDark)
    }
}

// MARK: - Vinyl Disc

struct VinylDisc: View {
    let isPlaying: Bool
    @State private var rotation: Double = 0

    var body: some View {
        ZStack {
            // Outer disc
            Circle()
                .fill(
                    RadialGradient(
                        colors: [.bgCard, Color(red: 0.086, green: 0.086, blue: 0.165), .bgCard],
                        center: .center,
                        startRadius: 0,
                        endRadius: 90
                    )
                )
                .frame(width: 180, height: 180)

            // Grooves
            ForEach([150, 130, 110], id: \.self) { size in
                Circle()
                    .stroke(Color.white.opacity(0.05), lineWidth: 1)
                    .frame(width: CGFloat(size), height: CGFloat(size))
            }

            // Center label
            Circle()
                .fill(Color.accent)
                .frame(width: 60, height: 60)

            // Spindle hole
            Circle()
                .fill(Color.bgDark)
                .frame(width: 10, height: 10)
        }
        .rotationEffect(.degrees(rotation))
        .onAppear {
            if isPlaying {
                withAnimation(.linear(duration: 3).repeatForever(autoreverses: false)) {
                    rotation = 360
                }
            }
        }
        .onChange(of: isPlaying) { _, playing in
            if playing {
                withAnimation(.linear(duration: 3).repeatForever(autoreverses: false)) {
                    rotation = rotation + 360
                }
            }
        }
    }
}

// MARK: - Progress Section

struct ProgressSection: View {
    let startedAtMs: TimeInterval
    let durationMs: Int

    @State private var elapsedMs: Int = 0
    let timer = Timer.publish(every: 1, on: .main, in: .common).autoconnect()

    var body: some View {
        VStack(spacing: 4) {
            ProgressView(value: Double(elapsedMs), total: Double(durationMs))
                .tint(.accent)
                .scaleEffect(y: 0.6)

            HStack {
                Text(formatMs(elapsedMs))
                    .foregroundColor(.textDim)
                Spacer()
                Text("-\(formatMs(max(0, durationMs - elapsedMs)))")
                    .foregroundColor(.textDim)
            }
            .font(.system(size: 12))
        }
        .padding(.horizontal, 8)
        .onReceive(timer) { _ in
            let now = Date().timeIntervalSince1970 * 1000
            let elapsed = Int(now - startedAtMs)
            elapsedMs = min(max(0, elapsed), durationMs)
        }
    }

    private func formatMs(_ ms: Int) -> String {
        let totalSec = ms / 1000
        let m = totalSec / 60
        let s = totalSec % 60
        return "\(m):\(String(format: "%02d", s))"
    }
}

// MARK: - Dedication Card

struct DedicationCard: View {
    let name: String?
    let message: String?

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text("REQUESTED BY")
                .font(.system(size: 10))
                .foregroundColor(.textDim)
                .tracking(0.5)

            Text(name ?? "A listener")
                .font(.subheadline)
                .fontWeight(.semibold)
                .foregroundColor(.textPrimary)

            if let msg = message, !msg.isEmpty {
                Text("\u{201C}\(msg)\u{201D}")
                    .font(.subheadline)
                    .italic()
                    .foregroundColor(.dedicationText)
                    .padding(.top, 2)
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(12)
        .background(Color.dedicationBg)
        .overlay(
            RoundedRectangle(cornerRadius: 10)
                .stroke(Color.accent.opacity(0.25), lineWidth: 1)
        )
        .clipShape(RoundedRectangle(cornerRadius: 10))
    }
}
