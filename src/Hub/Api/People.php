<?php

namespace Kukux\DigitalSignature\Hub\Api;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Arr;
use Kukux\DigitalSignature\Contracts\PersonnelDirectory;
use Kukux\DigitalSignature\Hub\Personnel;
use Kukux\DigitalSignature\Models\Identity;

/**
 * Who a `sub` is, for the hub API: the personnel directory plus the hub's
 * identities (which account is the person's current computer, and whether an
 * admin verified the claim).
 *
 * Reads the Identity model directly rather than Hub\Identity\IdentityService,
 * so the API never depends on the identity work's internals; both answer the
 * same questions from the same table.
 */
class People
{
    /** The fields an app sees about a person (docs/hub/contracts.md §2.1). */
    public const CLAIMS = ['sub', 'name', 'email', 'emp_no', 'unit', 'position'];

    /**
     * @throws HubApiException  503 when the hub app hasn't bound a directory.
     */
    public function directory(): PersonnelDirectory
    {
        try {
            return app(PersonnelDirectory::class);
        } catch (BindingResolutionException) {
            throw new HubApiException(503, 'directory_unavailable', 'The personnel directory is not configured on the hub.');
        }
    }

    public function find(string $key): ?Personnel
    {
        return $this->directory()->find($key);
    }

    /**
     * The contract's claim set. The directory's toClaims() decides what may
     * be shared; the Personnel fields only fill keys it left out entirely.
     *
     * @return array<string, mixed>
     */
    public function claims(Personnel $person): array
    {
        $claims = $this->directory()->toClaims($person) + [
            'sub'      => $person->key,
            'name'     => $person->name,
            'email'    => $person->email,
            'emp_no'   => $person->empNo,
            'unit'     => $person->unit,
            'position' => $person->position,
        ];

        // `sub` is always the personnel key, whatever the directory calls it.
        $claims['sub'] = $person->key;

        return Arr::only($claims, self::CLAIMS);
    }

    /** Exact employee-number match, active people only. */
    public function byEmpNo(string $empNo): ?Personnel
    {
        foreach ($this->directory()->search($empNo, 10) as $person) {
            if ($person->active && $person->empNo !== null && strcasecmp($person->empNo, $empNo) === 0) {
                return $person;
            }
        }

        return null;
    }

    /**
     * Exact email match, active people only.
     *
     * PersonnelDirectory has no email lookup, so: a directory method
     * `findByEmail()` when the hub app's directory has one, else its search
     * (some directories match emails there), else the hub's own accounts —
     * a verified person's user row carries their directory email.
     */
    public function byEmail(string $email): ?Personnel
    {
        $directory = $this->directory();

        if (method_exists($directory, 'findByEmail')) {
            $person = $directory->findByEmail($email);

            return $person instanceof Personnel && $person->active ? $person : null;
        }

        foreach ($directory->search($email, 10) as $person) {
            if ($person->active && $person->email !== null && strcasecmp($person->email, $email) === 0) {
                return $person;
            }
        }

        $model = config('auth.providers.users.model');

        if (! is_string($model) || ! class_exists($model)) {
            return null;
        }

        $userIds = $model::query()->where('email', $email)->pluck('id');

        $key = Identity::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('status', [Identity::PENDING, Identity::VERIFIED])
            ->whereNotNull('personnel_key')
            ->value('personnel_key');

        $person = $key !== null ? $directory->find($key) : null;

        return $person !== null && $person->active && strcasecmp((string) $person->email, $email) === 0 ? $person : null;
    }

    // ── Accounts (Identity) ───────────────────────────────────────────────

    public function personnelKeyFor(int $userId): ?string
    {
        return Identity::forUser($userId)?->personnel_key;
    }

    public function isVerified(int $userId): bool
    {
        return (bool) Identity::forUser($userId)?->isVerified();
    }

    /** The person's current (non-retired) account. */
    public function identity(string $personnelKey): ?Identity
    {
        return Identity::current($personnelKey);
    }

    public function userIdFor(string $personnelKey): ?int
    {
        $userId = $this->identity($personnelKey)?->user_id;

        return $userId !== null ? (int) $userId : null;
    }

    /**
     * Every account the person has had, current or retired: revocation and
     * webhooks look across all of them.
     *
     * @return array<int, int>
     */
    public function allUserIdsFor(string $personnelKey): array
    {
        return Identity::query()
            ->where('personnel_key', $personnelKey)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Separated from the university: by the hub's identity, or the registry. */
    public function isSeparated(string $personnelKey, ?Personnel $person = null): bool
    {
        if ($person !== null && ! $person->active) {
            return true;
        }

        return Identity::query()
            ->where('personnel_key', $personnelKey)
            ->where('status', Identity::SEPARATED)
            ->exists();
    }
}
