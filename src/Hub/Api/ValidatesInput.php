<?php

namespace Kukux\DigitalSignature\Hub\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Strict input validation for the hub API. Never $request->validate(): that
 * redirects a client that didn't ask for JSON, and its error body isn't the
 * contract's `{"error","message"}`.
 */
trait ValidatesInput
{
    /**
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>|null  $data  Defaults to the request's input.
     * @return array<string, mixed>
     *
     * @throws HubApiException
     */
    protected function validated(Request $request, array $rules, ?array $data = null, int $status = 422): array
    {
        $validator = Validator::make($data ?? $request->all(), $rules);

        if ($validator->fails()) {
            throw new HubApiException($status, 'invalid_request', (string) $validator->errors()->first());
        }

        return $validator->validated();
    }
}
