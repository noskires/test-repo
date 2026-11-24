<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. One work_order_completions row per completion declaration (revision) by the Site Vendor.
     * Revisions are never changed; a resubmission after an EIC Return adds the next one. Each holds the actual
     * quantity used per Work Order line (integer thousandths; quantities only, no amounts: the EIC sets the Billing
     * Amount in issue 21) and its files: completion documents (at least one) and photos.
     */
    public function up(): void
    {
        Schema::create('work_order_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders');
            $table->unsignedInteger('revision');
            $table->text('notes')->nullable();
            $table->foreignId('submitted_by_user_id')->constrained('users');
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique(['work_order_id', 'revision']);
        });

        Schema::create('work_order_completion_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_completion_id')->constrained('work_order_completions');
            $table->foreignId('work_order_line_id')->constrained('work_order_lines');
            $table->unsignedBigInteger('actual_quantity_thousandths');
            $table->timestamps();

            $table->unique(['work_order_completion_id', 'work_order_line_id'], 'wo_completion_lines_completion_line_unique');
        });

        Schema::create('work_order_completion_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_completion_id')->constrained('work_order_completions');
            $table->string('kind');
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
        Schema::dropIfExists('work_order_completion_files');
        Schema::dropIfExists('work_order_completion_lines');
        Schema::dropIfExists('work_order_completions');
    }
};
