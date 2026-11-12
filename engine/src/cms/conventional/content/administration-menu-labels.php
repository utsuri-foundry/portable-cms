<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

use Utsuri\PortableCms\Conventional\I18n\InterfaceLanguage;
use Utsuri\PortableCms\Conventional\I18n\Translator;

/** Profile and installation names must not make administration destinations ambiguous. */
final class AdministrationMenuLabels
{
    public static function assert(ProfileManifest $profile, ?AdministrationLabels $labels = null, ?EditorialTerminology $terminology = null): void
    {
        $terminology ??= new EditorialTerminology($profile->id, 0, []);
        foreach (InterfaceLanguage::SUPPORTED as $language) {
            $presented = $terminology->present($profile, $language, $labels);
            $t = Translator::load($language);
            $reserved = [];
            foreach (['shell.nav.overview', 'shell.nav.media', 'shell.nav.content_names', 'shell.nav.website_design', 'shell.nav.export', 'installation.title', 'holding.settings', 'languages.title', 'wording.title', 'account.team', 'account.personal', 'publication.title', 'preview.list.title'] as $key) {
                $reserved[self::normalize($t->text($key))] = $key;
            }
            $seen = [];
            foreach ($presented->types as $type) {
                foreach ([$type->label, $type->singularLabel] as $name) {
                    self::check($type->id, $language, $name, $reserved, $seen);
                }
            }
            foreach ($presented->navigation as $item) {
                if ($item->targetKind !== 'system') {
                    self::check($item->targetId, $language, $item->label, $reserved, $seen);
                }
            }
        }
    }

    /**
     * @param array<string, string> $reserved
     * @param array<string, string> $seen
     */
    private static function check(string $type, string $language, string $name, array $reserved, array &$seen): void
    {
        $key = self::normalize($name);
        $collision = $reserved[$key] ?? (isset($seen[$key]) && $seen[$key] !== $type ? $seen[$key] : null);
        if ($collision !== null) {
            throw new \InvalidArgumentException('Profile type ' . $type . ' label in ' . $language . ' collides with ' . $collision . ': ' . $name);
        }
        $seen[$key] = $type;
    }

    private static function normalize(string $value): string
    {
        return mb_strtolower(\Normalizer::normalize(preg_replace('/\s+/u', ' ', trim($value)) ?? $value, \Normalizer::FORM_C) ?: $value, 'UTF-8');
    }
}
