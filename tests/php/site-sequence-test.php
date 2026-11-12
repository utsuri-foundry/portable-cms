<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Database\SiteSequence;
use Utsuri\PortableCms\Conventional\Export\NormalizedExport;

$tests['site sequence export bounds preserve legacy documents and reject unsafe or inconsistent cursors'] = static function (): void {
    $legacy = normalizedExportFixture();
    assertSameValue($legacy, (new NormalizedExport($legacy))->document, 'legacy bytes acquire no invented sequence');
    $document = $legacy;
    $document['siteSequence'] = SiteSequence::MAXIMUM;
    $document['revisions'][0]['siteSequence'] = 1;
    $document['revisions'][1]['siteSequence'] = SiteSequence::MAXIMUM;
    assertSameValue($document, (new NormalizedExport($document))->document, 'portable exact integer boundary');
    $schema = (string) file_get_contents(dirname(__DIR__, 2) . '/schemas/portable-cms-export-v1.schema.json');
    \Utsuri\PortableCms\Conventional\JsonSchemaValidator::validateExportJson((new NormalizedExport($document))->json(), $schema);
    foreach ([-1, null, '1', 1.5, true, SiteSequence::MAXIMUM + 1] as $bad) {
        $invalid = [...$legacy, 'siteSequence' => $bad];
        assertInvalidNormalizedExport(static fn() => new NormalizedExport($invalid), 'invalid high-water mark accepted');
    }
    foreach ([0, null, '1', 1.5, SiteSequence::MAXIMUM + 1] as $bad) {
        $invalid = $document;
        $invalid['revisions'][0]['siteSequence'] = $bad;
        assertInvalidNormalizedExport(static fn() => new NormalizedExport($invalid), 'invalid revision position accepted');
    }
    foreach (['missing', 'smaller', 'duplicate'] as $case) {
        $invalid = $document;
        if ($case === 'missing') {
            unset($invalid['siteSequence']);
        } elseif ($case === 'smaller') {
            $invalid['siteSequence'] = 1;
        } else {
            $invalid['revisions'][1]['siteSequence'] = 1;
        }
        assertInvalidNormalizedExport(static fn() => new NormalizedExport($invalid), 'inconsistent sequence graph accepted');
    }
};
