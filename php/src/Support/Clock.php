<?php
declare(strict_types=1);

namespace Tds\Ext\Analytics\Support;

/**
 * Time, in the two forms this module stores: a UTC timestamp (`gmdate`) and the
 * Europe/Berlin calendar day the dashboard groups by. Never `NOW()` or
 * `CURDATE()` in SQL — the production DB session runs on Berlin time and mixing
 * the two shifts every row by an hour or two.
 */
final class Clock
{
    public static function utc(int $ts): string
    {
        return gmdate('Y-m-d H:i:s', $ts);
    }

    public static function day(int $ts): string
    {
        return (new \DateTimeImmutable('@' . $ts))
            ->setTimezone(new \DateTimeZone('Europe/Berlin'))
            ->format('Y-m-d');
    }

    /** Validate a `YYYY-MM-DD` query value; null for anything else. */
    public static function parseDay(mixed $v): ?string
    {
        if (!is_string($v) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v, new \DateTimeZone('Europe/Berlin'));
        return $d !== false && $d->format('Y-m-d') === $v ? $v : null;
    }

    public static function addDays(string $day, int $days): string
    {
        return (new \DateTimeImmutable($day, new \DateTimeZone('Europe/Berlin')))
            ->modify(($days >= 0 ? '+' : '') . $days . ' days')
            ->format('Y-m-d');
    }
}
