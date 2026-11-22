<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. A Site's PM Frequency is null until set. Its changes are logged in
     * site_assignment_changes with assignment = pm_frequency, next to the vendor and EIC changes.
     */
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->string('pm_frequency')->nullable();
        });

        Schema::table('site_assignment_changes', function (Blueprint $table) {
            $table->string('old_pm_frequency')->nullable();
            $table->string('new_pm_frequency')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_assignment_changes', function (Blueprint $table) {
            $table->dropColumn(['old_pm_frequency', 'new_pm_frequency']);
        });

        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn('pm_frequency');
        });
    }
};
