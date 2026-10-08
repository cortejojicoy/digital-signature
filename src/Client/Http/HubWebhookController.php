<?php

namespace Kukux\DigitalSignature\Client\Http;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Kukux\DigitalSignature\Client\HubWebhookHandler;
use Kukux\DigitalSignature\Models\HubEvent;
use Throwable;

/**
 * `POST /signature/hub/webhook`: events from the hub (A6).
 *
 * Outside the `web` group on purpose: the hub has no session or CSRF token.
 * Trust comes from the signature instead:
 *
 *   X-Signature-Hub-Signature: sha256=hex(hmac_sha256(webhook_secret, timestamp + "." + raw_body))
 *
 * with `X-Signature-Hub-Timestamp` within `hub.webhook_tolerance` seconds of
 * now, so a captured request can't be replayed later. Each event id is
 * handled once (HubEvent); a redelivery answers 200 and does nothing. If
 * handling fails, the id is released and the hub's retry tries again.
 */
class HubWebhookController extends Controller
{
    public function __invoke(Request $request, HubWebhookHandler $handler): JsonResponse
    {
        $secret = (string) config('signature.hub.webhook_secret');
        $id = (string) $request->header('X-Signature-Hub-Id', '');
        $event = (string) $request->header('X-Signature-Hub-Event', '');
        $timestamp = (string) $request->header('X-Signature-Hub-Timestamp', '');
        $signature = (string) $request->header('X-Signature-Hub-Signature', '');

        if ($secret === '' || $id === '' || $event === '' || ! ctype_digit($timestamp) || $signature === '') {
            return $this->refuse('invalid_signature', 'The webhook is missing its signature headers.');
        }

        if (abs(now()->getTimestamp() - (int) $timestamp) > max(1, (int) config('signature.hub.webhook_tolerance', 300))) {
            return $this->refuse('stale_timestamp', 'The webhook timestamp is outside the accepted window.');
        }

        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            return $this->refuse('invalid_signature', 'The webhook signature does not verify.');
        }

        $body = json_decode($request->getContent(), true);

        if (! is_array($body)) {
            return response()->json(['error' => 'invalid_body', 'message' => 'The webhook body is not JSON.'], 400);
        }

        if (HubEvent::query()->where('event_id', $id)->exists()) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        try {
            $record = HubEvent::create(['event_id' => $id, 'event' => $event, 'received_at' => now()]);
        } catch (QueryException) {
            // The same id arriving twice at once: the other one handles it.
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        try {
            $handler->handle($event, is_array($body['data'] ?? null) ? $body['data'] : []);
        } catch (Throwable $e) {
            report($e);

            // Not handled, so not a duplicate next time: let the hub retry.
            $record->delete();

            return response()->json(['error' => 'handler_failed', 'message' => 'The event could not be handled.'], 500);
        }

        return response()->json(['received' => true]);
    }

    protected function refuse(string $code, string $message): JsonResponse
    {
        return response()->json(['error' => $code, 'message' => $message], 401);
    }
}
