<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only record of every consequential act in the signing pipeline.
 *
 * The non-negotiable case is auto-affix: a signature was applied while its
 * owner was not in the request, so the system must be able to say exactly
 * who was signed for, what triggered it, and which grant authorised it.
 */
return new class extends Migration
{
    public function up(): void
    {
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

            $t->string('ip', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->text('context')->nullable(); // JSON

            $t->timestamps();

            $t->index(['subject_user_id', 'event']);
            $t->index(['signing_session_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_signature_audits');
    }
};
