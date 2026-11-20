<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves service names out of the branch row into a global catalogue.
 * branch_services keeps its primary keys so tickets, appointments and
 * employee permissions keep pointing at the same records.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('services')) {
            Schema::create('services', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('branch_services', 'service_id')) {
            Schema::table('branch_services', function (Blueprint $table) {
                $table->foreignId('service_id')->nullable()->constrained('services')->restrictOnDelete();
            });

            $resolved = [];
            DB::table('branch_services')->orderBy('id')->eachById(function ($row) use (&$resolved) {
                $name = trim((string) $row->name);
                if ($name === '') {
                    $name = 'خدمة عامة';
                }
                $key = mb_strtolower($name);
                if (! isset($resolved[$key])) {
                    $resolved[$key] = DB::table('services')->whereRaw('lower(name) = ?', [$key])->value('id')
                        ?: DB::table('services')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
                }
                DB::table('branch_services')->where('id', $row->id)->update(['service_id' => $resolved[$key]]);
            });

            Schema::table('branch_services', function (Blueprint $table) {
                $table->dropUnique(['branch_id', 'name']);
                $table->dropColumn('name');
                $table->dropForeign(['service_id']);
                $table->unsignedBigInteger('service_id')->nullable(false)->change();
                $table->foreign('service_id')->references('id')->on('services')->restrictOnDelete();
                $table->unique(['branch_id', 'service_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('branch_services', 'name')) {
            return;
        }

        Schema::table('branch_services', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'service_id']);
            $table->dropForeign(['service_id']);
            $table->string('name', 120)->nullable()->after('branch_id');
        });

        foreach (DB::table('branch_services')->get() as $row) {
            DB::table('branch_services')->where('id', $row->id)->update(['name' => DB::table('services')->where('id', $row->service_id)->value('name')]);
        }

        Schema::table('branch_services', function (Blueprint $table) {
            $table->dropColumn('service_id');
            $table->string('name', 120)->nullable(false)->change();
            $table->unique(['branch_id', 'name']);
        });

        Schema::dropIfExists('services');
    }
};
