<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Ai\AssistanceCandidate;
use Utsuri\PortableCms\Conventional\Ai\AssistanceFailure;
use Utsuri\PortableCms\Conventional\Ai\AssistancePolicy;
use Utsuri\PortableCms\Conventional\Ai\AssistanceRequest;
use Utsuri\PortableCms\Conventional\Ai\AssistanceTransport;
use Utsuri\PortableCms\Conventional\Ai\HttpAssistanceProvider;

/** @return array<string, mixed> */
function aiTestPolicyDocument(): array
{
    return ['enabled' => true, 'workerAvailable' => true, 'providerNoTraining' => true, 'providerZeroRetention' => true,
        'endpoint' => 'https://gateway.example.invalid/editorial', 'credential' => 'fictional-test-credential',
        'publicAddress' => '93.184.216.34', 'provider' => 'fictional-gateway', 'model' => 'fixture-v1', 'residency' => 'EU', 'retentionHours' => 24,
        'dailyRequests' => 20, 'dailyBudgetMicros' => 1000000, 'attemptCostMicros' => 1000, 'excludedTypes' => [], 'excludedMedia' => []];
}

/** @return array<string, mixed> */
function aiTestCandidateDocument(): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/schemas/fixtures/contracts/ai-candidate.accepted.json'), true, 16, JSON_THROW_ON_ERROR);
}

$tests['AI candidate contract rejects provider metadata executable output missing languages and unbounded suggestions'] = static function (): void {
    $good = aiTestCandidateDocument();
    assertSameValue($good, AssistanceCandidate::from($good, ['en'])->document(), 'shared fixture must pass the domain contract');
    assertSameValue($good, AssistanceCandidate::from(['suggestions' => $good['suggestions'], 'formatVersion' => 1], ['en'])->document(), 'object key order is not semantic');
    $bad = [$good + ['provider' => 'secret']];
    $next = $good;
    $next['suggestions']['en']['alt'] = '<img onerror="alert(1)">';
    $bad[] = $next;
    $next['suggestions']['en']['alt'] = "bad\0text";
    $bad[] = $next;
    $next['suggestions']['en']['alt'] = "\xff";
    $bad[] = $next;
    $next['suggestions']['en']['alt'] = str_repeat('é', 301);
    $bad[] = $next;
    $next = $good;
    $next['suggestions']['en']['taxonomy'] = array_fill(0, 6, 'term');
    $bad[] = $next;
    $next = $good;
    $next['suggestions']['en']['providerId'] = 'provider-id';
    $bad[] = $next;
    foreach ($bad as $value) {
        try {
            AssistanceCandidate::from($value, ['en']);
            throw new RuntimeException('hostile candidate accepted');
        } catch (AssistanceFailure $error) {
            assertSameValue('invalid_candidate', $error->safeCode, 'candidate failure has a stable safe code');
        }
    }
    try {
        AssistanceCandidate::from($good, ['en', 'ca']);
        throw new RuntimeException('missing language accepted');
    } catch (AssistanceFailure) {
    }
};

$tests['AI policy fails closed for missing qualification and unsafe credentials endpoints and bounds'] = static function (): void {
    assertSameValue(null, AssistancePolicy::from(null), 'AI is absent by default');
    assertSameValue(null, AssistancePolicy::from(['enabled' => false]), 'explicit disable needs no credentials');
    foreach (['workerAvailable' => false, 'providerNoTraining' => false, 'providerZeroRetention' => false,
        'endpoint' => 'http://example.invalid', 'credential' => "secret\r\nInjected: value", 'retentionHours' => 169,
        'publicAddress' => '127.0.0.1', 'dailyRequests' => 101, 'excludedMedia' => ['../../private'], 'surprise' => true] as $key => $value) {
        $config = aiTestPolicyDocument();
        $config[$key] = $value;
        try {
            AssistancePolicy::from($config);
            throw new RuntimeException('unsafe AI configuration accepted: ' . $key);
        } catch (InvalidArgumentException) {
        }
    }
    $config = aiTestPolicyDocument();
    $config['excludedTypes'] = ['project'];
    $config['excludedMedia'] = [str_repeat('a', 32)];
    $policy = AssistancePolicy::from($config);
    assertSameValue(false, $policy->permits('project', str_repeat('b', 32)), 'excluded record types never send');
    assertSameValue(false, $policy->permits('article', str_repeat('a', 32)), 'excluded images never send');
    assertTrueValue(!str_contains($policy->fingerprint(), $config['credential']), 'provenance contains no credential');
};

$tests['AI HTTP adapter sends one bounded neutral request and accepts only the versioned candidate contract'] = static function (): void {
    $policy = AssistancePolicy::from(aiTestPolicyDocument());
    $transport = new class implements AssistanceTransport {
        /** @var array<string, mixed> */
        public array $sent = [];
        public string $response = '';
        public function post(string $endpoint, string $credential, string $body, string $idempotencyKey): string
        {
            $this->sent = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            assertSameValue('fictional-test-credential', $credential, 'credential stays in the private transport');
            assertSameValue(str_repeat('a', 32), $idempotencyKey, 'retry identity is independent of attempt number');
            return $this->response;
        }
    };
    $transport->response = json_encode(aiTestCandidateDocument(), JSON_THROW_ON_ERROR);
    $request = new AssistanceRequest(['locales' => ['en'], 'context' => 'A fictional courtyard.', 'policyFingerprint' => $policy->fingerprint(), 'derivativeChecksum' => hash('sha256', 'image')]);
    $provider = new HttpAssistanceProvider($policy, $transport);
    assertSameValue(aiTestCandidateDocument(), $provider->suggest($request, 'image', str_repeat('a', 32))->document(), 'adapter returns the single canonical contract');
    assertSameValue(1000, $transport->sent['maximumCostMicros'], 'gateway receives a hard cost ceiling');
    assertSameValue('image', base64_decode($transport->sent['image']['dataBase64'], true), 'only supplied derivative bytes are sent');
    foreach (['credential', 'recordId', 'sourceRevision', 'provider'] as $key) {
        assertTrueValue(!array_key_exists($key, $transport->sent), 'internal storage identities never enter the provider payload');
    }
    foreach (['not JSON', str_repeat('x', 65537), '{"providerSecret":"must not persist"}'] as $response) {
        $transport->response = $response;
        try {
            $provider->suggest($request, 'image', str_repeat('a', 32));
            throw new RuntimeException('invalid adapter response accepted');
        } catch (AssistanceFailure $error) {
            assertSameValue('invalid_candidate', $error->safeCode, 'no provider diagnostic crosses the contract');
        }
    }
};
