<?php
declare(strict_types=1);

namespace Tds\Ext\Analytics\Domain;

use Tds\Ext\Analytics\Support\Clock;

/**
 * The dashboard's payloads, assembled from {@see Metrics} pairs. No SQL here:
 * a report is a composition of definitions that also drive the roll-up.
 */
final class Reports
{
    private const TOP = 50;

    public function __construct(private readonly Metrics $metrics)
    {
    }

    /** @return array<string, mixed> */
    public function overview(string $from, string $to, ?string $site): array
    {
        $now = $this->totals($from, $to, $site);
        $span = (int) ((new \DateTimeImmutable($to))->diff(new \DateTimeImmutable($from))->days) + 1;
        $prevTo = Clock::addDays($from, -1);
        $prevFrom = Clock::addDays($prevTo, -($span - 1));
        $prev = $this->totals($prevFrom, $prevTo, $site);

        return [
            'range' => ['from' => $from, 'to' => $to, 'previousFrom' => $prevFrom, 'previousTo' => $prevTo],
            'totals' => $now,
            'previous' => $prev,
            'series' => $this->series($from, $to, $site),
        ];
    }

    /** @return array<string, mixed> */
    public function summary(string $today, ?string $site): array
    {
        $from = Clock::addDays($today, -6);
        return [
            'range' => ['from' => $from, 'to' => $today],
            'totals' => $this->totals($from, $today, $site),
            'series' => $this->series($from, $today, $site),
        ];
    }

