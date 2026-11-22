<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. One pm_reports row per PM Report revision of a PM Task. Revisions are never changed or
     * deleted; the latest is the current one. Responses point at the immutable Checklist Items of the task's
     * checklist version. Material quantities are integer thousandths. Item photos have a checklist_item_id;
     * general attachments (PM Service Report, other photos) don't.
     */
    public function up(): void
    {
        Schema::create('pm_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pm_task_id')->constrained('pm_tasks');
            $table->unsignedInteger('revision');
            $table->foreignId('checklist_template_version_id')->nullable()->constrained('checklist_template_versions');
            $table->date('work_done_date');
            $table->text('findings')->nullable();
            $table->foreignId('submitted_by_user_id')->constrained('users');
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique(['pm_task_id', 'revision']);
        });

        Schema::create('pm_report_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pm_report_id')->constrained('pm_reports');
            $table->foreignId('checklist_item_id')->constrained('checklist_items');
            $table->text('value')->nullable();
            $table->text('remarks')->nullable();
            $table->boolean('is_failed')->default(false);
            $table->timestamps();

            $table->unique(['pm_report_id', 'checklist_item_id']);
        });

        Schema::create('pm_report_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pm_report_id')->constrained('pm_reports');
            $table->string('description');
            $table->unsignedBigInteger('quantity_thousandths');
            $table->timestamps();
        });

        Schema::create('pm_report_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pm_report_id')->constrained('pm_reports');
            $table->foreignId('checklist_item_id')->nullable()->constrained('checklist_items');
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pm_report_attachments');
        Schema::dropIfExists('pm_report_materials');
        Schema::dropIfExists('pm_report_responses');
        Schema::dropIfExists('pm_reports');
    }
};
