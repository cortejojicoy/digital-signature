# Walkthrough: moving a 1.x integration to 2.0

This is the real move of the Accomplishment Report (AR) in uplb-performance from its 1.x code to 2.0. It went one step at a time, and the tests stayed green after each one.

The [reference integration](reference-integration.md) shows where it ended up. This page shows **how it got there**: what we deleted, what replaced it, and what tripped us up.

> Short on time? Steps 1 and 2 alone are a safe upgrade with no change in behaviour. Everything after that is where the cleanup pays off.

---

## Before you start

Bump the package and confirm nothing broke:

```bash
composer require kukux/digital-signature:^2.0
php artisan migrate          # no new tables
php artisan filament:assets
php artisan test --filter=Signature
```

For the AR, all 10 existing signature tests passed on 2.0 **before we touched any code**. That's expected if your slot bindings already return a `User`, e.g. `fn ($r) => $r->attestedPersonnel?->user`. If they return a `Personnel` or `Employee`, do step 1 first.

Here's what we had in 1.x and what replaced it:

| 1.x (app code) | 2.0 |
|---|---|
| `RouteAccomplishmentReportForSignatures` action: transaction, refusals, wording | `AccomplishmentReportDocument` + the package's `DocumentRouter` |
| `AccomplishmentReportRenderer` (DomPDF pinned to A4) | `new DomPdfRenderer(paper: 'a4')` |
| A closure per slot to hop to the login | Relation names + one `SignatoryUserMapper` binding |
| `ROLES`, `openFor()`, `missingSignatories()` on the model | `SnapshotsSignatories` + a `SignatoryDefaults` class |
| View / Download re-render every time | The document of record, once routed |

---

## 1. Add the composition root

A single provider holds every app-specific binding. Ours does two things: it maps Personnel to their login, and it registers the policy for the package's `Signature` model. That policy used to be registered in `AppServiceProvider`.

```php
// app/Providers/SignatureServiceProvider.php
class SignatureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Signatories are Personnel; signatures belong to their login.
        $this->app->bind(SignatoryUserMapper::class, fn () => RelationUserMapper::using('user'));
    }

    public function boot(): void
    {
        // Vendor model, so Laravel's policy discovery never finds it.
        Gate::policy(Signature::class, DigitalSignaturePolicy::class);
    }
}
```

```php
// bootstrap/providers.php
App\Providers\AppServiceProvider::class,
App\Providers\SignatureServiceProvider::class,
```

## 2. Update a published config

If you published `config/signature.php` back in 1.x, it's missing two keys. The defaults apply anyway, but add them so you can see them:

```php
'documents' => [
    'accomplishment-report' => \App\Signatures\AccomplishmentReportDocument::class,
],
```

> **Check for duplicate keys while you're in there.** Ours had `'launcher'` twice, because an old hand-edited block survived a later re-publish. PHP quietly keeps the **last** one, so the first block's offset had never applied. Run `grep -c "'launcher' =>" config/signature.php`; it should print `1`.

## 3. Model: let the trait snapshot signatories

**Before:** the model had a `ROLES` list, an `openFor()` that copied the signatories in a loop, and a `renderData()` with its own name/position loop.

**After:** you declare the roles once. The trait handles copying, freezing and the view data.

```php
class AccomplishmentReport extends Model implements Signable
{
    use HasPdfTemplate, HasSignatories, SnapshotsSignatories, Tenantable;

    // Map each role to the column prefix you already have. No migration needed.
    public function signatoryRoles(): array
    {
        return [
            'attested' => 'attested_personnel',
            'noted'    => 'noted_personnel',
            'accepted' => 'accepted_personnel',
        ];
    }

    public function signatoryModel(): string
    {
        return Personnel::class;
    }

    public function attestedPersonnel(): BelongsTo
    {
        return $this->signatoryRelation('attested');
    }
    // notedPersonnel(), acceptedPersonnel(): same shape

    public function renderData(): array
    {
        return AccomplishmentReportService::viewData(
            AccomplishmentReportService::completedTasks($this->personnel, $this->dateRange()),
            $this->personnel,
            $this->dateRange(),
            $this->signatoryViewData('N/A'),   // attested_name, attested_position, …
        );
    }
}
```

`openFor()` became two small helpers. The definition in step 5 uses them:

```php
public function scopeForPeriod(Builder $query, Personnel $personnel, array $dateRange): void
{
    // whereDate, not equality: a `date` cast can store a time part on SQLite.
    $query->where('personnel_id', $personnel->id)
        ->whereDate('start_date', Carbon::parse($dateRange['start_date'])->toDateString())
        ->whereDate('end_date', Carbon::parse($dateRange['end_date'])->toDateString());
}

public static function newForPeriod(Personnel $personnel, array $dateRange): self
{
    return new static([
        'personnel_id' => $personnel->id,
        'start_date'   => Carbon::parse($dateRange['start_date'])->toDateString(),
        'end_date'     => Carbon::parse($dateRange['end_date'])->toDateString(),
    ]);
}
```

