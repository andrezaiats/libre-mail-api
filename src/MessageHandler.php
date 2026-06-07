<?php

namespace LibreMailApi;

use Ramsey\Uuid\Uuid;

class MessageHandler
{
    private $config;
    private $storage;
    private $logger;
    private $validator;
    private $smtpHandler;
    private $eventStorage;
    private $trackingHandler;

    public function __construct($config, $storage, $logger, $eventStorage = null, $trackingHandler = null)
    {
        $this->config = $config;
        $this->storage = $storage;
        $this->logger = $logger;
        $this->validator = new Validator($config);
        $this->eventStorage = $eventStorage;
        $this->trackingHandler = $trackingHandler;

        // Initialize SMTP handler if enabled
        if (!empty($config['smtp']['enabled'])) {
            $this->smtpHandler = new SmtpHandler($config, $logger, $eventStorage, $trackingHandler);
        }
    }

    public function sendMessage($domain, $postData, $files = [])
    {
        try {
            // Validate required fields
            $validation = $this->validator->validateMessage($postData);
            if (!$validation['valid']) {
                return ['status' => 400, 'data' => ['message' => $validation['error']]];
            }

            // Generate message ID
            $messageId = $this->generateMessageId($domain);
            
            // Process message
            $message = $this->processMessage($domain, $postData, $files, $messageId);
            
            // Store message (always store for simulation/logging purposes)
            $storageKey = $this->storage->storeMessage($message);

            // Attempt to send via SMTP if enabled
            $smtpResult = null;
            if ($this->smtpHandler && $this->smtpHandler->isEnabled()) {
                $smtpResult = $this->smtpHandler->sendMessage($message);

                if (!$smtpResult['success']) {
                    // Log SMTP failure but don't fail the API call (maintain Mailgun compatibility)
                    $this->logger->warning("SMTP sending failed, message stored for simulation", [
                        'message_id' => $messageId,
                        'total_recipients' => $smtpResult['total_recipients'] ?? 0,
                        'successful_sends' => $smtpResult['successful_sends'] ?? 0,
                        'failed_sends' => $smtpResult['failed_sends'] ?? 0,
                        'smtp_errors' => $smtpResult['errors'] ?? ['Unknown error']
                    ]);
                } else {
                    $this->logger->info("SMTP sending successful for all recipients", [
                        'message_id' => $messageId,
                        'total_recipients' => $smtpResult['total_recipients'] ?? 0,
                        'successful_sends' => $smtpResult['successful_sends'] ?? 0
                    ]);
                }
            }

            // Log the operation
            $logData = [
                'domain' => $domain,
                'message_id' => $messageId,
                'to' => $postData['to'] ?? 'unknown',
                'subject' => $postData['subject'] ?? 'no subject',
                'storage_key' => $storageKey
            ];

            if ($smtpResult) {
                $logData['smtp_enabled'] = true;
                $logData['smtp_success'] = $smtpResult['success'];
                $logData['total_recipients'] = $smtpResult['total_recipients'] ?? 0;
                $logData['successful_sends'] = $smtpResult['successful_sends'] ?? 0;
                $logData['failed_sends'] = $smtpResult['failed_sends'] ?? 0;
                if (!$smtpResult['success']) {
                    $logData['smtp_errors'] = $smtpResult['errors'] ?? [];
                }
            } else {
                $logData['smtp_enabled'] = false;
            }

            $this->logger->info("Message processed", $logData);

            // Return success response (always successful for API compatibility)
            $responseData = [
                'id' => $messageId,
                'message' => 'Queued. Thank you.'
            ];

            // Add SMTP status to response if enabled (for debugging)
            if ($this->smtpHandler && $this->smtpHandler->isEnabled()) {
                $responseData['smtp_status'] = $smtpResult['success'] ? 'sent' : 'partial_or_failed';
                $responseData['smtp_total_recipients'] = $smtpResult['total_recipients'] ?? 0;
                $responseData['smtp_successful_sends'] = $smtpResult['successful_sends'] ?? 0;
                $responseData['smtp_failed_sends'] = $smtpResult['failed_sends'] ?? 0;
                if (!$smtpResult['success']) {
                    $responseData['smtp_errors'] = $smtpResult['errors'] ?? ['Unknown error'];
                }
            }

            return [
                'status' => 200,
                'data' => $responseData
            ];

        } catch (\Exception $e) {
            $this->logger->error("Error sending message: " . $e->getMessage());
            return ['status' => 500, 'data' => ['message' => 'Internal server error']];
        }
    }

