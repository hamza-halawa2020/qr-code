<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'name']);
        });
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('branch_service_id')->nullable()->constrained('branch_services')->nullOnDelete();
            $table->string('service_name', 120)->nullable();
            $table->text('customer_phone')->nullable();
        });
        DB::table('branches')->orderBy('id')->eachById(function ($branch) {
            DB::table('branch_services')->insert(['branch_id' => $branch->id, 'name' => 'خدمة عامة', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_service_id');
            $table->dropColumn(['service_name', 'customer_phone']);
        });
        Schema::dropIfExists('branch_services');
    }
};
