<?php

declare(strict_types=1);

use Utsuri\PortableCms\Profile\ReferenceStudio\PageView;
use Utsuri\PortableCms\Profile\ReferenceStudio\Presentation;

return static fn(PageView $page, ?\Utsuri\PortableCms\Conventional\Compiler\RouteDiscovery $discovery = null, ?\Utsuri\PortableCms\Conventional\Compiler\SharingImage $sharingImage = null, ?\Utsuri\PortableCms\Conventional\Compiler\WebsiteIcon $websiteIcon = null): string => Presentation::render($page, 'home', $discovery, $sharingImage, $websiteIcon);
