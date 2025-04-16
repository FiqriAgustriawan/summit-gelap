<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mountains', function (Blueprint $table) {
            $table->id();
            $table->string('nama_gunung');
            $table->string('lokasi');
            $table->string('link_map');
            $table->integer('ketinggian');
            $table->enum('status_gunung', ['mudah', 'menengah', 'sulit']);
            $table->enum('status_pendakian', ['pemula', 'mahir', 'ahli']);
            $table->text('deskripsi');
            $table->json('peraturan')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mountains');
    }
};
