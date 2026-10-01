<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bearer tokens for paired agents, one device each. Stored as SHA-256 only.
 * A token alone is not enough to call the API: every request also carries a
 * proof signed by the device's session key (AuthenticateAgent).
 */
return new class extends Migration
{
    public function up(): void
    {
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

    public function down(): void
    {
        Schema::dropIfExists('digital_signature_agent_tokens');
    }
};
