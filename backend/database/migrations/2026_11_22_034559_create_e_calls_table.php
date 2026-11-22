<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Sites, Equipment and employees live in the external API (ADR 0001): their IDs are plain
     * strings with Snapshots, not foreign keys. The Site Vendor and EIC are copied in at filing time.
     * pm_task_id has no foreign key yet because PM Tasks arrive with issue 08. Issue 13 validates it.
     */
    public function up(): void
    {
        Schema::create('e_calls', function (Blueprint $table) {
            $table->id();
            $table->string('site_id')->index();
            $table->string('site_name_snapshot');
            $table->string('equipment_id')->nullable()->index();
            $table->string('equipment_name_snapshot')->nullable();
            $table->string('equipment_category_id')->nullable();
            $table->string('equipment_category_snapshot')->nullable();
            $table->string('equipment_location_snapshot')->nullable();
            $table->string('nature_of_issue');
            $table->text('description');
            $table->string('priority')->index();
            $table->string('status')->index();
            $table->foreignId('requestor_user_id')->constrained('users');
            $table->string('requestor_employee_id')->nullable()->index();
            $table->foreignId('vendor_id')->constrained('vendors');
            $table->string('eic_employee_id')->index();
            $table->unsignedBigInteger('pm_task_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('e_call_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('e_call_id')->constrained('e_calls');
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
        Schema::dropIfExists('e_call_attachments');
        Schema::dropIfExists('e_calls');
    }
};
