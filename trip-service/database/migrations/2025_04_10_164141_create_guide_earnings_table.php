<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('guide_earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guide_id')->constrained();
            $table->foreignId('trip_id')->constrained();
            $table->foreignId('booking_id')->constrained();
            $table->foreignId('payment_id')->constrained('payments');
            $table->decimal('amount', 10, 2);
            $table->decimal('platform_fee', 10, 2);
            $table->string('status')->default('pending'); // pending, processed, failed
            $table->text('description')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('guide_earnings');
    }
};
