<?php

declare(strict_types=1);

class TestEmailHandler
{
    public function handle(): void
    {
        $user = Auth::requireRole('super_admin');
        $db   = Database::get();

        // Get the admin's email
        $stmt = $db->prepare('SELECT email FROM users WHERE id = :id');
        $stmt->execute(['id' => $user['sub']]);
        $email = $stmt->fetchColumn();

        if (!$email) {
            Response::error('No email address on your account', 400);
        }

        try {
            $mailer = SmtpMailer::fromSettings();

            // Get station name for branding
            $nameStmt = $db->prepare("SELECT value FROM settings WHERE key = 'station_name'");
            $nameStmt->execute();
            $stationName = $nameStmt->fetchColumn() ?: 'RendezVox';

            $html = <<<HTML
            <div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;max-width:480px;margin:0 auto;padding:32px">
              <h2 style="color:#6c63ff;margin:0 0 16px">{$stationName}</h2>
              <p style="color:#333;line-height:1.6">This is a test email from your <strong>{$stationName}</strong> admin panel.</p>
              <p style="color:#333;line-height:1.6">If you received this, your SMTP settings are configured correctly.</p>
              <hr style="border:none;border-top:1px solid #eee;margin:24px 0">
              <p style="color:#999;font-size:12px">Sent from {$stationName} Admin</p>
            </div>
            HTML;

            $mailer->send($email, "{$stationName} — Test Email", $html);

            Response::json([
                'message' => "Test email sent to {$email}",
            ]);
        } catch (Throwable $e) {
            if (isset($mailer)) {
                error_log('TestEmail SMTP log: ' . json_encode($mailer->getLog()));
            }
            Response::json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
