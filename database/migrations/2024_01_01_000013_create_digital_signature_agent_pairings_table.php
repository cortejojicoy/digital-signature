<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One desktop-agent pairing attempt: started on the web, claimed by the agent
 * with its keys, confirmed on the web, then exchanged once for a token.
 * See digital-signature-agent/docs/protocol.md "Pairing".
 */
return new class extends Migration
{
    public function up(): void
    {
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

            $t->timestamp('token_issued_at')->nullable();
            $t->timestamp('expires_at');
            $t->timestamps();

            $t->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_signature_agent_pairings');
    }
};
