<?php
declare(strict_types=1);

namespace Tds\Ext\Analytics\Support;

/**
 * Validation of one beacon batch, by allow-list.
 *
 * Everything not named here is dropped, and every string is clipped to the
 * column it lands in. A field the beacon never sends (a value, a label text, a
 * query string) has no way into the database even if a modified client sends
 * it: there is no key for it to arrive under.
 */
final class Payload
{
    public const MAX_BYTES = 16384;
    public const MAX_EVENTS = 60;

    public const TYPES = [
        'pageview', 'click', 'outbound', 'scroll', 'section', 'form_start', 'form_field', 'form_submit', 'exit',
    ];

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** A slug-ish token: CTA names, section ids, form and field names. */
    private const TOKEN = '/^[A-Za-z0-9][A-Za-z0-9_.:\-\[\]]*$/';

    /**
     * @return array{
     *   site: string, lang: string, vid: string, sid: string, ret: bool,
     *   ref: ?string, utm: ?array{source?: string, medium?: string, campaign?: string},
     *   events: list<array{t: string, p: string, k: ?string, f: ?string, n: ?int}>
     * }|null
     */
    public static function parse(string $body): ?array
    {
        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            return null;
        }
        try {
            $d = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($d) || ($d['v'] ?? null) !== 1) {
            return null;
        }
        $site = is_string($d['site'] ?? null) ? $d['site'] : '';
        $vid = is_string($d['vid'] ?? null) ? strtolower($d['vid']) : '';
        $sid = is_string($d['sid'] ?? null) ? strtolower($d['sid']) : '';
        if (!Sites::isSite($site) || preg_match(self::UUID, $vid) !== 1 || preg_match(self::UUID, $sid) !== 1) {
            return null;
        }
        $events = [];
        foreach (is_array($d['events'] ?? null) ? array_slice($d['events'], 0, self::MAX_EVENTS) : [] as $e) {
            $event = is_array($e) ? self::event($e) : null;
            if ($event !== null) {
                $events[] = $event;
            }
        }
        if ($events === []) {
            return null;
        }
        $lang = is_string($d['lang'] ?? null) ? strtolower(substr($d['lang'], 0, 2)) : 'de';
        return [
            'site' => $site,
            'lang' => in_array($lang, ['de', 'en'], true) ? $lang : 'de',
            'vid' => $vid,
            'sid' => $sid,
            'ret' => ($d['ret'] ?? false) === true,
            'ref' => self::host($d['ref'] ?? null),
            'utm' => self::utm($d['utm'] ?? null),
            'events' => $events,
        ];
    }

    /** @param array<mixed> $e */
    private static function event(array $e): ?array
    {
        $t = $e['t'] ?? null;
        $p = self::path($e['p'] ?? null);
        if (!is_string($t) || !in_array($t, self::TYPES, true) || $p === null) {
            return null;
        }
        $k = null;
        $f = null;
        $n = null;
        switch ($t) {
            case 'click':
            case 'section':
            case 'form_start':
            case 'form_submit':
                $k = self::token($e['k'] ?? null, 60);
                if ($k === null) {
                    return null;
                }
                break;
            case 'form_field':
                $k = self::token($e['k'] ?? null, 60);
                $f = self::token($e['f'] ?? null, 60);
                if ($k === null || $f === null) {
                    return null;
                }
                break;
            case 'outbound':
                $k = self::host($e['k'] ?? null);
                if ($k === null) {
                    return null;
                }
                break;
            case 'scroll':
                $n = is_int($e['n'] ?? null) && in_array($e['n'], [25, 50, 75, 100], true) ? $e['n'] : null;
                if ($n === null) {
                    return null;
                }
                break;
            case 'exit':
                $n = is_int($e['n'] ?? null) ? max(0, min($e['n'], 6 * 3600 * 1000)) : null;
                if ($n === null || $n === 0) {
                    return null;
                }
                break;
        }
        return ['t' => $t, 'p' => $p, 'k' => $k, 'f' => $f, 'n' => $n];
    }

    /** A path, nothing else: no scheme, no host, no query, no fragment. */
    private static function path(mixed $v): ?string
    {
        if (!is_string($v) || $v === '' || $v[0] !== '/' || str_starts_with($v, '//')) {
            return null;
        }
        $v = (string) preg_replace('/[?#].*$/s', '', $v);
        if (preg_match('/[\x00-\x1f\s]/', $v) === 1) {
            return null;
        }
        return substr($v, 0, 200);
    }

    private static function host(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = strtolower(trim($v));
        if ($v === '' || strlen($v) > 120 || preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/', $v) !== 1) {
            return null;
        }
        return $v;
    }

    private static function token(mixed $v, int $max): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);
        if ($v === '' || preg_match(self::TOKEN, $v) !== 1) {
            return null;
        }
        return substr($v, 0, $max);
    }

    /** @return array{source?: string, medium?: string, campaign?: string}|null */
    private static function utm(mixed $v): ?array
    {
        if (!is_array($v)) {
            return null;
        }
        $out = [];
        foreach (['source', 'medium', 'campaign'] as $key) {
            $val = $v[$key] ?? null;
            // Campaign names are free text in a URL. A value that looks like an
            // e-mail address is dropped whole — checked BEFORE the alphabet
            // filter, which would otherwise turn it into a harmless-looking
            // "maxexample.com" and keep it.
            if (is_string($val) && !str_contains($val, '@') && !str_contains(strtolower($val), '%40')) {
                $val = strtolower(trim($val));
                $val = (string) preg_replace('/[^a-z0-9_.\-+ ]/', '', $val);
                if ($val !== '') {
                    $out[$key] = substr($val, 0, 100);
                }
            }
        }
        return $out === [] ? null : $out;
    }
}
