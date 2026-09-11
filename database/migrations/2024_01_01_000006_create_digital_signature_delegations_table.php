<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A user's standing consent for their signature to be applied without them
 * being present in the request.
 *
 * Scoped deliberately narrowly — a grant authorises ONE role on ONE template,
 * expires, and can cap the number of uses. It may only be created in the
 * grantor's own authenticated session; see SignatureDelegation::assertGrantable().
 */
return new class extends Migration
{
    public function up(): void
    {
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

    public function down(): void
    {
        Schema::dropIfExists('digital_signature_delegations');
    }
};
