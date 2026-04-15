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
            $t->foreignId('signature_id')->constrained()->cascadeOnDelete();

            $t->unsignedSmallInteger('page')->default(1);
            $t->float('x');       // points from left
            $t->float('y');       // points from bottom (PDF coordinate space)
            $t->float('width')->default(160);
            $t->float('height')->default(60);

            $t->string('label')->nullable(); // optional visible label under image
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_positions');
    }
};