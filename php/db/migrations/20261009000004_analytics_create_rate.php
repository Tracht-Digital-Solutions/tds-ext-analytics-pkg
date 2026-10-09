<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Per-minute request counter for the two public endpoints, keyed by a SALTED
 * hash of the client IP. The raw address is never stored; rows older than an
 * hour are deleted by `Maintenance`.
 */
final class AnalyticsCreateRate extends AbstractMigration
{
    public function change(): void
    {
        $this->table('analytics_rate', ['id' => false, 'primary_key' => ['ip_hash', 'minute']])
            ->addColumn('ip_hash', 'char', ['limit' => 64, 'null' => false])
            ->addColumn('minute', 'char', ['limit' => 12, 'null' => false])
            ->addColumn('hits', 'integer', ['signed' => false, 'default' => 0])
            ->create();
    }
}
