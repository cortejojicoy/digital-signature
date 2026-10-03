# Reference integration: the Accomplishment Report

The package's first and reference integration is the **Accomplishment Report / Certificate of Work Accomplishment** in uplb-performance. Every snippet on this page is copied from that app's code as it runs, so each one is known to work. The [recipes](recipes/generated-from-a-period.md) are this same shape with other names.

**The document.** One AR per person per period (a kinsena), generated from that person's completed tasks. Four people sign it in order:

```
Prepared By → Attested By → Noted By → Accepted By
 (the ratee)   ─────────── from the ratee's "Update Signatures" settings ───────────
```

**What the app's data looks like:**
- Signatories are `Personnel` rows. A login points at its person through `users.personnel_id`, so `Personnel::user()` is a `HasOne`.
- Who attests, notes and accepts for someone is their "Update Signatures" row (`App\Models\Signature`, one per person).
- The report table already existed, with `{role}_personnel_id` / `{role}_position` columns.

---

## 1. The composition root

The one place the app's specifics are bound: Personnel → login, and the signature-library policy.

```php
// app/Providers/SignatureServiceProvider.php
namespace App\Providers;

use App\Policies\DigitalSignaturePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Kukux\DigitalSignature\Contracts\SignatoryUserMapper;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Signatories\RelationUserMapper;

/**
 * Where this app plugs into kukux/digital-signature: the composition root.
 *
 * The package routes, signs and keeps the history of any document. What it
 * can't know is this app's, and it's all bound here:
 *
 *  - who signs is a Personnel row, and signatures belong to that person's
 *    login (`users.personnel_id`), so routing follows Personnel::user();
 *  - who may use a registered signature (DigitalSignaturePolicy).
 *
 * The documents themselves (config('signature.documents')) and their
 * templates (config('signature.templates')) are listed in config, as
 * class-strings so `config:cache` keeps working.
 */
class SignatureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Signatories are Personnel; a signature belongs to their login. A
        // person with no login routes as "named but has no login", never as
        // whichever user happens to share their id.
        $this->app->bind(SignatoryUserMapper::class, fn () => RelationUserMapper::using('user'));
    }

    public function boot(): void
    {
        // The package's Signature model is in a vendor namespace, so Laravel's
        // App\Models → App\Policies discovery never finds a policy for it.
        // Named DigitalSignaturePolicy so `shield:generate` can't overwrite it.
        Gate::policy(Signature::class, DigitalSignaturePolicy::class);
    }
}
```

```php
// bootstrap/providers.php
return [
    App\Providers\AppServiceProvider::class,
    App\Providers\SignatureServiceProvider::class,
    // ...
];
```

```php
// config/signature.php
    'templates' => [
    |        'dtr' => [
    |            'label'         => 'Daily Time Record',
    |            'view'          => 'pdf.dtr',
    |            'sample_data'   => ['user' => ['name' => 'Sample User']],
    |            'data_resolver' => fn ($record) => ['record' => $record],
    |            'slots'         => ['employee', 'in_charge'],
    |            // or, with metadata:
    |            // 'slots' => [
    |            //     'employee'  => ['label' => 'Employee', 'required' => true],
    |            //     'in_charge' => ['label' => 'In Charge', 'required' => true],
    |            // ],
    |        ],
    |    ],
    |
    | 2) Full class. Implement PdfTemplate yourself when you need a custom
    |    renderer, conditional slots, or domain-aware sample data.
    |
    |    'templates' => [
    |        \App\Pdf\DtrTemplate::class,
    |    ],
    |
    | Both styles can be mixed in one array. You can also add templates at
    | runtime via SignaturePlugin::make()->templates([...]) or
    | app(PdfTemplateRegistry::class)->register(...).
    */
    'templates' => [
        // A class-string, not an inline array: the AR/CWA needs a closure for
        // its data, and a closure in config breaks `php artisan config:cache`.
        \App\Pdf\AccomplishmentReportTemplate::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Signable documents
    |--------------------------------------------------------------------------
    | The documents this app routes for signatures, by key. Each says what is
    | specific to routing it (its template, how to find or file its record,
    | its own checks and wording); the package's DocumentRouter does the
    | routing. See App\Signatures\AccomplishmentReportDocument.
    */
    'documents' => [
        'accomplishment-report' => \App\Signatures\AccomplishmentReportDocument::class,
    ],
```

---

## 2. The model

The existing table, no migration. `signatoryRoles()` points the trait at the existing columns.

