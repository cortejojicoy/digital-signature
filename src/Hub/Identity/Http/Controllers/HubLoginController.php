<?php

namespace Kukux\DigitalSignature\Hub\Identity\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Kukux\DigitalSignature\Hub\Identity\HubLoginService;

/**
 * "Sign in with your computer", browser side (plan 1.5):
 *
 *   POST signature/hub/login/challenges         {uuid, match_code, link, expires_at}
 *   GET  signature/hub/login/challenges/{uuid}  {status, redirect?}; this browser only
 */
class HubLoginController extends Controller
{
    public function __construct(private readonly HubLoginService $logins) {}

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->logins->start($request->session(), $request->ip(), $request->userAgent()), 201);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $state = $this->logins->poll($request->session(), $uuid);

        return $state === null
            ? response()->json(['error' => 'not_found', 'message' => 'Sign-in request not found.'], 404)
            : response()->json($state);
    }
}
