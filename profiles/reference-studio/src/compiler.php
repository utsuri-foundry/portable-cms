<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Compiler\LanguageLayout;
use Utsuri\PortableCms\Conventional\Content\ProfilePackage;
use Utsuri\PortableCms\Profile\ReferenceStudio\ReferenceStudioSiteBuilder;

require_once __DIR__ . '/page-view.php';
require_once __DIR__ . '/presentation.php';
require_once __DIR__ . '/reference-content.php';
require_once __DIR__ . '/reference-search.php';
require_once __DIR__ . '/site-builder.php';

// Publication passes only the package; tools may ask for the root layout.
return static fn(ProfilePackage $package, LanguageLayout $layout = LanguageLayout::Prefixed): ReferenceStudioSiteBuilder => new ReferenceStudioSiteBuilder($package, $layout);
