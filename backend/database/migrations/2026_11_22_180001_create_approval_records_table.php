<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. One append-only table for every workflow's Approval Records. subject_type holds a
     * morph-map alias (pm_task, later e_call and work_order), never a class name. Statuses are strings because
     * each subject has its own status enum. There is no updated_at: records are never changed.
     */
    public function up(): void
    {
        Schema::create('approval_records', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('action');
            $table->foreignId('actor_user_id')->constrained('users');
            $table->string('actor_employee_id')->nullable();
            $table->text('remarks')->nullable();
            $table->string('previous_status');
            $table->string('resulting_status');
            $table->timestamp('created_at')->nullable();

            $table->index(['subject_type', 'subject_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_records');
    }
};
