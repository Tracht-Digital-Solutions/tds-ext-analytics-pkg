<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Anonymous day totals — what is left of a visit once its raw rows have aged
 * past the retention window. One row per (day, site, metric, dimension, key)
 * with a count; nothing in it refers to a visitor or a session.
 *
 * `dkey` rather than `key`: KEY is a reserved word in MySQL and MariaDB.
 */
final class AnalyticsCreateDaily extends AbstractMigration
{
    public function change(): void
    {
        $this->table('analytics_daily', [
            'id' => false,
            'primary_key' => ['day', 'site', 'metric', 'dim', 'dkey'],
        ])
            ->addColumn('day', 'date', ['null' => false])
            ->addColumn('site', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('metric', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('dim', 'string', ['limit' => 16, 'null' => false])
            ->addColumn('dkey', 'string', ['limit' => 255, 'null' => false, 'default' => ''])
            ->addColumn('count', 'biginteger', ['signed' => false, 'default' => 0])
            ->create();
    }
}
