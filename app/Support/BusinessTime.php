<?php

namespace App\Support;

use App\Models\Organization;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Organization business time. Storage stays canonical UTC; business concepts
 * (today, overdue, due date, validity) are interpreted in the organization's
 * explicit IANA timezone, resolved only by Organization::businessTimezone().
 *
 * DATE columns (quotation_date, valid_until, …) are calendar dates and are
 * compared as `Y-m-d` strings — never shifted through a UTC conversion.
 */
final class BusinessTime
{
    public const DEFAULT_TIMEZONE = 'UTC';

    /** The single validation rule for an organization timezone (canonical IANA identifier, case-sensitive). */
    public static function rules(): array
    {
        return ['required', 'string', 'max:64', 'timezone:all'];
    }

    public static function isValidTimezone(mixed $timezone): bool
    {
        return is_string($timezone) && in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }

    /** @return list<string> */
    public static function timezones(): array
    {
        return DateTimeZone::listIdentifiers();
    }

    public static function now(Organization $organization): CarbonImmutable
    {
        return CarbonImmutable::now($organization->businessTimezone());
    }

    /** The organization's current calendar date (`Y-m-d`). */
    public static function today(Organization $organization): string
    {
        return self::now($organization)->toDateString();
    }

    /** Whether a calendar date lies strictly before the organization's today. */
    public static function isBeforeToday(Organization $organization, CarbonInterface|string $date): bool
    {
        $value = $date instanceof CarbonInterface ? $date->format('Y-m-d') : $date;

        return $value < self::today($organization);
    }

    /**
     * Organization-local date (`Y-m-d`) and optional time (`H:i`) → UTC instant.
     * Without a time the start of the local day is used. Malformed values and
     * local times that do not exist (skipped by a DST change) are rejected.
     */
    public static function localToUtc(Organization $organization, string $date, ?string $time = null): CarbonImmutable
    {
        $format = $time === null ? '!Y-m-d' : '!Y-m-d H:i';
        $value = $time === null ? $date : $date.' '.$time;
        $local = CarbonImmutable::createFromFormat($format, $value, $organization->businessTimezone());
        if ($local === null || $local->format($time === null ? 'Y-m-d' : 'Y-m-d H:i') !== $value) {
            throw new InvalidArgumentException('invalid_local_datetime');
        }

        return $local->utc();
    }

    /** A stored UTC instant shown in the organization's timezone. */
    public static function toLocal(Organization $organization, CarbonInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::instance($instant)->setTimezone($organization->businessTimezone());
    }
}
