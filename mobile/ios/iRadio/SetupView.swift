import SwiftUI

struct SetupView: View {
    let onConnect: (String) -> Void

    @State private var url = "http://"
    @State private var error = ""

    var body: some View {
        VStack {
            Spacer()

            VStack(spacing: 16) {
                Image(systemName: "antenna.radiowaves.left.and.right")
                    .font(.system(size: 48))
                    .foregroundColor(.accent)

                Text("Connect to Station")
                    .font(.title2)
                    .foregroundColor(.textPrimary)

                Text("Enter your iRadio server address to start listening")
                    .font(.subheadline)
                    .foregroundColor(.textSecondary)
                    .multilineTextAlignment(.center)
                    .padding(.horizontal, 8)

                TextField("http://192.168.1.100", text: $url)
                    .textFieldStyle(.roundedBorder)
                    .keyboardType(.URL)
                    .autocorrectionDisabled()
                    .textInputAutocapitalization(.never)
                    .padding(.top, 8)

                if !error.isEmpty {
                    Text(error)
                        .font(.caption)
                        .foregroundColor(.errorRed)
                }

                Text("Audio streams from port 8000 automatically")
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
                    onConnect(cleaned)
                }) {
                    Text("Connect")
                        .fontWeight(.semibold)
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 12)
                }
                .buttonStyle(.borderedProminent)
                .tint(.accent)
            }
            .padding(28)
            .background(Color.bgCard)
            .clipShape(RoundedRectangle(cornerRadius: 20))
            .padding(.horizontal, 32)

            Spacer()
        }
        .background(Color.bgDark)
    }
}
