<?php

namespace Kukux\DigitalSignature\Signatories;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Kukux\DigitalSignature\Client\Exceptions\HubException;
use Kukux\DigitalSignature\Client\HubClient;
use Kukux\DigitalSignature\Contracts\SignatoryUserMapper;
use Kukux\DigitalSignature\Models\HubAccount;

/**
 * Client mode's default mapper (A8): a tagged person is a hub person.
 *
 *   a login              passes through, as with IdentityUserMapper
 *   anything else        looked up at the hub by emp_no, then email; the
 *                        local user linked to that hub `sub` (HubAccount),
 *                        or null when they have never signed in here
 *
 * Null is the contract's existing "tagged without a login": the slot routes
 * as unassigned and `refreshAssignments()` fills it once the person signs in
 * through the hub. Either way the app is linked as a holder for that person
 * (`POST people/{sub}/link`), so the hub sends it their signature updates.
 *
 * Identifiers come from the tagged model's `hubIdentifiers()` when it has one
 * (`['emp_no' => …, 'email' => …]`), else from its `emp_no`,
 * `employee_number`, `employee_no` and `email` attributes. Lookups are cached
 * for ten minutes; an unreachable hub routes the slot as unassigned for now
 * rather than failing the page.
 *
 * Bound only when the host hasn't bound a mapper of its own.
 */
class HubUserMapper implements SignatoryUserMapper
{
    public function __construct(protected HubClient $hub)
    {
    }

    public function toUser(Model $resolved): ?Authenticatable
    {
        if ($resolved instanceof Authenticatable) {
            return $resolved;
        }

        [$empNo, $email] = $this->identifiers($resolved);

        if ($empNo === null && $email === null) {
            return null;
        }

        $sub = $this->subFor($empNo, $email);

        if ($sub === null) {
            return null;
        }

        $this->link($sub);

        $userId = HubAccount::userIdFor($sub);

        if ($userId === null) {
            return null;
        }

        $model = (string) config('auth.providers.users.model');
        $user = $model::query()->find($userId);

        return $user instanceof Authenticatable ? $user : null;
    }

    /**
     * @return array{0: ?string, 1: ?string} emp_no, email
     */
    protected function identifiers(Model $resolved): array
    {
        if (method_exists($resolved, 'hubIdentifiers')) {
            $ids = (array) $resolved->hubIdentifiers();

            return [
                filled($ids['emp_no'] ?? null) ? (string) $ids['emp_no'] : null,
                filled($ids['email'] ?? null) ? (string) $ids['email'] : null,
            ];
        }

        $empNo = null;

        foreach (['emp_no', 'employee_number', 'employee_no'] as $attribute) {
            if (filled($resolved->getAttribute($attribute))) {
                $empNo = (string) $resolved->getAttribute($attribute);

                break;
            }
        }

        $email = $resolved->getAttribute('email');

        return [$empNo, filled($email) ? (string) $email : null];
    }

    protected function subFor(?string $empNo, ?string $email): ?string
    {
        $key = 'signature:hub:person:'.sha1(($empNo ?? '').'|'.strtolower($email ?? ''));

        $cached = Cache::get($key);

        if (is_string($cached)) {
            return $cached === '' ? null : $cached;
        }

        try {
            $person = $empNo !== null ? $this->hub->findPerson(empNo: $empNo) : null;
            $person ??= $email !== null ? $this->hub->findPerson(email: $email) : null;
        } catch (HubException $e) {
            Log::info('Hub people lookup skipped: '.$e->getMessage());

            return null;
        }

        $sub = isset($person['sub']) ? (string) $person['sub'] : null;

        Cache::put($key, $sub ?? '', 600);

        return $sub;
    }

    /** Once per person: the hub remembers holders. */
    protected function link(string $sub): void
    {
        $key = 'signature:hub:linked:'.sha1($sub);

        if (Cache::has($key)) {
            return;
        }

        try {
            $this->hub->link($sub);
            Cache::forever($key, true);
        } catch (HubException $e) {
            Log::info('Hub link skipped: '.$e->getMessage(), ['sub' => $sub]);
        }
    }
}
