<?php

use App\MasterData\Import\SourceSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. A read-only copy of the source database's master data (issue 03, ADR 0001 amended), filled
     * only by pss:import-master-data (or the sample seeder). source_id is the port's ID. Site and head references are
     * source IDs, not foreign keys, so a row that left the source keeps resolving. Rows are never deleted.
     */
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->string('source_id')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('equipment_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->string('source_id')->unique();
            $table->string('equipment_category_code')->index();
            $table->string('site_source_id')->index();
            $table->string('name');
            $table->string('location')->nullable();
            $table->date('installed_on')->nullable();
            $table->string('operating_status')->nullable();
            $table->json('details')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('source_id')->unique();
            $table->string('name');
            $table->string('position_title')->nullable();
            $table->string('immediate_head_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('equipment_categories')->insert(array_map(
            fn (array $category): array => [...$category, 'created_at' => $now, 'updated_at' => $now],
            SourceSchema::equipmentCategories(),
        ));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
        Schema::dropIfExists('equipment');
        Schema::dropIfExists('equipment_categories');
        Schema::dropIfExists('sites');
    }
};
