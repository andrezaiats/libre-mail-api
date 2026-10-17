<?php

namespace LibreMailApi\Campaign;

/**
 * Starts N background campaign workers (same pattern as MessageHandler::dispatchToWorker),
 * so the HTTP request returns immediately.
 */
class ProcessSpawner
{
    private string $script;
    private string $logFile;

    public function __construct(string $script, string $logFile)
    {
        $this->script = $script;
        $this->logFile = $logFile;
    }

    public function __invoke(int $campaignId, int $workers): void
    {
        if (!function_exists('exec')) {
            throw new \RuntimeException('exec() is disabled');
        }
        if (!is_file($this->script)) {
            throw new \RuntimeException('worker script not found: ' . $this->script);
        }
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        for ($i = 1; $i <= $workers; $i++) {
            $cmd = sprintf(
                '%s %s %d %s >> %s 2>&1 &',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($this->script),
                $campaignId,
                escapeshellarg('w' . $i . '-' . bin2hex(random_bytes(3))),
                escapeshellarg($this->logFile)
            );
            $out = [];
            $code = 0;
            exec($cmd, $out, $code);
            if ($code !== 0) {
                throw new \RuntimeException("worker $i failed to start (exit $code)");
            }
        }
    }
}
