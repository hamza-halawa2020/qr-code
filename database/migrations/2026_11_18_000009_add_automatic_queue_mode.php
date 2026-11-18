<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', fn (Blueprint $table) => $table->boolean('automatic_queue')->default(false));
        Schema::table('tickets', fn (Blueprint $table) => $table->timestamp('call_expires_at')->nullable()->index());
    }

    public function down(): void
    {
        Schema::table('tickets', fn (Blueprint $table) => $table->dropColumn('call_expires_at'));
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn('automatic_queue'));
    }
};
