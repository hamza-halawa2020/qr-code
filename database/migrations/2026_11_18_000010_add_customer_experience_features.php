<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', fn (Blueprint $table) => $table->string('board_token_hash', 64)->nullable());
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('near_notified_at')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('rating_comment', 1000)->nullable();
            $table->timestamp('rated_at')->nullable();
            $table->timestamp('appointment_at')->nullable()->index();
            $table->text('customer_name')->nullable();
        });
        Schema::create('appointment_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_service_id')->constrained('branch_services')->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->unsignedSmallInteger('capacity');
            $table->timestamps();
            $table->unique(['branch_service_id', 'starts_at']);
        });
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_slot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->text('customer_phone');
            $table->text('customer_name');
            $table->text('customer_email')->nullable();
            $table->string('status', 20)->default('booked')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('appointment_slots');
        Schema::table('tickets', fn (Blueprint $table) => $table->dropColumn(['near_notified_at', 'rating', 'rating_comment', 'rated_at', 'appointment_at', 'customer_name']));
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn('board_token_hash'));
    }
};
