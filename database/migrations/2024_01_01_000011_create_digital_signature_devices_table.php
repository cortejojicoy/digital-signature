<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The public halves of the keys a user signs from — the package's
 * `authorized_keys`. One row per key: a browser profile or a desktop agent
 * install. The private key never leaves the device.
 */
return new class extends Migration
{
    public function up(): void
    {
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

            $t->string('device_type', 16)->default('unknown'); // desktop | mobile | tablet | unknown
            $t->string('form_factor', 16)->nullable();          // laptop | desktop (agent only)
            $t->string('platform', 64)->nullable();
            $t->string('browser', 64)->nullable();
            $t->string('model', 120)->nullable();
            $t->text('user_agent')->nullable();

            // Agent only: salted hash of the hardware UUID, so a reinstall
            // can be recognised as the same machine; and the key the agent
            // signs its background API calls with.
            $t->string('hardware_id_hash', 64)->nullable();
            $t->string('agent_version', 32)->nullable();
            $t->text('session_public_key')->nullable();

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

    public function down(): void
    {
        Schema::dropIfExists('digital_signature_devices');
    }
};
