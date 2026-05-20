<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_certificates', function (Blueprint $t) {
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

    public function down(): void
    {
        Schema::dropIfExists('user_certificates');
    }
};