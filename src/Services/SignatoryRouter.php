<?php

namespace Kukux\DigitalSignature\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Kukux\DigitalSignature\Contracts\PdfTemplate;
use Kukux\DigitalSignature\Models\PdfTemplateSlot;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\SignatureRequest;
use Kukux\DigitalSignature\Models\SigningSession;
use Kukux\DigitalSignature\Pdf\SlotDefinition;
use Kukux\DigitalSignature\Signatories\RouteState;
use Kukux\DigitalSignature\Signatories\SignatoryResolverFactory;
use Kukux\DigitalSignature\Signatories\SignatoryRoute;

/**
 * Answers, for one record: who fills each slot, can they, and what is
 * standing in the way?
 *
 * This is the single join point between four otherwise independent things —
 * the template's slot declarations, the designer's saved coordinates, the
 * host record's tagged people, and each of those people's signature library.
 * Every UI surface and the signing pipeline read the result rather than
 * re-deriving it, so "who signs this?" has exactly one answer in the system.
 *
 * Routing is a pure read: it never creates sessions, requests or signatures.
 */
class SignatoryRouter
{
    /**
     * Per-record memo of resolved signatories, so one routeFor() pass hits
     * each relation once instead of once per consumer. Keyed by
     * "<object id>:<slot key>"; lives only as long as the request.
     *
     * @var array<string, Model|null>
     */
    protected array $resolved = [];

    public function __construct(
        protected PdfTemplateRegistry $registry,
        protected SignatoryResolverFactory $resolvers,
    ) {
    }

    /**
     * Route every slot of a record's template.
     *
     * @param  string|null  $templateKey  Defaults to the record's own template
     *                                    (via HasPdfTemplate / HasSignatories).
     * @return array<string, SignatoryRoute> Keyed by slot key, in signing order.
     */
    public function routeFor(Model $record, ?string $templateKey = null): array
    {
        $template = $this->resolveTemplate($record, $templateKey);
        $session  = $this->openSessionFor($record, $template->key());

        $slots = $this->orderedSlots($template);

        $placements = $this->savedPlacements($template->key());
        $requests   = $this->requestsFor($session);
        $signatures = $this->primarySignaturesFor($record, $slots);

        $routes = [];

        foreach ($slots as $slot) {
            $routes[$slot->key] = $this->routeSlot(
                record:     $record,
                slot:       $slot,
                placement:  $placements[$slot->key] ?? $slot->defaultPlacement(),
                request:    $requests[$slot->key] ?? null,
                signatures: $signatures,
                session:    $session,
            );
        }

        return $this->applySequencing($routes, $session);
    }

    /**
     * Route a single slot. Handy when a caller already knows which slot it
     * cares about and doesn't want to resolve the whole template.
     */
    public function routeSlotFor(Model $record, string $slotKey, ?string $templateKey = null): ?SignatoryRoute
    {
        return $this->routeFor($record, $templateKey)[$slotKey] ?? null;
    }

    /**
     * The person who should fill a slot, without any of the surrounding state.
     */
    public function resolveSignatory(Model $record, SlotDefinition $slot): ?Model
    {
        $memoKey = spl_object_id($record).':'.$slot->key;

        if (array_key_exists($memoKey, $this->resolved)) {
            return $this->resolved[$memoKey];
        }

        return $this->resolved[$memoKey] = $this->doResolveSignatory($record, $slot);
    }

