<?php

namespace LibreMailApi\Campaign;

use LibreMailApi\MailerFactory;
use Psr\Log\LoggerInterface;

/**
 * Wires campaign components from config.php. Everything is lazy so the Ghost
 * Mailgun path never depends on MySQL being reachable.
 */
class Factory
{
    private array $config;
    private LoggerInterface $logger;
    private ?Db $db = null;

    public function __construct(array $config, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function db(): Db
    {
        if ($this->db === null) {
            $this->db = new Db($this->config['mysql'] ?? []);
        }
        return $this->db;
    }

    public function campaignsConfig(): array
    {
        return $this->config['campaigns'] ?? [];
    }

    public function signer(): TokenSigner
    {
        return new TokenSigner((string) ($this->config['tracking']['hmac_secret'] ?? ''));
    }

    public function personalizer(): Personalizer
    {
        return new Personalizer($this->config['site']['public_url'] ?? 'https://aipster.com', $this->signer());
    }

    public function policy(): EngagementPolicy
    {
        return new EngagementPolicy($this->config['sunset'] ?? [], $this->campaignsConfig()['timezone'] ?? 'America/Sao_Paulo');
    }

    public function service(?callable $spawner = null): CampaignService
    {
        $root = dirname(__DIR__, 2);
        // Tables are created on the API path only, not on every pixel/click hit.
        $this->db()->ensureSchema();
        $spawner = $spawner ?? new ProcessSpawner($root . '/scripts/campaign-worker.php', $root . '/logs/campaign-worker.log');
        return new CampaignService(
            $this->db(),
            $this->policy(),
            $this->campaignsConfig() + ['pii_retention_days' => $this->config['sunset']['pii_retention_days'] ?? 180],
            $root . '/templates',
            $spawner,
            $this->logger
        );
    }

    /**
     * Ranker for the Ghost post newsletter send path. Lazy and read-only: no
     * schema creation, so the send worker never depends on migrations.
     */
    public function postRanker(): PostRanker
    {
        $root = dirname(__DIR__, 2);
        $service = new CampaignService(
            $this->db(),
            $this->policy(),
            $this->campaignsConfig(),
            $root . '/templates',
            function (int $campaignId, int $workers): void {
            },
            $this->logger
        );
        return new PostRanker(
            fn (\DateTimeImmutable $now) => $service->rankAudience($now),
            $this->policy(),
            $this->logger,
            (bool) ($this->config['sunset']['post_ranking'] ?? true)
        );
    }

    public function publicEndpoints(): PublicEndpoints
    {
        $site = $this->config['site'] ?? [];
        $unsub = new GhostUnsubscriber(
            $this->db(),
            $site['ghost_internal_url'] ?? 'http://127.0.0.1:2368',
            $site['public_url'] ?? 'https://aipster.com'
        );
        return new PublicEndpoints($this->db(), $this->signer(), $unsub, $this->campaignsConfig() + [
            'home_url' => rtrim($site['public_url'] ?? 'https://aipster.com', '/') . '/',
        ], $this->logger);
    }

    public function worker(): array
    {
        $mailer = new CampaignMailer(
            MailerFactory::create($this->config['smtp'], $this->logger),
            $this->campaignsConfig()['from'] ?? 'AIpster <noreply@aipster.com>'
        );
        $worker = new CampaignWorker(
            $this->db(),
            $this->personalizer(),
            [$mailer, 'send'],
            $this->campaignsConfig(),
            $this->logger
        );
        return [$worker, $mailer];
    }
}
