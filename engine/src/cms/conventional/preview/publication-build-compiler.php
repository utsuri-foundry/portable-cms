<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Preview;

/** Shares only the durable private build infrastructure, not preview selection or rendering. */
final readonly class PublicationBuildCompiler implements PreviewCompiler
{
    public function __construct(private PreviewCompiler $website, private PreviewCompiler $holding) {}

    public function compile(PreviewSnapshot $snapshot, string $outputRoot): PreviewBuild
    {
        return ($snapshot->holdingPage === null ? $this->website : $this->holding)->compile($snapshot, $outputRoot);
    }
}
