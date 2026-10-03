<?php

use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Contracts\PdfTemplate;
use Kukux\DigitalSignature\Contracts\RoutingGuard;
use Kukux\DigitalSignature\Models\SigningSession;
use Kukux\DigitalSignature\Routing\RoutingResult;
use Kukux\DigitalSignature\Services\DocumentRouter;
use Kukux\DigitalSignature\Tests\Support\LeaveForm;
use Kukux\DigitalSignature\Tests\Support\LeaveLedger;
use Kukux\DigitalSignature\Tests\Support\Person;
use Kukux\DigitalSignature\Tests\Support\StubPdfRenderer;

/**
 * DocumentRouter: the one routing implementation every app shares. Exercised
 * through a document defined the way the docs tell an app to define one.
 */
describe('DocumentRouter', function () {

    beforeEach(function () {
        Storage::fake('testing');
        leaveFormSchema();
        registerLeaveForm();

        $this->boss = signingPerson(21, 'Dr Reyes');
        $this->juana = signingPerson(22, 'Juana Cruz', supervisorId: 21);

        app()->instance(LeaveLedger::class, new LeaveLedger([22 => 3]));

        $this->period = ['period' => '2026-09'];
    });

    afterEach(fn () => Mockery::close());

    it('routes a document generated from a subject and context', function () {
        $result = app(DocumentRouter::class)->route('leave-form', $this->juana, $this->period);

        expect($result->ok())->toBeTrue()
            ->and($result->reason())->toBe(RoutingResult::ROUTED)
            ->and($result->record())->toBeInstanceOf(LeaveForm::class)
            ->and($result->session()->requests)->toHaveCount(2)
            ->and($result->title())->toBe('Routed for signatures')
            // Names as the document has them: the people, not their logins.
            ->and($result->body())->toBe("Sent to Juana Cruz and Dr Reyes. They'll sign in that order.");

        $requests = $result->session()->requests->keyBy('slot_key');

        // Routed to the LOGINS the mapper found, never to the people ids.
        expect((int) $requests['applicant']->user_id)->toBe($this->juana->user_id)
            ->and((int) $requests['reviewer']->user_id)->toBe($this->boss->user_id);
    });

    it('snapshots the signatories onto the record when it opens it', function () {
        $form = app(DocumentRouter::class)->route('leave-form', $this->juana, $this->period)->record();

        expect($form->reviewer_signatory_id)->toBe(21)
            ->and($form->reviewer_position)->toBe('Supervisor')
            ->and($form->signatoryViewData())->toBe(['reviewer_name' => 'Dr Reyes', 'reviewer_position' => 'Supervisor']);
    });

    it('reports a second route as already routed and keeps one session', function () {
        $router = app(DocumentRouter::class);

        $first = $router->route('leave-form', $this->juana, $this->period);
        $second = $router->route('leave-form', $this->juana, $this->period);

        expect($second->reason())->toBe(RoutingResult::ALREADY_ROUTED)
            ->and($second->ok())->toBeTrue()
            ->and($second->wasRouted())->toBeFalse()
            ->and($second->session()->id)->toBe($first->session()->id)
            ->and(SigningSession::count())->toBe(1);
    });

    it('stops at a preflight guard before writing anything, with the guard resolved from the container', function () {
        app()->instance(LeaveLedger::class, new LeaveLedger([]));

        $result = app(DocumentRouter::class)->route('leave-form', $this->juana, $this->period);

        expect($result->wasRefused())->toBeTrue()
            ->and($result->reason())->toBe('no_leave_days')
            ->and($result->title())->toBe('Nothing to file')
            ->and($result->body())->toBe('No leave days for 2026-09.')
            ->and(LeaveForm::count())->toBe(0);
    });

    it('refuses a missing signatory and leaves no row behind', function () {
        $this->juana->update(['supervisor_id' => null]);

        $result = app(DocumentRouter::class)->route('leave-form', $this->juana->fresh(), $this->period);

        expect($result->reason())->toBe(RoutingResult::MISSING_SIGNATORIES)
            // The definition's own wording wins over the package default.
            ->and($result->body())->toBe('Ask HR to set your Reviewer first.')
            ->and($result->title())->toBe('Signatories not set')
            ->and(LeaveForm::count())->toBe(0)
            ->and(SigningSession::count())->toBe(0);
    });

    it('tells "named but has no login" apart from "nobody named"', function () {
        $this->boss->update(['user_id' => null]);

        $result = app(DocumentRouter::class)->route('leave-form', $this->juana, $this->period);

        expect($result->reason())->toBe(RoutingResult::NOT_READY)
            ->and($result->body())->toBe('Dr Reyes is named as "Reviewer" but has no login, so they cannot sign.')
            ->and(LeaveForm::count())->toBe(0);
    });

    it('refuses when a signatory has no registered signature', function () {
        \Kukux\DigitalSignature\Models\Signature::where('user_id', $this->boss->user_id)->delete();

        $result = app(DocumentRouter::class)->route('leave-form', $this->juana, $this->period);

        expect($result->reason())->toBe(RoutingResult::NOT_READY)
            ->and($result->body())->toContain('has not registered a signature yet')
            ->and(LeaveForm::count())->toBe(0);
    });

    it('refuses when a view that uses markers lost one', function () {
        registerLeaveForm(['renderer' => \Kukux\DigitalSignature\Tests\Support\MarksApplicantOnlyRenderer::class]);

        $result = app(DocumentRouter::class)->route('leave-form', $this->juana, $this->period);

        expect($result->reason())->toBe(RoutingResult::MARKERS_MISSING)
            ->and($result->body())->toContain('Reviewer')
            ->and(LeaveForm::count())->toBe(0);
    });

    it('runs the definition\'s guards inside the transaction, before the package\'s', function () {
        $guard = new class implements RoutingGuard {
            public static array $seen = [];

            public function check(\Illuminate\Database\Eloquent\Model $record, PdfTemplate $template, array $context): ?RoutingResult
            {
                static::$seen[] = $record->exists;

                return RoutingResult::refused('on_hold');
            }
        };

        app(\Kukux\DigitalSignature\Services\DocumentRegistry::class)->register('leave-form', new class($guard) extends \Kukux\DigitalSignature\Tests\Support\LeaveFormDocument {
            public function __construct(private RoutingGuard $extra)
            {
                parent::__construct(new \Kukux\DigitalSignature\Tests\Support\SupervisorDefaults);
            }

            public function guards(): array
            {
                return [$this->extra];
            }
        });

        $result = app(DocumentRouter::class)->route('leave-form', $this->juana, $this->period);

        expect($result->reason())->toBe('on_hold')
            // It saw the opened record, and the refusal rolled that record back.
            ->and($guard::$seen)->toBe([true])
            ->and(LeaveForm::count())->toBe(0);
    });

    it('rolls back and rethrows when rendering fails', function () {
        registerLeaveForm(['renderer' => \Kukux\DigitalSignature\Tests\Support\ExplodingRenderer::class]);

        expect(fn () => app(DocumentRouter::class)->route('leave-form', $this->juana, $this->period))
            ->toThrow(RuntimeException::class, 'renderer exploded');

        expect(LeaveForm::count())->toBe(0);
    });

    it('says "any order" for a parallel document', function () {
        registerLeaveForm(['sequence_mode' => 'parallel']);

        $result = app(DocumentRouter::class)->route('leave-form', $this->juana, $this->period);

        expect($result->body())->toBe('Sent to Juana Cruz and Dr Reyes. They can sign in any order.');
    });

    it('takes an app-wide wording override from the translator', function () {
        app('translator')->addLines(['routing.routed.title' => 'Sent off'], 'en', 'signature');

        $result = app(DocumentRouter::class)->route('leave-form', $this->juana, $this->period);

        expect($result->title())->toBe('Sent off');
    });

    it('accepts a definition by class-string or instance as well as by key', function () {
        $byClass = app(DocumentRouter::class)->route(
            \Kukux\DigitalSignature\Tests\Support\LeaveFormDocument::class,
            $this->juana,
            $this->period,
        );

        expect($byClass->wasRouted())->toBeTrue();
    });
});
