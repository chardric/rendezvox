import SwiftUI

struct SettingsView: View {
    let currentURL: String
    let onSave: (String) -> Void
    let onBack: () -> Void

    @State private var url: String = ""
    @State private var error = ""

    var body: some View {
        VStack(spacing: 0) {
            // Nav bar
            HStack {
                Button(action: onBack) {
                    HStack(spacing: 4) {
                        Image(systemName: "chevron.left")
                        Text("Back")
                    }
                    .foregroundColor(.accent)
                }
                Spacer()
                Text("Settings")
                    .font(.headline)
                    .foregroundColor(.textPrimary)
                Spacer()
                // Balance spacer
                HStack(spacing: 4) {
                    Image(systemName: "chevron.left")
                    Text("Back")
                }
                .opacity(0)
            }
            .padding()
            .background(Color.bgCard)

            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    Text("Server Connection")
                        .font(.title3)
                        .foregroundColor(.textPrimary)

                    Text("Server URL")
                        .font(.caption)
                        .foregroundColor(.textSecondary)

                    TextField("http://192.168.1.100", text: $url)
                        .textFieldStyle(.roundedBorder)
                        .keyboardType(.URL)
                        .autocorrectionDisabled()
                        .textInputAutocapitalization(.never)

                    if !error.isEmpty {
                        Text(error)
                            .font(.caption)
                            .foregroundColor(.errorRed)
                    }

                    Text("Audio streams from port 8000 on the same host")
                        .font(.caption)
                        .foregroundColor(.textDim)

                    Button(action: {
                        let cleaned = url.trimmingCharacters(in: .whitespacesAndNewlines)
                            .trimmingCharacters(in: CharacterSet(charactersIn: "/"))
                        guard !cleaned.isEmpty, cleaned != "http://", cleaned != "https://" else {
                            error = "Enter a valid server URL"
                            return
                        }
                        guard cleaned.hasPrefix("http://") || cleaned.hasPrefix("https://") else {
                            error = "URL must start with http:// or https://"
                            return
                        }
                        onSave(cleaned)
                    }) {
                        Text("Save & Reconnect")
                            .fontWeight(.semibold)
                            .frame(maxWidth: .infinity)
                            .padding(.vertical, 12)
                    }
                    .buttonStyle(.borderedProminent)
                    .tint(.accent)

                    Spacer().frame(height: 48)

                    VStack(alignment: .leading, spacing: 4) {
                        Text("iRadio for iOS")
                            .font(.caption)
                            .foregroundColor(.textDim)
                        Text("Version 1.0.0")
                            .font(.caption2)
                            .foregroundColor(.textDim.opacity(0.6))
                        Text("\u{00A9} 2026 DownStreamTech")
                            .font(.caption2)
                            .foregroundColor(.textDim.opacity(0.6))
                    }
                }
                .padding(24)
            }
        }
        .background(Color.bgDark)
        .onAppear { url = currentURL }
    }
}
