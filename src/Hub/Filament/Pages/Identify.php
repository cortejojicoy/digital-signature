<?php

namespace Kukux\DigitalSignature\Hub\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Kukux\DigitalSignature\Hub\Identity\HubRedirector;
use Kukux\DigitalSignature\Hub\Identity\IdentityException;
use Kukux\DigitalSignature\Hub\Identity\IdentityService;
use Kukux\DigitalSignature\Hub\Identity\IdentityTransfer;
use Kukux\DigitalSignature\Hub\Personnel;
use Kukux\DigitalSignature\Models\Identity;
use Kukux\DigitalSignature\Models\Transfer;
use LogicException;

/**
 * "Who are you?" (plan 1.3). The only page a paired but unidentified account
 * may open (EnsureIdentified).
 *
 * The directory is searched, never listed (D12): 3+ characters or an exact
 * employee number, active people only, name/unit/position only, and a quota
 * per account (R9). The personnel keys of the results stay in the session;
 * the browser only ever sees their positions in the list.
 */
class Identify extends Page
{
    public const SLUG = 'identify';

    protected string $view = 'signature::hub.pages.identify';

    protected static ?string $slug = self::SLUG;

    protected static bool $shouldRegisterNavigation = false;

    private const RESULTS = 'signature.hub.identify.results';

    public string $query = '';

    /** @var array<int, array{name: string, unit: ?string, position: ?string}> */
    public array $results = [];

    public bool $searched = false;

    public ?string $error = null;

    public function mount(): void
    {
        if (Identity::forUser($this->userId())?->isIdentified()) {
            $this->redirect(app(HubRedirector::class)->profileUrl());
        }
    }

    public function getTitle(): string|Htmlable
    {
        return 'Who are you?';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Find yourself in the university personnel registry.';
    }

    public function search(IdentityService $identities): void
    {
        $this->error = null;

        try {
            $people = $identities->search($this->userId(), $this->query);
        } catch (IdentityException $e) {
            $this->error = $e->getMessage();
            $this->results = [];

            return;
        } catch (LogicException $e) {
            report($e);
            $this->error = 'The personnel registry is not available right now. Try again later.';

            return;
        }

        session()->put(self::RESULTS, array_map(fn (Personnel $p) => $p->key, $people));

        $this->results = array_map(fn (Personnel $p) => [
            'name'     => $p->name,
            'unit'     => $p->unit,
            'position' => $p->position,
        ], $people);
        $this->searched = true;
    }

    public function choose(int $index, IdentityService $identities): void
    {
        $this->error = null;
        $key = session(self::RESULTS, [])[$index] ?? null;

        if (! is_string($key)) {
            $this->error = 'Search again and pick your name from the list.';

            return;
        }

        try {
            $result = $identities->identify($this->userId(), $key);
        } catch (IdentityException $e) {
            $this->error = $e->getMessage();

            return;
        }

        session()->forget(self::RESULTS);
        $this->results = [];

        if ($result instanceof Identity) {
            $this->redirect(app(HubRedirector::class)->afterSignIn(auth()->user()));
        }

        // A transfer: the page now shows "approve it on your old computer".
    }

    /** wire:poll while a move waits: on to the Profile once it's approved. */
    public function checkTransfer(): void
    {
        if (Identity::forUser($this->userId())?->isIdentified()) {
            $this->redirect(app(HubRedirector::class)->afterSignIn(auth()->user()));
        }
    }

    public function cancelTransfer(IdentityTransfer $transfers): void
    {
        if ($transfer = $transfers->pendingFor($this->userId())) {
            $transfers->reject($transfer, $this->userId(), 'cancelled');
        }
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $transfer = app(IdentityTransfer::class)->pendingFor($this->userId());

        return [
            'transfer'    => $transfer,
            'person'      => $transfer ? $this->person($transfer) : null,
            'oldComputer' => $transfer ? app(IdentityService::class)->computer((int) $transfer->from_user_id)?->displayName() : null,
            'quotaLeft'   => max(0, (int) config('signature.hub.personnel.search_quota', 20) - (int) Identity::forUser($this->userId())?->search_count),
            'minSearch'   => (int) config('signature.hub.personnel.min_search', 3),
        ];
    }

    private function person(Transfer $transfer): ?Personnel
    {
        try {
            return app(IdentityService::class)->directory()->find($transfer->personnel_key);
        } catch (LogicException) {
            return null;
        }
    }

    private function userId(): int
    {
        return (int) auth()->id();
    }
}
