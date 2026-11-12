<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Ai;

/** Installation policy is private and explicit. An absent or disabled configuration performs no network I/O. */
final readonly class AssistancePolicy
{
    /**
     * @param list<string> $excludedTypes
     * @param list<string> $excludedMedia
     */
    private function __construct(
        public string $endpoint,
        public string $credential,
        public string $publicAddress,
        public string $provider,
        public string $model,
        public string $residency,
        public int $retentionHours,
        public int $dailyRequests,
        public int $dailyBudgetMicros,
        public int $attemptCostMicros,
        public array $excludedTypes,
        public array $excludedMedia,
    ) {}

    public static function from(mixed $config): ?self
    {
        if ($config === null || (is_array($config) && ($config['enabled'] ?? false) === false)) {
            return null;
        }
        if (!is_array($config) || ($config['enabled'] ?? null) !== true || ($config['workerAvailable'] ?? null) !== true
            || ($config['providerNoTraining'] ?? null) !== true || ($config['providerZeroRetention'] ?? null) !== true) {
            throw new \InvalidArgumentException('AI requires worker, residency and provider privacy qualification.');
        }
        $keys = array_keys($config);
        sort($keys);
        $allowed = ['enabled', 'workerAvailable', 'providerNoTraining', 'providerZeroRetention', 'endpoint', 'credential', 'publicAddress', 'provider', 'model', 'residency', 'retentionHours', 'dailyRequests', 'dailyBudgetMicros', 'attemptCostMicros', 'excludedTypes', 'excludedMedia'];
        sort($allowed);
        if ($keys !== $allowed) {
            throw new \InvalidArgumentException('AI configuration keys are invalid.');
        }
        foreach (['endpoint', 'credential', 'provider', 'model', 'residency'] as $key) {
            if (!is_string($config[$key]) || $config[$key] === '' || strlen($config[$key]) > 500
                || preg_match('/[\x00-\x20\x7f]/', $config[$key]) === 1) {
                throw new \InvalidArgumentException('AI configuration identity is invalid.');
            }
        }
        if (!is_string($config['publicAddress']) || filter_var($config['publicAddress'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new \InvalidArgumentException('AI requires a qualified public IPv4 address.');
        }
        $parts = parse_url($config['endpoint']);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || isset($parts['port']) && $parts['port'] !== 443) {
            throw new \InvalidArgumentException('AI endpoint must be a qualified HTTPS origin path.');
        }
        foreach (['retentionHours' => 168, 'dailyRequests' => 100, 'dailyBudgetMicros' => 100000000, 'attemptCostMicros' => 10000000] as $key => $maximum) {
            if (!is_int($config[$key]) || $config[$key] < 1 || $config[$key] > $maximum) {
                throw new \InvalidArgumentException('AI policy bounds are invalid.');
            }
        }
        foreach (['excludedTypes', 'excludedMedia'] as $key) {
            if (!is_array($config[$key]) || !array_is_list($config[$key]) || count($config[$key]) > 1000) {
                throw new \InvalidArgumentException('AI exclusion list is invalid.');
            }
            foreach ($config[$key] as $id) {
                if (!is_string($id) || preg_match($key === 'excludedMedia' ? '/\A[a-f0-9]{32}\z/' : '/\A[a-z][a-z0-9_-]{0,63}\z/', $id) !== 1) {
                    throw new \InvalidArgumentException('AI exclusion identity is invalid.');
                }
            }
        }
        return new self($config['endpoint'], $config['credential'], $config['publicAddress'], $config['provider'], $config['model'], $config['residency'], $config['retentionHours'], $config['dailyRequests'], $config['dailyBudgetMicros'], $config['attemptCostMicros'], $config['excludedTypes'], $config['excludedMedia']);
    }

    public function permits(string $typeId, string $mediaId): bool
    {
        return !in_array($typeId, $this->excludedTypes, true) && !in_array($mediaId, $this->excludedMedia, true);
    }

    /** Credentials never enter the job. A policy change invalidates outstanding jobs. */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([$this->endpoint, $this->publicAddress, $this->dailyRequests, $this->dailyBudgetMicros, $this->provider, $this->model, $this->residency, $this->retentionHours, $this->attemptCostMicros, $this->excludedTypes, $this->excludedMedia], JSON_THROW_ON_ERROR));
    }
}