> `signatoryViewData()` gets each name through `SignatoryRoute::nameOf()`, which tries `getSignatoryName()`, then `name`, then `full_name`. If your person model has a `name` column that isn't the display name, add `getSignatoryName()`.

## 4. Template: relation names and the stock renderer

```php
// before
$bind = fn (string $rel) => fn (AccomplishmentReport $r) => $r->{$rel}?->user;
new SlotDefinition(key: 'attested_by', /* … */ signatory: $bind('attestedPersonnel'));
renderer: new AccomplishmentReportRenderer,

// after
new SlotDefinition(key: 'attested_by', /* … */ signatory: 'attestedPersonnel');
renderer: new DomPdfRenderer(paper: 'a4'),
```

The mapper from step 1 turns each Personnel into a login. `DomPdfRenderer` records the `data-signature-slot` markers just like our custom renderer did, so **delete the renderer class**.

Your placement tests should pass unchanged. That's how you know the move worked.

## 5. The document definition

This class replaces the whole routing action. It only covers what's specific to the AR. Its dependencies come in through the constructor, built by the container.

```php
// app/Signatures/AccomplishmentReportDocument.php
class AccomplishmentReportDocument extends AbstractSignableDocument
{
    public function __construct(private PersonnelSignatoryDefaults $defaults) {}

    public function template(): string
    {
        return AccomplishmentReportTemplate::KEY;
    }

    public function preflight(): array
    {
        return [HasCompletedTasks::class];
    }

    public function locate(mixed $personnel, array $context = []): ?Model
    {
        return AccomplishmentReport::forPeriod($personnel, $context)->first();
    }

    public function open(mixed $personnel, array $context = []): Model
    {
        $report = $this->locate($personnel, $context)
            ?? AccomplishmentReport::newForPeriod($personnel, $context);

        if (! $report->hasBeenRoutedForSignatures()) {
            $report->issuance_date = $context['issuance_date'] ?? today()->toDateString();
            $report->snapshotSignatories($this->defaults);
        }

        $report->save();

        return $report;
    }

    // Keep your 1.x wording so users don't notice the move.
    public function messages(): array
    {
        return [
            'nothing_to_report'   => ['title' => 'Nothing to report', 'body' => 'There are no completed tasks in this period…'],
            'missing_signatories' => ['title' => 'No signatories set', 'body' => 'Set :roles under "Update Signatures" before routing this report.'],
            'routed'              => ['body' => 'Sent to :names. They\'ll sign in that order from the signature launcher.'],
            'already_routed'      => ['body' => 'This report is already with its signatories.'],
        ];
    }
}
```

The two classes it depends on are tiny:

```php
// app/Signatures/PersonnelSignatoryDefaults.php: who fills each role
class PersonnelSignatoryDefaults implements SignatoryDefaults
{
    public function for(Model $record, string $role): ?SignatoryAssignment
    {
        $settings = $record->personnel?->signature;   // the app's "Update Signatures" row
        $id = $settings?->{"{$role}_personnel_id"};

        return $id === null ? null : new SignatoryAssignment($id, $settings->{"{$role}_position"});
    }
}

// app/Signatures/Guards/HasCompletedTasks.php: refuse before anything is written
final class HasCompletedTasks implements PreflightGuard
{
    public function check(mixed $personnel, array $context): ?RoutingResult
    {
        return AccomplishmentReportService::completedTaskCount($personnel, $context) === 0
            ? RoutingResult::refused('nothing_to_report')
            : null;
    }
}
```

The 1.x action did its missing-signatory, lost-marker and not-ready checks by hand. The package's stock guards now cover all three, so **delete the action**.

## 6. The UI

### Route

```php
trait HasAccomplishmentReportActions
{
    use RoutesDocumentsForSignatures;

    protected function routeAccomplishmentReportForSignatures(): void
    {
        // notifies, and on a refusal throws Halt so the modal stays open
        $this->routeDocument('accomplishment-report', $this->currentPersonnel(), $this->dateRange);

        $this->showingARPreview = false;
    }
}
```

All the old `Notification::make()…` / `throw new Halt` branching goes away.

### View and Download: the document of record

This is the one change users will see. Once a period is routed, View and Download serve **the stored PDF**, not a re-render. A re-render would drop the signatures and pick up tasks added after routing.

