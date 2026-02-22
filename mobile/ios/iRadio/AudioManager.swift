import AVFoundation
import MediaPlayer

class AudioManager: ObservableObject {
    @Published var isPlaying = false
    @Published var isBuffering = false

    private var player: AVPlayer?
    private var playerItem: AVPlayerItem?
    private var timeObserver: Any?
    private var statusObservation: NSKeyValueObservation?
    private var rateObservation: NSKeyValueObservation?

    var stationName = "iRadio"
    var currentTitle = ""
    var currentArtist = ""

    init() {
        setupAudioSession()
        setupRemoteCommands()
    }

    private func setupAudioSession() {
        do {
            let session = AVAudioSession.sharedInstance()
            try session.setCategory(.playback, mode: .default, options: [])
            try session.setActive(true)
        } catch {
            print("Audio session error: \(error)")
        }
    }

    private func setupRemoteCommands() {
        let center = MPRemoteCommandCenter.shared()

        center.playCommand.addTarget { [weak self] _ in
            self?.play()
            return .success
        }

        center.pauseCommand.addTarget { [weak self] _ in
            self?.stop()
            return .success
        }

        center.stopCommand.addTarget { [weak self] _ in
            self?.stop()
            return .success
        }

        center.togglePlayPauseCommand.addTarget { [weak self] _ in
            if self?.isPlaying == true {
                self?.stop()
            } else {
                self?.play()
            }
            return .success
        }
    }

    func setStreamURL(_ urlString: String) {
        guard let url = URL(string: urlString + "?t=\(Int(Date().timeIntervalSince1970))") else { return }

        stop()

        playerItem = AVPlayerItem(url: url)
        player = AVPlayer(playerItem: playerItem)
        player?.automaticallyWaitsToMinimizeStalling = true

        statusObservation = playerItem?.observe(\.status) { [weak self] item, _ in
            DispatchQueue.main.async {
                switch item.status {
                case .readyToPlay:
                    self?.isBuffering = false
                case .failed:
                    self?.isPlaying = false
                    self?.isBuffering = false
                    // Auto-reconnect after delay
                    DispatchQueue.main.asyncAfter(deadline: .now() + 5) {
                        if self?.isPlaying == false {
                            self?.play()
                        }
                    }
                default:
                    break
                }
            }
        }

        rateObservation = player?.observe(\.rate) { [weak self] player, _ in
            DispatchQueue.main.async {
                self?.isPlaying = player.rate > 0
                self?.updateNowPlayingInfo()
            }
        }
    }

    func play() {
        if player == nil { return }
        isBuffering = true
        player?.play()
        updateNowPlayingInfo()
    }

    func stop() {
        player?.pause()
        isPlaying = false
        isBuffering = false
        updateNowPlayingInfo()
    }

    func toggle() {
        if isPlaying {
            stop()
        } else {
            play()
        }
    }

    func setVolume(_ volume: Float) {
        player?.volume = volume
    }

    func updateMetadata(title: String, artist: String, stationName: String) {
        self.currentTitle = title
        self.currentArtist = artist
        self.stationName = stationName
        updateNowPlayingInfo()
    }

    private func updateNowPlayingInfo() {
        var info: [String: Any] = [
            MPMediaItemPropertyTitle: currentTitle.isEmpty ? stationName : currentTitle,
            MPMediaItemPropertyArtist: currentArtist.isEmpty ? "Online Radio" : currentArtist,
            MPMediaItemPropertyAlbumTitle: stationName,
            MPNowPlayingInfoPropertyIsLiveStream: true,
            MPNowPlayingInfoPropertyPlaybackRate: isPlaying ? 1.0 : 0.0
        ]

        MPNowPlayingInfoCenter.default().nowPlayingInfo = info
    }

    deinit {
        statusObservation?.invalidate()
        rateObservation?.invalidate()
        player?.pause()
    }
}
