<?php

namespace Kukux\DigitalSignature\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Receives the browser-side device fingerprint (collected by machineFingerprint.js)
 * and stores it in the current session so SignatureManager can read it synchronously
 * during form submission without any Livewire state-path complexity.
 *
 * Route: POST /signature/device-fingerprint
 * Called automatically from the SignaturePad blade via a Livewire event listener.
 */
class DeviceFingerprintController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $fp = $request->validate([
            'fp' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ])['fp'];

        session(['sig_device_fp' => $fp]);

        return response()->json(['ok' => true]);
    }
}
