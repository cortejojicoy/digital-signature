<?php

namespace Kukux\DigitalSignature\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Kukux\DigitalSignature\Contracts\ConfiguresSigningSession;
use Kukux\DigitalSignature\Events\SignatureAutoAffixed;
use Kukux\DigitalSignature\Exceptions\DelegationNotPermittedException;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Kukux\DigitalSignature\Models\SignatureDelegation;
use Kukux\DigitalSignature\Models\SignatureRequest;
use Kukux\DigitalSignature\Models\SigningSession;
use Kukux\DigitalSignature\Notifications\SignatureAutoAffixedNotification;

/**
 * Applies a signature on its owner's behalf, when — and only when — that
 * owner has granted a standing delegation covering this template and role.
 *
 * The security position, in one line: automation may remove the *effort* of
 * signing, never the *consent*. Everything here exists to keep those apart.
 *
 * Three modes, set per template or globally via
 * `signature.auto_affix.mode` (see config/signature.php):
 *
 *   approval   (default) — never auto-affixes. Requests are created and the
 *                          signatory signs them themselves.
 *   delegated            — auto-affixes only where a usable
 *                          SignatureDelegation exists.
 *   implicit             — treats being tagged as consent. OFF by default,
 *                          gated behind an extra config acknowledgement, and
 *                          documented as unsafe: anyone who can edit the
 *                          record can then cause that person's certificate
 *                          to sign it.
 *
 * Every auto-affix writes an audit row naming the authorising grant and
 * notifies the signatory. Neither is optional.
 */
class AutoAffixService
{
    public function __construct(
        protected SigningSessionManager $sessions,
    ) {
    }

    /**
     * Auto-affix every request in a session that a delegation authorises.
     *
     * Runs in sequence order and stops at the first slot it cannot sign, so
     * a sequential session is never left with a gap — the remaining slots
     * simply wait for their signatories as usual.
     *
     * @return list<Signature> The signatures produced, in order.
     */
    public function process(SigningSession $session): array
    {
        $applied = [];

        foreach ($session->requests()->orderBy('sequence')->get() as $request) {
            if ($request->state->isTerminal()) {
                continue;
            }

            $signature = $this->attempt($request);

            if ($signature === null) {
                // Sequential sessions can't skip ahead; parallel ones can.
                if ($session->isSequential()) {
                    break;
                }

                continue;
            }

            $applied[] = $signature;
        }

        return $applied;
    }

    /**
     * Try to auto-affix one request. Returns null when no grant authorises
     * it — that is the normal, expected outcome, not an error.
     */
    public function attempt(SignatureRequest $request): ?Signature
    {
        if ($request->user_id === null) {
            return null;
        }

        $session = $request->session;
        $mode = $this->modeFor($session);

        if ($mode === 'approval') {
            return null;
        }

        $signature = $this->primarySignatureFor((int) $request->user_id);

        if ($signature === null) {
            return null;
        }

        $delegation = $mode === 'implicit'
            ? $this->implicitGrant($request, $signature)
            : $this->findGrant($request, $signature);

        if ($delegation === null) {
            return null;
        }

        $applied = $this->sessions->applySignature(
            request:      $request,
            useSignature: $signature,
            password:     null,
            delegated:    true,
        );

        $delegation->recordUse();

        SignatureAudit::record(SignatureAudit::SIGNATURE_AUTO_AFFIXED, [
            'subject_user_id'      => $request->user_id,
            // The signatory was not the actor — whoever triggered the session
            // was. Recording them separately is the whole point of the row.
            'signing_session_id'   => $session->id,
            'signature_request_id' => $request->id,
            'signature_id'         => $applied->id,
            'delegation_id'        => $delegation->exists ? $delegation->id : null,
            'context'              => [
                'mode'     => $mode,
                'slot'     => $request->slot_key,
                'role'     => $request->role,
                'template' => $session->template_key,
            ],
        ]);

        event(new SignatureAutoAffixed($request->fresh(), $delegation));

        $this->notifySignatory($request->fresh(), $delegation);

        return $applied;
    }

