# Testing a signable document

`SignableDocumentContract` is a trait with the checks every signable document should pass. Use it in a test class for each document. Give it a subject that routes, and optionally one that doesn't.

```php
namespace Tests\Feature\Signatures;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kukux\DigitalSignature\Testing\SignableDocumentContract;
use Tests\TestCase;

class DtrDocumentContractTest extends TestCase
{
    use RefreshDatabase, SignableDocumentContract;

    protected function documentKey(): string
    {
        return 'dtr';
    }

    /** Everyone named, each with a login and a registered signature. */
    protected function routableSubject(): array
    {
        $employee = Employee::factory()->withUser()->withSignature()->hasInCharge()->create();

        return [$employee, ['month' => '2026-09-01']];
    }

    /** Optional: something routing should refuse. */
    protected function unroutableSubject(): ?array
    {
        return [Employee::factory()->withUser()->withSignature()->create(), ['month' => '2026-09-01']];
    }
}
```

Your test class gets:

| Test | Checks |
|---|---|
| `test_the_sample_renders_to_a_pdf` | the template's sample renders |
| `test_every_required_slot_has_somewhere_to_go` | if the view uses `data-signature-slot` markers, every required slot is marked; otherwise each has a default or designer placement |
| `test_slot_signing_orders_are_unique` | no two slots share an `order` |
| `test_it_routes_and_a_second_route_reports_already_routed` | it routes, and routing again finds the same session |
| `test_a_refusal_leaves_no_record_behind` | a refused route rolls back the record it opened (skipped without `unroutableSubject()`) |
| `test_once_routed_the_stored_document_is_served_and_nothing_is_rendered_again` | after routing, the template is swapped for one that fails on any render; the document of record must still be served, and must match its hash |

Rendering is real, so your app's PDF stack (DomPDF, fonts) has to be installed. Fake the signature disk in `setUp()` if your suite doesn't already (`Storage::fake('local')`).

---

## Testing routing itself

Assert on `reason()`, never on the wording, which an app can change without touching code:

```php
use Kukux\DigitalSignature\Routing\RoutingResult;
use Kukux\DigitalSignature\Services\DocumentRouter;

$result = app(DocumentRouter::class)->route('dtr', $employee, ['month' => '2026-09-01']);

$this->assertSame(RoutingResult::MISSING_SIGNATORIES, $result->reason());
$this->assertSame(0, Dtr::count());
```

Guards are built by the container, so swap a dependency with `$this->app->instance(TimeLogRepository::class, $fake)`.

---

## Signing in tests without certificates

Signing a real PDF needs a CA, user certificates and TCPDF. To test what happens around signing (states, history, notifications), stub only the embedding step of `SignatureManager`. The package's own suite does this in `stubSignatureEmbedding()`, in `tests/Pest.php`.
