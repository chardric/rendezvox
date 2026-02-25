<?php

declare(strict_types=1);

class SmtpMailer
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $encryption; // 'tls', 'ssl', 'none'
    private string $fromAddress;
    private string $fromName;
    private array $log = [];

    /** @var resource|null */
    private $socket = null;

    public function __construct(
        string $host,
        int $port,
        string $username,
        string $password,
        string $encryption,
        string $fromAddress,
        string $fromName
    ) {
        $this->host        = $host;
        $this->port        = $port;
        $this->username    = $username;
        $this->password    = $password;
        $this->encryption  = $encryption;
        $this->fromAddress = $fromAddress;
        $this->fromName    = $fromName;
    }

    /**
     * Build a SmtpMailer from DB settings.
     */
    public static function fromSettings(): self
    {
        $db   = Database::get();
        $stmt = $db->query("SELECT key, value FROM settings WHERE key LIKE 'smtp_%'");
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $host = trim($rows['smtp_host'] ?? '');
        if ($host === '') {
            throw new RuntimeException('SMTP is not configured. Set the SMTP host in Settings.');
        }

        return new self(
            $host,
            (int) ($rows['smtp_port'] ?? 587),
            $rows['smtp_username'] ?? '',
            $rows['smtp_password'] ?? '',
            $rows['smtp_encryption'] ?? 'tls',
            $rows['smtp_from_address'] ?? '',
            $rows['smtp_from_name'] ?? 'RendezVox',
        );
    }

    /**
     * Send an HTML email.
     */
    public function send(string $to, string $subject, string $htmlBody): void
    {
        $this->log = [];

        // Validate addresses against CRLF injection
        $this->validateAddress($this->fromAddress);
        $this->validateAddress($to);

        $this->connect();

        try {
            $this->readGreeting();
            $this->sendEhlo();

            // STARTTLS for port 587 (or explicit 'tls' encryption)
            if ($this->encryption === 'tls') {
                $this->startTls();
                $this->sendEhlo();
            }

            // Authenticate
            if ($this->username !== '') {
                $this->authenticate();
            }

            // Envelope
            $this->sendCommand("MAIL FROM:<{$this->fromAddress}>", 250);
            $this->sendCommand("RCPT TO:<{$to}>", 250);

            // DATA
            $this->sendCommand('DATA', 354);
            $message = $this->buildMessage($to, $subject, $htmlBody);
            $this->sendRaw($message . "\r\n.\r\n");
            $this->readResponse(250);

            $this->sendCommand('QUIT', 221);
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Return the SMTP conversation log (for debugging).
     */
    public function getLog(): array
    {
        return $this->log;
    }

    // ── Connection ──────────────────────────────────────

    private function connect(): void
    {
        $prefix = ($this->encryption === 'ssl') ? 'ssl://' : '';
        $address = $prefix . $this->host . ':' . $this->port;

        $context = stream_context_create([
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);

        $errno  = 0;
        $errstr = '';
        $this->socket = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            10,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$this->socket) {
            throw new RuntimeException("Failed to connect to SMTP server {$address}: [{$errno}] {$errstr}");
        }

        stream_set_timeout($this->socket, 30);
        $this->logEntry('connect', "Connected to {$address}");
    }

    private function disconnect(): void
    {
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    // ── SMTP conversation ──────────────────────────────

    private function readGreeting(): void
    {
        $response = $this->readResponse(220);
        $this->logEntry('greeting', $response);
    }

    private function sendEhlo(): void
    {
        $hostname = gethostname() ?: 'localhost';
        $this->sendCommand("EHLO {$hostname}", 250);
    }

    private function startTls(): void
    {
        $this->sendCommand('STARTTLS', 220);

        $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }

        $result = @stream_socket_enable_crypto($this->socket, true, $crypto);
        if ($result !== true) {
            throw new RuntimeException('STARTTLS handshake failed');
        }

        $this->logEntry('tls', 'TLS encryption enabled');
    }

    private function authenticate(): void
    {
        $this->sendCommand('AUTH LOGIN', 334);
        $this->sendCommand(base64_encode($this->username), 334);
        $this->sendCommand(base64_encode($this->password), 235);
        $this->logEntry('auth', 'Authenticated');
    }

    // ── Low-level I/O ───────────────────────────────────

    private function sendCommand(string $command, int $expectedCode): string
    {
        // Don't log passwords
        $logCmd = str_starts_with($command, base64_encode($this->password))
            ? '(password hidden)'
            : $command;
        $this->logEntry('send', $logCmd);

        $this->sendRaw($command . "\r\n");
        return $this->readResponse($expectedCode);
    }

    private function sendRaw(string $data): void
    {
        $written = @fwrite($this->socket, $data);
        if ($written === false) {
            throw new RuntimeException('Failed to write to SMTP socket');
        }
    }

    private function readResponse(int $expectedCode): string
    {
        $response = '';
        $deadline = time() + 30;

        while (time() < $deadline) {
            $line = @fgets($this->socket, 4096);
            if ($line === false) {
                $info = stream_get_meta_data($this->socket);
                if ($info['timed_out']) {
                    throw new RuntimeException('SMTP read timed out');
                }
                throw new RuntimeException('SMTP connection lost');
            }

            $response .= $line;

            // Multi-line responses have '-' after code; last line has ' '
            if (isset($line[3]) && $line[3] !== '-') {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);
        $this->logEntry('recv', trim($response));

        if ($code !== $expectedCode) {
            throw new RuntimeException(
                "SMTP error: expected {$expectedCode}, got {$code}: " . trim($response)
            );
        }

        return $response;
    }

    // ── Message building ────────────────────────────────

    private function buildMessage(string $to, string $subject, string $htmlBody): string
    {
        $boundary = '----=_RendezVox_' . bin2hex(random_bytes(16));
        $date     = date('r');
        $msgId    = '<' . bin2hex(random_bytes(16)) . '@' . ($this->host ?: 'localhost') . '>';

        $fromEncoded = $this->fromName !== ''
            ? '=?UTF-8?B?' . base64_encode($this->fromName) . '?= <' . $this->fromAddress . '>'
            : $this->fromAddress;

        $headers = implode("\r\n", [
            "Date: {$date}",
            "From: {$fromEncoded}",
            "To: {$to}",
            "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=",
            "Message-ID: {$msgId}",
            "MIME-Version: 1.0",
            "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
        ]);

        // Plain text fallback (strip tags)
        $plainText = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $htmlBody));
        $plainText = html_entity_decode($plainText, ENT_QUOTES, 'UTF-8');
        $plainText = preg_replace('/\n{3,}/', "\n\n", trim($plainText));

        $body = "\r\n--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($plainText))
            . "\r\n--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($htmlBody))
            . "\r\n--{$boundary}--";

        return $headers . "\r\n" . $body;
    }

    // ── Validation ──────────────────────────────────────

    private function validateAddress(string $address): void
    {
        if (preg_match('/[\r\n]/', $address)) {
            throw new RuntimeException('Email address contains invalid characters (possible CRLF injection)');
        }

        if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException("Invalid email address: {$address}");
        }
    }

    private function logEntry(string $type, string $message): void
    {
        $this->log[] = ['type' => $type, 'message' => $message, 'time' => microtime(true)];
    }
}
