<?php

namespace Kukux\DigitalSignature\Hub\Identity\Concerns;

use Filament\Panel;
use Kukux\DigitalSignature\Hub\Identity\HubAccess;

/**
 * For the hub app's User model (implements Filament\Models\Contracts\FilamentUser):
 *
 *   class User extends Authenticatable implements FilamentUser
 *   {
 *       use HasHubPanelAccess;
 *   }
 *
 * Person panel: any usable identity. Admin panel: verified + super_admin, or
 * a break-glass session. See Hub\Identity\HubAccess and docs/hub/panels.md.
 */
trait HasHubPanelAccess
{
    public function canAccessPanel(Panel $panel): bool
    {
        return HubAccess::canAccessPanel($this, $panel);
    }
}
