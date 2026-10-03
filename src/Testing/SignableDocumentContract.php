<?php

namespace Kukux\DigitalSignature\Testing;

use Kukux\DigitalSignature\Contracts\PdfTemplate;
use Kukux\DigitalSignature\Contracts\PlacesSlotsInDocument;
use Kukux\DigitalSignature\Contracts\SignableDocument;
use Kukux\DigitalSignature\DocumentOfRecord\DocumentOfRecordResolver;
use Kukux\DigitalSignature\Models\PdfTemplateSlot;
use Kukux\DigitalSignature\Pdf\SlotAnchors;
use Kukux\DigitalSignature\Pdf\SlotDefinition;
use Kukux\DigitalSignature\Routing\RoutingResult;
use Kukux\DigitalSignature\Services\DocumentRegistry;
use Kukux\DigitalSignature\Services\DocumentRouter;
use Kukux\DigitalSignature\Services\PdfTemplateRegistry;

/**
 * The checks every signable document should pass, for an app's own test.
 *
 *   class DtrDocumentTest extends TestCase
 *   {
 *       use RefreshDatabase, SignableDocumentContract;
 *
 *       protected function documentKey(): string { return 'dtr'; }
 *
 *       protected function routableSubject(): array
 *       {
 *           $employee = Employee::factory()->withUser()->withSignature()->hasInCharge()->create();
 *
 *           return [$employee, ['month' => '2026-09-01']];
 *       }
 *   }
 *
 * gives that test class:
 *
 *  - the template's sample renders to a PDF;
 *  - every required slot has somewhere to go (a data-signature-slot marker,
 *    or a designer/default placement);
 *  - slot signing orders don't collide;
 *  - the subject routes, and routing it again reports "already routed";
 *  - a refusal leaves no record behind (when unroutableSubject() is given);
 *  - once routed, the stored document is served and nothing is re-rendered.
 *
 * Rendering is real, so the app's PDF stack (DomPDF, fonts) has to be there.
 * Fake the signature disk in setUp() if your suite doesn't already.
 */
trait SignableDocumentContract
{
    /** The key the document is registered under in config('signature.documents'). */
    abstract protected function documentKey(): string;

    /**
     * A subject and context that should route successfully: everyone named,
     * each with a login and a registered signature.
     *
     * @return array{0: mixed, 1?: array<string, mixed>}
     */
    abstract protected function routableSubject(): array;

    /**
     * A subject and context routing should refuse, e.g. with a signatory
     * missing. Null skips the "refusal leaves no row" check.
     *
     * @return array{0: mixed, 1?: array<string, mixed>}|null
     */
    protected function unroutableSubject(): ?array
    {
        return null;
    }

    public function test_the_sample_renders_to_a_pdf(): void
    {
        $path = $this->signableTemplate()->renderSample();

        $this->assertFileExists($path);
        $this->assertStringStartsWith('%PDF', (string) file_get_contents($path, length: 4));
    }

    public function test_every_required_slot_has_somewhere_to_go(): void
    {
        $template = $this->signableTemplate();
        $required = array_filter($template->slots(), fn (SlotDefinition $slot): bool => $slot->required);

        $this->assertNotEmpty($required, 'The template declares no required slots, so nobody has to sign it.');

        $marked = $template instanceof PlacesSlotsInDocument
            ? SlotAnchors::read($template->renderSample())
            : [];

        foreach ($required as $slot) {
            if ($marked !== []) {
                $this->assertArrayHasKey(
                    $slot->key,
                    $marked,
                    "The view marks signature spaces, but not \"{$slot->key}\". Add data-signature-slot=\"{$slot->key}\".",
                );

                continue;
            }

            $placed = $slot->hasDefaultPlacement()
                || PdfTemplateSlot::query()->where('template_key', $template->key())->where('slot_key', $slot->key)->exists();

            $this->assertTrue($placed, "Slot \"{$slot->key}\" has no marker, no default placement and no designer placement.");
        }
    }

    public function test_slot_signing_orders_are_unique(): void
    {
        $orders = array_values(array_filter(array_map(
            fn (SlotDefinition $slot): ?int => $slot->order,
            $this->signableTemplate()->slots(),
        ), fn (?int $order): bool => $order !== null));

        $this->assertSame(
            count($orders),
            count(array_unique($orders)),
            'Two slots share a signing order, so their sequence is undefined.',
        );
    }

    public function test_it_routes_and_a_second_route_reports_already_routed(): void
    {
        [$subject, $context] = $this->subjectAndContext($this->routableSubject());

        $first = $this->routeSignableDocument($subject, $context);

        $this->assertTrue($first->ok(), "Routing was refused ({$first->reason()}): {$first->body()}");
        $this->assertSame(RoutingResult::ROUTED, $first->reason());

        $second = $this->routeSignableDocument($subject, $context);

        $this->assertSame(RoutingResult::ALREADY_ROUTED, $second->reason());
        $this->assertTrue($second->session()->is($first->session()));
    }

    public function test_a_refusal_leaves_no_record_behind(): void
    {
        $unroutable = $this->unroutableSubject();

        if ($unroutable === null) {
            $this->markTestSkipped('No unroutableSubject() given.');
        }

        [$subject, $context] = $this->subjectAndContext($unroutable);

        $existed = $this->signableDefinition()->locate($subject, $context) !== null;

        $result = $this->routeSignableDocument($subject, $context);

        $this->assertTrue($result->wasRefused(), 'unroutableSubject() routed successfully.');

        if (! $existed) {
            $this->assertNull(
                $this->signableDefinition()->locate($subject, $context),
                "Routing refused ({$result->reason()}) but left the record it opened behind.",
            );
        }
    }

    public function test_once_routed_the_stored_document_is_served_and_nothing_is_rendered_again(): void
    {
        [$subject, $context] = $this->subjectAndContext($this->routableSubject());

        $routed = $this->routeSignableDocument($subject, $context);
        $this->assertTrue($routed->ok(), "Routing was refused ({$routed->reason()}): {$routed->body()}");

        $templates = app(PdfTemplateRegistry::class);
        $original = $templates->get($this->signableDefinition()->template());

        // Any render from here on is a bug: the routed document must come
        // from storage, or it is not what anyone signed.
        $templates->register(new RefusesToRender($original));

        try {
            $resolved = app(DocumentOfRecordResolver::class)->resolve(
                $this->documentKey(),
                $subject,
                $context,
                fn (): string => $this->fail('The document was rendered live after it had been routed.'),
            );

            $this->assertTrue($resolved->isOfRecord());
            $this->assertStringStartsWith('%PDF', substr($resolved->contents(), 0, 4));
            $this->assertTrue($resolved->ofRecord->current->verify(), 'The stored document does not match its recorded hash.');
        } finally {
            $templates->register($original);
        }
    }

    // -------------------------------------------------------------------------

    protected function signableDefinition(): SignableDocument
    {
        return app(DocumentRegistry::class)->get($this->documentKey());
    }

    protected function signableTemplate(): PdfTemplate
    {
        return app(PdfTemplateRegistry::class)->get($this->signableDefinition()->template());
    }

    /** @param  array<string, mixed>  $context */
    protected function routeSignableDocument(mixed $subject, array $context): RoutingResult
    {
        return app(DocumentRouter::class)->route($this->documentKey(), $subject, $context);
    }

    /** @return array{0: mixed, 1: array<string, mixed>} */
    private function subjectAndContext(array $pair): array
    {
        return [$pair[0], $pair[1] ?? []];
    }
}
