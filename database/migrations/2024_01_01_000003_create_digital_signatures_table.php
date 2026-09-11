<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digital_signatures', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->nullable()->unique();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Polymorphic: any model that implements Signable
            $t->nullableMorphs('signable');

            // ── Multi-signatory chain ────────────────────────────────────────
            // Set when this signature was produced as part of a signing
            // session. `sequence` + `parent_signature_id` make the chain
            // explicit: signature N's `document_hash` equals signature N-1's
            // `signed_document_hash`, so the whole progression is verifiable
            // from the database even where the PDF itself can only carry the
            // most recent PKCS#7 block.
            $t->foreignId('signing_session_id')
                ->nullable()
                ->constrained('digital_signing_sessions')
                ->nullOnDelete();
            $t->string('slot_key', 64)->nullable();
            $t->unsignedSmallInteger('sequence')->nullable();
            $t->foreignId('parent_signature_id')
                ->nullable()
                ->constrained('digital_signatures')
                ->nullOnDelete();

            $t->string('image_path');               // raw PNG stored on disk
            $t->string('document_hash', 64)->nullable();
            $t->string('image_hash', 64);           // SHA-256 of raw image bytes
            $t->string('signed_document_path')->nullable(); // final PDF path
            $t->string('signed_document_hash', 64)->nullable();
            $t->string('machine_fingerprint', 64)->nullable();

            // draw | upload | auto — `auto` marks a signature applied under a
            // delegation, with its owner absent from the request.
            $t->string('source', 16)->default('draw');
            $t->string('status', 16)->default('pending'); // pending | active | signed | revoked | failed

            $t->string('certificate_fingerprint', 64)->nullable();
            $t->text('certificate_password')->nullable();
            $t->text('pades_info')->nullable();     // JSON: TSA url, subfilter, reason

            $t->timestamp('signed_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();

            $t->index(['user_id', 'status']);
            $t->index('image_hash');
            $t->index(['signing_session_id', 'sequence'], 'ds_session_sequence_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_signatures');
    }
};
