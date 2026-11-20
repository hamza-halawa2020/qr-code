<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            // 0 = Sunday … 6 = Saturday (Carbon dayOfWeek).
            $table->unsignedTinyInteger('day_of_week');
            $table->string('opens_at', 5)->nullable();
            $table->string('closes_at', 5)->nullable();
            $table->boolean('is_closed')->default(false);
            $table->timestamps();

            $table->unique(['branch_id', 'day_of_week']);
        });

        // Keep existing branches working: copy their current single range to all 7 days.
        $now = now();
        $rows = [];
        foreach (DB::table('branches')->get(['id', 'opens_at', 'closes_at']) as $branch) {
            foreach (range(0, 6) as $day) {
                $rows[] = [
                    'branch_id'  => $branch->id,
                    'day_of_week' => $day,
                    'opens_at'   => $branch->opens_at ?: '05:00',
                    'closes_at'  => $branch->closes_at ?: '23:00',
                    'is_closed'  => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        if ($rows !== []) {
            DB::table('branch_schedules')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_schedules');
    }
};
