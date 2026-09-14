<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_positions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('signature_id')->constrained('digital_signatures')->cascadeOnDelete();

            $t->unsignedSmallInteger('page')->default(1);
            $t->float('x');       // points from left
            $t->float('y');       // points from bottom (PDF coordinate space)
            $t->float('width')->default(160);
            $t->float('height')->default(60);

            $t->string('label')->nullable(); // optional visible label under image

            // Which side of this stamp the provenance caption sits on:
            // bottom | top | left | right. Per placement rather than per
            // application, because a form dictates it and one document can
            // hold several kinds of signature line — a line with the printed
            // name already underneath has no room below and plenty beside it,
            // while one at the foot of a page has the opposite problem.
            // Null falls back to signature.caption.position.
            $t->string('caption_position', 10)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_positions');
    }
};