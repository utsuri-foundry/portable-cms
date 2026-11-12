<?php

declare(strict_types=1);

use Utsuri\PortableCms\Conventional\Publication\PublicationFailure;
use Utsuri\PortableCms\Conventional\Publication\ReleaseTime;
use Utsuri\PortableCms\Conventional\RuntimeConfiguration;

$tests['release schedules use the installation zone and refuse gaps repeated minutes and invalid ranges'] = static function (): void {
    $now = new DateTimeImmutable('2030-01-01T00:00:00Z');
    $zone = ReleaseTime::zone('Europe/Andorra');
    assertSameValue('2030-07-01T10:30:00+00:00', ReleaseTime::parse('2030-07-01T12:30', $zone, $now)->format(DATE_ATOM), 'summer offset is resolved from installation zone');
    assertSameValue('2030-01-02T11:30:00+00:00', ReleaseTime::parse('2030-01-02T12:30', $zone, $now)->format(DATE_ATOM), 'winter offset is resolved independently');
    assertSameValue('2031-01-01T00:00:00+00:00', ReleaseTime::parse('2031-01-01T00:00', ReleaseTime::zone('UTC'), $now)->format(DATE_ATOM), 'exact one-year bound is accepted');
    foreach ([
        ['2030-01-01T00:00', 'UTC', 'schedule_time_range'],
        ['2029-12-31T23:59', 'UTC', 'schedule_time_range'],
        ['2031-01-01T00:01', 'UTC', 'schedule_time_range'],
        ['2030-03-31T02:30', 'Europe/Andorra', 'schedule_time_invalid'],
        ['2030-10-27T02:30', 'Europe/Andorra', 'schedule_time_ambiguous'],
        ['2030-02-30T12:00', 'UTC', 'schedule_time_invalid'],
        ["2030-01-02T12:30\0", 'UTC', 'schedule_time_invalid'],
        ['2030-01-02T12:30Z', 'UTC', 'schedule_time_invalid'],
        ['', 'UTC', 'schedule_time_invalid'],
    ] as [$local, $name, $code]) {
        try {
            ReleaseTime::parse($local, ReleaseTime::zone($name), $now);
            throw new RuntimeException('Invalid wall time accepted');
        } catch (PublicationFailure $failure) {
            assertSameValue($code, $failure->codeName, 'safe actionable time validation');
        }
    }
    assertSameValue('UTC', RuntimeConfiguration::timeZone('UTC'), 'legacy installations have an explicit stable UTC default');
    foreach (['+02:00', 'Invalid/Zone', '', null, ['UTC'], '../Europe/Andorra'] as $invalid) {
        try {
            RuntimeConfiguration::timeZone($invalid);
            throw new LogicException('Invalid installation time zone accepted');
        } catch (RuntimeException $failure) {
            assertTrueValue(str_contains($failure->getMessage(), 'IANA'), 'invalid configuration fails closed');
        }
    }
};
