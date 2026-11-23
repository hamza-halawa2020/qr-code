<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            // Whether online appointment booking is open for this branch.
            $table->boolean('appointments_enabled')->default(true)->after('appointment_interval_minutes');
            // Minimum hours in advance a customer must book. 0 = no minimum.
            $table->unsignedSmallInteger('booking_lead_hours')->default(0)->after('appointments_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['appointments_enabled', 'booking_lead_hours']);
        });
    }
};