    public function sendMimeMessage($domain, $postData, $files = [])
    {
        try {
            // Validate MIME message
            if (!isset($postData['to']) || !isset($files['message'])) {
                return ['status' => 400, 'data' => ['message' => 'Missing required fields: to, message']];
            }

            // Generate message ID
            $messageId = $this->generateMessageId($domain);
            
            // Process MIME message
            $message = $this->processMimeMessage($domain, $postData, $files, $messageId);
            
            // Store message (always store for simulation/logging purposes)
            $storageKey = $this->storage->storeMessage($message);

            // Attempt to send via SMTP if enabled
            $smtpResult = null;
            if ($this->smtpHandler && $this->smtpHandler->isEnabled()) {
                $smtpResult = $this->smtpHandler->sendMimeMessage($message);

                if (!$smtpResult['success']) {
                    // Log SMTP failure but don't fail the API call (maintain Mailgun compatibility)
                    $this->logger->warning("SMTP MIME sending failed, message stored for simulation", [
                        'message_id' => $messageId,
                        'total_recipients' => $smtpResult['total_recipients'] ?? 0,
                        'successful_sends' => $smtpResult['successful_sends'] ?? 0,
                        'failed_sends' => $smtpResult['failed_sends'] ?? 0,
                        'smtp_errors' => $smtpResult['errors'] ?? ['Unknown error']
                    ]);
                } else {
                    $this->logger->info("SMTP MIME sending successful for all recipients", [
                        'message_id' => $messageId,
                        'total_recipients' => $smtpResult['total_recipients'] ?? 0,
                        'successful_sends' => $smtpResult['successful_sends'] ?? 0
                    ]);
                }
            }

            // Log the operation
            $logData = [
                'domain' => $domain,
                'message_id' => $messageId,
                'to' => $postData['to'],
                'storage_key' => $storageKey
            ];

            if ($smtpResult) {
                $logData['smtp_enabled'] = true;
                $logData['smtp_success'] = $smtpResult['success'];
                $logData['total_recipients'] = $smtpResult['total_recipients'] ?? 0;
                $logData['successful_sends'] = $smtpResult['successful_sends'] ?? 0;
                $logData['failed_sends'] = $smtpResult['failed_sends'] ?? 0;
                if (!$smtpResult['success']) {
                    $logData['smtp_errors'] = $smtpResult['errors'] ?? [];
                }
            } else {
                $logData['smtp_enabled'] = false;
            }

            $this->logger->info("MIME message processed", $logData);

            // Return success response (always successful for API compatibility)
            $responseData = [
                'id' => $messageId,
                'message' => 'Queued. Thank you.'
            ];

            // Add SMTP status to response if enabled (for debugging)
            if ($this->smtpHandler && $this->smtpHandler->isEnabled()) {
                $responseData['smtp_status'] = $smtpResult['success'] ? 'sent' : 'partial_or_failed';
                $responseData['smtp_total_recipients'] = $smtpResult['total_recipients'] ?? 0;
                $responseData['smtp_successful_sends'] = $smtpResult['successful_sends'] ?? 0;
                $responseData['smtp_failed_sends'] = $smtpResult['failed_sends'] ?? 0;
                if (!$smtpResult['success']) {
                    $responseData['smtp_errors'] = $smtpResult['errors'] ?? ['Unknown error'];
                }
            }

            return [
                'status' => 200,
                'data' => $responseData
            ];

        } catch (\Exception $e) {
            $this->logger->error("Error sending MIME message: " . $e->getMessage());
            return ['status' => 500, 'data' => ['message' => 'Internal server error']];
        }
    }

