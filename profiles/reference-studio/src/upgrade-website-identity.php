<?php

declare(strict_types=1);

/** The engine archives the former identity before this mapping runs, including on histories. */
return static function (string $typeId, array $body): array {
    if ($typeId === 'site-settings') {
        unset($body['siteName'], $body['websiteIcon']);
    }
    return $body;
};
