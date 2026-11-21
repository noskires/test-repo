<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. equipment_category_id is an external ID (ADR 0001), not a foreign key.
     * Versions and items are immutable once written; numeric criteria are stored as exact decimal strings.
     */
    public function up(): void
    {
        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('equipment_category_id')->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('checklist_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_template_id')->constrained('checklist_templates');
            $table->unsignedInteger('version_number');
            $table->string('content_fingerprint', 64);
            $table->timestamps();

            $table->unique(['checklist_template_id', 'version_number']);
        });

        Schema::create('checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_template_version_id')->constrained('checklist_template_versions');
            $table->unsignedInteger('position');
            $table->string('label');
            $table->string('response_type');
            $table->boolean('mandatory')->default(false);
            $table->boolean('remarks_required_on_fail')->default(false);
            $table->boolean('photo_required')->default(false);
            $table->string('acceptable_min', 32)->nullable();
            $table->string('acceptable_max', 32)->nullable();
            $table->text('acceptable_criteria')->nullable();
            $table->timestamps();

            $table->unique(['checklist_template_version_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checklist_items');
        Schema::dropIfExists('checklist_template_versions');
        Schema::dropIfExists('checklist_templates');
    }
};
