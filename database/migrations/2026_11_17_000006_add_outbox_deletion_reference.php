<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_messages', fn (Blueprint $table) => $table->uuid('ticket_public_id')->nullable());
    }

    public function down(): void
    {
        Schema::table('outbox_messages', fn (Blueprint $table) => $table->dropColumn('ticket_public_id'));
    }
};
