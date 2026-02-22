import Foundation

actor RadioAPI {
    private let baseURL: String
    private let session: URLSession

    var streamURL: String {
        return "\(baseURL)/stream/live"
    }

    var icecastBaseURL: String {
        return "\(baseURL)/stream"
    }

    init(baseURL: String) {
        self.baseURL = baseURL
        let config = URLSessionConfiguration.default
        config.timeoutIntervalForRequest = 30
        self.session = URLSession(configuration: config)
    }

    func fetchConfig() async -> StationConfig? {
        guard let url = URL(string: "\(baseURL)/api/config") else { return nil }
        do {
            let (data, _) = try await session.data(from: url)
            return try JSONDecoder().decode(StationConfig.self, from: data)
        } catch {
            return nil
        }
    }

    func fetchNowPlaying() async -> NowPlayingData? {
        guard let url = URL(string: "\(baseURL)/api/now-playing") else { return nil }
        do {
            let (data, _) = try await session.data(from: url)
            return try JSONDecoder().decode(NowPlayingData.self, from: data)
        } catch {
            return nil
        }
    }

    func fetchListenerCount() async -> Int {
        guard let url = URL(string: "\(icecastBaseURL)/status-json.xsl") else { return 0 }
        do {
            let (data, _) = try await session.data(from: url)
            guard let json = try JSONSerialization.jsonObject(with: data) as? [String: Any],
                  let icestats = json["icestats"] as? [String: Any] else { return 0 }

            if let source = icestats["source"] as? [String: Any] {
                return source["listeners"] as? Int ?? 0
            } else if let sources = icestats["source"] as? [[String: Any]] {
                let mount = sources.first { ($0["listenurl"] as? String)?.contains("/live") == true }
                return mount?["listeners"] as? Int ?? 0
            }
            return 0
        } catch {
            return 0
        }
    }

    func searchSong(title: String, artist: String? = nil) async -> SearchResult {
        var urlString = "\(baseURL)/api/search-song?title=\(title.addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed) ?? title)"
        if let artist = artist, !artist.isEmpty {
            urlString += "&artist=\(artist.addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed) ?? artist)"
        }
        guard let url = URL(string: urlString) else { return SearchResult(songs: nil, resolved: nil) }
        do {
            let (data, _) = try await session.data(from: url)
            return try JSONDecoder().decode(SearchResult.self, from: data)
        } catch {
            return SearchResult(songs: nil, resolved: nil)
        }
    }

    func submitRequest(body: SongRequestBody) async -> (Int, RequestResponse) {
        guard let url = URL(string: "\(baseURL)/api/request") else {
            return (0, RequestResponse(song: nil, error: "Invalid URL", suggestions: nil))
        }
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        do {
            request.httpBody = try JSONEncoder().encode(body)
            let (data, response) = try await session.data(for: request)
            let httpResponse = response as? HTTPURLResponse
            let statusCode = httpResponse?.statusCode ?? 0
            let responseData = try JSONDecoder().decode(RequestResponse.self, from: data)
            return (statusCode, responseData)
        } catch {
            return (0, RequestResponse(song: nil, error: "Network error", suggestions: nil))
        }
    }
}

// MARK: - SSE Client

class SSEClient {
    private var task: URLSessionDataTask?
    private let url: URL
    private var onEvent: ((NowPlayingData) -> Void)?

    init(url: URL) {
        self.url = url
    }

    func connect(onEvent: @escaping (NowPlayingData) -> Void) {
        self.onEvent = onEvent
        var request = URLRequest(url: url)
        request.setValue("text/event-stream", forHTTPHeaderField: "Accept")
        request.timeoutInterval = TimeInterval.infinity

        let config = URLSessionConfiguration.default
        config.timeoutIntervalForRequest = TimeInterval.infinity
        config.timeoutIntervalForResource = TimeInterval.infinity
        let session = URLSession(configuration: config, delegate: SSEDelegate(handler: self), delegateQueue: nil)
        task = session.dataTask(with: request)
        task?.resume()
    }

    func disconnect() {
        task?.cancel()
        task = nil
    }

    fileprivate var buffer = ""

    fileprivate func processBuffer() {
        let lines = buffer.components(separatedBy: "\n")
        var eventType: String?
        var dataStr = ""

        for line in lines {
            if line.hasPrefix("event:") {
                eventType = line.replacingOccurrences(of: "event:", with: "").trimmingCharacters(in: .whitespaces)
            } else if line.hasPrefix("data:") {
                dataStr += line.replacingOccurrences(of: "data:", with: "").trimmingCharacters(in: .whitespaces)
            } else if line.isEmpty && !dataStr.isEmpty {
                if eventType == "now-playing", let data = dataStr.data(using: .utf8) {
                    if let nowPlaying = try? JSONDecoder().decode(NowPlayingData.self, from: data) {
                        DispatchQueue.main.async { [weak self] in
                            self?.onEvent?(nowPlaying)
                        }
                    }
                }
                eventType = nil
                dataStr = ""
            }
        }

        // Keep incomplete data in buffer
        if let lastNewline = buffer.lastIndex(of: "\n") {
            buffer = String(buffer[buffer.index(after: lastNewline)...])
        }
    }
}

private class SSEDelegate: NSObject, URLSessionDataDelegate {
    weak var handler: SSEClient?

    init(handler: SSEClient) {
        self.handler = handler
    }

    func urlSession(_ session: URLSession, dataTask: URLSessionDataTask, didReceive data: Data) {
        guard let str = String(data: data, encoding: .utf8) else { return }
        handler?.buffer += str
        handler?.processBuffer()
    }
}
