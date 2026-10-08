<?php

namespace Kukux\DigitalSignature\Hub\Identity;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kukux\DigitalSignature\Models\Identity;

/**
 * The hub's rows in the host's `users` table.
 *
 * "Pair this computer" needs a user id before anyone knows who is pairing
 * (the agent protocol binds to it), so a provisional account is created with
 * placeholder values; identification later writes the registry's name and
 * email onto it. Those are the only writes the package makes to `users`.
 *
 * Hosts whose users table needs other columns set the attributes themselves:
 *
 *   HubAccounts::provisionalAttributesUsing(fn (string $email) => [
 *       'name' => 'Unidentified', 'email' => $email, 'type' => 'hub',
 *   ]);
 */
class HubAccounts
{
    public const PLACEHOLDER_DOMAIN = 'hub.invalid';

    private static ?Closure $provisionalAttributes = null;

    /** @param  Closure(string $placeholderEmail): array<string, mixed>|null  $callback */
    public static function provisionalAttributesUsing(?Closure $callback): void
    {
        self::$provisionalAttributes = $callback;
    }

    public function createProvisional(): Model
    {
        $email = self::placeholderEmail('unidentified');

        $attributes = self::$provisionalAttributes !== null
            ? (self::$provisionalAttributes)($email)
            : [
                'name'     => 'Unidentified',
                'email'    => $email,
                // Never used to sign in (the hub has no password login), but a
                // NOT NULL column is common; random, so it can't be guessed.
                'password' => Hash::make(Str::random(64)),
            ];

        $model = $this->model();
        $user = new $model;
        $user->forceFill($attributes)->save();

        return $user;
    }

    /**
     * Copy the registry's name and email onto the account. An email still
     * held by an account that no longer stands for anyone (retired, rejected)
     * is freed first; one held by someone's current account is left alone.
     *
     * @param  array<string, mixed>  $claims  PersonnelDirectory::toClaims()
     */
    public function applyClaims(Model $user, array $claims): void
    {
        $changes = array_filter(['name' => $claims['name'] ?? null], fn ($v) => filled($v));
        $email = $claims['email'] ?? null;

        if (filled($email) && $email !== $user->getAttribute('email')) {
            $holder = $this->model()::query()->where('email', $email)->whereKeyNot($user->getKey())->first();

            if ($holder !== null && Identity::forUser((int) $holder->getKey())?->isIdentified()) {
                Log::warning('signature.hub: email already belongs to another current account; not copied.', [
                    'user_id' => $user->getKey(), 'holder_id' => $holder->getKey(),
                ]);
            } else {
                if ($holder !== null) {
                    $this->vacate($holder);
                }

                $changes['email'] = $email;
            }
        }

        if ($changes !== []) {
            $user->forceFill($changes)->save();
        }
    }

    /** Give an account that no longer stands for anyone a placeholder email. */
    public function vacate(Model $user, string $tag = 'retired'): void
    {
        if (! str_ends_with((string) $user->getAttribute('email'), '@'.self::PLACEHOLDER_DOMAIN)) {
            $user->forceFill(['email' => self::placeholderEmail($tag)])->save();
        }
    }

    public function find(int $userId): ?Model
    {
        return $this->model()::query()->find($userId);
    }

    /** RFC 2606 `.invalid`: unique, and can never receive mail. */
    public static function placeholderEmail(string $tag): string
    {
        return $tag.'+'.Str::lower((string) Str::ulid()).'@'.self::PLACEHOLDER_DOMAIN;
    }

    /** @return class-string<Model> */
    protected function model(): string
    {
        return config('auth.providers.users.model');
    }
}