    public function retrieveMessage($domain, $storageKey)
    {
        try {
            $message = $this->storage->retrieveMessage($storageKey);
            
            if (!$message) {
                return ['status' => 404, 'data' => ['message' => 'Message not found']];
            }

            // Format response to match Mailgun API
            $response = [
                'Content-Transfer-Encoding' => '7bit',
                'Content-Type' => $message['content_type'] ?? 'text/plain',
                'From' => $message['from'],
                'Message-Id' => $message['message_id'],
                'Mime-Version' => '1.0',
                'Subject' => $message['subject'],
                'To' => $message['to'],
                'X-Mailgun-Tag' => $message['tags'] ?? '',
                'sender' => $message['sender'],
                'recipients' => $message['recipients'],
                'body-html' => $message['html'] ?? '',
                'body-plain' => $message['text'] ?? '',
                'stripped-html' => $message['html'] ?? '',
                'stripped-text' => $message['text'] ?? '',
                'stripped-signature' => '',
                'message-headers' => $message['headers'] ?? [],
                'X-Mailgun-Template-Name' => $message['template'] ?? '',
                'X-Mailgun-Template-Variables' => $message['template_variables'] ?? '{}'
            ];

            return ['status' => 200, 'data' => $response];

        } catch (\Exception $e) {
            $this->logger->error("Error retrieving message: " . $e->getMessage());
            return ['status' => 500, 'data' => ['message' => 'Internal server error']];
        }
    }

    public function getQueueStatus($domain)
    {
        // Simulate queue status
        $response = [
            'regular' => [
                'is_disabled' => false,
                'disabled' => null
            ],
            'scheduled' => [
                'is_disabled' => false,
                'disabled' => null
            ]
        ];

        return ['status' => 200, 'data' => $response];
    }

    public function deleteEnvelopes($domain)
    {
        try {
            // Simulate deletion of scheduled messages
            $this->logger->info("Deleted envelopes for domain: $domain");
            
            return [
                'status' => 200,
                'data' => ['message' => 'done']
            ];

        } catch (\Exception $e) {
            $this->logger->error("Error deleting envelopes: " . $e->getMessage());
            return ['status' => 500, 'data' => ['message' => 'Internal server error']];
        }
    }

    private function generateMessageId($domain)
    {
        $uuid = Uuid::uuid4()->toString();
        return "<{$uuid}@{$domain}>";
    }

    private function processMessage($domain, $postData, $files, $messageId)
    {
        // Extract recipients from recipient-variables if provided, otherwise use 'to' field
        $toRecipients = $this->extractRecipientsFromVariables($postData);

        // Generate recipient-variables if not provided and we have multiple recipients
        $recipientVariables = $this->generateRecipientVariables($postData);

        // Normalize tags to JSON array string
        $rawTags = $postData['o:tag'] ?? '';
        if (is_array($rawTags)) {
            $tagsJson = json_encode(array_values($rawTags));
        } elseif (is_string($rawTags) && $rawTags !== '') {
            $tagsJson = json_encode([$rawTags]);
        } else {
            $tagsJson = null;
        }

        $message = [
            'message_id' => $messageId,
            'domain' => $domain,
            'from' => $postData['from'],
            'to' => $toRecipients, // Now always a comma-separated string
            'cc' => $postData['cc'] ?? '',
            'bcc' => $postData['bcc'] ?? '',
            'subject' => $postData['subject'],
            'text' => $postData['text'] ?? '',
            'html' => $postData['html'] ?? '',
            'sender' => $this->extractEmail($postData['from']),
            'recipients' => $this->extractEmails($toRecipients),
            'timestamp' => time(),
            'content_type' => 'multipart/form-data',
            'headers' => $this->buildHeaders($postData),
            'tags' => $postData['o:tag'] ?? '',
            'template' => $postData['template'] ?? '',
            'template_variables' => $postData['t:variables'] ?? '{}',
            'recipient_variables' => $recipientVariables,
            'attachments' => $this->processAttachments($files),
            // Tracking metadata for open tracking
            'tracking_opens' => !empty($postData['o:tracking-opens']),
            'email_id' => $postData['v:email-id'] ?? null,
            'tags_json' => $tagsJson,
        ];

        return $message;
    }

    private function processMimeMessage($domain, $postData, $files, $messageId)
    {
        $mimeContent = file_get_contents($files['message']['tmp_name']);
        
        $message = [
            'message_id' => $messageId,
            'domain' => $domain,
            'to' => $postData['to'],
            'mime_content' => $mimeContent,
            'timestamp' => time(),
            'content_type' => 'message/rfc822',
            'headers' => [],
            'tags' => $postData['o:tag'] ?? '',
            'template' => $postData['template'] ?? '',
            'template_variables' => $postData['t:variables'] ?? '{}'
        ];

        return $message;
    }

