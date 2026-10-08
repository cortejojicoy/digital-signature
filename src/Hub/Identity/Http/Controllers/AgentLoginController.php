<?php

namespace Kukux\DigitalSignature\Hub\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Kukux\DigitalSignature\Agent\AgentApiException;
use Kukux\DigitalSignature\Hub\Identity\HubLoginService;
use Kukux\DigitalSignature\Http\Middleware\AuthenticateAgent;

/**
 * `POST signature/agent/logins/{challenge}/claim` (docs/hub/contracts.md §4):
 * authenticated exactly like jobs/{job}/claim (bearer + request proof), and
 * answered with a normal job payload, purpose `login`. Errors are the agent
 * protocol's `{"error":{"code","message"}}` (AgentApiException).
 */
class AgentLoginController extends Controller
{
    public function __construct(private readonly HubLoginService $logins) {}

    public function claim(Request $request, string $challenge): JsonResponse
    {
        $body = json_decode($request->getContent() ?: '{}', true);
        $token = is_array($body) ? ($body['link_token'] ?? null) : null;

        if (! is_string($token) || $token === '' || strlen($token) > 128) {
            throw new AgentApiException(422, 'invalid_request', 'Missing or invalid `link_token`.');
        }

        return response()->json($this->logins->claim($request->attributes->get(AuthenticateAgent::ATTRIBUTE), $challenge, $token));
    }
}
