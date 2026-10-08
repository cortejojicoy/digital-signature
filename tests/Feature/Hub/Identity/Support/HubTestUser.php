<?php

namespace Kukux\DigitalSignature\Tests\Feature\Hub\Identity\Support;

use Filament\Models\Contracts\FilamentUser;
use Kukux\DigitalSignature\Hub\Identity\Concerns\HasHubPanelAccess;
use Kukux\DigitalSignature\Tests\Support\TestUser;

/**
 * The hub app's User model: the package's panel-access trait, and a tiny
 * stand-in for Shield's roles (a `roles` column, comma-separated).
 */
class HubTestUser extends TestUser implements FilamentUser
{
    use HasHubPanelAccess;

    public function hasRole(string $role): bool
    {
        return in_array($role, explode(',', (string) $this->roles), true);
    }

    public function assignRole(string $role): void
    {
        if (! $this->hasRole($role)) {
            $this->forceFill(['roles' => trim($this->roles.','.$role, ',')])->save();
        }
    }
}
