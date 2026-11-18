<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_services', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_service_id')->constrained('branch_services')->cascadeOnDelete();
            $table->primary(['user_id', 'branch_service_id']);
        });
        // Existing employees previously served every service. Preserve that
        // starting configuration until they select their own capabilities.
        DB::table('users')->whereNotNull('branch_id')->whereIn('role', ['admin', 'manager', 'agent'])->orderBy('id')->eachById(function ($user) {
            $rows = DB::table('branch_services')->where('branch_id', $user->branch_id)->pluck('id')->map(fn ($id) => ['user_id' => $user->id, 'branch_service_id' => $id])->all();
            if ($rows) {
                DB::table('employee_services')->insert($rows);
            }
        });
        Schema::table('tickets', function (Blueprint $table) {
            $table->index(['branch_id', 'business_date', 'status', 'branch_service_id', 'number'], 'tickets_service_queue_index');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', fn (Blueprint $table) => $table->dropIndex('tickets_service_queue_index'));
        Schema::dropIfExists('employee_services');
    }
};
