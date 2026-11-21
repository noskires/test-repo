<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Sites live in the external API (ADR 0001): site_id is the external ID, not a foreign key.
     */
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->string('site_id')->unique();
            $table->string('site_name_snapshot');
            $table->foreignId('vendor_id')->nullable()->constrained('vendors');
            $table->string('eic_employee_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('site_assignment_changes', function (Blueprint $table) {
            $table->id();
            $table->string('site_id')->index();
            $table->string('assignment');
            $table->foreignId('old_vendor_id')->nullable()->constrained('vendors');
            $table->foreignId('new_vendor_id')->nullable()->constrained('vendors');
            $table->string('old_eic_employee_id')->nullable();
            $table->string('new_eic_employee_id')->nullable();
            $table->foreignId('changed_by_user_id')->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_assignment_changes');
        Schema::dropIfExists('site_settings');
    }
};
