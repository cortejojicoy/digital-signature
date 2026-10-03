# Signable documents

A **document definition** says everything about routing one kind of document that is specific to it, and nothing else. The package's `DocumentRouter` does the routing.

```php
namespace App\Signatures;

use Kukux\DigitalSignature\Documents\AbstractSignableDocument;

class TravelRequestDocument extends AbstractSignableDocument
{
    public function template(): string
    {
        return 'travel-request';
    }
}
```

```php
// config/signature.php
'documents' => [
    'travel-request' => \App\Signatures\TravelRequestDocument::class,
],
```

That is a complete definition for a document whose record already exists. Route it from a record page:

```php
use Kukux\DigitalSignature\Filament\Actions\RouteForSignaturesAction;

RouteForSignaturesAction::make()->document('travel-request')
```

---

## `SignableDocument`

| Method | Called | Returns |
|---|---|---|
| `template(): string` | always | the registered `PdfTemplate` key |
| `locate(mixed $subject, array $context = []): ?Model` | by the document-of-record lookup, on every view | the record, or `null` if none was ever filed. **Must not create anything.** |
| `open(mixed $subject, array $context = []): Model` | inside the routing transaction | the record to route, found or created. Rolled back if routing refuses. |
| `preflight(): array` | first, outside the transaction | `PreflightGuard` class-strings or instances |
| `guards(): array` | after `open()`, before the package's guards | `RoutingGuard` class-strings or instances |
| `messages(): array` | when wording a result | `['reason' => ['title' => …, 'body' => …]]` overrides |

`AbstractSignableDocument` defaults: `locate()` / `open()` return the subject when it is the record, and there are no guards or messages. A document generated from something else (a person and a period) overrides `locate()` and `open()`:

```php
use App\Models\Dtr;
use Kukux\DigitalSignature\Documents\AbstractSignableDocument;

class DtrDocument extends AbstractSignableDocument
{
    public function __construct(private SupervisorSignatoryDefaults $defaults) {}

    public function template(): string
    {
        return 'dtr';
    }

    public function locate(mixed $employee, array $context = []): ?Model
    {
        return Dtr::firstWhere(['employee_id' => $employee->id, 'month' => $context['month']]);
    }

    public function open(mixed $employee, array $context = []): Model
    {
        $dtr = $this->locate($employee, $context)
            ?? new Dtr(['employee_id' => $employee->id, 'month' => $context['month']]);

        $dtr->snapshotSignatories($this->defaults);
        $dtr->save();

        return $dtr;
    }
}
```

The definition is built by the container, so its constructor can ask for what it needs: here, the `SignatoryDefaults` implementation that says who the in-charge is.

---

## Routing

| From | Use |
|---|---|
| A record page header | `RouteForSignaturesAction::make()->document('travel-request')` |
| A page that generates the document | `RouteForSignaturesAction::make()->document('dtr')->subject(fn () => $this->employee)->context(fn () => ['month' => $this->month])` |
| A modal footer whose `->action()` branches on a mode | `use RoutesDocumentsForSignatures;` then `$this->routeDocument('dtr', $employee, $context);` |
| Anywhere else | `app(DocumentRouter::class)->route('dtr', $employee, $context)` |

`RouteForSignaturesAction` and `routeDocument()` both notify, and both **halt** on a refusal, so a surrounding modal stays open for the user to fix what it names. `RequestSignaturesAction` (record pages, 1.x) goes through the same router.

### `DocumentRouter::route()`, step by step

1. **Preflight guards.** Nothing is written yet; a refusal costs nothing.
2. **Begin a transaction, `open()` the record.** It may be created here.
3. **Already routed?** If a session is open, fill in any signatory tagged since it opened, commit, and return `already_routed`.
4. **Guards.** The definition's, then the package's, in this order:
   - `RequiredSignatoriesAssigned`: every required slot names someone → else `missing_signatories`.
   - `SignatureMarkersPresent`: every required slot has somewhere to go → else `markers_missing`. If the view uses `data-signature-slot` markers, each required slot must be marked. If it uses none, each needs a designer or default placement.
   - `SignatoriesReady`: everyone named has a login and a registered signature → else `not_ready`.

   The first refusal **rolls back**, so a refusal leaves no record behind.
