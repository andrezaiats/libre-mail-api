<?php

namespace LibreMailApi;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use Psr\Log\LoggerInterface;

/**
 * Builds a PHPMailer instance from the `smtp` config section.
 *
 * SMTPKeepAlive is on: one connection (STARTTLS + AUTH) is reused for every
 * recipient of a batch instead of reconnecting per message. Callers must call
 * smtpClose() when done and getSMTPInstance()->reset() after a failed send.
 */
class MailerFactory
{
    public static function create(array $smtp, ?LoggerInterface $logger = null): PHPMailer
    {
        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = $smtp['host'];
        $mailer->Port = $smtp['port'];
        $mailer->Timeout = $smtp['timeout'] ?? 30;
        $mailer->SMTPKeepAlive = true;
        $mailer->CharSet = PHPMailer::CHARSET_UTF8;

        if ($smtp['auth'] ?? false) {
            $mailer->SMTPAuth = true;
            $mailer->Username = $smtp['username'];
            $mailer->Password = $smtp['password'];
        }

        $encryption = $smtp['encryption'] ?? '';
        if ($encryption === 'tls') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($encryption === 'ssl') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        }

        // The PowerMTA certificate does not validate from this host; the owner
        // explicitly accepts skipping verification on this hop.
        if (!($smtp['verify_peer'] ?? true)) {
            $mailer->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => $smtp['allow_self_signed'] ?? false,
                ],
            ];
        }

        if (($smtp['debug'] ?? false) && $logger) {
            $mailer->SMTPDebug = SMTP::DEBUG_SERVER;
            $mailer->Debugoutput = function ($str) use ($logger) {
                $logger->debug('SMTP Debug: ' . trim($str));
            };
        }

        return $mailer;
    }

    /** Bring the connection back to a usable state after a failed send. */
    public static function recover(PHPMailer $mailer): void
    {
        $smtp = $mailer->getSMTPInstance();
        if ($smtp->connected()) {
            $smtp->reset();
        }
    }
}
