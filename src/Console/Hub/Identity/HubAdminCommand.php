<?php

namespace Kukux\DigitalSignature\Console\Hub\Identity;

use Illuminate\Console\Command;
use Kukux\DigitalSignature\Contracts\PersonnelDirectory;
use Kukux\DigitalSignature\Hub\Identity\HubAccounts;
use Kukux\DigitalSignature\Hub\Identity\IdentityException;
use Kukux\DigitalSignature\Hub\Identity\IdentityService;
use Kukux\DigitalSignature\Hub\Personnel;
use Kukux\DigitalSignature\Models\SignatureAudit;

/**
 * Bootstrap (plan 1.8): the first super_admin pairs and identifies like
 * everyone else, then on the server:
 *
 *   php artisan signature:hub-admin 2004-01234
 *
 * verifies their identity (nobody else can yet), gives their current account
 * the super_admin role (Shield / spatie assignRole) and writes an
 * `admin.granted` audit row with actor_type console.
 */
class HubAdminCommand extends Command
{
    protected $signature = 'signature:hub-admin {emp_no : The person\'s employee number}';

    protected $description = 'Verify a person\'s hub identity and make their account a super_admin';

    public function handle(IdentityService $identities, HubAccounts $accounts): int
    {
        $empNo = trim((string) $this->argument('emp_no'));
        $person = $this->findPerson($identities->directory(), $empNo);

        if ($person === null) {
            $this->error("No one with employee number {$empNo} in the personnel registry.");

            return self::FAILURE;
        }

        $userId = $identities->userIdFor($person->key);

        if ($userId === null) {
            $this->error("{$person->name} hasn't paired a computer and identified yet. Ask them to open the hub and click \"Pair this computer\" first.");

            return self::FAILURE;
        }

        try {
            $identities->verify($userId, null, 'console');
        } catch (IdentityException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $user = $accounts->find($userId);
        $role = (string) config('signature.hub.super_admin_role', 'super_admin');

        if ($user !== null && method_exists($user, 'assignRole')) {
            $user->assignRole($role);
            $granted = true;
        } else {
            $granted = false;
            $this->warn("Your User model has no assignRole(): give account #{$userId} the [{$role}] role yourself, "
                .'or tell the hub who is a super_admin with HubAccess::superAdminUsing().');
        }

        SignatureAudit::record(SignatureAudit::ADMIN_GRANTED, [
            'subject_user_id' => $userId,
            'actor_user_id'   => null,
            'actor_type'      => 'console',
            'personnel_key'   => $person->key,
            'context'         => ['role' => $role, 'assigned' => $granted, 'emp_no' => $empNo],
        ]);

        $this->info("{$person->name} is verified".($granted ? " and has the {$role} role." : '.'));

        return self::SUCCESS;
    }

    private function findPerson(PersonnelDirectory $directory, string $empNo): ?Personnel
    {
        if (method_exists($directory, 'findByEmpNo')) {
            return $directory->findByEmpNo($empNo);
        }

        foreach ($directory->search($empNo, 10) as $person) {
            if ($person->empNo === $empNo) {
                return $person;
            }
        }

        return null;
    }
}
