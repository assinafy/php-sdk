<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Support;

use Assinafy\SDK\Support\Iso8601;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Iso8601Test extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function acceptedValues(): array
    {
        return [
            'UTC Z' => ['2026-12-31T23:59:59Z'],
            'fractional seconds' => ['2026-12-31T23:59:59.123Z'],
            'positive offset' => ['2026-12-31T23:59:59+03:00'],
            'negative offset' => ['2026-12-31T23:59:59-03:00'],
            'maximum offset' => ['2026-12-31T23:59:59+23:59'],
            'leap day in a leap year' => ['2028-02-29T12:00:00Z'],
        ];
    }

    #[DataProvider('acceptedValues')]
    public function testAcceptsIso8601DateTimesWithAnExplicitOffset(string $value): void
    {
        $this->assertNull(Iso8601::reasonInvalid($value));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rejectedValues(): array
    {
        return [
            // The API interprets a bare local time in the server's zone, so the SDK
            // refuses anything without a Z or an explicit offset.
            'no timezone' => ['2026-12-31T23:59:59', Iso8601::REASON_FORMAT],
            'date only' => ['2026-12-31', Iso8601::REASON_FORMAT],
            'space instead of T' => ['2026-12-31 23:59:59Z', Iso8601::REASON_FORMAT],
            'empty' => ['', Iso8601::REASON_FORMAT],
            'not a date' => ['tomorrow', Iso8601::REASON_FORMAT],
            'offset hours out of range' => ['2026-08-05T12:00:00+24:00', Iso8601::REASON_OFFSET],
            'offset minutes out of range' => ['2026-08-05T12:00:00+02:60', Iso8601::REASON_OFFSET],
            // Correctly shaped and in range, but not a real instant: PHP rolls these
            // over instead of throwing, so only the warning bag reveals them.
            'day beyond month end' => ['2026-02-30T12:00:00Z', Iso8601::REASON_INVALID],
            'February 29th in a common year' => ['2026-02-29T12:00:00Z', Iso8601::REASON_INVALID],
            'month 13' => ['2026-13-01T12:00:00Z', Iso8601::REASON_INVALID],
        ];
    }

    #[DataProvider('rejectedValues')]
    public function testRejectsWithTheReasonEachCallerReports(string $value, string $reason): void
    {
        $this->assertSame($reason, Iso8601::reasonInvalid($value));
    }
}
