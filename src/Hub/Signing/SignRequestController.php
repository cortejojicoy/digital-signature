<?php

namespace Kukux\DigitalSignature\Hub\Signing;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Kukux\DigitalSignature\Hub\Api\HubApiException;
use Kukux\DigitalSignature\Hub\Api\ValidatesInput;
use Kukux\DigitalSignature\Hub\OAuth\AuthenticateHubToken;

/**
 * POST /signature/hub/api/v1/sign-requests        scope `sign`
 * GET  /signature/hub/api/v1/sign-requests/{id}
 *
 * 202 with the approval link on a new request; 200 with the same request
 * when the idempotency key was seen before (a retry, R1).
 */
class SignRequestController extends Controller
{
    use ValidatesInput;

    private const HASH = 'regex:/^[a-f0-9]{64}$/';

    public function __construct(private readonly HubSignRequestService $requests) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->json()->all() ?: $request->all();

        // The header form of the key (R1) when the body leaves it out.
        if (! isset($data['idempotency_key']) && $request->hasHeader('Idempotency-Key')) {
            $data['idempotency_key'] = $request->header('Idempotency-Key');
        }

        foreach (['document_hash', 'specimen_hash'] as $hash) {
            if (isset($data[$hash]) && is_string($data[$hash])) {
                $data[$hash] = strtolower($data[$hash]);
            }
        }

        $input = $this->validated($request, [
            'sub'             => ['required', 'string', 'max:64'],
            'document_hash'   => ['required', 'string', self::HASH],
            'specimen_hash'   => ['required', 'string', self::HASH],
            'title'           => ['required', 'string', 'min:1', 'max:250'],
            'slot'            => ['nullable', 'string', 'max:128'],
            'capacity'        => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9._:\-]+$/'],
        ], $data);

        [$signRequest, $link, $replayed] = $this->requests->create(AuthenticateHubToken::app($request), $input);

        $body = $this->requests->present($signRequest);

        if ($body['status'] === 'pending') {
            $body += [
                'approval_link' => $link,
                'job_uuid'      => $signRequest->agentJob?->uuid,
                'expires_at'    => $signRequest->expires_at->toIso8601String(),
            ];
        }

        return response()->json($body, $replayed ? 200 : 202);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $signRequest = $this->requests->find(AuthenticateHubToken::app($request), $id)
            ?? throw new HubApiException(404, 'unknown_request', 'No sign request with that id for this app.');

        return response()->json($this->requests->present($signRequest));
    }
}
