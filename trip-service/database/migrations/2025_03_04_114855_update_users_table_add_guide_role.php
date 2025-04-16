<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // First drop the existing role column
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });

        // Then create new role column with all three roles
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('user');
        });

        // Add constraint to ensure only valid roles
        DB::statement("ALTER TABLE users ADD CONSTRAINT check_role CHECK (role IN ('user', 'admin', 'guide'))");
    }

    public function down(): void
    {
        // Remove constraint
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS check_role');

        // Drop and recreate original column
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('user');
        });

        // Add back original constraint
        DB::statement("ALTER TABLE users ADD CONSTRAINT check_role CHECK (role IN ('user', 'admin'))");
    }
};
