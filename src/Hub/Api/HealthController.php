<?php

namespace Kukux\DigitalSignature\Hub\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * GET /signature/hub/api/v1/health — for the uptime monitor (R1, R13).
 * No auth, and nothing in the body but booleans.
 *
 *   200 {"status":"ok", "checks":{...}}
 *   503 {"status":"degraded", ...}   when any configured check fails
 *
 * `mirrors_disk` is null when the hub isn't configured to manage mirrors.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $mirrors = config('signature.hub.mirrors_disk');

        $checks = [
            'database'      => $this->check('database', fn () => DB::connection()->getPdo() !== null && DB::select('select 1') !== null),
            'queue'         => $this->check('queue', fn () => is_int(Queue::connection(config('signature.queue_connection'))->size(config('signature.queue')))),
            'specimen_disk' => $this->check('specimen_disk', fn () => $this->diskResponds(config('signature.hub.specimen_disk') ?: config('signature.storage_disk'))),
            'mirrors_disk'  => blank($mirrors) ? null : $this->check('mirrors_disk', fn () => $this->diskResponds($mirrors)),
        ];

        $ok = ! in_array(false, $checks, true);

        return response()->json(
            ['status' => $ok ? 'ok' : 'degraded', 'checks' => $checks],
            $ok ? 200 : 503,
            ['Cache-Control' => 'no-store'],
        );
    }

    /** A probe that answers at all (a missing object is fine, an error is not). */
    private function diskResponds(string $disk): bool
    {
        Storage::disk($disk)->exists('.signature-hub-health');

        return true;
    }

    private function check(string $name, \Closure $probe): bool
    {
        try {
            return (bool) $probe();
        } catch (\Throwable $e) {
            Log::warning("signature-hub: health check [{$name}] failed.", ['error' => $e->getMessage()]);

            return false;
        }
    }
}
