# Recipe: a document that already is a record (Travel Request)

For a document that is already a row before anyone routes it, like a Travel Request someone filed. The record is the subject: there's nothing to find or create, so the definition is shorter than the [period recipe](generated-from-a-period.md).

> **Placeholders.** `TravelRequest`, `OfficeChainSignatoryDefaults` and the column names stand in for your app's.

**The document:** a travel request, recommended for approval by the requester's unit head, then approved by the director. It can't be routed until its itinerary is approved.

---

## 1. Composition root

```php
// app/Providers/SignatureServiceProvider.php
class SignatureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Whoever your approvers are; see "external signatories" if they come from another system.
        $this->app->bind(SignatoryUserMapper::class, fn () => RelationUserMapper::using('user'));
    }
}
```

```php
// config/signature.php
'templates' => [\App\Pdf\TravelRequestTemplate::class],
'documents' => ['travel-request' => \App\Signatures\TravelRequestDocument::class],
```

## 2. Migration and model

```php
Schema::table('travel_requests', function (Blueprint $table) {
    SignatoryColumns::add($table, ['recommending', 'approving'], references: 'employees');
});
```

```php
class TravelRequest extends Model implements Signable
{
    use HasPdfTemplate, HasSignatories, SnapshotsSignatories;

    protected string $signaturePdfTemplate = 'travel-request';

    public function signatoryRoles(): array
    {
        return ['recommending', 'approving'];
    }

    public function signatoryModel(): string
    {
        return Employee::class;
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requester_id');
    }

    public function recommending(): BelongsTo
    {
        return $this->signatoryRelation('recommending');
    }

    public function approving(): BelongsTo
    {
        return $this->signatoryRelation('approving');
    }
}
```

## 3. Template and view

As in the [period recipe](generated-from-a-period.md#3-template). Three slots bound to `requester`, `recommending` and `approving`, each marked `data-signature-slot` in the view.

## 4. Definition

The inherited `locate()` / `open()` return the record. Only the guard and the signatory snapshot are added:

```php
use Kukux\DigitalSignature\Documents\AbstractSignableDocument;

class TravelRequestDocument extends AbstractSignableDocument
{
    public function __construct(private OfficeChainSignatoryDefaults $defaults) {}

    public function template(): string
    {
        return 'travel-request';
    }

    public function guards(): array
    {
        return [ItineraryIsApproved::class];
    }

    public function open(mixed $request, array $context = []): Model
    {
        $request->snapshotSignatories($this->defaults);
        $request->save();

        return $request;
    }
}
```

The guard checks the opened record, inside the routing transaction:

```php
use Kukux\DigitalSignature\Contracts\PdfTemplate;
use Kukux\DigitalSignature\Contracts\RoutingGuard;
use Kukux\DigitalSignature\Routing\RoutingResult;

final class ItineraryIsApproved implements RoutingGuard
{
    public function check(Model $record, PdfTemplate $template, array $context): ?RoutingResult
    {
        return $record->itinerary_approved_at === null
            ? RoutingResult::refused('itinerary_not_approved', title: 'Itinerary not approved', body: 'Approve the itinerary before routing this request.')
            : null;
    }
}
```

Who recommends and approves comes from the org chart, injected:

```php
final class OfficeChainSignatoryDefaults implements SignatoryDefaults
{
    public function __construct(private OrgChart $chart) {}

    public function for(Model $record, string $role): ?SignatoryAssignment
    {
        $person = match ($role) {
            'recommending' => $this->chart->unitHeadOf($record->requester),
            'approving'    => $this->chart->directorOf($record->requester),
        };

        return $person ? new SignatoryAssignment($person->id, $person->position_title) : null;
    }
}
```

## 5. UI

A record page, so no subject or context:

```php
protected function getHeaderActions(): array
{
    return [
        RouteForSignaturesAction::make()->document('travel-request'),
        ViewDocumentOfRecordAction::make()->document('travel-request'),
        ViewDocumentHistoryAction::make()->document('travel-request'),
    ];
}
```

Or show the history inline: `DocumentHistoryEntry::make()` in the infolist.

## 6. Test

```php
class TravelRequestDocumentContractTest extends TestCase
{
    use RefreshDatabase, SignableDocumentContract;

    protected function documentKey(): string
    {
        return 'travel-request';
    }

    protected function routableSubject(): array
    {
        return [TravelRequest::factory()->itineraryApproved()->withSignatories()->create()];
    }

    protected function unroutableSubject(): ?array
    {
        return [TravelRequest::factory()->withSignatories()->create()]; // itinerary not approved
    }
}
```

`test_a_refusal_leaves_no_record_behind` only checks for a leftover record when routing created one. Here the record existed beforehand, so it checks the refusal alone.

---

## Checklist

1. `composer require kukux/digital-signature:^2.0`, then `php artisan signature:install`.
2. **Composition root:** create `App\Providers\SignatureServiceProvider`, add it to `bootstrap/providers.php`, and bind `SignatoryUserMapper` unless your signatories are `User` rows.
3. **Migration:** `SignatoryColumns::add(...)` for each role you snapshot.
4. **Model:** `implements Signable`; `use HasPdfTemplate, HasSignatories, SnapshotsSignatories`; `signatoryRoles()`, `signatoryModel()`, and a `signatoryRelation()` per role.
5. **Template:** a class in `config('signature.templates')`, with slots bound to relation names and `renderer: new DomPdfRenderer(paper: …)`.
6. **View:** `data-signature-slot="<key>"` on every signature space.
7. **Definition:** a class in `config('signature.documents')`, with its guards and a `SignatoryDefaults` taken in its constructor.
8. **UI:** `RouteForSignaturesAction`, plus `ViewDocumentOfRecordAction` / `DownloadDocumentOfRecordAction` / `ViewDocumentHistoryAction`.
9. **Test:** `use SignableDocumentContract`. When it passes, the integration is done.
10. **Designer:** open the template once and check the sample placements.
