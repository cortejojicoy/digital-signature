<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signatures', function (Blueprint $t) {
            // Unique token per signing request — used to build tamper-evident audit trails
            $t->uuid('uuid')->nullable()->unique()->after('id');

            // SHA-256 of the original PDF before signing — lets you prove the doc was not
            // altered before the signer saw it
            $t->string('document_hash', 64)->nullable()->after('image_hash')
                ->comment('SHA-256 of the source PDF before signing');

            // SHA-256 of the signed PDF — lets you detect any post-signing tampering
            $t->string('signed_document_hash', 64)->nullable()->after('signed_document_path')
                ->comment('SHA-256 of the signed PDF after signing');
        });
    }

    public function down(): void
    {
        Schema::table('signatures', function (Blueprint $t) {
            $t->dropUnique(['uuid']);
            $t->dropColumn(['uuid', 'document_hash', 'signed_document_hash']);
        });
    }
};