    /**
     * Create a standing grant. Only the grantor may do this, in their own
     * authenticated session — an administrator cannot consent for someone else.
     *
     * @throws DelegationNotPermittedException
     */
    public function grant(
        int $grantorId,
        Signature $signature,
        string $templateKey,
        ?string $role = null,
        ?\DateTimeInterface $expiresAt = null,
        ?int $maxUses = null,
        ?Model $signable = null,
        ?int $actorId = null,
    ): SignatureDelegation {
        SignatureDelegation::assertGrantable($grantorId, $actorId ?? auth()->id());

        if ((int) $signature->user_id !== $grantorId) {
            throw new DelegationNotPermittedException(
                'A delegation can only authorise a signature that belongs to the grantor.'
            );
        }

        if ($signature->isRevoked()) {
            throw new DelegationNotPermittedException(
                'A revoked signature cannot be delegated.'
            );
        }

        $delegation = SignatureDelegation::create([
            'user_id'            => $grantorId,
            'signature_id'       => $signature->id,
            'template_key'       => $templateKey,
            'role'               => $role,
            'signable_type'      => $signable?->getMorphClass(),
            'signable_id'        => $signable?->getKey(),
            'max_uses'           => $maxUses,
            'expires_at'         => $expiresAt ?? $this->defaultExpiry(),
            'granted_ip'         => request()->ip(),
            'granted_user_agent' => request()->userAgent(),
        ]);

        SignatureAudit::record(SignatureAudit::DELEGATION_GRANTED, [
            'subject_user_id' => $grantorId,
            'signature_id'    => $signature->id,
            'delegation_id'   => $delegation->id,
            'context'         => [
                'template'   => $templateKey,
                'role'       => $role,
                'expires_at' => $delegation->expires_at?->toIso8601String(),
                'max_uses'   => $maxUses,
            ],
        ]);

        return $delegation;
    }

    public function revokeGrant(SignatureDelegation $delegation, ?int $actorId = null): void
    {
        SignatureDelegation::assertGrantable((int) $delegation->user_id, $actorId ?? auth()->id());

        $delegation->revoke();

        SignatureAudit::record(SignatureAudit::DELEGATION_REVOKED, [
            'subject_user_id' => $delegation->user_id,
            'delegation_id'   => $delegation->id,
        ]);
    }

    // -------------------------------------------------------------------------

    /**
     * The grant authorising this request, or null. A grant must be usable,
     * cover this record, and still point at a signature the user owns.
     */
    protected function findGrant(SignatureRequest $request, Signature $signature): ?SignatureDelegation
    {
        $session = $request->session;
        $record = $session->signable;

        if ($record === null) {
            return null;
        }

        return SignatureDelegation::query()
            ->for((int) $request->user_id, $session->template_key, $request->role)
            ->usable()
            ->where('signature_id', $signature->id)
            ->get()
            // Narrower grants (scoped to one record) win over blanket ones.
            ->sortByDesc(fn (SignatureDelegation $d) => $d->signable_type !== null ? 1 : 0)
            ->first(fn (SignatureDelegation $d) => $d->covers($record));
    }

    /**
     * Implicit mode: manufacture an unsaved grant so the audit trail still
     * records *something* as the authority, clearly labelled as implicit.
     *
     * Requires an explicit second acknowledgement in config because the
     * consequence is severe — anyone who can edit the record can cause this
     * user's certificate to sign it.
     */
    protected function implicitGrant(SignatureRequest $request, Signature $signature): ?SignatureDelegation
    {
        if (! config('signature.auto_affix.allow_implicit', false)) {
            throw new DelegationNotPermittedException(
                'signature.auto_affix.mode is "implicit" but signature.auto_affix.allow_implicit is false. '
                .'Implicit auto-affix treats tagging someone as their consent to sign, which lets anyone '
                .'who can edit the record sign as that person. Set allow_implicit to true only if that is '
                .'a deliberate, documented decision for this deployment.'
            );
        }

        return new SignatureDelegation([
            'user_id'      => $request->user_id,
            'signature_id' => $signature->id,
            'template_key' => $request->session->template_key,
            'role'         => $request->role,
        ]);
    }

    protected function modeFor(SigningSession $session): string
    {
        $template = app(PdfTemplateRegistry::class)->find($session->template_key);

        if ($template instanceof ConfiguresSigningSession) {
            return $template->autoAffixMode();
        }

        return config('signature.auto_affix.mode', 'approval');
    }

    protected function primarySignatureFor(int $userId): ?Signature
    {
        return Signature::primaryActiveFor($userId)->latest('id')->first();
    }

    /**
     * Non-negotiable #5: the signatory is told about every auto-affix, not
     * just about the grant. Failure to notify must not roll back a signature
     * that has already been embedded, so it is reported rather than thrown.
     */
    protected function notifySignatory(SignatureRequest $request, SignatureDelegation $delegation): void
    {
        if (! config('signature.auto_affix.notify', true)) {
            return;
        }

        $user = $request->user;

        if ($user === null) {
            return;
        }

        try {
            NotificationFacade::send($user, new SignatureAutoAffixedNotification($request, $delegation));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function defaultExpiry(): ?\DateTimeInterface
    {
        $days = config('signature.auto_affix.default_grant_days', 365);

        return $days ? now()->addDays((int) $days) : null;
    }
}
