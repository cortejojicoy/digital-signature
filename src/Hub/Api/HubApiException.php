<?php

namespace Kukux\DigitalSignature\Hub\Api;

use Illuminate\Http\JsonResponse;

/**
 * An error in the hub API's shape: `{"error": "<code>", "message": "<text>"}`,
 * plus any extra top-level fields (e.g. `current_specimen_hash` on a 409).
 *
 * Thrown anywhere under /signature/hub; Laravel renders it through render().
 * See docs/hub/contracts.md §2.
 */
class HubApiException extends \RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra  Top-level fields beside `error` and `message`.
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $extra = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(
            ['error' => $this->errorCode, 'message' => $this->getMessage()] + $this->extra,
            $this->status,
            $this->headers,
        );
    }
}