```php
protected function resolveAccomplishmentReport(Personnel $personnel): ResolvedDocument
{
    return app(DocumentOfRecordResolver::class)->resolve(
        'accomplishment-report',
        $personnel,
        $this->dateRange,
        live: fn (): string => AccomplishmentReportService::pdfFor($personnel, $this->dateRange),
    );
}

// Download
return $this->resolveAccomplishmentReport($personnel)
    ->download(AccomplishmentReportService::downloadFilename($personnel, $this->dateRange));

// Preview (an iframe of the bytes, under the "2 of 4 signed" banner)
$document = $this->resolveAccomplishmentReport($personnel);

return AccomplishmentReportService::previewHtml($document->contents(), $document->banner());
```

On the app side, we split the old `download()` / `previewFor()` helpers into three smaller ones, so the draft and the stored copy share them:

```php
public static function pdfFor(Personnel $p, array $range): string              // draft bytes
public static function downloadFilename(Personnel $p, array $range): string    // "JDC-AR-Sep 01.pdf"
public static function previewHtml(string $pdf, ?HtmlString $banner = null): HtmlString
```

> A routed download gets a state suffix: `JDC-AR-Sep 01-in-progress.pdf`, then `…-signed.pdf` once the last signature is on.

### History

```php
protected function getAccomplishmentReportHistoryAction(): Action
{
    return ViewDocumentHistoryAction::make('accomplishmentReportHistory')
        ->document('accomplishment-report')
        ->subject(fn (): ?Personnel => $this->currentPersonnel())
        ->context(fn (): array => $this->dateRange)
        ->label('AR signing history')
        ->outlined();
}
```

Put it in the page's header actions. It hides itself until the chosen period has been routed.

Import the **un-versioned** class, `Kukux\DigitalSignature\Filament\Actions\ViewDocumentHistoryAction`, not the `V3\` or `V4\` one. The package aliases it to the right Filament version.

## 7. Tests

**Assert reasons, not titles.** Wording can change; reason codes don't.

```php
// before
$this->assertSame('Not ready for signatures', $result['title']);

// after
$result = app(DocumentRouter::class)->route('accomplishment-report', $this->preparer, self::PERIOD);
$this->assertSame(RoutingResult::NOT_READY, $result->reason());
```

Move the shared setup into a concern. Then the package's contract test is about fifteen lines:

```php
class AccomplishmentReportDocumentContractTest extends TestCase
{
    use BuildsAccomplishmentReportScenario, RefreshDatabase, SignableDocumentContract;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildAccomplishmentReportScenario();
        $this->registerEveryone();
    }

    protected function documentKey(): string { return 'accomplishment-report'; }

    protected function routableSubject(): array { return [$this->preparer, self::PERIOD]; }

    protected function unroutableSubject(): ?array
    {
        $this->preparer->signature->update(['noted_personnel_id' => null]);

        return [$this->preparer->fresh(), self::PERIOD];
    }
}
```

Then add one test through the real UI, to prove Download serves the stored copy:

```php
public function test_a_routed_period_downloads_its_stored_document(): void
{
    app(DocumentRouter::class)->route('accomplishment-report', $this->preparer, self::PERIOD);

    // a task completed after routing must not show up
    Task::factory()->create([/* … same personnel, completed inside the period … */]);

    $stored = DocumentOfRecord::for(AccomplishmentReport::sole())->contents();
    $name   = AccomplishmentReportService::downloadFilename($this->preparer, self::PERIOD);

    $this->downloadFromTheBoard()
        ->assertFileDownloaded(str_replace('.pdf', '-in-progress.pdf', $name), $stored);
}
```

The AR ended up with 19 signature tests, up from 10, and all pass.

---

## Gotchas we hit

- **Don't hard-code download names in tests.** Factories fill in a middle name, so "Juana Cruz" came out as `JOC-…`, not `JC-…`. Build the name with your own `downloadFilename()`.
- **The page's period isn't your test's period.** The task board starts on *today's* half-month. To test the history action for a routed September period, `->set('dateRange', self::PERIOD)` first.
- **A DB-level cascade skips the retention check.** `DocumentRetainedException` only fires through Eloquent. If the record's parent FK is `cascadeOnDelete()`, deleting the parent removes routed records quietly, and their sessions and files point at nothing. Use `restrictOnDelete()`, or guard it in an observer.
- **Bulk exports aren't covered automatically.** Our ZIP of every person's AR still renders live. Route each file through `DocumentOfRecordResolver` if routed periods in a ZIP must be the signed copies.
- **`config:cache` still works.** Documents and templates are class-strings in config. Only container bindings live in the provider. Run `php artisan config:cache` once to confirm.

## What we deleted

- `app/Actions/Signatures/RouteAccomplishmentReportForSignatures.php`
- `app/Pdf/AccomplishmentReportRenderer.php`
- `AccomplishmentReport::ROLES`, `openFor()`, `missingSignatories()`
- Four slot closures and the hand-wired notifications

About 250 lines went. The next document (OPCR, FSR, …) needs just a template and a definition.
