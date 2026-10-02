# Routing a Generated Report for Signatures

This guide adds a **Route for Signatures** button to a report your app generates on the fly, using an Accomplishment Report (AR) as the example. Clicking it sends the report to its signatories in order, and each one is notified when it's their turn.

It's a worked example. The pieces are the same for any generated document: payslips, DTRs, certificates.

---

## What you're building

| Piece | What it does |
|---|---|
| A record (`AccomplishmentReport`) | The thing that gets signed. Holds the period and a snapshot of who signs it. |
| A template class | The Blade view, the signature lines and who each line is for. |
| An A4 renderer | Keeps the page size fixed, so signature positions don't move. |
| A routing action | Checks everything is in place, then opens the signing session. |
| The button | A footer action on the modal that already generates the report. |

**Why a record?** The package signs a database record, not a loose PDF. If your report is generated from other data (tasks, a date range), you need a small table that says "this person's report for this period". Downloads can keep working without it.

---

## 1. The table

```php
// database/migrations/2026_10_02_100000_create_accomplishment_reports_table.php
Schema::create('accomplishment_reports', function (Blueprint $table) {
    $table->id();
    $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnDelete();

    $table->date('start_date');
    $table->date('end_date');
    $table->date('issuance_date')->nullable();

    // A snapshot of the signatories, frozen once the report is routed.
    foreach (['attested', 'noted', 'accepted'] as $role) {
        $table->foreignId("{$role}_personnel_id")->nullable()->constrained('personnel')->nullOnDelete();
        $table->string("{$role}_position")->nullable();
    }

    $table->timestamps();

    $table->unique(['personnel_id', 'start_date', 'end_date']);
});
```

Store the signatories on the report, not just on the person's settings. Otherwise editing "Update Signatures" later would change who an already-routed report is waiting on.

---

## 2. The model

```php
// app/Models/AccomplishmentReport.php
use Kukux\DigitalSignature\Concerns\HasPdfTemplate;
use Kukux\DigitalSignature\Contracts\Signable;
use Kukux\DigitalSignature\Traits\HasSignatories;

class AccomplishmentReport extends Model implements Signable
{
    use HasPdfTemplate, HasSignatories;

    public const ROLES = ['attested', 'noted', 'accepted'];

    protected string $signaturePdfTemplate = AccomplishmentReportTemplate::KEY;

    protected $fillable = [
        'personnel_id', 'start_date', 'end_date', 'issuance_date',
        'attested_personnel_id', 'attested_position',
        'noted_personnel_id', 'noted_position',
        'accepted_personnel_id', 'accepted_position',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'issuance_date' => 'date',
    ];

    /**
     * The report for this person and period, created if needed. Until it's
     * routed, its signatories follow the current settings; after that they stay put.
     */
    public static function openFor(Personnel $personnel, array $dateRange): self
    {
        $start = Carbon::parse($dateRange['start_date'])->toDateString();
        $end = Carbon::parse($dateRange['end_date'])->toDateString();

        $report = static::query()
            ->where('personnel_id', $personnel->id)
            ->whereDate('start_date', $start)
            ->whereDate('end_date', $end)
            ->first()
            ?? new static(['personnel_id' => $personnel->id, 'start_date' => $start, 'end_date' => $end]);

        if (! $report->exists || $report->latestSigningSession() === null) {
            $defaults = $personnel->signature;

            $report->issuance_date = $dateRange['issuance_date'] ?? today()->toDateString();

            foreach (self::ROLES as $role) {
                $report->{"{$role}_personnel_id"} = $defaults?->{"{$role}_personnel_id"};
                $report->{"{$role}_position"} = $defaults?->{"{$role}_position"};
            }
        }

        $report->save();

        return $report;
    }

    public function personnel(): BelongsTo         { return $this->belongsTo(Personnel::class); }
    public function attestedPersonnel(): BelongsTo { return $this->belongsTo(Personnel::class, 'attested_personnel_id'); }
    public function notedPersonnel(): BelongsTo    { return $this->belongsTo(Personnel::class, 'noted_personnel_id'); }
    public function acceptedPersonnel(): BelongsTo { return $this->belongsTo(Personnel::class, 'accepted_personnel_id'); }

    /** The Blade's data: same as the download, with this report's frozen signatories. */
    public function renderData(): array
    {
        $signature = [];

        foreach (self::ROLES as $role) {
            $signature["{$role}_name"] = $this->{"{$role}Personnel"}?->full_name ?? 'N/A';
            $signature["{$role}_position"] = $this->{"{$role}_position"} ?? 'N/A';
        }

        return AccomplishmentReportService::viewData(
            AccomplishmentReportService::completedTasks($this->personnel, $this->dateRange()),
            $this->personnel,
            $this->dateRange(),
            $signature,
        );
    }

    /** Roles with nobody set, e.g. ['Noted By']. */
    public function missingSignatories(): array
    {
        return array_values(array_map(
            fn (string $role) => ucfirst($role).' By',
            array_filter(self::ROLES, fn (string $role) => $this->{"{$role}_personnel_id"} === null),
        ));
    }

    public function getSignableTitle(): string
    {
        return 'Accomplishment Report · '.$this->personnel?->full_name;
    }
}
```