```php
// app/Models/AccomplishmentReport.php
class AccomplishmentReport extends Model implements Signable
{
    use HasPdfTemplate, HasSignatories, SnapshotsSignatories, Tenantable;

    protected string $signaturePdfTemplate = AccomplishmentReportTemplate::KEY;

    protected $fillable = [
        'personnel_id',
        'start_date',
        'end_date',
        'issuance_date',
        'attested_personnel_id',
        'attested_position',
        'noted_personnel_id',
        'noted_position',
        'accepted_personnel_id',
        'accepted_position',
        'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'issuance_date' => 'date',
    ];

    /**
     * The roles copied from the personnel's "Update Signatures" settings, onto
     * this table's existing `{role}_personnel_id` / `{role}_position` columns.
     */
    public function signatoryRoles(): array
    {
        return [
            'attested' => 'attested_personnel',
            'noted' => 'noted_personnel',
            'accepted' => 'accepted_personnel',
        ];
    }

    public function signatoryModel(): string
    {
        return Personnel::class;
    }

    // ...

    public function attestedPersonnel(): BelongsTo
    {
        return $this->signatoryRelation('attested');
    }

    public function notedPersonnel(): BelongsTo
    {
        return $this->signatoryRelation('noted');
    }

    public function acceptedPersonnel(): BelongsTo
    {
        return $this->signatoryRelation('accepted');
    }

    /** @return array{start_date: string, end_date: string, issuance_date: string} */
    public function dateRange(): array
    {
        return [
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'issuance_date' => ($this->issuance_date ?? today())->toDateString(),
        ];
    }

    /**
     * The view data for the AR/CWA Blade: the same document the download
     * produces, but with this report's frozen signatories.
     *
     * @return array<string, mixed>
     */
    public function renderData(): array
    {
        return AccomplishmentReportService::viewData(
            AccomplishmentReportService::completedTasks($this->personnel, $this->dateRange()),
            $this->personnel,
            $this->dateRange(),
            $this->signatoryViewData('N/A'),
        );
    }
}
```

---

## 3. The template

Slots bind to the report's Personnel relations by name. The mapper from step 1 turns each one into a login. The renderer is the package's own, pinned to A4.

```php
// app/Pdf/AccomplishmentReportTemplate.php
    public function __construct()
    {
        parent::__construct(
            key: self::KEY,
            label: 'Accomplishment Report / CWA',
            view: 'accomplishment-report',
            slots: $this->slotDefinitions(),
            sampleData: fn (): array => $this->sampleData(),
            dataResolver: fn (AccomplishmentReport $report): array => $report->renderData(),
            // Pinned, not inherited from dompdf.default_paper_size: slots are
            // absolute PDF points, and A4 is what the download prints too.
            renderer: new DomPdfRenderer(paper: 'a4'),
            signableClass: AccomplishmentReport::class,
            // Every signatory signs in their own session, never on someone's
            // behalf, whatever the global default says.
            autoAffixMode: 'approval',
            // A chain of endorsements: attesting work nobody has prepared,
            // or noting an unattested report, means nothing.
            sequenceMode: 'sequential',
        );
    }

    protected function slotDefinitions(): array
    {
        $width = 140.0;
        $height = 38.0;
        $pageOneY = 130.0;

        return [
            new SlotDefinition(
                key: 'prepared_by', label: 'Prepared By',
                defaultPage: 1, defaultX: 53.0, defaultY: $pageOneY, defaultWidth: $width, defaultHeight: $height,
                required: true, signatory: 'personnel', role: 'preparer', order: 1,
            ),
            new SlotDefinition(
                key: 'attested_by', label: 'Attested By',
                defaultPage: 1, defaultX: 221.0, defaultY: $pageOneY, defaultWidth: $width, defaultHeight: $height,
                required: true, signatory: 'attestedPersonnel', role: 'attester', order: 2,
            ),
            new SlotDefinition(
                key: 'noted_by', label: 'Noted By',
                defaultPage: 1, defaultX: 389.0, defaultY: $pageOneY, defaultWidth: $width, defaultHeight: $height,
                required: true, signatory: 'notedPersonnel', role: 'noter', order: 3,
            ),
            // Page 2 is the Certificate of Work Accomplishment; its acceptance
            // line is centred and wider than page 1's columns.
            new SlotDefinition(
                key: 'accepted_by', label: 'Accepted By',
                defaultPage: 2, defaultX: 293.0, defaultY: 275.0, defaultWidth: 200.0, defaultHeight: $height,
                required: true, signatory: 'acceptedPersonnel', role: 'acceptor', order: 4,
            ),
        ];
    }
```