    private function extractEmail($emailString)
    {
        if (preg_match('/<([^>]+)>/', $emailString, $matches)) {
            return $matches[1];
        }
        return trim($emailString);
    }

    private function extractEmails($emailString)
    {
        $emails = [];
        $parts = explode(',', $emailString);

        foreach ($parts as $part) {
            $emails[] = $this->extractEmail(trim($part));
        }

        return implode(', ', $emails);
    }

    /**
     * Extract recipients from recipient-variables if provided, otherwise use 'to' field
     * Priority: recipient-variables keys > 'to' field
     */
    private function extractRecipientsFromVariables($postData)
    {
        // If recipient-variables is provided, use its keys as recipients
        if (!empty($postData['recipient-variables'])) {
            $recipientVariables = json_decode($postData['recipient-variables'], true);
            if (is_array($recipientVariables) && !empty($recipientVariables)) {
                // Use the keys (email addresses) from recipient-variables
                $recipients = array_keys($recipientVariables);
                return implode(',', array_map('trim', $recipients));
            }
        }

        // Fallback to original behavior: use 'to' field
        return $this->normalizeRecipients($postData['to']);
    }

    /**
     * Normalize recipients to handle both formats:
     * 1. Comma-separated string: "email1@example.com,email2@example.com"
     * 2. Array from multiple form fields: ["email1@example.com", "email2@example.com"]
     */
    private function normalizeRecipients($toData)
    {
        if (is_array($toData)) {
            // Multiple 'to' parameters (Mailgun native format)
            // PHP receives them as an array
            return implode(',', array_map('trim', $toData));
        } else {
            // Single 'to' parameter with comma-separated emails
            return trim($toData);
        }
    }

    /**
     * Generate recipient-variables JSON for multiple recipients
     * This creates a JSON object with recipient email as key and extracted name/email info as values
     * Format matches Mailgun's expected structure with first/last name separation
     */
    private function generateRecipientVariables($postData)
    {
        // If recipient-variables is already provided, use it
        if (!empty($postData['recipient-variables'])) {
            return $postData['recipient-variables'];
        }

        // If we don't have multiple recipients, return empty JSON
        if (!is_array($postData['to'])) {
            return '{}';
        }

        $recipientVariables = [];
        $recipientIndex = 1;

        foreach ($postData['to'] as $recipient) {
            $recipient = trim($recipient);
            if (empty($recipient)) {
                continue;
            }

            $email = $this->extractEmail($recipient);
            $name = $this->extractName($recipient);

            // Split name into first and last parts
            $nameParts = $this->splitName($name);

            // Create variables for this recipient (Mailgun format)
            $recipientVariables[$email] = [
                'first' => $nameParts['first'],
                'last' => $nameParts['last'],
                'id' => 'to_' . $recipientIndex
            ];

            $recipientIndex++;
        }

        return json_encode($recipientVariables);
    }

    /**
     * Extract name from email string like "John Doe <john@example.com>" or just "john@example.com"
     */
    private function extractName($emailString)
    {
        $emailString = trim($emailString);

        // Check if it's in format "Name <email@domain.com>"
        if (preg_match('/^"?([^"<]+)"?\s*<[^>]+>$/', $emailString, $matches)) {
            return trim($matches[1], '"');
        }

        // Check if it's in format Name <email@domain.com> without quotes
        if (preg_match('/^([^<]+)\s*<[^>]+>$/', $emailString, $matches)) {
            return trim($matches[1]);
        }

        // If it's just an email, extract the local part as name
        $email = $this->extractEmail($emailString);
        $localPart = strstr($email, '@', true);

        // Convert email local part to a more readable name
        return ucwords(str_replace(['.', '_', '-'], ' ', $localPart));
    }

    /**
     * Split a full name into first and last name parts
     */
    private function splitName($fullName)
    {
        $fullName = trim($fullName);

        if (empty($fullName)) {
            return ['first' => '', 'last' => ''];
        }

        // Split by spaces
        $parts = explode(' ', $fullName);
        $parts = array_filter($parts); // Remove empty parts
        $parts = array_values($parts); // Re-index

        if (count($parts) === 0) {
            return ['first' => '', 'last' => ''];
        } elseif (count($parts) === 1) {
            return ['first' => $parts[0], 'last' => ''];
        } else {
            // First part is first name, everything else is last name
            $first = $parts[0];
            $last = implode(' ', array_slice($parts, 1));
            return ['first' => $first, 'last' => $last];
        }
    }

