<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. A Work Order is created from exactly one approved E-Call: work_orders.e_call_id is UNIQUE,
     * the database guard against two concurrent creates. e_call_proposal_id is the approved FSR + BOQ revision it
     * was built from. Money per ADR 0002: line totals exact in millicentavos; the estimated amount stored both
     * exactly and rounded half-up to integer centavos. Each approved BOQ line appears at most once per Work Order.
     */
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('e_call_id')->unique()->constrained('e_calls');
            $table->foreignId('e_call_proposal_id')->constrained('e_call_proposals');
            $table->string('status')->index();
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->unsignedBigInteger('estimated_amount_millicentavos');
            $table->unsignedBigInteger('estimated_amount_centavos');
            $table->timestamps();
        });

        Schema::create('work_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders');
            $table->foreignId('e_call_proposal_line_id')->constrained('e_call_proposal_lines');
            $table->string('code')->nullable();
            $table->string('description');
            $table->string('type');
            $table->string('unit_of_measure');
            $table->unsignedBigInteger('quantity_thousandths');
            $table->unsignedBigInteger('unit_price_centavos');
            $table->unsignedBigInteger('line_total_millicentavos');
            $table->timestamps();

            $table->unique(['work_order_id', 'e_call_proposal_line_id']);
        });

        Schema::create('work_order_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders');
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
        Schema::dropIfExists('work_order_attachments');
        Schema::dropIfExists('work_order_lines');
        Schema::dropIfExists('work_orders');
    }
};
