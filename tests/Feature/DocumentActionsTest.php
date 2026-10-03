<?php

use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Filament\Actions\DownloadDocumentOfRecordAction;
use Kukux\DigitalSignature\Filament\Actions\RequestSignaturesAction;
use Kukux\DigitalSignature\Filament\Actions\RouteForSignaturesAction;
use Kukux\DigitalSignature\Filament\Actions\ViewDocumentHistoryAction;
use Kukux\DigitalSignature\Filament\Actions\ViewDocumentOfRecordAction;
use Kukux\DigitalSignature\Models\SigningSession;
use Kukux\DigitalSignature\Tests\Support\LeaveForm;
use Kukux\DigitalSignature\Tests\Support\LeaveLedger;

/**
 * The Filament layer over DocumentRouter and the document of record. Each
 * action is a thin wrapper: these check the wiring, not the routing rules.
 */
describe('Document actions', function () {

    beforeEach(function () {
        Storage::fake('testing');
        leaveFormSchema();
        registerLeaveForm();

        $this->boss = signingPerson(21, 'Dr Reyes');
        $this->juana = signingPerson(22, 'Juana Cruz', supervisorId: 21);
        app()->instance(LeaveLedger::class, new LeaveLedger([22 => 3]));

        $this->period = ['period' => '2026-09'];
    });

    it('routes a generated document from its subject and context', function () {
        RouteForSignaturesAction::make()
            ->document('leave-form')
            ->subject(fn () => $this->juana)
            ->context(fn () => $this->period)
            ->call();

        expect(LeaveForm::count())->toBe(1)
            ->and(SigningSession::count())->toBe(1);
    });

    it('halts on a refusal, so a surrounding modal stays open', function () {
        app()->instance(LeaveLedger::class, new LeaveLedger([]));

        expect(fn () => RouteForSignaturesAction::make()
            ->document('leave-form')
            ->subject($this->juana)
            ->context($this->period)
            ->call()
        )->toThrow(Halt::class);

        expect(LeaveForm::count())->toBe(0);
    });

    it('routes a record page\'s own record through the same router', function () {
        $form = LeaveForm::create(['person_id' => 22, 'period' => '2026-09', 'reviewer_signatory_id' => 21]);

        RequestSignaturesAction::make()->autoAffix(false)->call(['record' => $form]);

        expect(SigningSession::count())->toBe(1);
    });

    it('refuses on a record page with the new wording, and halts', function () {
        $form = LeaveForm::create(['person_id' => 22, 'period' => '2026-09']);

        expect(fn () => RequestSignaturesAction::make()->call(['record' => $form]))->toThrow(Halt::class);

        expect(SigningSession::count())->toBe(0);
    });

    it('shows the view and history actions only once the document is routed', function () {
        $view = ViewDocumentOfRecordAction::make()->document('leave-form')->subject($this->juana)->context($this->period);
        $history = ViewDocumentHistoryAction::make()->document('leave-form')->subject($this->juana)->context($this->period);

        expect($view->isVisible())->toBeFalse()
            ->and($history->isVisible())->toBeFalse();

        RouteForSignaturesAction::make()->document('leave-form')->subject($this->juana)->context($this->period)->call();

        $view = ViewDocumentOfRecordAction::make()->document('leave-form')->subject($this->juana)->context($this->period);

        expect($view->isVisible())->toBeTrue()
            ->and($view->getUrl())->toContain('/signature/documents/');
    });

    it('downloads the live draft before routing and the stored document after', function () {
        $download = fn () => DownloadDocumentOfRecordAction::make()
            ->document('leave-form')
            ->subject($this->juana)
            ->context($this->period)
            ->live(fn () => '%PDF-1.4 live draft')
            ->filename('leave.pdf');

        $draft = $download()->call();

        ob_start();
        $draft->sendContent();
        expect(ob_get_clean())->toBe('%PDF-1.4 live draft');

        RouteForSignaturesAction::make()->document('leave-form')->subject($this->juana)->context($this->period)->call();

        $stored = $download()->call();
        ob_start();
        $stored->sendContent();

        expect(ob_get_clean())->toStartWith('%PDF-1.4')
            ->and($stored->headers->get('Content-Disposition'))->toContain('leave-in-progress.pdf');
    });
});
