<?php

namespace LibreMailApi\Campaign;

use LibreMailApi\MailerFactory;
use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

/** Sends personalized campaign messages over one kept-alive SMTP connection. */
class CampaignMailer
{
    private PHPMailer $mailer;
    private string $fromEmail;
    private string $fromName;

    public function __construct(PHPMailer $mailer, string $from)
    {
        $this->mailer = $mailer;
        if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>\s*$/', $from, $m)) {
            $this->fromName = trim($m[1]);
            $this->fromEmail = trim($m[2]);
        } else {
            $this->fromName = '';
            $this->fromEmail = trim($from);
        }
    }

    /**
     * @param array{to_email: string, to_name: ?string, subject: string, html: string, text: string, headers: array} $msg
     * @throws SendFailed when the server does not accept the message
     */
    public function send(array $msg): void
    {
        $m = $this->mailer;
        $m->clearAllRecipients();
        $m->clearAttachments();
        $m->clearCustomHeaders();
        $m->clearReplyTos();
        try {
            $m->setFrom($this->fromEmail, $this->fromName);
            // Message-ID on the sender's domain instead of the container hostname
            $m->MessageID = sprintf('<%s@%s>', bin2hex(random_bytes(16)), substr(strrchr($this->fromEmail, '@'), 1));
            $m->addAddress($msg['to_email'], (string) ($msg['to_name'] ?? ''));
            $m->Subject = $msg['subject'];
            $m->isHTML(true);
            $m->Body = $msg['html'];
            $m->AltBody = $msg['text'];
            foreach ($msg['headers'] as [$name, $value]) {
                $m->addCustomHeader($name, $value);
            }
            $m->send();
        } catch (MailerException $e) {
            $msg = $e->getMessage() ?: $m->ErrorInfo;
            $code = (string) ($m->getSMTPInstance()->getError()['smtp_code'] ?? '');
            MailerFactory::recover($m);
            $transient = $code === '' ? (bool) preg_match('/connect|authenticat|timed? ?out|connection|data not accepted|tls/i', $msg)
                : $code[0] === '4';
            throw SendFailed::from($msg, $transient, $e);
        }
    }

    public function close(): void
    {
        $this->mailer->smtpClose();
    }
}
