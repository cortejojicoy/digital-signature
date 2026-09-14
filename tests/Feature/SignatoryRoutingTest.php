<?php

use Kukux\DigitalSignature\Models\PdfTemplateSlot;
use Kukux\DigitalSignature\Services\SignatoryRouter;
use Kukux\DigitalSignature\Signatories\RouteState;
use Kukux\DigitalSignature\Tests\Support\AccomplishmentReport;

/**
 * The scenario from the concept doc: an Accomplishment Report with
 * Prepared by / Attested by / Noted by, each tagged to a different person.
 */
function arTemplate(array $extra = []): void
{
    registerRoutedTemplate('accomplishment-report', [
        'prepared_by' => ['label' => 'Prepared by', 'signatory' => 'preparedBy', 'order' => 1, 'required' => true],
        'attested_by' => ['label' => 'Attested by', 'signatory' => 'attestedBy', 'order' => 2, 'required' => true],
        'noted_by'    => ['label' => 'Noted by',    'signatory' => 'notedBy',    'order' => 3, 'required' => true],
    ], $extra);

    foreach (['prepared_by' => 100.0, 'attested_by' => 300.0, 'noted_by' => 500.0] as $slot => $x) {
        PdfTemplateSlot::create([
            'template_key' => 'accomplishment-report',
            'slot_key'     => $slot,
            'page'         => 1,
            'x'            => $x,
            'y'            => 90.0,
            'width'        => 160.0,
            'height'       => 50.0,
        ]);
    }
}

describe('SignatoryRouter', function () {

    beforeEach(function () {
        arTemplate();

        $this->juan  = makeUser(11, 'Juan Dela Cruz');
        $this->maria = makeUser(12, 'Maria Santos');
        $this->reyes = makeUser(13, 'Dr Reyes');

        $this->report = AccomplishmentReport::create([
            'title'           => 'Q1 accomplishments',
            'prepared_by_id'  => $this->juan->id,
            'attested_by_id'  => $this->maria->id,
            'noted_by_id'     => $this->reyes->id,
        ]);
    });

    it('resolves each slot to the person tagged on the record', function () {
        makePrimarySignature(11);
        makePrimarySignature(12);
        makePrimarySignature(13);

        $routes = app(SignatoryRouter::class)->routeFor($this->report);

        expect(array_keys($routes))->toBe(['prepared_by', 'attested_by', 'noted_by'])
            ->and($routes['prepared_by']->signerName())->toBe('Juan Dela Cruz')
            ->and($routes['attested_by']->signerName())->toBe('Maria Santos')
            ->and($routes['noted_by']->signerName())->toBe('Dr Reyes');
    });

    it('attaches each signatory\'s own registered signature', function () {
        $juanSig  = makePrimarySignature(11);
        $mariaSig = makePrimarySignature(12);
        makePrimarySignature(13);

        $routes = app(SignatoryRouter::class)->routeFor($this->report);

        expect($routes['prepared_by']->signature->id)->toBe($juanSig->id)
            ->and($routes['attested_by']->signature->id)->toBe($mariaSig->id);
    });

    it('reads placement from the designer-saved slot rows', function () {
        makePrimarySignature(11);

        $routes = app(SignatoryRouter::class)->routeFor($this->report);

        expect($routes['attested_by']->position)->toBe([
            'page' => 1, 'x' => 300.0, 'y' => 90.0, 'width' => 160.0, 'height' => 50.0,
        ]);
    });

    it('reports unassigned when nobody is tagged for a role', function () {
        $this->report->update(['noted_by_id' => null]);
        makePrimarySignature(11);
        makePrimarySignature(12);

        $routes = app(SignatoryRouter::class)->routeFor($this->report->fresh());

        expect($routes['noted_by']->state)->toBe(RouteState::Unassigned)
            ->and($routes['noted_by']->blockerMessage())->toContain('No one is assigned');
    });

    it('reports awaiting_registration when the tagged person has no signature', function () {
        makePrimarySignature(11);
        makePrimarySignature(12);
        // Dr Reyes never registered one.

        $routes = app(SignatoryRouter::class)->routeFor($this->report);

        expect($routes['noted_by']->state)->toBe(RouteState::AwaitingRegistration)
            ->and($routes['noted_by']->blockerMessage())->toContain('Dr Reyes has not registered');
    });

    it('ignores a revoked signature when deciding readiness', function () {
        makePrimarySignature(11);
        makePrimarySignature(12);
        makePrimarySignature(13)->update(['status' => 'revoked']);

        $routes = app(SignatoryRouter::class)->routeFor($this->report);

        expect($routes['noted_by']->signature)->toBeNull()
            ->and($routes['noted_by']->state)->toBe(RouteState::AwaitingRegistration);
    });

    it('lists exactly the slots that block completion', function () {
        makePrimarySignature(11);

        $blockers = app(SignatoryRouter::class)->blockers($this->report);

        expect(array_keys($blockers))->toBe(['prepared_by', 'attested_by', 'noted_by']);
    });

    it('is not routable while a required signatory cannot sign', function () {
        makePrimarySignature(11);
        makePrimarySignature(12);

        expect(app(SignatoryRouter::class)->isRoutable($this->report))->toBeFalse();

        makePrimarySignature(13);
        app(SignatoryRouter::class)->forgetResolved();

        expect(app(SignatoryRouter::class)->isRoutable($this->report->fresh()))->toBeTrue();
    });

    it('orders slots by their declared order, not config order', function () {
        registerRoutedTemplate('reordered', [
            'noted_by'    => ['signatory' => 'notedBy',    'order' => 3],
            'prepared_by' => ['signatory' => 'preparedBy', 'order' => 1],
            'attested_by' => ['signatory' => 'attestedBy', 'order' => 2],
        ]);

        $routes = app(SignatoryRouter::class)->routeFor($this->report, 'reordered');

        expect(array_keys($routes))->toBe(['prepared_by', 'attested_by', 'noted_by']);
    });

    it('resolves a signatory from a foreign key when no relation exists', function () {
        registerRoutedTemplate('by-key', [
            'prepared_by' => ['signatory' => 'prepared_by_id'],
        ]);

        $routes = app(SignatoryRouter::class)->routeFor($this->report, 'by-key');

        expect($routes['prepared_by']->user?->getKey())->toBe($this->juan->id);
    });

    it('resolves a signatory from a closure binding', function () {
        registerRoutedTemplate('by-closure', [
            'approver' => ['signatory' => fn ($record) => $record->attestedBy],
        ]);

        $routes = app(SignatoryRouter::class)->routeFor($this->report, 'by-closure');

        expect($routes['approver']->signerName())->toBe('Maria Santos');
    });

    it('degrades to unassigned rather than throwing on a broken binding', function () {
        registerRoutedTemplate('broken', [
            'ghost' => ['signatory' => 'noSuchRelation'],
        ]);

        $routes = app(SignatoryRouter::class)->routeFor($this->report, 'broken');

        expect($routes['ghost']->state)->toBe(RouteState::Unassigned);
    });
});
