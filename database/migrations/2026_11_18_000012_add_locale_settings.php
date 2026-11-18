<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('display_locale', 5)->default('ar')->after('missed_turn_retries');
            $table->string('panel_locale', 5)->default('ar')->after('display_locale');
        });
    }

    public function down(): void
    {
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn(['display_locale', 'panel_locale']));
    }
};
