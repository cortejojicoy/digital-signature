<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every table the package owns, in one migration.
 *
 * Up to v1.8.x the schema shipped as fifteen migrations. This file replaces
 * them, and must therefore work on two kinds of database:
 *
 *  - a fresh one, where it creates everything below; and
 *  - one that already ran the old migrations — auto-loaded or published, at
 *    any release — where those tables exist, possibly without the columns
 *    added in later releases.
 *
 * Hence every create is guarded by hasTable and every late column by
 * hasColumn: a host upgrading runs `php artisan migrate` and gets only what
 * it is missing. On such a database the old rows in the migrations table
 * still record who created the tables, so down() leaves them alone — rolling
 * back this migration must never drop signatures it did not create.
 *
 * Tables are created in dependency order: MySQL and Postgres require a
 * foreign key's target to exist at CREATE TABLE time, even though SQLite
 * would accept either order. `users` is the host app's own table.
 *
 * The 9999_12_31 prefix makes this sort after every real timestamp, so the
 * migrator always runs it after the host's own migrations — including a
 * `users` table created later than Laravel's default 0001_01_01 one. A future
 * package migration takes the next number (9999_12_31_000001, …). Publishing
 * replaces the prefix with the publish time, which also lands it last.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('digital_user_certificates')) {
            Schema::create('digital_user_certificates', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();
                $t->string('pfx_path');                 // encrypted PFX on disk
                $t->string('fingerprint', 64)->unique(); // SHA-256 of cert DER
                $t->string('serial')->nullable();
                $t->string('subject_dn')->nullable();
                $t->string('driver', 32)->default('openssl');
                $t->timestamp('issued_at')->nullable();
                $t->timestamp('expires_at')->nullable();
                $t->timestamp('revoked_at')->nullable();
                $t->timestamps();

                $t->index('user_id');
            });
        }

        // The public halves of the keys a user signs from — the package's
        // `authorized_keys`. One row per key: a browser profile or a desktop
        // agent install. The private key never leaves the device.
        if (! Schema::hasTable('digital_signature_devices')) {
            Schema::create('digital_signature_devices', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid')->unique();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();

                $t->string('label', 120);

                // SPKI, PEM-encoded. key_fingerprint is SHA-256 of the DER.
                $t->text('public_key');
                $t->string('key_fingerprint', 64);
                $t->string('algorithm', 16);                    // ES256 | RS256

                // browser — a Web Crypto key in one browser profile
                // agent   — a Secure Enclave / TPM key held by the desktop agent
                $t->string('kind', 16)->default('browser');
                $t->string('protection', 16)->default('browser'); // browser | software | tpm | secure_enclave
                $t->boolean('user_presence')->default(false);  // OS-enforced Touch ID / Hello on use
                $t->boolean('attested')->default(false);

                // Enums\DeviceType values; browser devices also write their
                // coarser desktop | mobile | tablet | unknown. detected_* is
                // what the agent reported, kept for audit and policy;
                // device_type may be the owner's correction of it.
                $t->string('device_type', 16)->default('unknown');
                $t->string('detected_device_type', 16)->nullable();
                $t->unsignedTinyInteger('chassis_type')->nullable();   // SMBIOS type 3 (Windows agents)
                $t->boolean('virtual')->default(false);                // firmware reported a VM
                $t->string('form_factor', 16)->nullable();          // laptop | desktop (agent only)
                $t->string('platform', 64)->nullable();
                $t->string('browser', 64)->nullable();
                $t->string('model', 120)->nullable();
                $t->text('user_agent')->nullable();

                // Agent only: salted hash of the hardware UUID, so a reinstall
                // can be recognised as the same machine; and the key the agent
                // signs its background API calls with.
                $t->string('hardware_id_hash', 64)->nullable();
                // hardware_id_hash while this is an active agent, else null.
                // Unique: one active pairing per computer for this app
                // (SigningDevice keeps it in step).
                $t->string('active_hardware_key', 64)->nullable()->unique('dsd_active_hardware_unique');
                $t->string('agent_version', 32)->nullable();
                $t->text('session_public_key')->nullable();
                $t->timestamp('rebound_at')->nullable();   // re-paired from the same computer

                $t->string('status', 16)->default('active');   // active | pending | revoked

                $t->string('registered_ip', 45)->nullable();
                $t->string('last_used_ip', 45)->nullable();
                $t->timestamp('last_used_at')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->timestamp('revoked_at')->nullable();
                $t->timestamps();

                $t->unique(['user_id', 'key_fingerprint'], 'dsd_user_key_unique');
                $t->index(['user_id', 'status']);
                $t->index('hardware_id_hash');
            });
        }

        // A signing session owns one document through N signatures. The key
        // column is `base_document_path`: the PDF is rendered ONCE when the
        // session opens and frozen there. Without that, every signatory would
        // sign a freshly-rendered PDF and orphan the stamps of everyone before
        // them.
        if (! Schema::hasTable('digital_signing_sessions')) {
            Schema::create('digital_signing_sessions', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid')->unique();

                // The host-app record being signed (implements Signable).
                $t->morphs('signable');

                $t->string('template_key', 64);

                // Rendered once at session open; never re-rendered.
                $t->string('base_document_path');
                $t->string('base_document_hash', 64)->nullable();

                // Advances with each signature; equals base_document_path until
                // the first signatory signs.
                $t->string('current_document_path')->nullable();
                $t->string('current_document_hash', 64)->nullable();

                // open | complete | cancelled | expired
                $t->string('status', 16)->default('open');

                // sequential | parallel — sequential honours SlotDefinition::$order.
                $t->string('sequence_mode', 16)->default('sequential');

                // progressive | incremental — see config('signature.multi_signature.mode').
                $t->string('signing_mode', 16)->default('progressive');

                $t->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();

                $t->timestamp('completed_at')->nullable();
                $t->timestamp('cancelled_at')->nullable();
                $t->timestamp('expires_at')->nullable();
                $t->timestamps();

                $t->index(['signable_type', 'signable_id', 'status'], 'dss_signable_status_idx');
                $t->index(['template_key', 'status']);
            });
        }

        if (! Schema::hasTable('digital_signatures')) {
            Schema::create('digital_signatures', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid')->nullable()->unique();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();

                // Polymorphic: any model that implements Signable
                $t->nullableMorphs('signable');

                // ── Multi-signatory chain ────────────────────────────────────
                // Set when this signature was produced as part of a signing
                // session. `sequence` + `parent_signature_id` make the chain
                // explicit: signature N's `document_hash` equals signature
                // N-1's `signed_document_hash`, so the whole progression is
                // verifiable from the database even where the PDF itself can
                // only carry the most recent PKCS#7 block.
                $t->foreignId('signing_session_id')
                    ->nullable()
                    ->constrained('digital_signing_sessions')
                    ->nullOnDelete();
                $t->string('slot_key', 64)->nullable();
                $t->unsignedSmallInteger('sequence')->nullable();
                $t->foreignId('parent_signature_id')
                    ->nullable()
                    ->constrained('digital_signatures')
                    ->nullOnDelete();

                $t->string('image_path');               // raw PNG stored on disk
                $t->string('document_hash', 64)->nullable();
                $t->string('image_hash', 64);           // SHA-256 of raw image bytes
                $t->string('signed_document_path')->nullable(); // final PDF path
                $t->string('signed_document_hash', 64)->nullable();
                $t->string('machine_fingerprint', 64)->nullable();

                // On a primary signature, the device it was created on; on a
                // document-signing row, the device it was used on. Null means
                // no verified device was present — delegated signing, a queue
                // worker, or an install that does not require one.
                $t->foreignId('device_id')
                    ->nullable()
                    ->constrained('digital_signature_devices')
                    ->nullOnDelete();

                // draw | upload | auto — `auto` marks a signature applied under
                // a delegation, with its owner absent from the request.
                $t->string('source', 16)->default('draw');
                $t->string('status', 16)->default('pending'); // pending | active | signed | revoked | failed

                $t->string('certificate_fingerprint', 64)->nullable();
                $t->text('certificate_password')->nullable();
                $t->text('pades_info')->nullable();     // JSON: TSA url, subfilter, reason

                $t->timestamp('signed_at')->nullable();
                $t->timestamp('revoked_at')->nullable();
                $t->timestamps();

                $t->index(['user_id', 'status']);
                $t->index('image_hash');
                $t->index(['signing_session_id', 'sequence'], 'ds_session_sequence_idx');
            });
        }

        if (! Schema::hasTable('signature_positions')) {
            Schema::create('signature_positions', function (Blueprint $t) {
                $t->id();
                $t->foreignId('signature_id')->constrained('digital_signatures')->cascadeOnDelete();

                $t->unsignedSmallInteger('page')->default(1);
                $t->float('x');       // points from left
                $t->float('y');       // points from bottom (PDF coordinate space)
                $t->float('width')->default(160);
                $t->float('height')->default(60);

                $t->string('label')->nullable(); // optional visible label under image

                // Which side of this stamp the provenance caption sits on:
                // bottom | top | left | right. Per placement rather than per
                // application, because a form dictates it and one document can
                // hold several kinds of signature line — a line with the
                // printed name already underneath has no room below and plenty
                // beside it, while one at the foot of a page has the opposite
                // problem. Null falls back to signature.caption.position.
                $t->string('caption_position', 10)->nullable();
                $t->timestamps();
            });
        }

        // One row per (session, slot) — the unit of work a signatory acts on.
        // This is what the signatory's inbox lists, what the SignatoryPanel
        // renders, and what records the decision (signed / declined).
        if (! Schema::hasTable('digital_signature_requests')) {
            Schema::create('digital_signature_requests', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid')->unique();

                $t->foreignId('signing_session_id')
                    ->constrained('digital_signing_sessions')
                    ->cascadeOnDelete();

                $t->string('slot_key', 64);
                $t->string('role', 64);

                // The resolved signatory. Nullable because a session can be
                // opened before every role is filled — the row then sits in
                // the `unassigned` state until the record is updated.
                $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

                // Set once the slot is actually signed.
                $t->foreignId('signature_id')
                    ->nullable()
                    ->constrained('digital_signatures')
                    ->nullOnDelete();

                $t->unsignedSmallInteger('sequence')->default(0);
                $t->boolean('required')->default(true);

                // Mirrors Kukux\DigitalSignature\Signatories\RouteState.
                $t->string('state', 32)->default('unassigned');

                // Frozen placement in PDF points, captured when the request was
                // created so a later designer edit can't silently move a
                // signature that has already been agreed to.
                $t->unsignedSmallInteger('page')->nullable();
                $t->float('x')->nullable();
                $t->float('y')->nullable();
                $t->float('width')->nullable();
                $t->float('height')->nullable();

                $t->timestamp('requested_at')->nullable();
                $t->timestamp('responded_at')->nullable();
                $t->text('declined_reason')->nullable();

                $t->timestamps();

                $t->unique(['signing_session_id', 'slot_key'], 'dsr_session_slot_unique');
                $t->index(['user_id', 'state']);
            });
        }

        // A user's standing consent for their signature to be applied without
        // them being present in the request. Scoped deliberately narrowly — a
        // grant authorises ONE role on ONE template, expires, and can cap the
        // number of uses. It may only be created in the grantor's own
        // authenticated session; see SignatureDelegation::assertGrantable().
        if (! Schema::hasTable('digital_signature_delegations')) {
            Schema::create('digital_signature_delegations', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid')->unique();

                // The grantor — the person whose signature may be auto-applied.
                $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();

                // The signature image the grant authorises. Revoking that
                // signature revokes the grant with it.
                $t->foreignId('signature_id')
                    ->constrained('digital_signatures')
                    ->cascadeOnDelete();

                $t->string('template_key', 64);

                // Null = every role on that template.
                $t->string('role', 64)->nullable();

                // Optional narrowing to one specific record.
                $t->nullableMorphs('signable');

                $t->unsignedInteger('max_uses')->nullable();
                $t->unsignedInteger('uses')->default(0);

                $t->timestamp('expires_at')->nullable();
                $t->timestamp('revoked_at')->nullable();

                // Provenance of the grant itself, for the audit trail.
                $t->string('granted_ip', 45)->nullable();
                $t->text('granted_user_agent')->nullable();

                $t->timestamps();

                $t->index(['user_id', 'template_key', 'role'], 'dsd_user_scope_idx');
            });
        }

        // Append-only record of every consequential act in the signing
        // pipeline. The non-negotiable case is auto-affix: a signature was
        // applied while its owner was not in the request, so the system must
        // be able to say exactly who was signed for, what triggered it, and
        // which grant authorised it.
        if (! Schema::hasTable('digital_signature_audits')) {
            Schema::create('digital_signature_audits', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid')->unique();

                // session.opened, request.signed, request.declined,
                // signature.auto_affixed, delegation.granted, delegation.revoked, …
                $t->string('event', 64);

                // Whose signature the event concerns.
                $t->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();

                // Who/what caused it. Null for scheduled or system-triggered acts.
                $t->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->string('actor_type', 32)->default('user'); // user | system | console | job

                $t->foreignId('signing_session_id')
                    ->nullable()
                    ->constrained('digital_signing_sessions')
                    ->nullOnDelete();

                $t->foreignId('signature_request_id')
                    ->nullable()
                    ->constrained('digital_signature_requests')
                    ->nullOnDelete();

                $t->foreignId('signature_id')
                    ->nullable()
                    ->constrained('digital_signatures')
                    ->nullOnDelete();

                // The grant that authorised an auto-affix, when applicable.
                $t->foreignId('delegation_id')
                    ->nullable()
                    ->constrained('digital_signature_delegations')
                    ->nullOnDelete();

                // The registered device the act came from, when there was one.
                $t->foreignId('device_id')
                    ->nullable()
                    ->constrained('digital_signature_devices')
                    ->nullOnDelete();

                $t->string('ip', 45)->nullable();
                $t->text('user_agent')->nullable();
                $t->text('context')->nullable(); // JSON

                $t->timestamps();

                $t->index(['subject_user_id', 'event']);
                $t->index(['signing_session_id', 'event']);
            });
        }

        if (! Schema::hasTable('digital_pdf_template_slots')) {
            Schema::create('digital_pdf_template_slots', function (Blueprint $t) {
                $t->id();

                // Pairs with PdfTemplate::key() / SlotDefinition::$key in code.
                // These are app-defined strings, not foreign keys — the table
                // only stores the saved coordinates; the template/slot
                // identity lives in code.
                $t->string('template_key', 64);
                $t->string('slot_key', 64);

                $t->unsignedSmallInteger('page')->default(1);
                $t->float('x');       // PDF points from left
                $t->float('y');       // PDF points from BOTTOM
                $t->float('width');
                $t->float('height');

                $t->timestamps();

                $t->unique(['template_key', 'slot_key']);
            });
        }

        // One desktop-agent pairing attempt: started on the web, claimed by
        // the agent with its keys, confirmed on the web, then exchanged once
        // for a token. See digital-signature-agent/docs/protocol.md "Pairing".
        if (! Schema::hasTable('digital_signature_agent_pairings')) {
            Schema::create('digital_signature_agent_pairings', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid')->unique();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();

                // Only hashes: the code and the poll secret are bearer secrets.
                $t->string('user_code_hash', 64)->index();
                $t->string('nonce', 64);
                $t->string('poll_secret_hash', 64)->nullable();

                // pending | awaiting_confirmation | confirmed | rejected | expired
                $t->string('status', 24)->default('pending');

                // What the agent sent in its claim: keys, device description, attestation.
                $t->text('claim')->nullable();

                $t->foreignId('device_id')
                    ->nullable()
                    ->constrained('digital_signature_devices')
                    ->nullOnDelete();

                // The same user's device on the same computer, which
                // confirming updates in place instead of adding a device.
                $t->foreignId('replaces_device_id')
                    ->nullable()
                    ->constrained('digital_signature_devices')
                    ->nullOnDelete();

                $t->timestamp('token_issued_at')->nullable();
                $t->timestamp('expires_at');
                $t->timestamps();

                $t->index(['user_id', 'status']);
            });
        }

        // Bearer tokens for paired agents, one device each. Stored as SHA-256
        // only. A token alone is not enough to call the API: every request
        // also carries a proof signed by the device's session key
        // (AuthenticateAgent).
        if (! Schema::hasTable('digital_signature_agent_tokens')) {
            Schema::create('digital_signature_agent_tokens', function (Blueprint $t) {
                $t->id();
                $t->foreignId('device_id')
                    ->constrained('digital_signature_devices')
                    ->cascadeOnDelete();
                $t->string('token_hash', 64)->unique();
                $t->timestamp('last_used_at')->nullable();
                $t->timestamp('revoked_at')->nullable();
                $t->timestamps();
            });
        }

        // "Approve this signing on your computer": one request to a paired
        // agent to sign a receipt over a document hash. A completed job is
        // consumed by the signature it approved, which then records the agent
        // as its device. See digital-signature-agent/docs/protocol.md
        // "Signing jobs".
        if (! Schema::hasTable('digital_signature_agent_jobs')) {
            Schema::create('digital_signature_agent_jobs', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid')->unique();
                $t->foreignId('user_id')->constrained()->cascadeOnDelete();

                // Which agent claimed it. Null until claimed.
                $t->foreignId('device_id')
                    ->nullable()
                    ->constrained('digital_signature_devices')
                    ->nullOnDelete();

                // The signature this approval was spent on.
                $t->foreignId('signature_id')
                    ->nullable()
                    ->constrained('digital_signatures')
                    ->nullOnDelete();

                $t->string('purpose', 32)->default('sign_receipt');
                $t->string('title');
                $t->nullableMorphs('signable');
                $t->string('payload_hash', 64);
                $t->string('nonce', 64);

                // Single use: cleared when the agent claims the job.
                $t->string('link_token_hash', 64)->nullable();

                // pending | claimed | completed | rejected | expired
                $t->string('status', 16)->default('pending');
                $t->string('reason', 32)->nullable();

                $t->timestamp('claimed_at')->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->timestamp('consumed_at')->nullable();
                $t->timestamp('expires_at');
                $t->timestamps();

                $t->index(['user_id', 'status', 'payload_hash'], 'dsaj_user_status_payload_idx');
            });
        }

        $this->addColumnsMissingFromEarlierReleases();

        // Primary (reusable) signatures — those with no signable_id — were
        // once persisted as status='pending'; a registered signature image is
        // just "available for use". Only for databases the old backfill
        // migration never reached: rows written since are left as they are.
        if (! $this->legacyMigrationRan('backfill_primary_digital_signatures_active_status')) {
            DB::table('digital_signatures')
                ->whereNull('signable_id')
                ->where('status', 'pending')
                ->update(['status' => 'active']);
        }
    }

    public function down(): void
    {
        // These tables were created by the pre-consolidation migrations, and
        // may hold real signatures; this migration only adopted them.
        if ($this->legacyMigrationRan('create_digital_signatures_table')) {
            return;
        }

        Schema::dropIfExists('digital_signature_agent_jobs');
        Schema::dropIfExists('digital_signature_agent_tokens');
        Schema::dropIfExists('digital_signature_agent_pairings');
        Schema::dropIfExists('digital_pdf_template_slots');
        Schema::dropIfExists('digital_signature_audits');
        Schema::dropIfExists('digital_signature_delegations');
        Schema::dropIfExists('digital_signature_requests');
        Schema::dropIfExists('signature_positions');
        Schema::dropIfExists('digital_signatures');
        Schema::dropIfExists('digital_signing_sessions');
        Schema::dropIfExists('digital_signature_devices');
        Schema::dropIfExists('digital_user_certificates');
    }

    /**
     * Columns that later releases added to tables which had already shipped.
     * A fresh table above already has them; a table from an older release
     * does not.
     */
    protected function addColumnsMissingFromEarlierReleases(): void
    {
        if (! Schema::hasColumn('signature_positions', 'caption_position')) {
            Schema::table('signature_positions', function (Blueprint $t) {
                $t->string('caption_position', 10)->nullable()->after('label');
            });
        }

        if (! Schema::hasColumn('digital_signatures', 'device_id')) {
            Schema::table('digital_signatures', function (Blueprint $t) {
                $t->foreignId('device_id')
                    ->nullable()
                    ->after('machine_fingerprint')
                    ->constrained('digital_signature_devices')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('digital_signature_audits', 'device_id')) {
            Schema::table('digital_signature_audits', function (Blueprint $t) {
                $t->foreignId('device_id')
                    ->nullable()
                    ->after('delegation_id')
                    ->constrained('digital_signature_devices')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('digital_signature_devices', 'detected_device_type')) {
            Schema::table('digital_signature_devices', function (Blueprint $t) {
                $t->string('detected_device_type', 16)->nullable()->after('device_type');
                $t->unsignedTinyInteger('chassis_type')->nullable()->after('detected_device_type');
                $t->boolean('virtual')->default(false)->after('chassis_type');
                $t->timestamp('rebound_at')->nullable()->after('session_public_key');
            });
        }

        if (! Schema::hasColumn('digital_signature_devices', 'active_hardware_key')) {
            Schema::table('digital_signature_devices', function (Blueprint $t) {
                $t->string('active_hardware_key', 64)->nullable()->after('hardware_id_hash');
            });

            $this->backfillActiveHardwareKeys();

            Schema::table('digital_signature_devices', function (Blueprint $t) {
                $t->unique('active_hardware_key', 'dsd_active_hardware_unique');
            });
        }

        if (! Schema::hasColumn('digital_signature_agent_pairings', 'replaces_device_id')) {
            Schema::table('digital_signature_agent_pairings', function (Blueprint $t) {
                $t->foreignId('replaces_device_id')
                    ->nullable()
                    ->after('device_id')
                    ->constrained('digital_signature_devices')
                    ->nullOnDelete();
            });
        }
    }

    /**
     * An install from before one-pairing-per-computer may already hold two
     * active agent devices for one computer. The newest keeps the key; the
     * rest stay active but unkeyed, so the unique index can be built and no
     * one loses a working device on upgrade. Pairing checks still see them.
     */
    protected function backfillActiveHardwareKeys(): void
    {
        DB::table('digital_signature_devices')
            ->where('kind', 'agent')
            ->where('status', 'active')
            ->whereNotNull('hardware_id_hash')
            ->orderByDesc('id')
            ->get(['id', 'hardware_id_hash'])
            ->unique('hardware_id_hash')
            ->each(fn (object $row) => DB::table('digital_signature_devices')
                ->where('id', $row->id)
                ->update(['active_hardware_key' => $row->hardware_id_hash]));
    }

    /**
     * Whether one of the pre-consolidation migrations is recorded as run,
     * under either its original name or a published copy's timestamp.
     */
    protected function legacyMigrationRan(string $suffix): bool
    {
        $repository = app('migration.repository');

        if (! $repository->repositoryExists()) {
            return false;
        }

        foreach ($repository->getRan() as $migration) {
            if (str_ends_with($migration, '_'.$suffix)) {
                return true;
            }
        }

        return false;
    }
};
