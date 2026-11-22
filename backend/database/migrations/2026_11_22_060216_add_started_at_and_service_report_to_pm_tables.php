<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Only adds nullable columns: existing tasks keep a null started_at, and report revisions
     * stored before the PM Service Report was required keep null service report columns. No row is rewritten.
     */
    public function up(): void
    {
        Schema::table('pm_tasks', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable();
        });

        Schema::table('pm_reports', function (Blueprint $table) {
            $table->string('service_report_disk')->nullable();
            $table->string('service_report_path')->nullable();
            $table->string('service_report_original_name')->nullable();
            $table->string('service_report_mime_type')->nullable();
            $table->unsignedBigInteger('service_report_size_bytes')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pm_reports', function (Blueprint $table) {
            $table->dropColumn([
                'service_report_disk',
                'service_report_path',
                'service_report_original_name',
                'service_report_mime_type',
                'service_report_size_bytes',
            ]);
        });

        Schema::table('pm_tasks', function (Blueprint $table) {
            $table->dropColumn('started_at');
        });
    }
};
