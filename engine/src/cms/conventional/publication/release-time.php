<?php

declare(strict_types=1);

namespace Utsuri\PortableCms\Conventional\Publication;

/** Explicit installation-zone wall time; never the browser or PHP process default. */
final class ReleaseTime
{
    public static function zone(mixed $name): \DateTimeZone
    {
        if (!is_string($name) || !in_array($name, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            throw new \InvalidArgumentException('Installation time zone must be an IANA identifier');
        }
        return new \DateTimeZone($name);
    }

    public static function parse(string $local, \DateTimeZone $zone, \DateTimeImmutable $now): \DateTimeImmutable
    {
        if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}\z/', $local) !== 1) {
            throw new PublicationFailure('schedule_time_invalid');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $local, $zone);
        if ($date === false || $date->format('Y-m-d\TH:i') !== $local) {
            throw new PublicationFailure('schedule_time_invalid');
        }
        // A repeated autumn minute has two instants. Refuse it rather than guess.
        $wall = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $local, new \DateTimeZone('UTC'));
        if ($wall === false) {
            throw new PublicationFailure('schedule_time_invalid');
        }
        $matches = [];
        foreach ($zone->getTransitions($date->getTimestamp() - 86400, $date->getTimestamp() + 86400) ?: [] as $transition) {
            $instant = $wall->setTimestamp($wall->getTimestamp() - $transition['offset']);
            if ($instant->setTimezone($zone)->format('Y-m-d\TH:i') === $local) {
                $matches[$instant->getTimestamp()] = true;
            }
        }
        if (count($matches) > 1) {
            throw new PublicationFailure('schedule_time_ambiguous');
        }
        self::assertFuture($date, $now);
        return $date->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function assertFuture(\DateTimeImmutable $date, \DateTimeImmutable $now): void
    {
        if ($date <= $now || $date > $now->modify('+1 year')) {
            throw new PublicationFailure('schedule_time_range');
        }
    }
}
