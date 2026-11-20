<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }
};
