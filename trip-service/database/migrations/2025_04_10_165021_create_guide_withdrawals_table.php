<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Check if the table exists before trying to create it
        if (!Schema::hasTable('guide_withdrawals')) {
            Schema::create('guide_withdrawals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('guide_id')->constrained();
                $table->decimal('amount', 10, 2);
                $table->string('status')->default('pending'); // pending, processed, rejected
                $table->string('bank_name');
                $table->string('account_number');
                $table->string('account_name');
                $table->text('notes')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->foreignId('processed_by')->nullable()->constrained('users');
                $table->string('reference_number')->nullable();
                $table->string('transaction_id')->nullable(); // For Midtrans payout ID
                $table->text('reject_reason')->nullable(); // To store rejection reasons
                $table->timestamps();
            });
        } else {
            // If the table exists, check if we need to add the transaction_id and reject_reason columns
            if (!Schema::hasColumn('guide_withdrawals', 'transaction_id')) {
                Schema::table('guide_withdrawals', function (Blueprint $table) {
                    $table->string('transaction_id')->nullable()->after('reference_number');
                });
            }

            if (!Schema::hasColumn('guide_withdrawals', 'reject_reason')) {
                Schema::table('guide_withdrawals', function (Blueprint $table) {
                    $table->text('reject_reason')->nullable()->after('transaction_id');
                });
            }
        }
    }

    public function down()
    {
        Schema::dropIfExists('guide_withdrawals');
    }
};
