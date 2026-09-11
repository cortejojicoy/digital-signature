<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (session, slot) — the unit of work a signatory acts on.
 *
 * This is what the signatory's inbox lists, what the SignatoryPanel renders,
 * and what records the decision (signed / declined) with its timestamp.
 */
return new class extends Migration
{
    public function up(): void
    {
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

    public function down(): void
    {
        Schema::dropIfExists('digital_signature_requests');
    }
};