Two things worth copying:

- **Use `whereDate()` to find the report.** With a `date` cast, SQLite stores `2026-09-01 00:00:00`, so `where('start_date', '2026-09-01')` misses the existing row and tries to insert a duplicate.
- **Share the view data with your download.** Moving the Blade's data into one `viewData()` method means the signed copy prints exactly what the download does.

```php
// app/Services/AccomplishmentReportService.php
public static function viewData(Collection $records, Personnel $personnel, array $dateRange, ?array $signature = null): array
{
    return [
        'records' => $records->sortBy('sort')->values(),
        'personnel' => $personnel,
        'start_date' => $dateRange['start_date'] ?? '',
        'end_date' => $dateRange['end_date'] ?? '',
        'issuance_date' => $dateRange['issuance_date'] ?? '',
        'signature' => $signature ?? static::loadSignatureData($personnel),
    ];
}
```

---

## 3. The template

```php
// app/Pdf/AccomplishmentReportTemplate.php
use Kukux\DigitalSignature\Pdf\BladePdfTemplate;
use Kukux\DigitalSignature\Pdf\SlotDefinition;

class AccomplishmentReportTemplate extends BladePdfTemplate
{
    public const KEY = 'accomplishment-report';   // never change it: saved slot positions use it

    public const EXPECTED_PAGES = 2;               // page 1 = AR, page 2 = CWA

    public function __construct()
    {
        parent::__construct(
            key: self::KEY,
            label: 'Accomplishment Report / CWA',
            view: 'accomplishment-report',
            slots: $this->slotDefinitions(),
            sampleData: fn (): array => $this->sampleData(),
            dataResolver: fn (AccomplishmentReport $report): array => $report->renderData(),
            renderer: new AccomplishmentReportRenderer,
            signableClass: AccomplishmentReport::class,
            autoAffixMode: 'approval',   // everyone signs in their own session
            sequenceMode: 'sequential',  // in the order below
        );
    }

    protected function slotDefinitions(): array
    {
        // Signatures belong to a User. If your signatories are another model
        // (here, Personnel), hop to the login. No login means "unassigned".
        $bind = fn (string $relation) => fn (AccomplishmentReport $report) => $report->{$relation}?->user;

        return [
            new SlotDefinition(key: 'prepared_by', label: 'Prepared By', required: true, order: 1, role: 'preparer',
                signatory: $bind('personnel'),
                defaultPage: 1, defaultX: 53.0, defaultY: 130.0, defaultWidth: 140.0, defaultHeight: 38.0),
            new SlotDefinition(key: 'attested_by', label: 'Attested By', required: true, order: 2, role: 'attester',
                signatory: $bind('attestedPersonnel'),
                defaultPage: 1, defaultX: 221.0, defaultY: 130.0, defaultWidth: 140.0, defaultHeight: 38.0),
            new SlotDefinition(key: 'noted_by', label: 'Noted By', required: true, order: 3, role: 'noter',
                signatory: $bind('notedPersonnel'),
                defaultPage: 1, defaultX: 389.0, defaultY: 130.0, defaultWidth: 140.0, defaultHeight: 38.0),
            new SlotDefinition(key: 'accepted_by', label: 'Accepted By', required: true, order: 4, role: 'acceptor',
                signatory: $bind('acceptedPersonnel'),
                defaultPage: 2, defaultX: 293.0, defaultY: 275.0, defaultWidth: 200.0, defaultHeight: 38.0),
        ];
    }

    /** Refuse reports long enough to push the signature lines onto another page. */
    public function pageCountMatches(AccomplishmentReport $report): bool
    {
        $contents = (string) file_get_contents($this->renderFor($report));

        return max(1, preg_match_all('#/Type\s*/Page[^s]#', $contents)) === self::EXPECTED_PAGES;
    }
}
```

