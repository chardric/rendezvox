import SwiftUI

struct RequestView: View {
    let onSearch: (String, String?) async -> SearchResult
    let onSubmit: (SongRequestBody) async -> (Int, RequestResponse)

    @Environment(\.dismiss) private var dismiss

    @State private var title = ""
    @State private var artist = ""
    @State private var listenerName = ""
    @State private var message = ""
    @State private var suggestions: [SearchSong] = []
    @State private var resolvedSong: SearchSong?
    @State private var statusMsg = ""
    @State private var isSuccess = false
    @State private var isSubmitting = false
    @State private var searchTask: Task<Void, Never>?

    var body: some View {
        NavigationView {
            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    // Status
                    if !statusMsg.isEmpty {
                        Text(statusMsg)
                            .font(.subheadline)
                            .foregroundColor(isSuccess ? .successGreen : .errorRed)
                    }

                    // Resolved indicator
                    if let song = resolvedSong {
                        HStack(spacing: 6) {
                            Image(systemName: "checkmark.circle.fill")
                                .foregroundColor(.successGreen)
                            Text("\(song.title) \u{2014} \(song.artist)")
                                .font(.subheadline)
                                .foregroundColor(.successGreen)
                        }
                        .padding(10)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .background(Color.successGreen.opacity(0.12))
                        .clipShape(RoundedRectangle(cornerRadius: 8))
                    }

                    // Song Title
                    VStack(alignment: .leading, spacing: 4) {
                        Text("Song Title *")
                            .font(.caption)
                            .foregroundColor(.textSecondary)

                        TextField("Start typing a song title...", text: $title)
                            .textFieldStyle(.roundedBorder)
                            .onChange(of: title) { _, newValue in
                                resolvedSong = nil
                                debouncedSearch()
                            }
                    }

                    // Suggestions
                    if !suggestions.isEmpty {
                        VStack(spacing: 0) {
                            ForEach(suggestions) { song in
                                Button(action: {
                                    selectSong(song)
                                }) {
                                    VStack(alignment: .leading, spacing: 2) {
                                        Text(song.title)
                                            .foregroundColor(.textPrimary)
                                            .font(.subheadline)
                                        Text(song.artist)
                                            .foregroundColor(.textDim)
                                            .font(.caption)
                                    }
                                    .frame(maxWidth: .infinity, alignment: .leading)
                                    .padding(.horizontal, 12)
                                    .padding(.vertical, 8)
                                }
                                Divider().background(Color.textDim.opacity(0.3))
                            }
                        }
                        .background(Color.bgDark)
                        .clipShape(RoundedRectangle(cornerRadius: 8))
                    }

                    // Artist
                    VStack(alignment: .leading, spacing: 4) {
                        Text("Artist (optional)")
                            .font(.caption)
                            .foregroundColor(.textSecondary)
                        TextField("Artist name", text: $artist)
                            .textFieldStyle(.roundedBorder)
                            .onChange(of: artist) { _, _ in
                                if title.trimmingCharacters(in: .whitespaces).count >= 2 {
                                    resolvedSong = nil
                                    debouncedSearch()
                                }
                            }
                    }

                    // Listener Name
                    VStack(alignment: .leading, spacing: 4) {
                        Text("Your Name (optional)")
                            .font(.caption)
                            .foregroundColor(.textSecondary)
                        TextField("Listener name", text: $listenerName)
                            .textFieldStyle(.roundedBorder)
                    }

                    // Message
                    VStack(alignment: .leading, spacing: 4) {
                        Text("Message (optional)")
                            .font(.caption)
                            .foregroundColor(.textSecondary)
                        TextField("Dedication or note", text: $message, axis: .vertical)
                            .textFieldStyle(.roundedBorder)
                            .lineLimit(2...4)
                    }
                }
                .padding(20)
            }
            .background(Color.bgCard)
            .navigationTitle("Request a Song")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                        .foregroundColor(.textSecondary)
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button(action: submitRequest) {
                        if isSubmitting {
                            ProgressView().tint(.white)
                        } else {
                            Text("Submit")
                        }
                    }
                    .disabled(isSubmitting)
                    .foregroundColor(.accent)
                }
            }
        }
    }

    private func selectSong(_ song: SearchSong) {
        resolvedSong = song
        title = song.title
        artist = song.artist
        suggestions = []
    }

    private func debouncedSearch() {
        searchTask?.cancel()
        searchTask = Task {
            try? await Task.sleep(nanoseconds: 350_000_000)
            guard !Task.isCancelled else { return }

            let trimmed = title.trimmingCharacters(in: .whitespaces)
            guard trimmed.count >= 2 else {
                await MainActor.run {
                    suggestions = []
                    resolvedSong = nil
                }
                return
            }

            let artistTrimmed = artist.trimmingCharacters(in: .whitespaces)
            let result = await onSearch(trimmed, artistTrimmed.isEmpty ? nil : artistTrimmed)

            await MainActor.run {
                if result.resolved == true, let first = result.songs?.first {
                    selectSong(first)
                } else {
                    suggestions = result.songs ?? []
                }
            }
        }
    }

    private func submitRequest() {
        let trimmed = title.trimmingCharacters(in: .whitespaces)
        guard !trimmed.isEmpty else {
            statusMsg = "Enter a song title"
            isSuccess = false
            return
        }

        isSubmitting = true
        Task {
            let body = SongRequestBody(
                title: trimmed,
                artist: artist.trimmingCharacters(in: .whitespaces).isEmpty ? nil : artist.trimmingCharacters(in: .whitespaces),
                listener_name: listenerName.trimmingCharacters(in: .whitespaces).isEmpty ? nil : listenerName.trimmingCharacters(in: .whitespaces),
                message: message.trimmingCharacters(in: .whitespaces).isEmpty ? nil : message.trimmingCharacters(in: .whitespaces)
            )

            let (code, response) = await onSubmit(body)

            await MainActor.run {
                isSubmitting = false

                if (200...299).contains(code), let song = response.song {
                    statusMsg = "Requested: \(song.title) \u{2014} \(song.artist)"
                    isSuccess = true
                    Task {
                        try? await Task.sleep(nanoseconds: 2_000_000_000)
                        dismiss()
                    }
                } else if code == 422, let sug = response.suggestions, !sug.isEmpty {
                    statusMsg = "Multiple matches \u{2014} please select one:"
                    isSuccess = false
                    suggestions = sug
                } else {
                    statusMsg = response.error ?? "Request failed"
                    isSuccess = false
                }
            }
        }
    }
}
