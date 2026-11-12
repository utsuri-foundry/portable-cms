<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Content;

/** Serving profiles consume engine identity; only outgoing upgrade inspection accepts old declarations. */
final class WebsiteIdentityProfile
{
    public static function assert(ProfileManifest $profile): void
    {
        foreach ($profile->types as $type) {
            self::fields($type->fields, $type->id);
        }
    }

    /** @param list<FieldDefinition> $fields */
    private static function fields(array $fields, string $type): void
    {
        foreach ($fields as $field) {
            if (isset($field->settings['searchSharing']['siteNameField']) || ($field->settings['purpose'] ?? null) === 'website-icon') {
                throw new \InvalidArgumentException('Profile type ' . $type . ' field ' . $field->id . ' must use the name and icon from engine Website settings');
            }
            self::fields($field->fields, $type);
            foreach ($field->blockTypes as $block) {
                self::fields($block->fields, $type);
            }
        }
    }
}
