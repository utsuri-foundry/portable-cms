<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

interface PreviewCompiler
{
    public function compile(PreviewSnapshot $snapshot, string $outputRoot): PreviewBuild;
}
