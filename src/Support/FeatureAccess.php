<?php

namespace Kukux\DigitalSignature\Support;

use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Kukux\DigitalSignature\Hub\Identity\HubAccess;
use Kukux\DigitalSignature\Models\FeatureRule;
use Throwable;

/**
 * Who may use the package's gated features, for rolling one out to a few
 * people before everyone (testing on production data, a pilot office).
 *
 *   FeatureAccess::allows(FeatureAccess::ROUTE_FOR_SIGNATURES)
 *
 * A super_admin sets each feature from the launcher's Settings tab:
 *
 *   everyone      the default, and what a feature with no row means
 *   selected      the people picked there, plus super admins
 *   super_admins  super admins only
 *
 * Super admins always pass, so they can try a feature before anyone else.
 * Who is a super_admin is HubAccess::isSuperAdmin(): `hasRole()` with
 * `signature.hub.super_admin_role`, or HubAccess::superAdminUsing().
 *
 * Only the Filament surfaces check this (actions, the designer, pairing).
 * DocumentRouter and SignatureManager don't: they're also called from jobs,
 * commands and tests, where there is nobody to ask about.
 *
 * Scoped: rules are read once per request, then reused.
 */
final class FeatureAccess
{
    public const ROUTE_FOR_SIGNATURES = 'route_for_signatures';

    public const SIGN_DOCUMENT = 'sign_document';

    public const TEMPLATE_DESIGNER = 'template_designer';

    public const DESKTOP_AGENT = 'desktop_agent';

    public const EVERYONE = 'everyone';

    public const SELECTED = 'selected';

    public const SUPER_ADMINS = 'super_admins';

    public const MODES = [self::EVERYONE, self::SELECTED, self::SUPER_ADMINS];

    /** @var array<string, FeatureRule>|null */
    private ?array $rules = null;

    /**
     * The gated features, in the order the Settings tab lists them.
     *
     * @return array<string, array{label: string, description: string}>
     */
    public static function features(): array
    {
        return [
            self::ROUTE_FOR_SIGNATURES => [
                'label'       => 'Route for signatures',
                'description' => 'Send a document to its signatories: Route for signatures and Request signatures.',
            ],
            self::SIGN_DOCUMENT => [
                'label'       => 'Sign document',
                'description' => 'Sign a record\'s PDF directly with the Sign Document action.',
            ],
            self::TEMPLATE_DESIGNER => [
                'label'       => 'Placement designer',
                'description' => 'Open a template\'s designer and move its signature zones.',
            ],
            self::DESKTOP_AGENT => [
                'label'       => 'Desktop agent pairing',
                'description' => 'Pair a computer with Kukux Sign Agent from the Devices tab.',
            ],
        ];
    }

    /** Whether the signed-in user (or $user) may use $feature. */
    public static function allows(string $feature, ?Authenticatable $user = null): bool
    {
        return app(self::class)->check($feature, $user ?? self::currentUser());
    }

    public static function isSuperAdmin(?Authenticatable $user = null): bool
    {
        return HubAccess::isSuperAdmin($user ?? self::currentUser());
    }

    public function check(string $feature, ?Authenticatable $user): bool
    {
        // Not one of ours: nothing gates it.
        if (! array_key_exists($feature, self::features())) {
            return true;
        }

        $rule = $this->rules()[$feature] ?? null;
        $mode = $rule?->mode ?? self::EVERYONE;

        if ($mode === self::EVERYONE || ! in_array($mode, self::MODES, true)) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        if (HubAccess::isSuperAdmin($user)) {
            return true;
        }

        return $mode === self::SELECTED
            && in_array((string) $user->getAuthIdentifier(), $rule?->userIds() ?? [], true);
    }

    public function mode(string $feature): string
    {
        return $this->rules()[$feature]->mode ?? self::EVERYONE;
    }

    /** @return array<int, string> */
    public function userIds(string $feature): array
    {
        return ($this->rules()[$feature] ?? null)?->userIds() ?? [];
    }

    public function setMode(string $feature, string $mode, int|string|null $by = null): void
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException("Unknown feature access mode [{$mode}].");
        }

        $this->write($feature, fn (FeatureRule $rule) => $rule->mode = $mode, $by);
    }

    public function grant(string $feature, int|string $userId, int|string|null $by = null): void
    {
        $this->write($feature, function (FeatureRule $rule) use ($userId): void {
            $rule->user_ids = array_values(array_unique([...$rule->userIds(), (string) $userId]));
        }, $by);
    }

    public function revoke(string $feature, int|string $userId, int|string|null $by = null): void
    {
        $this->write($feature, function (FeatureRule $rule) use ($userId): void {
            $rule->user_ids = array_values(array_diff($rule->userIds(), [(string) $userId]));
        }, $by);
    }

    private function write(string $feature, callable $change, int|string|null $by): void
    {
        if (! array_key_exists($feature, self::features())) {
            throw new InvalidArgumentException("Unknown feature [{$feature}].");
        }

        $rule = FeatureRule::query()->firstOrNew(['feature' => $feature]);
        $rule->mode ??= self::EVERYONE;

        $change($rule);

        $rule->updated_by = is_numeric($by) ? (int) $by : null;
        $rule->save();

        $this->rules = null;
    }

    /** @return array<string, FeatureRule> */
    private function rules(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        // Read on every panel page. A host that upgraded without migrating
        // yet keeps every feature on rather than breaking.
        try {
            return $this->rules = FeatureRule::query()->get()->keyBy('feature')->all();
        } catch (QueryException) {
            return $this->rules = [];
        }
    }

    /** Per the panel's guard where there is one, like the launcher. */
    private static function currentUser(): ?Authenticatable
    {
        try {
            $user = Filament::auth()->user();
        } catch (Throwable) {
            $user = null;
        }

        return $user ?? auth()->user();
    }
}
