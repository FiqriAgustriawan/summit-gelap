<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('guide_earnings')) {
            // Add any missing columns to the existing table
            if (!Schema::hasColumn('guide_earnings', 'description')) {
                Schema::table('guide_earnings', function (Blueprint $table) {
                    $table->text('description')->nullable();
                });
            }

            if (!Schema::hasColumn('guide_earnings', 'processed_at')) {
                Schema::table('guide_earnings', function (Blueprint $table) {
                    $table->timestamp('processed_at')->nullable();
                });
            }
        }
    }

    public function down()
    {
        // Reverse the changes if needed
        if (Schema::hasTable('guide_earnings')) {
            if (Schema::hasColumn('guide_earnings', 'description')) {
                Schema::table('guide_earnings', function (Blueprint $table) {
                    $table->dropColumn('description');
                });
            }

            if (Schema::hasColumn('guide_earnings', 'processed_at')) {
                Schema::table('guide_earnings', function (Blueprint $table) {
                    $table->dropColumn('processed_at');
                });
            }
        }
    }
};
