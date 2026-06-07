<?php

namespace LibreMailApi;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
use Psr\Log\LoggerInterface;

/**
 * SMTP Handler for actually sending emails
 * Handles real email delivery via SMTP servers
 */
class SmtpHandler
{
    private $config;
    private $logger;
    private $mailer;
    private $eventStorage;
    private $trackingHandler;

    public function __construct(array $config, LoggerInterface $logger, $eventStorage = null, $trackingHandler = null)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->eventStorage = $eventStorage;
        $this->trackingHandler = $trackingHandler;
        $this->initializeMailer();
    }

    /**
     * Initialize PHPMailer with SMTP configuration
     */
    private function initializeMailer()
    {
        $this->mailer = new PHPMailer(true);

        // Server settings
        $this->mailer->isSMTP();
        $this->mailer->Host = $this->config['smtp']['host'];
        $this->mailer->Port = $this->config['smtp']['port'];
        $this->mailer->Timeout = $this->config['smtp']['timeout'] ?? 30;

        // Authentication
        if ($this->config['smtp']['auth'] ?? false) {
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = $this->config['smtp']['username'];
            $this->mailer->Password = $this->config['smtp']['password'];
        }

        // Encryption
        $encryption = $this->config['smtp']['encryption'] ?? '';
        if ($encryption === 'tls') {
            $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($encryption === 'ssl') {
            $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        }

        // SSL options
        if (!($this->config['smtp']['verify_peer'] ?? true)) {
            $this->mailer->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => $this->config['smtp']['allow_self_signed'] ?? false
                ]
            ];
        }

        // Debug settings
        if ($this->config['smtp']['debug'] ?? false) {
            $this->mailer->SMTPDebug = SMTP::DEBUG_SERVER;
            $this->mailer->Debugoutput = function($str, $level) {
                $this->logger->debug("SMTP Debug: " . trim($str));
            };
        }
    }

    /**
     * Send a regular email message
     */
    public function sendMessage($message)
    {
        // Parse recipients for 'to' field
        $toRecipients = $this->parseRecipients($message['to']);

        // If no recipients, return error
        if (empty($toRecipients)) {
            return [
                'success' => false,
                'error' => 'No valid recipients found in "to" field'
            ];
        }

        $results = [];
        $overallSuccess = true;
        $errors = [];

        // Send separate email to each 'to' recipient
        foreach ($toRecipients as $index => $toRecipient) {
            try {
                // Reset mailer for new message
                $this->mailer->clearAllRecipients();
                $this->mailer->clearAttachments();
                $this->mailer->clearCustomHeaders();

                // Set sender
                $fromEmail = $this->extractEmail($message['from']);
                $fromName = $this->extractName($message['from']);

                if (empty($fromEmail)) {
                    $fromEmail = $this->config['smtp']['from_email'];
                    $fromName = $this->config['smtp']['from_name'];
                }

                $this->mailer->setFrom($fromEmail, $fromName);

                // Set single 'to' recipient
                $this->mailer->addAddress($toRecipient['email'], $toRecipient['name']);

                // Set CC recipients if present (same for all emails)
                if (!empty($message['cc'])) {
                    $ccRecipients = $this->parseRecipients($message['cc']);
                    foreach ($ccRecipients as $recipient) {
                        $this->mailer->addCC($recipient['email'], $recipient['name']);
                    }
                }

                // Set BCC recipients if present (same for all emails)
                if (!empty($message['bcc'])) {
                    $bccRecipients = $this->parseRecipients($message['bcc']);
                    foreach ($bccRecipients as $recipient) {
                        $this->mailer->addBCC($recipient['email'], $recipient['name']);
                    }
                }

                // Set reply-to if present
                if (!empty($message['h:Reply-To'])) {
                    $replyTo = $this->extractEmail($message['h:Reply-To']);
                    $this->mailer->addReplyTo($replyTo);
                }

                // Set body content with recipient-specific variables
                $htmlBody = $message['html'] ?? '';
                $textBody = $message['text'] ?? '';
                $subject = $message['subject'] ?? '';

                // Apply recipient-specific variables if present
                if (!empty($message['recipient_variables'])) {
                    $recipientVars = json_decode($message['recipient_variables'], true);
                    if (isset($recipientVars[$toRecipient['email']])) {
                        $vars = $recipientVars[$toRecipient['email']];

                        // Replace variables in subject, HTML and text
                        $subject = $this->replaceRecipientVariables($subject, $vars);
                        $htmlBody = $this->replaceRecipientVariables($htmlBody, $vars);
                        $textBody = $this->replaceRecipientVariables($textBody, $vars);
                    }
                }

                // Inject open-tracking pixel if enabled
                $deliveryId = null;
                if (!empty($message['tracking_opens']) && $this->trackingHandler && !empty($htmlBody)) {
                    $deliveryId = $this->trackingHandler->createDelivery(
                        $message['message_id'],
                        $toRecipient['email'],
                        $message['email_id'] ?? null,
                        $message['tags_json'] ?? null
                    );
                    $htmlBody = $this->trackingHandler->injectTrackingPixel($htmlBody, $deliveryId);
                }

                // Set the personalized subject
                $this->mailer->Subject = $subject;

                if (!empty($htmlBody)) {
                    $this->mailer->isHTML(true);
                    $this->mailer->Body = $htmlBody;
                    if (!empty($textBody)) {
                        $this->mailer->AltBody = $textBody;
                    }
                } else {
                    $this->mailer->isHTML(false);
                    $this->mailer->Body = $textBody;
                }

                // Add custom headers
                if (!empty($message['headers']) && is_array($message['headers'])) {
                    foreach ($message['headers'] as $header) {
                        if (is_array($header) && count($header) >= 2) {
                            // Headers are in format [['name', 'value'], ...]
                            $name = $header[0];
                            $value = $header[1];

                            // Skip standard headers that PHPMailer handles automatically
                            $skipHeaders = ['mime-version', 'subject', 'from', 'to', 'content-transfer-encoding'];
                            if (!in_array(strtolower($name), $skipHeaders)) {
                                $this->mailer->addCustomHeader($name, $value);
                            }
                        } elseif (is_string($header)) {
                            // Handle string headers in format "Name: Value"
                            $parts = explode(':', $header, 2);
                            if (count($parts) === 2) {
                                $name = trim($parts[0]);
                                $value = trim($parts[1]);
                                $skipHeaders = ['mime-version', 'subject', 'from', 'to', 'content-transfer-encoding'];
                                if (!in_array(strtolower($name), $skipHeaders)) {
                                    $this->mailer->addCustomHeader($name, $value);
                                }
                            }
                        }
                    }
                }

                // Add attachments
                if (!empty($message['attachments']) && is_array($message['attachments'])) {
                    foreach ($message['attachments'] as $attachment) {
                        if (isset($attachment['path']) && file_exists($attachment['path'])) {
                            $this->mailer->addAttachment(
                                $attachment['path'],
                                $attachment['name'] ?? basename($attachment['path']),
                                'base64',
                                $attachment['type'] ?? 'application/octet-stream'
                            );
                        }
                    }
                }

                // Send the email
                $result = $this->mailer->send();

                // Emit "delivered" event on SMTP 250 acceptance
                if ($this->eventStorage) {
                    $this->eventStorage->storeEvent(
                        'delivered',
                        $toRecipient['email'],
                        $message['message_id'],
                        $message['email_id'] ?? null,
                        $message['tags_json'] ?? null
                    );
                }

                $this->logger->info("Email sent successfully via SMTP", [
                    'message_id' => $message['message_id'] ?? 'unknown',
                    'to' => $toRecipient['email'],
                    'recipient_index' => $index + 1,
                    'total_recipients' => count($toRecipients),
                    'subject' => $message['subject'] ?? 'no subject',
                    'smtp_host' => $this->config['smtp']['host']
                ]);

                $results[] = [
                    'success' => true,
                    'recipient' => $toRecipient['email'],
                    'message' => 'Email sent successfully via SMTP'
                ];

            } catch (Exception $e) {
                $this->logger->error("Failed to send email via SMTP", [
                    'message_id' => $message['message_id'] ?? 'unknown',
                    'to' => $toRecipient['email'],
                    'recipient_index' => $index + 1,
                    'total_recipients' => count($toRecipients),
                    'error' => $e->getMessage(),
                    'smtp_host' => $this->config['smtp']['host']
                ]);

                $overallSuccess = false;
                $errors[] = "Failed to send to {$toRecipient['email']}: " . $e->getMessage();

                $results[] = [
                    'success' => false,
                    'recipient' => $toRecipient['email'],
                    'error' => $e->getMessage(),
                    'smtp_error' => $this->mailer->ErrorInfo
                ];
            }
        }

        // Return overall result
        return [
            'success' => $overallSuccess,
            'message' => $overallSuccess ?
                'All emails sent successfully via SMTP' :
                'Some emails failed to send',
            'total_recipients' => count($toRecipients),
            'successful_sends' => count(array_filter($results, function($r) { return $r['success']; })),
            'failed_sends' => count(array_filter($results, function($r) { return !$r['success']; })),
            'results' => $results,
            'errors' => $errors
        ];
    }

    /**
     * Send a MIME message
     */
    public function sendMimeMessage($message)
    {
        // Parse recipients for 'to' field
        $toRecipients = $this->parseRecipients($message['to']);

        // If no recipients, return error
        if (empty($toRecipients)) {
            return [
                'success' => false,
                'error' => 'No valid recipients found in "to" field'
            ];
        }

        $results = [];
        $overallSuccess = true;
        $errors = [];

        // Send separate email to each 'to' recipient
        foreach ($toRecipients as $index => $toRecipient) {
            try {
                // Reset mailer for new message
                $this->mailer->clearAllRecipients();
                $this->mailer->clearAttachments();
                $this->mailer->clearCustomHeaders();

                // Set single 'to' recipient
                $this->mailer->addAddress($toRecipient['email'], $toRecipient['name']);

                // Set the raw MIME message
                $this->mailer->MsgHTML($message['mime_content']);

                // Send the email
                $result = $this->mailer->send();

                $this->logger->info("MIME email sent successfully via SMTP", [
                    'message_id' => $message['message_id'] ?? 'unknown',
                    'to' => $toRecipient['email'],
                    'recipient_index' => $index + 1,
                    'total_recipients' => count($toRecipients),
                    'smtp_host' => $this->config['smtp']['host']
                ]);

                $results[] = [
                    'success' => true,
                    'recipient' => $toRecipient['email'],
                    'message' => 'MIME email sent successfully via SMTP'
                ];

            } catch (Exception $e) {
                $this->logger->error("Failed to send MIME email via SMTP", [
                    'message_id' => $message['message_id'] ?? 'unknown',
                    'to' => $toRecipient['email'],
                    'recipient_index' => $index + 1,
                    'total_recipients' => count($toRecipients),
                    'error' => $e->getMessage(),
                    'smtp_host' => $this->config['smtp']['host']
                ]);

                $overallSuccess = false;
                $errors[] = "Failed to send to {$toRecipient['email']}: " . $e->getMessage();

                $results[] = [
                    'success' => false,
                    'recipient' => $toRecipient['email'],
                    'error' => $e->getMessage(),
                    'smtp_error' => $this->mailer->ErrorInfo
                ];
            }
        }

        // Return overall result
        return [
            'success' => $overallSuccess,
            'message' => $overallSuccess ?
                'All MIME emails sent successfully via SMTP' :
                'Some MIME emails failed to send',
            'total_recipients' => count($toRecipients),
            'successful_sends' => count(array_filter($results, function($r) { return $r['success']; })),
            'failed_sends' => count(array_filter($results, function($r) { return !$r['success']; })),
            'results' => $results,
            'errors' => $errors
        ];
    }

    /**
     * Test SMTP connection
     */
    public function testConnection()
    {
        try {
            $this->mailer->smtpConnect();
            $this->mailer->smtpClose();
            
            return [
                'success' => true,
                'message' => 'SMTP connection successful'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Extract email address from string like "Name <email@domain.com>"
     */
    private function extractEmail($emailString)
    {
        if (preg_match('/<([^>]+)>/', $emailString, $matches)) {
            return trim($matches[1]);
        }
        return trim($emailString);
    }

    /**
     * Extract name from string like "Name <email@domain.com>"
     */
    private function extractName($emailString)
    {
        if (preg_match('/^([^<]+)</', $emailString, $matches)) {
            $name = trim($matches[1]);

            // Remove outer quotes if present (both single and double quotes)
            if (preg_match('/^["\'](.+)["\']$/', $name, $quoteMatches)) {
                $name = $quoteMatches[1];

                // Remove escaped characters (handles cases like \"Name\" -> "Name")
                $name = stripslashes($name);

                // Remove quotes again if they were escaped (handles "\"Name\"" -> Name)
                if (preg_match('/^["\'](.+)["\']$/', $name, $innerQuotes)) {
                    $name = $innerQuotes[1];
                }
            }

            return $name;
        }
        return '';
    }

    /**
     * Parse recipients string into array of email/name pairs
     */
    private function parseRecipients($recipientsString)
    {
        $recipients = [];
        $emails = explode(',', $recipientsString);
        
        foreach ($emails as $email) {
            $email = trim($email);
            if (!empty($email)) {
                $recipients[] = [
                    'email' => $this->extractEmail($email),
                    'name' => $this->extractName($email)
                ];
            }
        }
        
        return $recipients;
    }

    /**
     * Replace recipient variables in content (Mailgun format: %recipient.variable%)
     */
    private function replaceRecipientVariables($content, $variables)
    {
        if (empty($variables) || !is_array($variables)) {
            return $content;
        }

        foreach ($variables as $key => $value) {
            // Replace Mailgun format: %recipient.key%
            $content = str_replace("%recipient.{$key}%", $value, $content);
            // Also support simple format: %key%
            $content = str_replace("%{$key}%", $value, $content);
        }

        return $content;
    }

    /**
     * Check if SMTP is enabled in configuration
     */
    public function isEnabled()
    {
        return !empty($this->config['smtp']['enabled']) &&
               !empty($this->config['smtp']['host']) &&
               !empty($this->config['smtp']['username']);
    }
}
