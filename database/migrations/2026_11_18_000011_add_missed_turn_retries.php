<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', fn (Blueprint $table) => $table->unsignedTinyInteger('missed_turn_retries')->default(0));
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedTinyInteger('retry_limit')->nullable();
            $table->unsignedTinyInteger('retry_attempts')->default(0);
            $table->timestamp('deferred_at')->nullable();
            $table->timestamp('retry_ready_at')->nullable();
            $table->index(['branch_id', 'status', 'branch_service_id', 'deferred_at'], 'tickets_deferred_service_index');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('tickets_deferred_service_index');
            $table->dropColumn(['retry_limit', 'retry_attempts', 'deferred_at', 'retry_ready_at']);
        });
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn('missed_turn_retries'));
    }
};
