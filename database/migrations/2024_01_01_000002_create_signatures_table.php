<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signatures', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Polymorphic: any model that implements Signable
            $t->nullableMorphs('signable');

            $t->string('image_path');               // raw PNG stored on disk
            $t->string('image_hash', 64);           // SHA-256 of raw image bytes
            $t->string('signed_document_path')->nullable(); // final PDF path

            $t->string('source', 16)->default('draw'); // draw | upload
            $t->string('status', 16)->default('pending'); // pending | signed | revoked | failed

            $t->string('certificate_fingerprint', 64)->nullable();
            $t->text('pades_info')->nullable();     // JSON: TSA url, subfilter, reason

            $t->timestamp('signed_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();

            $t->index(['user_id', 'status']);
            $t->index('image_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signatures');
    }
};