    /** @return array<string, mixed> */
    public function pages(string $from, string $to, ?string $site): array
    {
        $views = $this->metrics->counts('pageviews.path', $from, $to, $site);
        $entries = $this->metrics->counts('entries.path', $from, $to, $site);
        $exits = $this->metrics->counts('exits.path', $from, $to, $site);
        $bounces = $this->metrics->counts('bounces.path', $from, $to, $site);

        $paths = array_unique(array_merge(array_keys($views), array_keys($entries)));
        $rows = [];
        foreach ($paths as $path) {
            if ($path === '') {
                continue;
            }
            $pv = $views[$path] ?? 0;
            $en = $entries[$path] ?? 0;
            $ex = $exits[$path] ?? 0;
            $rows[] = [
                'path' => (string) $path,
                'pageviews' => $pv,
                'entries' => $en,
                'exits' => $ex,
                'exitRate' => $pv > 0 ? round($ex / $pv, 4) : null,
                'bounceRate' => $en > 0 ? round(($bounces[$path] ?? 0) / $en, 4) : null,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $b['pageviews'] <=> $a['pageviews'] ?: $b['entries'] <=> $a['entries']);
        return ['pages' => array_slice($rows, 0, self::TOP)];
    }

    /** @return array<string, mixed> */
    public function scroll(string $from, string $to, ?string $site): array
    {
        $views = $this->metrics->counts('pageviews.path', $from, $to, $site);
        $depth = [];
        foreach ($this->metrics->counts('scroll.path', $from, $to, $site) as $key => $count) {
            [$pct, $path] = array_pad(explode('|', (string) $key, 2), 2, '');
            $depth[$path][(int) $pct] = $count;
        }
        $sections = [];
        foreach ($this->metrics->counts('sections.path', $from, $to, $site) as $key => $count) {
            [$path, $id] = array_pad(explode('#', (string) $key, 2), 2, '');
            $sections[$path][] = ['id' => $id, 'count' => $count];
        }

        $rows = [];
        foreach (array_slice($views, 0, self::TOP, true) as $path => $pv) {
            $path = (string) $path;
            if ($path === '' || $pv <= 0) {
                continue;
            }
            $reached = [];
            foreach ([25, 50, 75, 100] as $m) {
                $reached[(string) $m] = round(min(1, ($depth[$path][$m] ?? 0) / $pv), 4);
            }
            $secs = array_map(
                static fn (array $s): array => ['id' => $s['id'], 'count' => $s['count'], 'share' => round(min(1, $s['count'] / $pv), 4)],
                $sections[$path] ?? [],
            );
            // Page order is the reading order: the section most people reach
            // comes first, which for a long page is also the top of it.
            usort($secs, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
            $rows[] = ['path' => $path, 'pageviews' => $pv, 'reached' => $reached, 'sections' => $secs];
        }
        return ['pages' => $rows];
    }

    /** @return array<string, mixed> */
    public function sources(string $from, string $to, ?string $site): array
    {
        $out = [];
        foreach (array_keys(Metrics::SESSION_DIMS) as $dim) {
            $out[$dim] = self::list($this->metrics->counts('visits.' . $dim, $from, $to, $site), 25);
        }
        return $out;
    }

    /** @return array<string, mixed> */
    public function clicks(string $from, string $to, ?string $site): array
    {
        return [
            'cta' => self::list($this->metrics->counts('clicks.cta', $from, $to, $site), self::TOP),
            'outbound' => self::list($this->metrics->counts('clicks.outbound', $from, $to, $site), self::TOP),
        ];
    }

    /** @return array<string, mixed> */
    public function forms(string $from, string $to, ?string $site): array
    {
        $started = $this->metrics->counts('forms.start', $from, $to, $site);
        $submitted = $this->metrics->counts('forms.submit', $from, $to, $site);
        $fields = [];
        foreach ($this->metrics->counts('forms.abandon', $from, $to, $site) as $key => $count) {
            [$form, $field] = array_pad(explode('|', (string) $key, 2), 2, '');
            $fields[$form][] = ['field' => $field, 'count' => $count];
        }
        $rows = [];
        foreach (array_unique(array_merge(array_keys($started), array_keys($submitted))) as $form) {
            $form = (string) $form;
            $st = $started[$form] ?? 0;
            $sub = $submitted[$form] ?? 0;
            $abandonedBy = $fields[$form] ?? [];
            $abandoned = array_sum(array_column($abandonedBy, 'count'));
            $rows[] = [
                'form' => $form,
                'started' => $st,
                'submitted' => $sub,
                'abandoned' => $abandoned,
                'conversion' => $st > 0 ? round(min(1, $sub / $st), 4) : null,
                'abandonedAt' => $abandonedBy,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $b['started'] <=> $a['started']);
        return ['forms' => $rows];
    }

    /** @return array<string, int|float|null> */
    private function totals(string $from, string $to, ?string $site): array
    {
        $visits = $this->metrics->total('visits.all', $from, $to, $site);
        $bounces = $this->metrics->total('bounces.all', $from, $to, $site);
        $duration = $this->metrics->total('duration.all', $from, $to, $site);
        return [
            'visits' => $visits,
            'visitors' => $this->metrics->total('visitors.all', $from, $to, $site),
            'returning' => $this->metrics->total('returning.all', $from, $to, $site),
            'pageviews' => $this->metrics->total('pageviews.all', $from, $to, $site),
            'bounceRate' => $visits > 0 ? round($bounces / $visits, 4) : null,
            'avgDurationMs' => $visits > 0 ? (int) round($duration / $visits) : null,
        ];
    }

    /** @return list<array{day: string, visits: int, pageviews: int}> one row per day, zeros filled */
    private function series(string $from, string $to, ?string $site): array
    {
        $visits = $this->metrics->counts('visits.day', $from, $to, $site);
        $views = $this->metrics->counts('pageviews.day', $from, $to, $site);
        $out = [];
        for ($d = $from; $d <= $to; $d = Clock::addDays($d, 1)) {
            $out[] = ['day' => $d, 'visits' => $visits[$d] ?? 0, 'pageviews' => $views[$d] ?? 0];
        }
        return $out;
    }

    /**
     * @param array<string, int> $counts
     * @return list<array{key: string, count: int}>
     */
    private static function list(array $counts, int $limit): array
    {
        $out = [];
        foreach (array_slice($counts, 0, $limit, true) as $k => $c) {
            $out[] = ['key' => (string) $k, 'count' => $c];
        }
        return $out;
    }
}
