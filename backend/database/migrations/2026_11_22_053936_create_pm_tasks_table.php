<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Sites, Equipment and employees are external IDs with Snapshots (ADR 0001).
     * open_equipment_id equals equipment_id while the task is open and is null once it is Completed or Cancelled.
     * Its unique index is the database guarantee of at most one open PM Task per Equipment (NULLs don't collide).
     * due_from_date is the date the due date counts from (installation date or the previous Work Done Date),
     * so a PM Frequency change can re-date a Scheduled task without asking the directory.
     */
    public function up(): void
    {
        Schema::create('pm_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('site_id')->index();
            $table->string('site_name_snapshot');
            $table->string('equipment_id')->index();
            $table->string('open_equipment_id')->nullable()->unique();
            $table->string('equipment_name_snapshot');
            $table->string('equipment_category_id');
            $table->string('equipment_category_snapshot');
            $table->string('equipment_location_snapshot')->nullable();
            $table->foreignId('vendor_id')->constrained('vendors');
            $table->string('eic_employee_id')->index();
            $table->foreignId('checklist_template_version_id')->nullable()->constrained('checklist_template_versions');
            $table->boolean('no_checklist')->default(false);
            $table->boolean('equipment_removed')->default(false);
            $table->string('status')->index();
            $table->date('due_from_date');
            $table->date('due_date')->index();
            $table->date('work_done_date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pm_tasks');
    }
};
