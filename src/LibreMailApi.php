<?php

namespace LibreMailApi;

use GuzzleHttp\Client;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class LibreMailApi
{
    private $config;
    private $logger;
    private $messageHandler;
    private $storage;
    private $validator;
    private $eventStorage;
    private $trackingHandler;
    private ?Campaign\Factory $campaignFactory = null;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../config/config.php';
        $this->initializeLogger();
        $this->initializeComponents();
    }

    private function initializeLogger()
    {
        $this->logger = new Logger('libre-mail-api');
        if ($this->config['logging']['enabled']) {
            $logDir = dirname($this->config['logging']['file']);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            $this->logger->pushHandler(
                new StreamHandler($this->config['logging']['file'], Logger::INFO)
            );
        }
    }

    private function initializeComponents()
    {
        $this->storage = new Storage($this->config);
        $this->validator = new Validator($this->config);
        $this->eventStorage = new EventStorage($this->config['storage']['path']);
        $this->trackingHandler = new TrackingHandler(
            $this->eventStorage,
            $this->config['tracking']['pixel_base_url'] ?? 'https://localhost/t/o',
            $this->config['tracking']['hmac_secret'] ?? 'default-secret'
        );
        $this->messageHandler = new MessageHandler(
            $this->config, $this->storage, $this->logger,
            $this->eventStorage, $this->trackingHandler
        );
    }

    public function handleRequest()
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        
        // Remove leading slash
        $uri = ltrim($uri, '/');
        
        $this->logger->info("Request: $method $uri");

        try {
            // Tracking pixel route — no auth required
            if ($method === 'GET' && preg_match('#^t/o/(.+)$#', $uri, $matches)) {
                $this->trackingHandler->handlePixelRequest($matches[1]);
                return true;
            }

            // Campaign open/click/unsubscribe routes — no auth required
            if (preg_match('#^t/(co|c|u)/#', $uri)) {
                if ($this->campaigns()->publicEndpoints()->handle($method, $uri, $_SERVER, $_POST)) {
                    return true;
                }
                return $this->sendResponse(404, ['message' => 'Not found']);
            }

            // Authenticate request
            if (!$this->authenticate()) {
                return $this->sendResponse(401, ['message' => 'Unauthorized']);
            }

            // Route the request
            $response = $this->route($method, $uri);
            return $this->sendResponse($response['status'], $response['data']);

        } catch (\Exception $e) {
            $this->logger->error("Error handling request: " . $e->getMessage());
            return $this->sendResponse(500, ['message' => 'Internal server error']);
        }
    }

    private function authenticate()
    {
        if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
            return false;
        }

        return $_SERVER['PHP_AUTH_USER'] === $this->config['api']['auth']['username'] &&
               $_SERVER['PHP_AUTH_PW'] === $this->config['api']['auth']['password'];
    }

    private function campaigns(): Campaign\Factory
    {
        if ($this->campaignFactory === null) {
            $this->campaignFactory = new Campaign\Factory($this->config, $this->logger);
        }
        return $this->campaignFactory;
    }

    /**
     * Daily/re-engagement campaign API (JSON):
     *   POST v3/campaigns               create (or dry_run / test_to)
     *   GET  v3/campaigns/{key}         status
     *   POST v3/campaigns/{key}/resume  reclaim expired leases and restart workers
     */
    private function routeCampaigns(string $method, string $uri): ?array
    {
        if (!preg_match('#^v3/campaigns(?:/([^/]+))?(/resume)?/?$#', $uri, $m)) {
            return null;
        }
        $key = isset($m[1]) && $m[1] !== '' ? rawurldecode($m[1]) : null;
        $resume = !empty($m[2]);
        try {
            if ($key === null && $method === 'POST') {
                $body = json_decode((string) file_get_contents('php://input'), true);
                if (!is_array($body)) {
                    return ['status' => 400, 'data' => ['message' => 'Body must be a JSON object']];
                }
                return $this->campaigns()->service()->create($body);
            }
            if ($key !== null && $resume && $method === 'POST') {
                return $this->campaigns()->service()->resume($key);
            }
            if ($key !== null && !$resume && $method === 'GET') {
                return $this->campaigns()->service()->status($key);
            }
            return ['status' => 405, 'data' => ['message' => 'Method not allowed']];
        } catch (\Throwable $e) {
            $this->logger->error('campaign API error', ['uri' => $uri, 'error' => $e->getMessage()]);
            return ['status' => 500, 'data' => ['message' => 'Internal server error']];
        }
    }

    private function route($method, $uri)
    {
        $campaign = $this->routeCampaigns($method, $uri);
        if ($campaign !== null) {
            return $campaign;
        }

        // Parse URI patterns:
        // v3/{domain}/messages
        // v3/domains/{domain}/messages/{storage_key}
        $parts = explode('/', $uri);

        if (count($parts) < 3 || $parts[0] !== 'v3') {
            return ['status' => 404, 'data' => ['message' => 'Not found']];
        }

        // Handle different URI patterns
        if ($parts[1] === 'domains') {
            // Pattern: v3/domains/{domain}/messages/{storage_key}
            if (count($parts) < 4) {
                return ['status' => 404, 'data' => ['message' => 'Not found']];
            }
            $domain = $parts[2];
            $endpoint = $parts[3];
        } else {
            // Pattern: v3/{domain}/messages
            $domain = $parts[1];
            $endpoint = $parts[2];
        }

        switch ($method) {
            case 'POST':
                return $this->handlePost($domain, $endpoint, $parts);
            case 'GET':
                return $this->handleGet($domain, $endpoint, $parts);
            case 'DELETE':
                return $this->handleDelete($domain, $endpoint, $parts);
            default:
                return ['status' => 405, 'data' => ['message' => 'Method not allowed']];
        }
    }

    private function handlePost($domain, $endpoint, $parts)
    {
        switch ($endpoint) {
            case 'messages':
                return $this->messageHandler->sendMessage($domain, $_POST, $_FILES);
            case 'messages.mime':
                return $this->messageHandler->sendMimeMessage($domain, $_POST, $_FILES);
            default:
                return ['status' => 404, 'data' => ['message' => 'Endpoint not found']];
        }
    }

    private function handleGet($domain, $endpoint, $parts)
    {
        switch ($endpoint) {
            case 'messages':
                // Check for storage key in different positions based on URI pattern
                $storageKey = null;
                if ($parts[1] === 'domains' && isset($parts[4])) {
                    // Pattern: v3/domains/{domain}/messages/{storage_key}
                    $storageKey = $parts[4];
                } elseif ($parts[1] !== 'domains' && isset($parts[3])) {
                    // Pattern: v3/{domain}/messages/{storage_key}
                    $storageKey = $parts[3];
                }

                if ($storageKey) {
                    return $this->messageHandler->retrieveMessage($domain, $storageKey);
                }
                break;
            case 'events':
                return $this->handleEventsRequest($domain);
            case 'sending_queues':
                return $this->messageHandler->getQueueStatus($domain);
            case 'smtp':
                // Handle SMTP-related endpoints
                if (isset($parts[4]) && $parts[4] === 'test') {
                    return $this->messageHandler->testSmtpConnection();
                } else {
                    return $this->messageHandler->getSmtpStatus();
                }
            default:
                return ['status' => 404, 'data' => ['message' => 'Endpoint not found']];
        }

        return ['status' => 404, 'data' => ['message' => 'Not found']];
    }

    private function handleEventsRequest($domain)
    {
        $params = $_GET;
        $options = [
            'event' => $params['event'] ?? null,
            'tags' => $params['tags'] ?? null,
            'begin' => isset($params['begin']) ? (float) $params['begin'] : null,
            'end' => isset($params['end']) ? (float) $params['end'] : null,
            'limit' => isset($params['limit']) ? (int) $params['limit'] : 300,
            'ascending' => $params['ascending'] ?? 'yes',
            'page' => $params['page'] ?? null,
        ];

        $baseUrl = $this->config['api']['base_url'];
        $result = $this->eventStorage->fetch($options, $baseUrl, $domain);

        return ['status' => 200, 'data' => $result];
    }

    private function handleDelete($domain, $endpoint, $parts)
    {
        switch ($endpoint) {
            case 'envelopes':
                return $this->messageHandler->deleteEnvelopes($domain);
            default:
                return ['status' => 404, 'data' => ['message' => 'Endpoint not found']];
        }
    }

    private function sendResponse($status, $data)
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT);
        return true;
    }
}