The coordinates are only the sample layout, for the designer's preview and as a fallback. A real report places each signature where its marker landed.

## 4. The view's markers

Each signature space is its own element, marked with its slot key, so the stamp follows it to whatever page and height the task list pushes it to:

```blade
{{-- resources/views/accomplishment-report.blade.php: the AR's three columns --}}
<div class="signature-space" data-signature-slot="{{ ['prepared_by', 'attested_by', 'noted_by'][$index] }}"></div>

{{-- and the CWA's acceptance line --}}
<div class="signature-space" style="height: 52px;" data-signature-slot="accepted_by"></div>
```

---

## 5. The document definition

Everything AR-specific about routing, and nothing else. The subject is the `Personnel` row and the context is the period. `open()` finds or files the report and snapshots its signatories, until it has been routed.

```php
// app/Signatures/AccomplishmentReportDocument.php
namespace App\Signatures;

use App\Models\AccomplishmentReport;
use App\Models\Personnel;
use App\Pdf\AccomplishmentReportTemplate;
use App\Signatures\Guards\HasCompletedTasks;
use Illuminate\Database\Eloquent\Model;
use Kukux\DigitalSignature\Documents\AbstractSignableDocument;

/**
 * The AR/CWA as a document this app routes for signatures: everything about
 * routing it that is specific to the AR, and nothing else.
 *
 * The AR is generated, not stored: a person and a period produce it. So the
 * subject is the Personnel row and the context is the period, and this finds
 * or files the report row that a signature needs to be bound to. Routing
 * itself (the transaction, "already routed", the signatory checks, opening the
 * session, the wording) is the package's DocumentRouter.
 *
 *   app(DocumentRouter::class)->route('accomplishment-report', $personnel, $dateRange);
 */
class AccomplishmentReportDocument extends AbstractSignableDocument
{
    public function __construct(private PersonnelSignatoryDefaults $defaults)
    {
    }

    public function template(): string
    {
        return AccomplishmentReportTemplate::KEY;
    }

    public function preflight(): array
    {
        return [HasCompletedTasks::class];
    }

    /** @param  Personnel  $personnel */
    public function locate(mixed $personnel, array $context = []): ?Model
    {
        return AccomplishmentReport::forPeriod($personnel, $context)->first();
    }

    /**
     * The report for this person and period, filed if needed. Until it has
     * been routed its signatories and issuance date follow the current
     * settings; after that they stay as they were when it was routed.
     *
     * @param  Personnel  $personnel
     */
    public function open(mixed $personnel, array $context = []): Model
    {
        $report = $this->locate($personnel, $context) ?? AccomplishmentReport::newForPeriod($personnel, $context);

        if (! $report->hasBeenRoutedForSignatures()) {
            $report->issuance_date = $context['issuance_date'] ?? today()->toDateString();
            $report->snapshotSignatories($this->defaults);
        }

        $report->save();

        return $report;
    }

    public function messages(): array
    {
        return [
            'nothing_to_report' => [
                'title' => 'Nothing to report',
                'body'  => 'There are no completed tasks in this period, so there is nothing for anyone to attest to.',
            ],
            'missing_signatories' => [
                'title' => 'No signatories set',
                'body'  => 'Set :roles under "Update Signatures" before routing this report.',
            ],
            'routed' => [
                'body' => 'Sent to :names. They\'ll sign in that order from the signature launcher.',
            ],
            'already_routed' => [
                'body' => 'This report is already with its signatories.',
            ],
        ];
    }
}
```

Its dependencies are plain classes, built by the container:

```php
// app/Signatures/PersonnelSignatoryDefaults.php
namespace App\Signatures;

use App\Models\AccomplishmentReport;
use Illuminate\Database\Eloquent\Model;
use Kukux\DigitalSignature\Contracts\SignatoryDefaults;
use Kukux\DigitalSignature\Signatories\SignatoryAssignment;

/**
 * Who attests, notes and accepts for a person: their "Update Signatures"
 * settings (App\Models\Signature, the per-personnel row; not the package's
 * signature image).
 *
 * Read once, when a report is first filed. SnapshotsSignatories stops reading
 * it once the report has been routed, so editing those settings later can't
 * change who a routed report is waiting on.
 */
class PersonnelSignatoryDefaults implements SignatoryDefaults
{
    public function for(Model $record, string $role): ?SignatoryAssignment
    {
        /** @var AccomplishmentReport $record */
        $settings = $record->personnel?->signature;
        $id = $settings?->{"{$role}_personnel_id"};

        return $id === null ? null : new SignatoryAssignment($id, $settings->{"{$role}_position"});
    }
}
```

