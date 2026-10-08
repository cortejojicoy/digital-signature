<?php

namespace Kukux\DigitalSignature\Hub\Specimens;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Hub\Api\People;
use Kukux\DigitalSignature\Models\HubApp;
use Kukux\DigitalSignature\Models\HubHolder;

/**
 * Nightly reconcile of the mirrors bucket against the holders table (R4):
 * each app's prefix should hold `<hub_uuid>.png` only for people it holds,
 * and only their current active specimen. Anything else is an orphan.
 */
class MirrorAuditor
{
    public function __construct(
        private readonly People $people,
        private readonly SpecimenService $specimens,
    ) {}

    public function configured(): bool
    {
        return filled(config('signature.hub.mirrors_disk'));
    }

    /**
     * @return array<int, array{app: string, prefix: string, objects: int, expected: int, orphans: array<int, string>}>
     */
    public function audit(): array
    {
        if (! $this->configured()) {
            return [];
        }

        $disk = Storage::disk(config('signature.hub.mirrors_disk'));
        $report = [];

        foreach (HubApp::query()->orderBy('client_id')->get() as $app) {
            $prefix = trim((string) ($app->mirror_prefix ?: $app->client_id), '/');
            $expected = $this->expectedFor($app, $prefix);
            $objects = $disk->files($prefix);

            $report[] = [
                'app'      => $app->client_id,
                'prefix'   => $prefix,
                'objects'  => count($objects),
                'expected' => count($expected),
                'orphans'  => array_values(array_filter($objects, fn (string $path) => ! isset($expected[$path]))),
            ];
        }

        return $report;
    }

    /**
     * Delete the orphans an audit found, logging each one.
     *
     * @param  array<int, array{app: string, orphans: array<int, string>}>  $report
     */
    public function deleteOrphans(array $report): int
    {
        $disk = Storage::disk(config('signature.hub.mirrors_disk'));
        $deleted = 0;

        foreach ($report as $row) {
            foreach ($row['orphans'] as $path) {
                $disk->delete($path);
                $deleted++;

                Log::warning('signature-hub: deleted orphan mirror object.', ['app' => $row['app'], 'path' => $path]);
            }
        }

        return $deleted;
    }

    /** @return array<string, true>  expected object paths, as keys */
    private function expectedFor(HubApp $app, string $prefix): array
    {
        $expected = [];

        foreach (HubHolder::query()->where('app_id', $app->id)->pluck('personnel_key') as $key) {
            $userId = $this->people->userIdFor($key);
            $uuid = $userId !== null ? $this->specimens->current($userId)?->uuid : null;

            if ($uuid !== null) {
                $expected["{$prefix}/{$uuid}.png"] = true;
            }
        }

        return $expected;
    }
}
