# Recipe: signatories from another system

When the people who sign live outside your app's database (an HR directory, an LDAP tree, a central personnel API), the record names them by an id from that system. Two things have to be supplied, both by injection:

1. **who fills each role**: a `SignatoryDefaults` that asks the directory;
2. **which login that person is**: a `SignatoryUserMapper` that maps their directory id to your `users` table.

> **Placeholders.** `HrDirectory`, `HttpHrDirectory`, `DirectoryPerson` and `users.hr_id` stand in for your system.

---

## The directory is a service you bind

```php
interface HrDirectory
{
    public function approverFor(string $employeeHrId, string $role): ?DirectoryPerson;

    public function idOf(Model $person): ?string;
}
```

```php
// app/Providers/SignatureServiceProvider.php
public function register(): void
{
    $this->app->bind(HrDirectory::class, HttpHrDirectory::class);
    $this->app->bind(SignatoryUserMapper::class, HrDirectoryUserMapper::class);
}
```

## Mapping a directory person to a login

```php
use Illuminate\Contracts\Auth\Authenticatable;
use Kukux\DigitalSignature\Contracts\SignatoryUserMapper;

final class HrDirectoryUserMapper implements SignatoryUserMapper
{
    public function __construct(private HrDirectory $hr) {}

    public function toUser(Model $resolved): ?Authenticatable
    {
        if ($resolved instanceof Authenticatable) {
            return $resolved;
        }

        $hrId = $this->hr->idOf($resolved);

        return $hrId === null ? null : User::firstWhere('hr_id', $hrId);
    }
}
```

An approver with no account in your app maps to `null`, and routing refuses with `not_ready`, naming them: *"… is named as "Approving" but has no login, so they cannot sign."* Nobody else's login is ever guessed.

## Snapshotting from the directory

Store the directory people locally (a `directory_people` table with `hr_id`, `name`, `position`, refreshed on read). That way the snapshot has something to point at, and the document keeps naming them even if the directory changes later:

```php
final class DirectorySignatoryDefaults implements SignatoryDefaults
{
    public function __construct(private HrDirectory $hr) {}

    public function for(Model $record, string $role): ?SignatoryAssignment
    {
        $approver = $this->hr->approverFor($record->requester_hr_id, $role);

        if ($approver === null) {
            return null;
        }

        $person = DirectoryPerson::updateOrCreate(['hr_id' => $approver->id], [
            'name' => $approver->name,
            'position' => $approver->position,
        ]);

        return new SignatoryAssignment($person->id, $approver->position);
    }
}
```

The model's `signatoryModel()` is `DirectoryPerson::class`, and its slots bind to `signatoryRelation('approving')` and so on. The rest is the [existing-record recipe](existing-record.md).

## Testing

Bind a fake directory in the test, and the whole chain runs without the network:

```php
$this->app->instance(HrDirectory::class, new FakeHrDirectory([
    'E-1001' => ['recommending' => 'E-2001', 'approving' => 'E-3001'],
]));
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