```php
// app/Signatures/Guards/HasCompletedTasks.php
namespace App\Signatures\Guards;

use App\Services\AccomplishmentReportService;
use Kukux\DigitalSignature\Contracts\PreflightGuard;
use Kukux\DigitalSignature\Routing\RoutingResult;

/**
 * An AR with no completed tasks has nothing for anyone to attest to. Checked
 * before the report is filed, so refusing costs nothing.
 */
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

---

## 6. The UI

The AR workbench is a modal on the task board, where the person has just picked the period. *Route for Signatures* is a footer submit whose branch calls `routeDocument()`. **View AR** and **Download AR** go through the document of record, and a history action appears once the period is routed:

```php
// app/Filament/Concerns/HasAccomplishmentReportActions.php
trait HasAccomplishmentReportActions
{
    use RoutesDocumentsForSignatures;

    // ...

    protected function routeAccomplishmentReportForSignatures(): void
    {
        $personnel = $this->currentPersonnel();

        if (! $personnel) {
            Notification::make()->title('No personnel selected')->warning()->send();

            throw new Halt;
        }

        $this->routeDocument('accomplishment-report', $personnel, $this->dateRange);

        $this->showingARPreview = false;
    }

    /**
     * The AR for the chosen period, as View AR and Download AR show it.
     *
     * Once a period has been routed, that is its document of record: the
     * stored PDF the signatories signed, never a re-render. A re-render would
     * drop their signatures and rebuild the report from whatever the tasks say
     * today, showing something nobody signed. Only an unrouted period, the
     * ordinary draft, is rendered live.
     */
    protected function resolveAccomplishmentReport(Personnel $personnel): ResolvedDocument
    {
        return app(DocumentOfRecordResolver::class)->resolve(
            'accomplishment-report',
            $personnel,
            $this->dateRange,
            live: fn (): string => AccomplishmentReportService::pdfFor($personnel, $this->dateRange),
        );
    }

    /**
     * Every version of the chosen period's routed AR: who signed, when, and
     * whether each file still matches what was signed. Hidden until routed.
     */
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

```php
// the modal's ->action(), the branches that use them
if ($mode === 'route') {
    $this->routeAccomplishmentReportForSignatures();

    return;
}

// Download AR
$download = $this->resolveAccomplishmentReport($personnel)
    ->download(AccomplishmentReportService::downloadFilename($personnel, $this->dateRange));

// View AR
$document = $this->resolveAccomplishmentReport($personnel);

return AccomplishmentReportService::previewHtml($document->contents(), $document->banner());
```

```php
// the board's header actions
$this->getAccomplishmentReportAction(),
$this->getAccomplishmentReportHistoryAction(),
```

Signatories sign from the package's launcher. Each one finds the copy they signed under its **Signed** tab.

---

## 7. The test

The package's contract, run against the AR:

```php
// tests/Feature/Signatures/AccomplishmentReportDocumentContractTest.php
namespace Tests\Feature\Signatures;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kukux\DigitalSignature\Testing\SignableDocumentContract;
use Tests\Feature\Signatures\Concerns\BuildsAccomplishmentReportScenario;
use Tests\TestCase;

/**
 * The checks every signable document passes, from the package, run against
 * the AR: the sample renders, every signature line is marked in the Blade,
 * routing twice is "already routed", a refusal leaves no report behind, and a
 * routed AR is served from storage, never re-rendered.
 */
class AccomplishmentReportDocumentContractTest extends TestCase
{
    use BuildsAccomplishmentReportScenario, RefreshDatabase, SignableDocumentContract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildAccomplishmentReportScenario();
        $this->registerEveryone();
    }

    protected function documentKey(): string
    {
        return 'accomplishment-report';
    }

    protected function routableSubject(): array
    {
        return [$this->preparer, self::PERIOD];
    }

    /** Noted By unset under "Update Signatures". */
    protected function unroutableSubject(): ?array
    {
        $this->preparer->signature->update(['noted_personnel_id' => null]);

        return [$this->preparer->fresh(), self::PERIOD];
    }
}
```

Next to it, `RouteAccomplishmentReportTest` covers the AR's own refusals (by reason code), and `AccomplishmentReportDocumentOfRecordTest` checks that View/Download AR serve the stored document once routed.

---

## What the app no longer has

Before 2.0 the AR needed its own routing action (transaction handling, "already routed", missing-signatory and marker checks, readiness), its own A4 renderer, a closure per slot to hop from Personnel to login, a list of roles kept in step with the template, and hand-wired notifications. All of that is now the package's, shared by every document.
