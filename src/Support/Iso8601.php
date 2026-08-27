<?php

declare(strict_types=1);

namespace Assinafy\SDK\Support;

/**
 * Validation for the ISO 8601 / RFC 3339 date-times the API accepts in `expires_at`.
 *
 * The API rejects a bare local date-time: the value must end in `Z` or an explicit
 * `±HH:MM` UTC offset, so an expiry is never interpreted in the server's timezone.
 *
 * Shared by {@see \Assinafy\SDK\AssinafyClient::uploadAndRequestSignatures()} and
 * {@see \Assinafy\SDK\Resources\AssignmentResource}, which apply the same rule but
 * raise different exception types — this class returns the reason and lets each
 * caller throw in its own idiom.
 *
 * @internal
 */
final class Iso8601
{
    /** The value is not shaped like an ISO 8601 date-time at all. */
    public const REASON_FORMAT = 'must be an ISO 8601 date-time';

    /** Correctly shaped, but the `±HH:MM` offset is out of range. */
    public const REASON_OFFSET = 'must use a valid UTC offset';

    /** Correctly shaped and in range, but not a real instant (e.g. February 30th). */
    public const REASON_INVALID = 'must be a valid ISO 8601 date-time';

    private const PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-](\d{2}):(\d{2}))$/';

    /**
     * Explain why `$value` is not an acceptable date-time, or `null` when it is.
     *
     * @return self::REASON_*|null
     */
    public static function reasonInvalid(string $value): ?string
    {
        if (preg_match(self::PATTERN, $value, $matches) !== 1) {
            return self::REASON_FORMAT;
        }

        if (isset($matches[1]) && ((int) $matches[1] > 23 || (int) $matches[2] > 59)) {
            return self::REASON_OFFSET;
        }

        try {
            new \DateTimeImmutable($value);
        } catch (\Exception) {
            return self::REASON_INVALID;
        }

        // A rolled-over date such as 2026-02-30 parses without throwing but records a
        // warning, so the error bag is the only signal that the instant was not real.
        $parseErrors = \DateTimeImmutable::getLastErrors();
        if (
            is_array($parseErrors)
            && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0)
        ) {
            return self::REASON_INVALID;
        }

        return null;
    }
}
