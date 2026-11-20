<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('branches', 'board_token')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->text('board_token')->nullable()->after('board_token_hash');
            });
        }

        if (! Schema::hasColumn('counters', 'display_token')) {
            Schema::table('counters', function (Blueprint $table) {
                $table->text('display_token')->nullable()->after('display_token_hash');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('counters', 'display_token')) {
            Schema::table('counters', fn (Blueprint $table) => $table->dropColumn('display_token'));
        }

        if (Schema::hasColumn('branches', 'board_token')) {
            Schema::table('branches', fn (Blueprint $table) => $table->dropColumn('board_token'));
        }
    }
};
