<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('guides', function (Blueprint $table) {
            $table->timestamp('suspended_until')->nullable();
            $table->string('suspension_reason')->nullable();
        });
    }

    public function down()
    {
        Schema::table('guides', function (Blueprint $table) {
            $table->dropColumn(['suspended_until', 'suspension_reason']);
        });
    }
};
