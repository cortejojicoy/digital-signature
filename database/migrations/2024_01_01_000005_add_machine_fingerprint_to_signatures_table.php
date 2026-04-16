<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signatures', function (Blueprint $t) {
            // SHA-256 hash of (userId | userAgent | ip | deviceFingerprint).
            // Stored so you can audit which machine created a given signature
            // and enforce machine-lock validation on re-upload.
            $t->string('machine_fingerprint', 64)->nullable()->after('signed_document_hash')
              ->comment('SHA-256 of userId + userAgent + IP + device fingerprint at store time');
        });
    }

    public function down(): void
    {
        Schema::table('signatures', function (Blueprint $t) {
            $t->dropColumn('machine_fingerprint');
        });
    }
};
