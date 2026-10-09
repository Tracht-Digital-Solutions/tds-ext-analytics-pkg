<?php
declare(strict_types=1);

namespace Tds\Ext\Analytics\Support;

use MaxMind\Db\Reader;

/**
 * Country from an IP address, looked up in a LOCAL database file — the address
 * never leaves the server.
 *
 * The file is DB-IP's free "IP to Country Lite" (CC BY 4.0; the dashboard
 * carries the attribution). The host has no cron, so {@see refresh()} runs from
 * the in-process maintenance: it fetches the current month's file when the
 * local copy is missing or older than 35 days, at most one attempt a day. Until
 * a file exists every lookup answers `null`, which the dashboard shows as
 * "unbekannt" — measurement never depends on it.
 */
final class GeoIp
{
    private const MAX_AGE = 35 * 86400;
    private const RETRY_AFTER = 86400;

    private ?Reader $reader = null;
    private bool $opened = false;

    public function __construct(private readonly string $dir)
    {
    }

    /**
     * The system temp dir: writable on every host this runs on, and losing it
     * costs one re-download, not data. Deliberately no env var of its own.
     */
    public static function defaultDir(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tds-analytics';
    }

    public function file(): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . 'dbip-country-lite.mmdb';
    }

    public function available(): bool
    {
        return class_exists(Reader::class) && is_file($this->file());
    }

    /** Two-letter ISO code, upper case, or null. */
    public function country(?string $ip): ?string
    {
        if ($ip === null || !$this->open()) {
            return null;
        }
        try {
            $record = $this->reader?->get($ip);
        } catch (\Throwable) {
            return null;
        }
        $code = is_array($record) ? ($record['country']['iso_code'] ?? null) : null;
        return is_string($code) && preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : null;
    }

    private function open(): bool
    {
        if (!$this->opened) {
            $this->opened = true;
            if ($this->available()) {
                try {
                    $this->reader = new Reader($this->file());
                } catch (\Throwable) {
                    $this->reader = null;
                }
            }
        }
        return $this->reader !== null;
    }

    /**
     * Download the current month's file if the local copy is stale. Never
     * throws; returns whether a usable file exists afterwards.
     *
     * @param (callable(string): (string|false))|null $fetch test seam; default is an HTTP GET
     */
    public function refresh(?callable $fetch = null, ?int $now = null): bool
    {
        $now ??= time();
        $file = $this->file();
        if (is_file($file) && $now - (int) filemtime($file) < self::MAX_AGE) {
            return true;
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            return is_file($file);
        }
        $marker = $this->dir . DIRECTORY_SEPARATOR . 'last-attempt';
        if (is_file($marker) && $now - (int) filemtime($marker) < self::RETRY_AFTER) {
            return is_file($file);
        }
        @touch($marker, $now);

        $fetch ??= static function (string $url): string|false {
            $ctx = stream_context_create(['http' => ['timeout' => 20, 'user_agent' => 'tds-analytics']]);
            return @file_get_contents($url, false, $ctx);
        };
        // The current month first; early in a month DB-IP may not have
        // published it yet, so fall back to the previous one.
        foreach ([gmdate('Y-m', $now), gmdate('Y-m', strtotime('first day of last month', $now) ?: $now)] as $month) {
            $gz = $fetch("https://download.db-ip.com/free/dbip-country-lite-{$month}.mmdb.gz");
            if (!is_string($gz) || $gz === '') {
                continue;
            }
            $raw = @gzdecode($gz);
            if (!is_string($raw) || strlen($raw) < 1024) {
                continue;
            }
            $tmp = $file . '.tmp';
            if (@file_put_contents($tmp, $raw) === false || !@rename($tmp, $file)) {
                @unlink($tmp);
                continue;
            }
            $this->opened = false;
            $this->reader = null;
            return true;
        }
        return is_file($file);
    }
}
