<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('guides', function (Blueprint $table) {
            $table->string('whatsapp')->nullable();
            $table->string('instagram')->nullable();
            $table->text('about')->nullable();

        });
    }

    public function down()
    {
        Schema::table('guides', function (Blueprint $table) {
            $table->dropColumn([
                'whatsapp',
                'instagram',
                'about',
            ]);
        });
    }
};