<?php

namespace Kukux\DigitalSignature\Hub\Documents;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Kukux\DigitalSignature\Hub\Api\ValidatesInput;
use Kukux\DigitalSignature\Hub\OAuth\AuthenticateHubToken;
use Kukux\DigitalSignature\Verification\DocumentSnapshot;

/**
 * PUT /signature/hub/api/v1/documents/{uuid}        scope `sign`
 *
 * A client app reporting one routed document: its versions' fingerprints and
 * who signed which slot (a Verification\DocumentSnapshot array). The whole
 * document each time; idempotent. 200 with what the hub now holds.
 *
 * Under `sign` rather than a scope of its own: reporting the documents it
 * had signed is part of an app signing through the hub, and every app that
 * can ask for a signature can already say this much.
 */
class DocumentController extends Controller
{
    use ValidatesInput;

    private const HASH = 'regex:/^[a-fA-F0-9]{64}$/';

    public function __construct(private readonly HubDocuments $documents) {}

    public function update(Request $request, string $uuid): JsonResponse
    {
        $data = $request->json()->all() ?: $request->all();

        $input = $this->validated($request, [
            'title'                           => ['nullable', 'string', 'max:250'],
            'status'                          => ['required', 'string', 'in:open,complete,cancelled,expired'],
            'expired'                         => ['sometimes', 'boolean'],
            'required'                        => ['required', 'integer', 'min:0', 'max:100'],
            'versions'                        => ['required', 'array', 'min:1', 'max:200'],
            'versions.*.number'               => ['required', 'integer', 'min:0', 'distinct'],
            'versions.*.sha256'               => ['required', 'string', self::HASH],
            'versions.*.created_at'           => ['nullable', 'date'],
            'slots'                           => ['present', 'array', 'max:100'],
            'slots.*.slot'                    => ['required', 'string', 'max:128'],
            'slots.*.label'                   => ['required', 'string', 'max:250'],
            'slots.*.state'                   => ['required', 'string', 'in:signed,declined,pending'],
            'slots.*.signer'                  => ['nullable', 'string', 'max:250'],
            'slots.*.signed_at'               => ['nullable', 'date'],
            'slots.*.version'                 => ['nullable', 'integer', 'min:1'],
            'slots.*.certificate_fingerprint' => ['nullable', 'string', self::HASH],
            'slots.*.revoked'                 => ['sometimes', 'boolean'],
        ], $data);

        $document = $this->documents->record(
            AuthenticateHubToken::app($request),
            $uuid,
            DocumentSnapshot::fromArray($input),
        );

        return response()->json([
            'uuid'     => $document->uuid,
            'status'   => $document->status,
            'versions' => $document->versions()->count(),
        ]);
    }
}
