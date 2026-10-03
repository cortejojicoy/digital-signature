# Service provider: the composition root

Every app-specific binding lives in one service provider. Create it once:

```php
// app/Providers/SignatureServiceProvider.php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Kukux\DigitalSignature\Contracts\SignatoryUserMapper;
use Kukux\DigitalSignature\Signatories\RelationUserMapper;

class SignatureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SignatoryUserMapper::class, fn () => RelationUserMapper::using('user'));
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

Documents and templates are **not** bound here. They go in `config/signature.php` as class-strings, so `php artisan config:cache` keeps working:

```php
// config/signature.php
'templates' => [\App\Pdf\DtrTemplate::class],
'documents' => ['dtr' => \App\Signatures\DtrDocument::class],
```

They are still built by the container, so their constructors can ask for anything you bind here.

---

## `SignatoryUserMapper`: how a signatory becomes a login

Signatures belong to logins. A registered signature, its certificate and every signature request are keyed by `user_id`. Your records probably name someone else: a `Personnel` row, an `Employee`, an approver in an HR system.

A slot binds to whatever the record holds. The mapper turns it into a login, once, for every slot of every document:

```php
new SlotDefinition(key: 'in_charge', label: 'In Charge', signatory: 'inCharge', order: 2, required: true)
//                                                                 ↑ returns an Employee; the mapper finds its User
```

Pick the shape your app has:

| Your signatories are | Bind | Notes |
|---|---|---|
| `User` rows | nothing | The default `IdentityUserMapper` passes logins through. |
| Rows with a relation to their login (`Personnel::user()`, `Employee::user()`) | `RelationUserMapper::using('user')` | A model that already is a login passes through too, so you can mix. |
| Ids in another system | your own class | See [Recipe: external signatories](recipes/external-signatories.md). |

```php
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Kukux\DigitalSignature\Contracts\SignatoryUserMapper;

final class HrDirectoryUserMapper implements SignatoryUserMapper
{
    public function __construct(private HrDirectory $hr) {}

    public function toUser(Model $resolved): ?Authenticatable
    {
        return User::firstWhere('hr_id', $this->hr->idOf($resolved));
    }
}
```

Returning `null` means "this person can't sign here". Routing then refuses with `not_ready`: *"Dr Reyes is named as "In Charge" but has no login, so they cannot sign."*

> **Why the default refuses rather than guesses.** In 1.x, a slot bound straight to a `Personnel` relation stored the Personnel id as a `user_id`, routing the document to whichever user shared that number. In 2.0 anything that isn't a login is refused until you bind a mapper.

---

## `DocumentOfRecordGate`: who may see a routed document

The default (`DefaultDocumentOfRecordGate`):

- **view**: anyone the record's policy allows to `view` it, plus everyone the document was routed to and whoever routed it;
- **one version**: the above, or the person whose signature produced that version. That's their copy of what they signed, so they can always open it, even after losing access to the record;
- **delete**: never, once the record has been routed.

Bind your own to change any of that:

```php
$this->app->bind(DocumentOfRecordGate::class, TravelRequestGate::class);

final class TravelRequestGate extends DefaultDocumentOfRecordGate
{
    public function canView(?Authenticatable $user, Model $record): bool
    {
        return $user?->hasRole('accounting') || parent::canView($user, $record);
    }
}
```

---

## What else you might bind

| Binding | When |
|---|---|
| `Gate::policy(\Kukux\DigitalSignature\Models\Signature::class, YourPolicy::class)` in `boot()` | You keep your own policy for the signature library. Laravel's policy discovery never looks at vendor models. |
| Guard dependencies, e.g. `$this->app->bind(TimeLogRepository::class, EloquentTimeLogRepository::class)` | A guard or definition takes an interface in its constructor. |
| `SignaturePlugin::make()->resolveSignatoriesUsing(fn ($record, $slot) => …)` on the panel | One global override for who fills a slot, ahead of each slot's own binding. Its result still goes through the mapper. |
