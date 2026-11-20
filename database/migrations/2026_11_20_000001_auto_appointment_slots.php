<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Converts the old "one slot per service" scheme into shared branch slots.
 *
 * The create migrations already describe the final schema, so on a fresh
 * install this migration is a no-op. It exists for databases created before
 * appointments became service-independent and keeps their bookings intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('branches', 'appointment_interval_minutes')) {
            Schema::table('branches', fn (Blueprint $table) => $table->unsignedSmallInteger('appointment_interval_minutes')->default(60));
        }

        if (! Schema::hasColumn('appointments', 'branch_service_id')) {
            Schema::table('appointments', fn (Blueprint $table) => $table->foreignId('branch_service_id')->nullable()->constrained('branch_services'));
        }

        if (! Schema::hasColumn('appointment_slots', 'branch_service_id')) {
            return;
        }

        // A booking now carries its own service, so copy it from the old slot.
        foreach (DB::table('appointments')->whereNull('branch_service_id')->get() as $appointment) {
            $slot = DB::table('appointment_slots')->find($appointment->appointment_slot_id);

            if ($slot?->branch_service_id) {
                DB::table('appointments')->where('id', $appointment->id)->update(['branch_service_id' => $slot->branch_service_id]);
            }
        }

        // Collapse service-specific duplicates into a single shared slot.
        DB::table('appointment_slots')->orderBy('id')->get()
            ->groupBy(fn ($slot) => $slot->branch_id.'|'.$slot->starts_at)
            ->each(function ($group) {
                $keep = $group->first();
                $duplicateIds = $group->skip(1)->pluck('id')->all();

                if ($duplicateIds === []) {
                    return;
                }

                DB::table('appointments')->whereIn('appointment_slot_id', $duplicateIds)->update(['appointment_slot_id' => $keep->id]);
                DB::table('appointment_slots')->whereIn('id', $duplicateIds)->delete();
            });

        Schema::table('appointment_slots', function (Blueprint $table) {
            $table->dropForeign(['branch_service_id']);
            $table->dropUnique(['branch_service_id', 'starts_at']);
            $table->dropColumn(['branch_service_id', 'capacity']);
            $table->unique(['branch_id', 'starts_at']);
        });

        $fallbackService = DB::table('branch_services')->orderBy('id')->value('id');
        if ($fallbackService) {
            DB::table('appointments')->whereNull('branch_service_id')->update(['branch_service_id' => $fallbackService]);
        }

        Schema::table('appointments', fn (Blueprint $table) => $table->unsignedBigInteger('branch_service_id')->nullable(false)->change());
    }

    public function down(): void
    {
        if (Schema::hasColumn('appointment_slots', 'branch_service_id')) {
            return;
        }

        Schema::table('appointment_slots', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'starts_at']);
            $table->foreignId('branch_service_id')->nullable()->constrained('branch_services');
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->unique(['branch_service_id', 'starts_at']);
        });

        foreach (DB::table('appointments')->get() as $appointment) {
            DB::table('appointment_slots')->where('id', $appointment->appointment_slot_id)->update(['branch_service_id' => $appointment->branch_service_id]);
        }

        Schema::table('appointments', fn (Blueprint $table) => $table->dropColumn('branch_service_id'));
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn('appointment_interval_minutes'));
    }
};
