<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

use Utsuri\PortableCms\Conventional\Compiler\ProfileBuildFailure;
use Utsuri\PortableCms\Conventional\Compiler\ProfileBuildFailures;
use Utsuri\PortableCms\Conventional\Identity\Clock;
use Utsuri\PortableCms\Conventional\Identity\RandomSource;
use Utsuri\PortableCms\Conventional\PrivateDiagnosticLog;

/** Private profile diagnostics, with no stack or technical text in the editor projection. */
final readonly class PreviewErrorReporter
{
    public function __construct(private Clock $clock, private RandomSource $random, private ?string $logPath, private string $profileRoot) {}

    public function report(\Throwable $error): string
    {
        $reference = bin2hex($this->random->bytes(16));
        $causes = array_map($this->details(...), $error instanceof ProfileBuildFailures ? $error->failures : [$error]);
        $entry = json_encode(['event' => 'preview_build_diagnostic', 'at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'), 'reference' => $reference, ...$causes[0], 'causes' => $causes], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        PrivateDiagnosticLog::append($this->logPath, $entry . "\n");
        return $reference;
    }

    /** @return array{exception: string, message: string, profileSource: ?string, context: array<string, ?string>} */
    private function details(\Throwable $error): array
    {
        $context = $error instanceof ProfileBuildFailure ? ['reason' => $error->reason->value, 'recordId' => $error->recordId, 'typeId' => $error->typeId, 'route' => $error->route, 'fieldId' => $error->fieldId, 'locale' => $error->locale] : [];
        while ($error->getPrevious() !== null) {
            $error = $error->getPrevious();
        }
        $source = null;
        foreach ([['file' => $error->getFile(), 'line' => $error->getLine()], ...$error->getTrace()] as $frame) {
            if (isset($frame['file'], $frame['line']) && str_starts_with($frame['file'], $this->profileRoot . '/')) {
                $source = substr($frame['file'], strlen($this->profileRoot) + 1) . ':' . $frame['line'];
                break;
            }
        }
        $message = mb_substr($error->getMessage(), 0, 2000);
        $message = str_replace($this->profileRoot, '[profile]', $message);
        $message = preg_replace('/(authorization\s*[:=]\s*)(Bearer|Basic)\s+[^\s,;]+/i', '$1[redacted]', $message) ?? '';
        $message = preg_replace('/(password|passwd|secret|token|authorization)\s*[:=]\s*[^\s,;]+/i', '$1=[redacted]', $message) ?? '';
        $message = preg_replace('~(https?://)[^\s/@]+:[^\s/@]+@~i', '$1[redacted]@', $message) ?? '';
        return ['exception' => $error::class, 'message' => $message, 'profileSource' => $source, 'context' => $context];
    }
}