- **Default positions** are in PDF points, with `y` measured from the bottom of the page. They're only a starting point: once you save a slot in the placement designer, the saved position wins.
- **`sampleData()`** feeds the designer's preview. Build it from unsaved models so it never touches the database.
- **Register it as a class name**, not an inline array. Its closures would break `php artisan config:cache`:

```php
// config/signature.php
'templates' => [
    \App\Pdf\AccomplishmentReportTemplate::class,
],
```

---

## 4. The A4 renderer

```php
// app/Pdf/AccomplishmentReportRenderer.php
use Barryvdh\DomPDF\Facade\Pdf;
use Kukux\DigitalSignature\Pdf\Renderers\PdfRenderer;

class AccomplishmentReportRenderer implements PdfRenderer
{
    public function render(string $view, array $data, string $destinationPath): string
    {
        @mkdir(dirname($destinationPath), 0775, true);

        file_put_contents($destinationPath, Pdf::loadView($view, $data)->setPaper('a4')->output());

        return $destinationPath;
    }

    public static function isAvailable(): bool
    {
        return class_exists(Pdf::class);
    }
}
```

Use the same paper size as your download. See [Pin the paper size](pdf-templates.md#pin-the-paper-size).

---

## 5. The routing action

One class decides whether the report can go out, then sends it. Each refusal says what to fix, and leaves no row behind.

```php
// app/Actions/Signatures/RouteAccomplishmentReportForSignatures.php
class RouteAccomplishmentReportForSignatures
{
    public function handle(Personnel $personnel, array $dateRange): array
    {
        if (AccomplishmentReportService::completedTaskCount($personnel, $dateRange) === 0) {
            return $this->refuse('Nothing to report', 'There are no completed tasks in this period.');
        }

        // By hand, not DB::transaction(): a refusal is a normal return,
        // which the closure form would commit.
        DB::beginTransaction();

        try {
            $report = AccomplishmentReport::openFor($personnel, $dateRange);

            if (($session = $report->currentSigningSession()) !== null) {
                DB::commit();

                return ['ok' => true, 'title' => 'Already routed', 'body' => 'This report is already with its signatories.',
                        'report' => $report, 'session' => $session];
            }

            if (($missing = $report->missingSignatories()) !== []) {
                DB::rollBack();

                return $this->refuse('No signatories set', 'Set '.implode(', ', $missing).' under "Update Signatures" first.');
            }

            if (! (new AccomplishmentReportTemplate)->pageCountMatches($report)) {
                DB::rollBack();

                return $this->refuse('Report is too long to sign', 'Split the period into shorter ranges and route each one.');
            }

            if (! $report->isReadyForSignatures()) {
                DB::rollBack();

                return $this->refuse('Not ready for signatures', implode(' ', $report->signatureBlockers()));
            }

            $session = $report->openSigningSession();

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return ['ok' => true, 'title' => 'Routed for signatures', 'body' => 'They\'ll sign in order from the signature launcher.',
                'report' => $report, 'session' => $session];
    }

    protected function refuse(string $title, string $body): array
    {
        return ['ok' => false, 'title' => $title, 'body' => $body];
    }
}
```

What the package gives you here:

| Call | Does |
|---|---|
| `isReadyForSignatures()` | False while a required signatory has no login or no registered signature. |
| `signatureBlockers()` | The reasons, e.g. "Dr Reyes has not registered a signature yet." |
| `openSigningSession()` | Freezes the PDF, creates one request per signatory and notifies the first one. Calling it again returns the open session. |
| `currentSigningSession()` | The open session, or null. |

---

## 6. The button

Put it in the footer of the modal that already builds the report. The person has just picked the period there, so the button routes exactly what they're looking at.

A footer action on its own does nothing. Filament runs the modal's `->action()` for every footer submit, so you need **both** halves: the button, and a branch that handles its mode.

```php
->extraModalFooterActions(fn (Action $action): array => [
    $action->makeModalSubmitAction('viewAR', ['mode' => 'view'])
        ->label('Preview AR')->icon('heroicon-o-eye')->color('gray'),

    // 1. The button: submits the form with mode = 'route'.
    $action->makeModalSubmitAction('routeAR', ['mode' => 'route'])
        ->label('Route for Signatures')->icon('heroicon-o-paper-airplane')->color('primary'),
])
->action(function (array $data, array $arguments) {
    $mode = $arguments['mode'] ?? 'download';

    // … save the picked period into $this->dateRange, handle 'view' …

    // 2. The branch. Without it, 'route' falls through to the download below.
    if ($mode === 'route') {
        $this->routeAccomplishmentReportForSignatures();

        return;
    }

    // … the download …
})
```

```php
protected function routeAccomplishmentReportForSignatures(): void
{
    $result = app(RouteAccomplishmentReportForSignatures::class)
        ->handle($this->currentPersonnel(), $this->dateRange);

    $notification = Notification::make()->title($result['title'])->body($result['body']);

    if (! $result['ok']) {
        $notification->warning()->send();

        throw new Halt;   // keep the modal open so they can change the period
    }

    $notification->success()->send();
}
```

On a page that shows one record, you don't need any of this: use `RequestSignaturesAction::make()` in the header instead. See [Signatory Routing](signatory-routing.md#requestsignaturesaction).

---

## 7. Place the signature lines

Open `/admin/signature-templates/accomplishment-report/design` once, drag each line onto its printed rule and save. See [The placement designer](pdf-templates.md#the-placement-designer).

---

## What happens next

1. **The preparer is notified** by email that the report is ready for their signature.
2. They open the floating **Signatures** button, find it under **Awaiting**, check the PDF, place their signature and sign.
3. **The attester is notified** the moment the preparer signs, and so on down the chain.
4. When everyone has signed, `$report->signedDocumentPath()` returns the finished PDF.

Notifications go out on the `SignatoryTurnReached` event, once per signatory, when they can actually sign. A parallel session notifies everyone at once. Channels come from `sessions.notification_channels` (default `['mail']`); set it to `[]` to send nothing. Your `User` model needs Laravel's `Notifiable` trait.

---

## Testing it

These cover the parts most likely to break:

```php
public function test_it_routes_the_report_to_all_four_signatories_in_order(): void
{
    $this->registerEveryone();   // a primary signature for each signatory

    $result = app(RouteAccomplishmentReportForSignatures::class)->handle($this->preparer, self::PERIOD);

    $this->assertTrue($result['ok']);
    $this->assertSame(
        ['prepared_by', 'attested_by', 'noted_by', 'accepted_by'],
        $result['session']->requests()->orderBy('sequence')->pluck('slot_key')->all(),
    );
}

public function test_the_modal_button_routes_instead_of_downloading(): void
{
    $this->registerEveryone();

    Livewire::actingAs($this->preparer->user)
        ->test(TaskBoard::class)
        ->mountAction('accomplishmentReport')
        ->setActionData(['start_date' => '2026-09-01', 'end_date' => '2026-09-15', /* … */])
        ->callMountedAction(['mode' => 'route'])
        ->assertNotified('Routed for signatures')
        ->assertNoFileDownloaded();
}
```

Also worth a test each:
- routing twice returns the same session;
- an empty period, a missing signatory and an unregistered signature are each refused, with nothing saved;
- the template renders the expected page count.

Use `Storage::fake('local')` so rendered PDFs don't land in real storage.

---

## Related

- [Signatory Routing](signatory-routing.md): slots, sessions, consent and the events
- [PDF Templates](pdf-templates.md): the template contract and the placement designer
- [Drawer Signing UX](drawer-signing-ux.md): what signatories see when they sign
