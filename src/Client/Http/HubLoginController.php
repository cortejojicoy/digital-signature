<?php

namespace Kukux\DigitalSignature\Client\Http;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kukux\DigitalSignature\Client\Exceptions\HubException;
use Kukux\DigitalSignature\Client\Exceptions\HubUnavailableException;
use Kukux\DigitalSignature\Client\Exceptions\MirrorIntegrityException;
use Kukux\DigitalSignature\Client\HubClient;
use Kukux\DigitalSignature\Client\HubSignatureSync;
use Kukux\DigitalSignature\Models\HubAccount;
use Throwable;

/**
 * "Sign in with UPLB Signature" (A5): authorization code + PKCE (S256)
 * against the hub, then a normal session here.
 *
 *   GET signature/hub/login     state + code verifier into the session,
 *                               redirect to the hub's authorize endpoint
 *   GET signature/hub/callback  check state, exchange the code, read
 *                               userinfo, find or create the local user, log
 *                               them in, fetch their mirror if they have none
 *
 * Who the person is here:
 *
 *   1. the user already linked to this hub `sub` (HubAccount);
 *   2. else the user with the same email, linked now and from then on by
 *      `sub` alone (so a later email change never moves the link);
 *   3. else a new user, when `hub.create_users` is on.
 *
 * The host's own password login keeps working beside this (break-glass).
 */
class HubLoginController extends Controller
{
    public const SESSION_KEY = 'signature.hub.oauth';

    public function redirect(Request $request, HubClient $hub): RedirectResponse
    {
        $state = Str::random(40);
        $verifier = Str::random(64);

        $request->session()->put(self::SESSION_KEY, [
            'state'    => $state,
            'verifier' => $verifier,
            'panel'    => $this->panelId($request->query('panel')),
        ]);

        return redirect()->away($hub->authorizeUrl($state, self::challenge($verifier), $this->callbackUrl()));
    }

    public function callback(Request $request, HubClient $hub, HubSignatureSync $sync): RedirectResponse
    {
        $pending = $request->session()->pull(self::SESSION_KEY);
        $state = (string) $request->query('state', '');

        abort_unless(
            is_array($pending) && $state !== '' && hash_equals((string) ($pending['state'] ?? ''), $state),
            403,
            'This sign-in link has expired or was already used. Start again from the sign-in page.',
        );

        if ($request->filled('error')) {
            abort(403, 'UPLB Signature did not sign you in: '.Str::limit((string) $request->query('error_description', $request->query('error')), 200));
        }

        $code = (string) $request->query('code', '');
        abort_if($code === '', 400, 'UPLB Signature returned no sign-in code.');

        try {
            $token = $hub->exchangeCode($code, (string) $pending['verifier'], $this->callbackUrl());
            $claims = $hub->userinfo((string) ($token['access_token'] ?? ''));
        } catch (HubUnavailableException) {
            abort(503, 'UPLB Signature cannot be reached right now. Try again in a minute, or use your local login.');
        } catch (HubException $e) {
            abort(403, 'UPLB Signature refused the sign-in: '.$e->getMessage());
        }

        $sub = (string) ($claims['sub'] ?? $token['sub'] ?? '');
        abort_if($sub === '', 403, 'UPLB Signature did not say who you are.');

        $user = $this->resolveUser($sub, $claims);

        $panel = $this->panel($pending['panel'] ?? null);
        $guard = $panel?->auth() ?? Auth::guard();

        if ($guard instanceof StatefulGuard) {
            $guard->login($user);
        }

        $request->session()->regenerate();

        // First visit: fetch the signature image now, so the tray isn't
        // empty the first time a document is opened.
        if ($sync->mirrorFor((int) $user->getAuthIdentifier()) === null) {
            try {
                $sync->pull($user);
            } catch (HubException|MirrorIntegrityException $e) {
                Log::info('Hub mirror not fetched at sign-in: '.$e->getMessage(), ['user_id' => $user->getAuthIdentifier()]);
            }
        }

        return redirect()->intended($panel?->getUrl() ?? url('/'));
    }

    /**
     * @param  array<string, mixed>  $claims  userinfo
     */
    protected function resolveUser(string $sub, array $claims): Authenticatable
    {
        $model = (string) config('auth.providers.users.model');
        $userId = HubAccount::userIdFor($sub);
        $user = $userId !== null ? $model::query()->find($userId) : null;
        $email = filled($claims['email'] ?? null) ? Str::lower((string) $claims['email']) : null;

        if ($user === null && $email !== null) {
            $user = $model::query()->whereRaw('LOWER(email) = ?', [$email])->first();

            // Already linked to someone else at the hub: never re-point it.
            abort_if(
                $user !== null && HubAccount::query()->where('user_id', $user->getKey())->where('sub', '!=', $sub)->exists(),
                403,
                'This account is linked to a different UPLB Signature person. Ask an administrator.',
            );
        }

        if ($user === null) {
            abort_unless(
                (bool) config('signature.hub.create_users', true) && $email !== null,
                403,
                'There is no account here for you yet. Ask an administrator to add you.',
            );

            $user = $this->createUser($model, $claims, $email);
        }

        $account = HubAccount::query()->firstOrNew(['sub' => $sub]);
        $account->fill([
            'user_id'   => $user->getKey(),
            'claims'    => array_intersect_key($claims, array_flip(['sub', 'name', 'email', 'emp_no', 'unit', 'position'])),
            'linked_at' => $account->linked_at ?? now(),
        ])->save();

        return $user;
    }

    /**
     * A user who signs in only through the hub. The password is random and
     * never shown: local login is for break-glass accounts.
     *
     * @param  array<string, mixed>  $claims
     */
    protected function createUser(string $model, array $claims, string $email): Model
    {
        /** @var Model $user */
        $user = new $model;

        $user->forceFill([
            'name'     => (string) ($claims['name'] ?? '') ?: Str::before($email, '@'),
            'email'    => $email,
            'password' => Hash::make(Str::random(64)),
        ])->save();

        return $user;
    }

    /** RFC 7636 S256: base64url(sha256(verifier)), no padding. */
    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    protected function callbackUrl(): string
    {
        return route('signature.hub.callback');
    }

    protected function panelId(mixed $id): ?string
    {
        return is_string($id) && $this->panel($id) !== null ? $id : null;
    }

    /** The panel to sign into: the one named, else the default. */
    protected function panel(?string $id): ?\Filament\Panel
    {
        if (! class_exists(Filament::class)) {
            return null;
        }

        try {
            return $id !== null ? Filament::getPanel($id) : Filament::getDefaultPanel();
        } catch (Throwable) {
            return null;
        }
    }
}
