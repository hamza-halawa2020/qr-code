<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->unique()->after('name');
        });

        // Copy existing email values to phone so current accounts keep working
        DB::table('users')->update(['phone' => DB::raw('email')]);

        Schema::table('users', function (Blueprint $table) {
            // Keep email column but make it nullable — not used for login anymore
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
            $table->dropColumn('phone');
        });
    }
};