    /**
     * Forget memoized signatories — call after re-tagging a record within
     * the same request so routing sees the new assignment.
     */
    public function forgetResolved(?Model $record = null): void
    {
        if ($record === null) {
            $this->resolved = [];

            return;
        }

        $prefix = spl_object_id($record).':';

        foreach (array_keys($this->resolved) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->resolved[$key]);
            }
        }
    }

    protected function doResolveSignatory(Model $record, SlotDefinition $slot): ?Model
    {
        // A global override wins over the slot's own binding, but may return
        // null to defer back to it — that keeps the override useful for
        // "special-case one role" without having to reimplement the rest.
        if ($this->resolvers->hasOverride()) {
            $resolved = ($this->resolvers->override())($record, $slot);

            if ($resolved instanceof Model) {
                return $resolved;
            }
        }

        $resolver = $this->resolvers->make($slot);

        if ($resolver === null) {
            return null;
        }

        $user = $resolver->resolve($record, $slot);

        return $user instanceof Model ? $user : null;
    }

    /**
     * Slots that still block completion, as human-readable strings. This is
     * the "who's blocking this?" report.
     *
     * @return array<string, string> slot key => blocker message
     */
    public function blockers(Model $record, ?string $templateKey = null): array
    {
        $blockers = [];

        foreach ($this->routeFor($record, $templateKey) as $key => $route) {
            if (! $route->blocksCompletion()) {
                continue;
            }

            $blockers[$key] = $route->blockerMessage() ?? $route->state->label();
        }

        return $blockers;
    }

    /**
     * True when every required slot is resolvable — i.e. opening a session
     * now would not immediately stall on a missing person or signature.
     */
    public function isRoutable(Model $record, ?string $templateKey = null): bool
    {
        foreach ($this->routeFor($record, $templateKey) as $route) {
            if (! $route->isRequired()) {
                continue;
            }

            if (in_array($route->state, [RouteState::Unassigned, RouteState::AwaitingRegistration], true)) {
                return false;
            }
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    protected function routeSlot(
        Model $record,
        SlotDefinition $slot,
        ?array $placement,
        ?SignatureRequest $request,
        Collection $signatures,
        ?SigningSession $session,
    ): SignatoryRoute {
        $user = $this->resolveSignatory($record, $slot);

        // A request that already reached a decision is authoritative — the
        // record may have been re-tagged since, but what happened, happened.
        if ($request !== null && $request->state->isTerminal()) {
            return new SignatoryRoute(
                slot:      $slot,
                position:  $request->position() ?? $placement,
                user:      $request->user ?? $user,
                signature: $request->signature,
                request:   $request,
                state:     $request->state,
            );
        }

        $signature = $user === null
            ? null
            : $signatures->get((int) $user->getKey());

        $state = match (true) {
            $user === null && ! $slot->isRouted() => RouteState::Ready,
            $user === null                        => RouteState::Unassigned,
            $signature === null                   => RouteState::AwaitingRegistration,
            $session === null                     => RouteState::Ready,
            default                               => RouteState::AwaitingConsent,
        };

        // An unrouted slot with no placement isn't actually actionable.
        if ($state === RouteState::Ready && $placement === null) {
            $state = RouteState::AwaitingConsent;
        }

        return new SignatoryRoute(
            slot:      $slot,
            position:  $request?->position() ?? $placement,
            user:      $user,
            signature: $signature,
            request:   $request,
            state:     $state,
        );
    }

    /**
     * In a sequential session, a slot that is otherwise ready but sits behind
     * an unsigned required slot is reported as Blocked so the UI can explain
     * the wait instead of offering a button that would be refused.
     *
     * @param  array<string, SignatoryRoute>  $routes
     * @return array<string, SignatoryRoute>
     */
    protected function applySequencing(array $routes, ?SigningSession $session): array
    {
        if ($session !== null && ! $session->isSequential()) {
            return $routes;
        }

        if ($session === null && config('signature.sessions.sequence_mode', 'sequential') !== 'sequential') {
            return $routes;
        }

        $blocked = false;

        foreach ($routes as $key => $route) {
            // Only downgrade slots that would otherwise be actionable. A slot
            // that is Unassigned or AwaitingRegistration carries a more useful
            // diagnostic than "waiting on someone else", and overwriting it
            // would hide the thing the host actually has to fix.
            $actionable = in_array($route->state, [
                RouteState::Ready,
                RouteState::AwaitingConsent,
            ], true);

            if ($blocked && $actionable) {
                $routes[$key] = new SignatoryRoute(
                    slot:      $route->slot,
                    position:  $route->position,
                    user:      $route->user,
                    signature: $route->signature,
                    request:   $route->request,
                    state:     RouteState::Blocked,
                );

                continue;
            }

            // Everything after the first outstanding required slot waits.
            if ($route->isRequired() && $route->state !== RouteState::Signed) {
                $blocked = true;
            }
        }

        return $routes;
    }

    protected function resolveTemplate(Model $record, ?string $templateKey): PdfTemplate
    {
        $key = $templateKey
            ?? (method_exists($record, 'signatureTemplateKey') ? $record->signatureTemplateKey() : null);

        if ($key === null) {
            throw new \InvalidArgumentException(sprintf(
                'Cannot route signatories for [%s]: no template key given and the model does not '
                .'expose signatureTemplateKey(). Use the HasSignatories or HasPdfTemplate trait, '
                .'or pass the key explicitly.',
                $record::class,
            ));
        }

        return $this->registry->get($key);
    }

    /**
     * @return list<SlotDefinition> Slots in signing order.
     */
    protected function orderedSlots(PdfTemplate $template): array
    {
        $slots = $template->slots();

        usort(
            $slots,
            fn (SlotDefinition $a, SlotDefinition $b) => $a->sortOrder() <=> $b->sortOrder(),
        );

        return $slots;
    }

    /**
     * Saved designer coordinates for the template, keyed by slot.
     *
     * @return array<string, array{page:int,x:float,y:float,width:float,height:float}>
     */
    protected function savedPlacements(string $templateKey): array
    {
        return PdfTemplateSlot::query()
            ->where('template_key', $templateKey)
            ->get()
            ->mapWithKeys(fn (PdfTemplateSlot $row) => [
                $row->slot_key => [
                    'page'   => $row->page,
                    'x'      => $row->x,
                    'y'      => $row->y,
                    'width'  => $row->width,
                    'height' => $row->height,
                ],
            ])
            ->all();
    }

    protected function openSessionFor(Model $record, string $templateKey): ?SigningSession
    {
        return SigningSession::query()
            ->forSignable($record)
            ->where('template_key', $templateKey)
            ->open()
            ->latest('id')
            ->first();
    }

    /**
     * @return array<string, SignatureRequest> Keyed by slot key.
     */
    protected function requestsFor(?SigningSession $session): array
    {
        if ($session === null) {
            return [];
        }

        return $session->requests()
            ->with(['user', 'signature'])
            ->get()
            ->keyBy('slot_key')
            ->all();
    }

    /**
     * One query for every candidate signatory's active primary signature,
     * rather than one per slot.
     *
     * @param  list<SlotDefinition>  $slots
     * @return Collection<int, Signature> Keyed by user id.
     */
    protected function primarySignaturesFor(Model $record, array $slots): Collection
    {
        $userIds = [];

        foreach ($slots as $slot) {
            $user = $this->resolveSignatory($record, $slot);

            if ($user !== null) {
                $userIds[] = (int) $user->getKey();
            }
        }

        $userIds = array_values(array_unique($userIds));

        if ($userIds === []) {
            return collect();
        }

        return Signature::query()
            ->whereIn('user_id', $userIds)
            ->primary()
            ->where('status', 'active')
            ->orderBy('id')
            ->get()
            // keyBy lets later rows overwrite earlier ones, so ascending id
            // order leaves the newest signature per user as the winner.
            ->keyBy(fn (Signature $s) => (int) $s->user_id);
    }
}
