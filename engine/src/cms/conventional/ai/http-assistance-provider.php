<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Ai;

interface AssistanceTransport
{
    public function post(string $endpoint, string $credential, string $body, string $idempotencyKey): string;
}

/** A reviewed gateway translates the one internal request into its provider API. */
final class HttpAssistanceProvider implements AssistanceProvider
{
    public function __construct(private readonly AssistancePolicy $policy, private readonly AssistanceTransport $transport) {}

    public function suggest(AssistanceRequest $request, string $imageBytes, string $idempotencyKey): AssistanceCandidate
    {
        if ($imageBytes === '' || !hash_equals((string) ($request->frozen['derivativeChecksum'] ?? ''), hash('sha256', $imageBytes)) || strlen($imageBytes) > 4194304 || preg_match('/\A[a-f0-9]{32}\z/', $idempotencyKey) !== 1
            || $this->policy->fingerprint() !== ($request->frozen['policyFingerprint'] ?? '')) {
            throw new AssistanceFailure('invalid_request');
        }
        $body = json_encode(['formatVersion' => 1, 'recipe' => 'image-editorial-v1', 'model' => $this->policy->model,
            'locales' => $request->locales(), 'context' => $request->frozen['context'],
            'maximumCostMicros' => $this->policy->attemptCostMicros, 'residency' => $this->policy->residency,
            'image' => ['mime' => 'image/webp', 'sha256' => $request->frozen['derivativeChecksum'], 'dataBase64' => base64_encode($imageBytes)]], JSON_THROW_ON_ERROR);
        $response = $this->transport->post($this->policy->endpoint, $this->policy->credential, $body, $idempotencyKey);
        if (strlen($response) > 65536) {
            throw new AssistanceFailure('invalid_candidate');
        }
        try {
            return AssistanceCandidate::from(json_decode($response, true, 16, JSON_THROW_ON_ERROR), $request->locales());
        } catch (\JsonException) {
            throw new AssistanceFailure('invalid_candidate');
        }
    }
}

final class CurlAssistanceTransport implements AssistanceTransport
{
    public function __construct(private readonly string $publicAddress) {}

    public function post(string $endpoint, string $credential, string $body, string $idempotencyKey): string
    {
        if (!function_exists('curl_init')) {
            throw new AssistanceFailure('unavailable');
        }
        $host = parse_url($endpoint, PHP_URL_HOST);
        if (!is_string($host)) {
            throw new AssistanceFailure('provider_failed');
        }
        // Pin one public IPv4 address for this transfer; neither redirects nor a DNS rebinding may reach private hosts.
        $address = $this->publicAddress;
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new AssistanceFailure('provider_failed');
        }
        $curl = curl_init($endpoint);
        if ($curl === false) {
            throw new AssistanceFailure('provider_failed');
        }
        $response = '';
        try {
            $configured = curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $credential, 'Idempotency-Key: ' . $idempotencyKey],
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 45, CURLOPT_PROXY => '',
                CURLOPT_RESOLVE => [$host . ':443:' . $address],
                CURLOPT_WRITEFUNCTION => static function (\CurlHandle $handle, string $chunk) use (&$response): int {
                    if (strlen($response) + strlen($chunk) > 65536) {
                        return 0;
                    }
                    $response .= $chunk;
                    return strlen($chunk);
                }]);
            if (!$configured) {
                throw new AssistanceFailure('provider_failed');
            }
            $ok = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $mime = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
            if ($ok === false) {
                // Ambiguous transport failures are terminal: a timeout may already have incurred provider cost.
                throw new AssistanceFailure('provider_failed');
            }
            if ($status === 429 || $status === 503) {
                throw new AssistanceFailure('provider_retry', true);
            }
            if ($status !== 200 || !is_string($mime) || strtolower(trim(explode(';', $mime)[0])) !== 'application/json') {
                throw new AssistanceFailure('provider_failed');
            }
            return $response;
        } finally {
            curl_close($curl);
        }
    }
}
