<?php

namespace Kukux\DigitalSignature\Agent;

use Illuminate\Http\JsonResponse;

/**
 * An error in the agent protocol's shape:
 * `{"error":{"code":"<snake_case>","message":"<human text>"}}`.
 * The agent shows `message` to the user.
 */
class AgentApiException extends \RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra  Top-level fields beside `error`.
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(
            ['error' => ['code' => $this->errorCode, 'message' => $this->getMessage()]] + $this->extra,
            $this->status,
        );
    }
}
