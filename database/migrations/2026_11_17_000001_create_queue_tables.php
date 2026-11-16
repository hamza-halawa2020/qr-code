<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('timezone')->default('Africa/Cairo');
            $table->boolean('is_open')->default(true);
            $table->string('opens_at', 5)->default('09:00');
            $table->string('closes_at', 5)->default('17:00');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamps();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('role')->default('agent');
        });
        Schema::create('counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unsignedInteger('number');
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->boolean('is_paused')->default(false);
            $table->string('display_token_hash', 64)->unique();
            $table->timestamps();
            $table->unique(['branch_id', 'number']);
        });
        Schema::create('daily_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('business_date');
            $table->unsignedInteger('last_number')->default(0);
            $table->unique(['branch_id', 'business_date']);
        });
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('token_hash', 64)->unique();
            $table->string('booking_key', 64)->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('business_date');
            $table->unsignedInteger('number');
            $table->string('status')->default('waiting');
            $table->foreignId('counter_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('called_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'business_date', 'number']);
            $table->index(['branch_id', 'status', 'business_date', 'number']);
            $table->index(['counter_id', 'status']);
        });
        Schema::create('ticket_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('version');
            $table->string('type');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->text('token');
            $table->string('token_hash', 64)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['push_subscriptions', 'outbox_messages', 'ticket_events', 'tickets', 'daily_sequences', 'counters'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn('role');
        });
        Schema::dropIfExists('branches');
    }
};
