# Plan: plug-and-play install (`php artisan signature:install`)

> **Status: implemented.** See `src/Console/InstallCommand.php`, `src/Console/Install/`, `tests/Feature/InstallCommandTest.php` and docs/installation.md "Quick install".

Goal: after `composer require kukux/digital-signature`, one command sets up
everything up to a migrated database. You answer "yes", it logs each step, and
it ends with "Done" plus what's left to do by hand.

```bash
composer require kukux/digital-signature
php artisan signature:install
```

---

## Why a command, not a prompt during `composer require`

Composer doesn't run a dependency's scripts. Only the **app's** `composer.json`
scripts run, so a package can't prompt on install by itself.

| Option | Verdict |
|---|---|
| Composer plugin (`type: composer-plugin`) | Rejected. Composer 2.2+ asks "trust this plugin?" first, CI blocks it with `allow-plugins`, and it runs before Laravel boots, so it can't call Artisan safely. |
| Hook in the app's `post-autoload-dump` | Rejected. It's the user's file, and it runs on every `composer install` and deploy. |
| **`php artisan signature:install`** | **Chosen.** Same pattern as `filament:install` and `breeze:install`. Laravel is booted, it can be tested, and it can be re-run safely. |

To make it feel automatic, we use the one Artisan command that *does* run
during `composer require`: every Laravel app's `composer.json` calls
`php artisan package:discover` after autoload. The provider listens for
`CommandFinished` on `package:discover` and, if the package isn't set up yet,
prints:

```
  INFO  Discovering packages.
  kukux/digital-signature ............................. DONE
  ...

  INFO  Digital Signature isn't set up yet. Run: php artisan signature:install
```

So the prompt to install shows up right at the end of `composer require`.

"Set up" means `config/signature.php` exists **or** the package tables exist.
The hint never shows in production (`app()->isProduction()`) or once either is
true. It's printed only, never interactive, because Composer may be running
without a TTY (CI, Docker builds).

---

## What it looks like

```
$ php artisan signature:install

  Digital Signature for Filament: installer

  This will:
   • check requirements
   • publish config/signature.php
   • add the SIGNATURE_* settings to .env
   • publish the Filament assets
   • run the package migration
   • register the plugin on your Filament panels
   • register a signature policy

 ┌ Continue? ───────────────────────────────────────────────┐
 │ ● Yes / ○ No                                             │
 └──────────────────────────────────────────────────────────┘

  Checking requirements ................................. DONE
    PHP 8.3.12, ext-openssl, ext-gd, Laravel 12.31, Filament 4.1
    ! barryvdh/laravel-dompdf not installed (needed for PDF templates)
  Publishing config ..................................... DONE
    config/signature.php
  Updating .env ......................................... DONE
    added 9 keys, kept 3 you already had
  Publishing assets ..................................... DONE
  Running migration ..................................... DONE
    9999_12_31_000000_create_digital_signature_tables
  Registering plugin .................................... DONE
    app/Providers/Filament/AdminPanelProvider.php
    app/Providers/Filament/AppPanelProvider.php
  Registering policy .................................... DONE
    app/Policies/DigitalSignaturePolicy.php

  INFO  Digital Signature is installed.

  Next:
   • composer require barryvdh/laravel-dompdf   (for PDF templates)
   • Set SIGNATURE_TSA_URL if you want trusted timestamps
   • Docs: https://cortejojicoy.github.io/digital-signature/
```

Built on `Illuminate\Console\View\Components` (`$this->components->task()`,
`->info()`, `->warn()`) for the log lines, and `Laravel\Prompts` (`confirm`,
`multiselect`) for questions. Both ship with Laravel 12, so no new dependency.

---

## The steps

Each step is its own small class implementing `InstallStep`, so it can be
tested alone and skipped with a flag. Every step is **idempotent**: re-running
the installer only does what's missing and says "SKIPPED (already done)" for the rest.

| # | Step | Does | Skips when |
|---|---|---|---|
| 1 | Requirements | Checks PHP ≥ 8.2, `ext-openssl`, `ext-gd`, Laravel 12, Filament 3/4/5 (`FilamentVersion`). Warns, doesn't fail, for optional `barryvdh/laravel-dompdf` and `ext-imagick`. Hard failures stop the install. | never |
| 2 | Config | Publishes `signature-config`. If the file exists, it leaves it alone and checks for the "one level deep" trap: warns about any top-level block missing keys the package has (e.g. a trimmed `launcher`). | file exists (unless `--force`) |
| 3 | `.env` | Appends a commented `# Digital signatures` block with the starter keys from installation.md. Only adds missing keys and never changes a value. Also writes `.env.example`. | all keys present |
| 4 | Assets | Calls `filament:assets`. | `--no-assets` |
| 5 | Storage | Makes sure the `SIGNATURE_DISK` disk exists. Refuses to go on if it's `public`, because certificates would be web-readable. | never |
| 6 | Migration | Shows what will run (`migrate --pretend` summary), asks, then runs `migrate`. Detects legacy published migrations and says they'll be adopted, not duplicated. | `--no-migrate`, or nothing pending |
| 7 | Plugin | Finds panel providers (`app/Providers/Filament/*PanelProvider.php`), asks which ones (multiselect, all ticked), and inserts `SignaturePlugin::make()` into `->plugins([...])` plus the `use` line. | already registered on that panel |
| 8 | Policy | Writes `app/Policies/DigitalSignaturePolicy.php` (owner-only, the one from installation.md) and adds `Gate::policy(...)` to `AppServiceProvider::boot()`. | policy already bound for `Signature` |

