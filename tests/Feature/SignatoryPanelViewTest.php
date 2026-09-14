<?php

use Kukux\DigitalSignature\Filament\Components\SignatoryPanel;
use Kukux\DigitalSignature\Models\PdfTemplateSlot;
use Kukux\DigitalSignature\Services\SignatoryRouter;
use Kukux\DigitalSignature\Signatories\RouteState;
use Kukux\DigitalSignature\Tests\Support\AccomplishmentReport;

/**
 * Filament exposes a component's public methods to its view as closures, so
 * the template is rendered here with the same closures the entry would supply.
 * That exercises the real template against real SignatoryRoute objects without
 * needing a full schema/Livewire context.
 */
function renderPanel(array $routes, $session = null, ?string $error = null, bool $placement = false): string
{
    return view('signature::components.signatory-panel', [
        'getRoutes'          => fn () => $routes,
        'getSession'         => fn () => $session,
        'getRoutingError'    => fn () => $error,
        'shouldShowPlacement' => fn () => $placement,
    ])->render();
}

describe('signatory-panel blade template', function () {

    beforeEach(function () {
        registerRoutedTemplate('accomplishment-report', [
            'prepared_by' => ['label' => 'Prepared by', 'signatory' => 'preparedBy', 'order' => 1, 'required' => true],
            'noted_by'    => ['label' => 'Noted by',    'signatory' => 'notedBy',    'order' => 2, 'required' => true],
        ]);

        foreach (['prepared_by', 'noted_by'] as $i => $slot) {
            PdfTemplateSlot::updateOrCreate(
                ['template_key' => 'accomplishment-report', 'slot_key' => $slot],
                ['page' => 1, 'x' => 100.0 + ($i * 200), 'y' => 90.0, 'width' => 160.0, 'height' => 50.0],
            );
        }

        makeUser(11, 'Juan Dela Cruz');
        makeUser(13, 'Dr Reyes');
        makePrimarySignature(11);
        // Dr Reyes deliberately has no signature.

        $this->report = AccomplishmentReport::create([
            'prepared_by_id' => 11,
            'noted_by_id'    => 13,
        ]);

        $this->routes = app(SignatoryRouter::class)->routeFor($this->report);
    });

    it('renders each role, its holder, and its state', function () {
        $html = renderPanel($this->routes);

        expect($html)->toContain('Prepared by')
            ->and($html)->toContain('Juan Dela Cruz')
            ->and($html)->toContain('Noted by')
            ->and($html)->toContain('Dr Reyes');
    });

    it('names the blocker when a signatory has no registered signature', function () {
        $html = renderPanel($this->routes);

        expect($html)->toContain('Dr Reyes has not registered a signature yet.');
    });

    it('shows Unassigned when nobody holds the role', function () {
        $this->report->update(['noted_by_id' => null]);
        app(SignatoryRouter::class)->forgetResolved();

        $html = renderPanel(app(SignatoryRouter::class)->routeFor($this->report->fresh()));

        expect($html)->toContain('Unassigned')
            ->and($html)->toContain('No one is assigned');
    });

    it('hides placement coordinates unless asked', function () {
        expect(renderPanel($this->routes))->not->toContain('p1 ·')
            ->and(renderPanel($this->routes, placement: true))->toContain('p1');
    });

    it('explains a routing failure instead of rendering nothing', function () {
        $html = renderPanel([], error: 'No PdfTemplate registered with key [nope]');

        expect($html)->toContain('Signatory routing failed')
            ->and($html)->toContain('No PdfTemplate registered with key [nope]');
    });

    it('handles a template with no slots', function () {
        expect(renderPanel([]))->toContain('declares no signature slots');
    });

    it('styles every colour RouteState can return', function () {
        $source = file_get_contents(__DIR__.'/../../resources/views/components/signatory-panel.blade.php');

        foreach (RouteState::cases() as $state) {
            expect($source)->toContain("=== '{$state->color()}'");
        }
    });

    it('reads only accessors the entry actually exposes', function () {
        // A rename on the PHP side would otherwise break the template silently.
        foreach (['getRoutes', 'getSession', 'getRoutingError', 'shouldShowPlacement'] as $method) {
            expect(method_exists(SignatoryPanel::class, $method))->toBeTrue();
        }
    });

    it('defaults its name so make() needs no argument', function () {
        expect(SignatoryPanel::make()->getName())->toBe('signatories');
    });
});