    private function buildHeaders($postData)
    {
        // Normalize recipients for headers
        $toRecipients = $this->normalizeRecipients($postData['to']);

        $headers = [
            ['Mime-Version', '1.0'],
            ['Subject', $postData['subject']],
            ['From', $postData['from']],
            ['To', $toRecipients],
            ['Content-Transfer-Encoding', '7bit']
        ];

        // Add custom headers
        foreach ($postData as $key => $value) {
            if (strpos($key, 'h:') === 0) {
                $headerName = substr($key, 2);
                $headers[] = [$headerName, $value];
            }
        }

        return $headers;
    }

    private function processAttachments($files)
    {
        $attachments = [];

        if (isset($files['attachment'])) {
            // Handle single file or multiple files
            if (is_array($files['attachment']['name'])) {
                // Multiple files
                $fileCount = count($files['attachment']['name']);
                for ($i = 0; $i < $fileCount; $i++) {
                    if (!empty($files['attachment']['name'][$i]) &&
                        !empty($files['attachment']['tmp_name'][$i]) &&
                        is_uploaded_file($files['attachment']['tmp_name'][$i])) {

                        // Store the attachment
                        $storedAttachment = $this->storage->storeAttachment([
                            'name' => $files['attachment']['name'][$i],
                            'tmp_name' => $files['attachment']['tmp_name'][$i],
                            'size' => $files['attachment']['size'][$i],
                            'type' => $files['attachment']['type'][$i]
                        ], '');

                        if ($storedAttachment) {
                            $attachments[] = [
                                'filename' => $files['attachment']['name'][$i],
                                'name' => $files['attachment']['name'][$i],
                                'size' => $files['attachment']['size'][$i],
                                'type' => $files['attachment']['type'][$i],
                                'path' => $storedAttachment['path']
                            ];
                        }
                    }
                }
            } else {
                // Single file
                if (!empty($files['attachment']['name']) &&
                    !empty($files['attachment']['tmp_name']) &&
                    is_uploaded_file($files['attachment']['tmp_name'])) {

                    // Store the attachment
                    $storedAttachment = $this->storage->storeAttachment($files['attachment'], '');

                    if ($storedAttachment) {
                        $attachments[] = [
                            'filename' => $files['attachment']['name'],
                            'name' => $files['attachment']['name'],
                            'size' => $files['attachment']['size'],
                            'type' => $files['attachment']['type'],
                            'path' => $storedAttachment['path']
                        ];
                    }
                }
            }
        }

        return $attachments;
    }

    /**
     * Test SMTP connection
     */
    public function testSmtpConnection()
    {
        if (!$this->smtpHandler || !$this->smtpHandler->isEnabled()) {
            return [
                'status' => 400,
                'data' => [
                    'message' => 'SMTP is not enabled',
                    'smtp_enabled' => false
                ]
            ];
        }

        $result = $this->smtpHandler->testConnection();

        return [
            'status' => $result['success'] ? 200 : 500,
            'data' => [
                'message' => $result['success'] ? 'SMTP connection successful' : 'SMTP connection failed',
                'smtp_enabled' => true,
                'smtp_host' => $this->config['smtp']['host'],
                'smtp_port' => $this->config['smtp']['port'],
                'smtp_encryption' => $this->config['smtp']['encryption'],
                'success' => $result['success'],
                'error' => $result['error'] ?? null
            ]
        ];
    }

    /**
     * Get SMTP configuration status
     */
    public function getSmtpStatus()
    {
        $smtpEnabled = !empty($this->config['smtp']['enabled']);
        $smtpConfigured = $smtpEnabled &&
                         !empty($this->config['smtp']['host']) &&
                         !empty($this->config['smtp']['username']);

        return [
            'status' => 200,
            'data' => [
                'smtp_enabled' => $smtpEnabled,
                'smtp_configured' => $smtpConfigured,
                'smtp_host' => $this->config['smtp']['host'] ?? null,
                'smtp_port' => $this->config['smtp']['port'] ?? null,
                'smtp_encryption' => $this->config['smtp']['encryption'] ?? null,
                'smtp_username' => $smtpEnabled ? $this->config['smtp']['username'] : null
            ]
        ];
    }
}
