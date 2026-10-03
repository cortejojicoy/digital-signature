# Recipe: a document generated from a period (DTR)

For a document that isn't a row until someone routes it: it's **generated** from a subject and a context, like a person's Daily Time Record for a month. The [reference integration](../reference-integration.md) (the Accomplishment Report) is this same shape, running in production; this recipe swaps in DTR names.

> **Placeholders.** `Employee`, `TimeLogRepository`, `DtrPdf` and the column names stand in for your app's. The shape is what matters.

**The document:** one DTR per employee per month, signed by the employee and then their in-charge (their supervisor). Printed on CSC Form 48, legal-size.

---

## 1. Composition root

```php
// app/Providers/SignatureServiceProvider.php
class SignatureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Employees log in through Employee::user(). If your DTR signatories
        // are already User rows, delete this line.
        $this->app->bind(SignatoryUserMapper::class, fn () => RelationUserMapper::using('user'));

        // What the guard below depends on.
        $this->app->bind(TimeLogRepository::class, EloquentTimeLogRepository::class);
    }
}
```

```php
// config/signature.php
'templates' => [\App\Pdf\DtrTemplate::class],
'documents' => ['dtr' => \App\Signatures\DtrDocument::class],
```

## 2. Migration and model

```php
use Kukux\DigitalSignature\Database\SignatoryColumns;

Schema::create('dtrs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
    $table->date('month');
    SignatoryColumns::add($table, ['in_charge'], references: 'employees');
    $table->timestamps();

    $table->unique(['employee_id', 'month']);
});
```

```php
use Kukux\DigitalSignature\Concerns\HasPdfTemplate;
use Kukux\DigitalSignature\Contracts\Signable;
use Kukux\DigitalSignature\Traits\HasSignatories;
use Kukux\DigitalSignature\Traits\SnapshotsSignatories;

class Dtr extends Model implements Signable
{
    use HasPdfTemplate, HasSignatories, SnapshotsSignatories;

    protected string $signaturePdfTemplate = 'dtr';

    protected $fillable = ['employee_id', 'month', 'in_charge_signatory_id', 'in_charge_position'];

    public function signatoryRoles(): array
    {
        return ['in_charge'];
    }

    public function signatoryModel(): string
    {
        return Employee::class;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function inCharge(): BelongsTo
    {
        return $this->signatoryRelation('in_charge');
    }
}
```

## 3. Template

```php
use Kukux\DigitalSignature\Pdf\BladePdfTemplate;
use Kukux\DigitalSignature\Pdf\Renderers\DomPdfRenderer;
use Kukux\DigitalSignature\Pdf\SlotDefinition;

class DtrTemplate extends BladePdfTemplate
{
    public const KEY = 'dtr';

    public function __construct()
    {
        parent::__construct(
            key: self::KEY,
            label: 'Daily Time Record',
            view: 'pdf.dtr',
            slots: [
                new SlotDefinition(key: 'employee', label: 'Employee', signatory: 'employee', order: 1, required: true,
                    defaultPage: 1, defaultX: 60.0, defaultY: 90.0, defaultWidth: 160.0, defaultHeight: 40.0),
                new SlotDefinition(key: 'in_charge', label: 'In Charge', signatory: 'inCharge', order: 2, required: true,
                    defaultPage: 1, defaultX: 330.0, defaultY: 90.0, defaultWidth: 160.0, defaultHeight: 40.0),
            ],
            sampleData: fn (): array => DtrPdf::sampleData(),
            dataResolver: fn (Dtr $dtr): array => DtrPdf::viewData($dtr->employee, $dtr->month, $dtr->signatoryViewData()),
            renderer: new DomPdfRenderer(paper: 'legal'),
            signableClass: Dtr::class,
            sequenceMode: 'sequential',
        );
    }
}
```

## 4. View

```blade
<div class="signature-space" data-signature-slot="employee"></div>
<p class="signature-name">{{ $employee->full_name }}</p>

<div class="signature-space" data-signature-slot="in_charge"></div>
<p class="signature-name">{{ $signature['in_charge_name'] }}</p>
```