### Editing PHP files safely (steps 7 and 8)

These are the risky steps, since they touch the user's code.

- Find the insertion point with a tight regex: `->plugins([` in the provider, and the
  `boot()` body in `AppServiceProvider`. If the shape isn't what we expect (no
  `->plugins(`, or more than one), **don't guess**. Print the snippet to paste
  and mark the step "MANUAL".
- Show a small diff before writing, and ask once per file.
- Run `php -l` on the result. If it fails, restore the original and fall back to
  "MANUAL".
- Never touch a file with uncommitted git changes without saying so in the prompt.

---

## Flags

| Flag | Effect |
|---|---|
| `--force` | Overwrite the published config. Also needed to migrate in production. |
| `--no-migrate` | Skip the migration (e.g. migrations run in a deploy step). |
| `--no-assets` | Skip `filament:assets`. |
| `--no-panel` / `--panel=admin` | Skip plugin registration, or do one panel only. |
| `--no-policy` | Skip the policy. |
| `--publish-migrations` | Publish the migration instead of running it from `vendor/`. |
| `--agent` | Also enable the desktop agent: set `SIGNATURE_AGENT_ENABLED=true` and generate a stable `SIGNATURE_AGENT_SERVER_ID` / `SIGNATURE_AGENT_SALT`, so rotating `APP_KEY` later doesn't break pairings. |
| `--dry-run` | Log what each step *would* do, change nothing. |
| `-n` / `--no-interaction` | Answer yes to everything, for CI and scripts. Code edits (steps 7–8) fall back to "MANUAL" unless `--force`. |

Exit code is non-zero if a required step failed, so CI catches it.

---

## Production safety

- In production it refuses to run `migrate` without `--force`, like `migrate` itself.
- It never overwrites `.env` values, never removes keys, and never touches `APP_KEY`.
- `--dry-run` is the recommended first run on an existing app.

---

## Files

```
src/Console/InstallCommand.php            signature:install, orchestrates the steps
src/Console/Install/InstallStep.php        interface: label(), shouldRun(), run(): StepResult
src/Console/Install/StepResult.php         done | skipped | manual | failed, + detail lines
src/Console/Install/Steps/CheckRequirements.php
src/Console/Install/Steps/PublishConfig.php
src/Console/Install/Steps/UpdateEnv.php
src/Console/Install/Steps/PublishAssets.php
src/Console/Install/Steps/CheckStorage.php
src/Console/Install/Steps/RunMigration.php
src/Console/Install/Steps/RegisterPlugin.php
src/Console/Install/Steps/RegisterPolicy.php
src/Console/Install/EnvFile.php            read/append .env without reformatting it
src/Console/Install/PhpFileEditor.php      find-insert-lint-restore for steps 7–8
stubs/DigitalSignaturePolicy.php.stub
```

The provider registers the command next to `ReleaseAgentComputer`, plus the
one-line "not set up yet" hint.

---

## Tests

Pest, in Testbench's temp app skeleton:

- Fresh app: every step reports DONE, the config, `.env` keys, tables, plugin line and policy all exist.
- Run twice: second run is all SKIPPED and changes no file (compare file hashes).
- Existing `.env` value: kept, not overwritten.
- Trimmed `launcher` block in config: warning printed, file untouched.
- Panel provider without `->plugins(`: step is MANUAL, file untouched, snippet printed.
- Broken edit: lint fails, original restored.
- `public` disk: install stops before migrating.
- Production env without `--force`: migration refused.
- `--dry-run`: no file changes, all steps logged.
- `-n`: no prompts, code edits are MANUAL.

---

## Docs

- `README.md` and `docs/installation.md`: the quick install becomes two lines
  (`composer require` and `signature:install`). The current manual steps stay
  below as "Manual install" for people who want control.
- `docs/configuration.md`: one line pointing at `--agent` for the server ID/salt.

---

## Decisions

1. **Policy step:** on by default; `--no-policy` skips it.
2. **Shield:** when installed, the installer adds `php artisan shield:generate --all` to "Next".
3. **Hint after `composer require`:** on, printed after `package:discover` until the package is set up, never in production.
