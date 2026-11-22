<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. One row per FSR + BOQ revision of an E-Call. Revisions are never changed or deleted.
     * Money is exact integers: unit prices in centavos, line and BOQ totals in millicentavos (thousandths of a
     * centavo), quantities in thousandths. total_centavos is the exact total rounded half-up to the centavo (only
     * the BOQ total is rounded). The Price List item's details and price are copied onto each line.
     */
    public function up(): void
    {
        Schema::create('e_call_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('e_call_id')->constrained('e_calls');
            $table->unsignedInteger('revision');
            $table->text('assessment_notes');
            $table->string('fsr_disk');
            $table->string('fsr_path');
            $table->string('fsr_original_name');
            $table->string('fsr_mime_type');
            $table->unsignedBigInteger('fsr_size_bytes');
            $table->unsignedBigInteger('total_millicentavos');
            $table->unsignedBigInteger('total_centavos');
            $table->foreignId('submitted_by_user_id')->constrained('users');
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique(['e_call_id', 'revision']);
        });

        Schema::create('e_call_proposal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('e_call_proposal_id')->constrained('e_call_proposals');
            $table->foreignId('price_list_item_id')->constrained('price_list_items');
            $table->string('code')->nullable();
            $table->string('description');
            $table->string('type');
            $table->string('unit_of_measure');
            $table->unsignedBigInteger('quantity_thousandths');
            $table->unsignedBigInteger('unit_price_centavos');
            $table->unsignedBigInteger('line_total_millicentavos');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('e_call_proposal_lines');
        Schema::dropIfExists('e_call_proposals');
    }
};