## 5. Definition

```php
use Kukux\DigitalSignature\Documents\AbstractSignableDocument;

class DtrDocument extends AbstractSignableDocument
{
    public function __construct(private SupervisorSignatoryDefaults $defaults) {}

    public function template(): string
    {
        return DtrTemplate::KEY;
    }

    public function preflight(): array
    {
        return [HasTimeLogs::class];
    }

    public function locate(mixed $employee, array $context = []): ?Model
    {
        return Dtr::query()
            ->where('employee_id', $employee->id)
            ->whereDate('month', $context['month'])
            ->first();
    }

    public function open(mixed $employee, array $context = []): Model
    {
        $dtr = $this->locate($employee, $context)
            ?? new Dtr(['employee_id' => $employee->id, 'month' => $context['month']]);

        $dtr->snapshotSignatories($this->defaults);
        $dtr->save();

        return $dtr;
    }

    public function messages(): array
    {
        return [
            'no_time_logs' => ['title' => 'Nothing to file', 'body' => 'There are no time logs for this month yet.'],
            'missing_signatories' => ['body' => 'Ask HR to set your :roles first.'],
        ];
    }
}
```

```php
use Kukux\DigitalSignature\Contracts\SignatoryDefaults;
use Kukux\DigitalSignature\Signatories\SignatoryAssignment;

final class SupervisorSignatoryDefaults implements SignatoryDefaults
{
    public function for(Model $record, string $role): ?SignatoryAssignment
    {
        $supervisor = $record->employee?->supervisor;

        return $supervisor ? new SignatoryAssignment($supervisor->id, $supervisor->position_title) : null;
    }
}
```

```php
use Kukux\DigitalSignature\Contracts\PreflightGuard;
use Kukux\DigitalSignature\Routing\RoutingResult;

final class HasTimeLogs implements PreflightGuard
{
    public function __construct(private TimeLogRepository $logs) {}

    public function check(mixed $employee, array $context): ?RoutingResult
    {
        return $this->logs->countFor($employee, $context['month']) === 0
            ? RoutingResult::refused('no_time_logs')
            : null;
    }
}
```

## 6. UI

On the page that shows an employee's DTR for a month:

```php
use Kukux\DigitalSignature\Filament\Actions\DownloadDocumentOfRecordAction;
use Kukux\DigitalSignature\Filament\Actions\RouteForSignaturesAction;
use Kukux\DigitalSignature\Filament\Actions\ViewDocumentHistoryAction;

protected function getHeaderActions(): array
{
    return [
        RouteForSignaturesAction::make()
            ->document('dtr')
            ->subject(fn () => $this->employee)
            ->context(fn () => ['month' => $this->month]),

        DownloadDocumentOfRecordAction::make()
            ->document('dtr')
            ->subject(fn () => $this->employee)
            ->context(fn () => ['month' => $this->month])
            ->live(fn () => DtrPdf::render($this->employee, $this->month))
            ->filename('DTR.pdf'),

        ViewDocumentHistoryAction::make()
            ->document('dtr')
            ->subject(fn () => $this->employee)
            ->context(fn () => ['month' => $this->month]),
    ];
}
```

If the DTR already lives in a modal with its own footer, route from its `->action()` instead: `use RoutesDocumentsForSignatures;` and `$this->routeDocument('dtr', $this->employee, ['month' => $this->month]);`, as the [reference integration](../reference-integration.md#6-the-ui) does.

## 7. Test

```php
class DtrDocumentContractTest extends TestCase
{
    use RefreshDatabase, SignableDocumentContract;

    protected function documentKey(): string
    {
        return 'dtr';
    }

    protected function routableSubject(): array
    {
        $employee = Employee::factory()->withUser()->withSignature()->hasSupervisor()->hasTimeLogs('2026-09')->create();

        return [$employee, ['month' => '2026-09-01']];
    }
}
```

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