5. **Open the signing session.** The PDF is frozen, one request is created per slot, and the first signatory is notified. Commit, and return `routed`.
6. Any exception rolls back and is rethrown.

The transaction is driven by hand, not with `DB::transaction()`, because a refusal is an ordinary return value and the closure form would commit it.

---

## Guards

A precondition of your own is a class. The container builds it, so it can depend on your services:

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

| Contract | Runs | Gets |
|---|---|---|
| `PreflightGuard::check(mixed $subject, array $context)` | before anything is written | what the caller passed |
| `RoutingGuard::check(Model $record, PdfTemplate $template, array $context)` | inside the transaction, after `open()` | the opened record |

Return `null` to pass, or `RoutingResult::refused('your_reason')` to stop.

---

## `RoutingResult` and wording

```php
$result = app(DocumentRouter::class)->route('dtr', $employee, ['month' => '2026-09']);

$result->ok();          // routed or already routed
$result->reason();      // 'routed', 'already_routed', 'missing_signatories', 'markers_missing', 'not_ready', or a guard's code
$result->title();       // "Routed for signatures"
$result->body();        // "Sent to Juana Cruz and Dr Reyes. They'll sign in that order."
$result->record();      // the routed record
$result->session();     // the signing session
```

**Test the reason, not the wording.** The wording is resolved, first match wins, from:

1. an explicit title/body on the result (`RoutingResult::refused('x', title: …, body: …)`);
2. the definition's `messages()`;
3. your app's `lang/vendor/signature/{locale}/routing.php` (`php artisan vendor:publish --tag=signature-lang`);
4. the package's `lang/en/routing.php`.

| `reason()` | Default title | Default body |
|---|---|---|
| `routed` | Routed for signatures | Sent to :names. They'll sign in that order. (parallel: "They can sign in any order.") |
| `already_routed` | Already routed | This document is already with its signatories. |
| `missing_signatories` | Signatories not set | Set :roles before routing this document. |
| `markers_missing` | Signature lines not found | The document has no place for :slots. … |
| `not_ready` | Not ready for signatures | :blockers |
| `failed` | Could not route this document | :error |

A guard code with no wording anywhere still gets a title: `itinerary_not_approved` → "Itinerary not approved".

```php
public function messages(): array
{
    return [
        'no_time_logs'        => ['title' => 'Nothing to file', 'body' => 'No time logs for this month yet.'],
        'missing_signatories' => ['body' => 'Set your :roles under "My signatories" first.'],
    ];
}
```

---

## Freezing who signs: `SnapshotsSignatories`

A routed document must keep naming the people it was routed to. Editing someone's settings next week mustn't change who an already-routed document is waiting on. `SnapshotsSignatories` copies each role onto the record and stops copying once the record has been routed.

```php
use Kukux\DigitalSignature\Traits\SnapshotsSignatories;

class Dtr extends Model implements Signable
{
    use HasPdfTemplate, HasSignatories, SnapshotsSignatories;

    protected string $signaturePdfTemplate = 'dtr';

    public function signatoryRoles(): array { return ['in_charge']; }

    public function signatoryModel(): string { return Employee::class; }

    public function inCharge(): BelongsTo { return $this->signatoryRelation('in_charge'); }
}
```

```php
// the migration
use Kukux\DigitalSignature\Database\SignatoryColumns;

SignatoryColumns::add($table, ['in_charge'], references: 'employees');
// in_charge_signatory_id (nullable, null on delete), in_charge_position
```

To keep an existing schema, map each role to its key-column prefix: `['attested' => 'attested_personnel']` reads `attested_personnel_id` and `attested_position`.

Who fills each role comes from a `SignatoryDefaults` implementation. Your definition takes it in its constructor and passes it to `snapshotSignatories()`:

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

| Method | Does |
|---|---|
| `snapshotSignatories(SignatoryDefaults $defaults)` | copies each role; no-op once routed; doesn't save |
| `hasBeenRoutedForSignatures()` | has any session ever been opened? |
| `signatoryRelation($role)` | a `belongsTo` for the relation a slot binds to |
| `signatoryFor($role)` | the person in a role |
| `missingSignatoryRoles()` | roles with nobody set |
| `signatoryViewData('N/A')` | `{role}_name` / `{role}_position` for the view |
