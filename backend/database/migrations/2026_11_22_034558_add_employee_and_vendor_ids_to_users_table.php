<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. employee_id is the external employee ID (ADR 0001) and vendor_id marks vendor staff.
     * Issue 04 fills both at login. Until then only factories set them.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_id')->nullable()->index();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_id');
            $table->dropIndex(['employee_id']);
            $table->dropColumn('employee_id');
        });
    }
};
