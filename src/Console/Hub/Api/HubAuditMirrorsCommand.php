<?php

namespace Kukux\DigitalSignature\Console\Hub\Api;

use Illuminate\Console\Command;
use Kukux\DigitalSignature\Hub\Specimens\MirrorAuditor;

/**
 * Nightly: compare each app's prefix on the mirrors bucket with who it holds
 * (R4). An orphan is an image of someone the app doesn't hold, or of a
 * revoked / replaced specimen.
 *
 *   php artisan signature:hub-audit-mirrors
 *   php artisan signature:hub-audit-mirrors --delete-orphans
 *
 * Exits 1 when orphans remain, so a scheduler can alert on it.
 */
class HubAuditMirrorsCommand extends Command
{
    protected $signature = 'signature:hub-audit-mirrors
        {--delete-orphans : Delete each orphan object (logged)}';

    protected $description = 'Compare app mirror objects with the hub\'s holders table';

    public function handle(MirrorAuditor $auditor): int
    {
        if (! $auditor->configured()) {
            $this->warn('SIGNATURE_HUB_MIRRORS_DISK is not set: the hub does not manage app mirrors.');

            return self::SUCCESS;
        }

        $report = $auditor->audit();

        if ($report === []) {
            $this->info('No apps registered.');

            return self::SUCCESS;
        }

        $this->table(
            ['app', 'prefix', 'objects', 'expected', 'orphans'],
            array_map(fn (array $row) => [$row['app'], $row['prefix'], $row['objects'], $row['expected'], count($row['orphans'])], $report),
        );

        $orphans = array_merge(...array_map(fn (array $row) => $row['orphans'], $report));

        if ($orphans === []) {
            $this->info('No orphans.');

            return self::SUCCESS;
        }

        foreach ($orphans as $path) {
            $this->line("  orphan: {$path}");
        }

        if ($this->option('delete-orphans')) {
            $this->info('Deleted '.$auditor->deleteOrphans($report).' orphan object(s).');

            return self::SUCCESS;
        }

        $this->warn(count($orphans).' orphan object(s). Run with --delete-orphans to remove them.');

        return self::FAILURE;
    }
}
