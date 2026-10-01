<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Approve this signing on your computer": one request to a paired agent to
 * sign a receipt over a document hash. A completed job is consumed by the
 * signature it approved, which then records the agent as its device.
 * See digital-signature-agent/docs/protocol.md "Signing jobs".
 */
return new class extends Migration
{
    public function up(): void
    {
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

    public function down(): void
    {
        Schema::dropIfExists('digital_signature_agent_jobs');
    }
};
