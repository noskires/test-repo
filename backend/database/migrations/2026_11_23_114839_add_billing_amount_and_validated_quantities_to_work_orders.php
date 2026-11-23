<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. The EIC's validation (issue 21): the validated quantity per Work Order line (separate from
     * the vendor's actual quantities on completions, and from the approved quantity) with its exact line total, and
     * the Work Order's Billing Amount stored exactly and rounded once, half-up, to centavos (ADR 0002), separate from
     * the estimated amount. All null until validated. Adds nullable columns only; no rows are rewritten.
     */
    public function up(): void
    {
        Schema::table('work_order_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('validated_quantity_thousandths')->nullable();
            $table->unsignedBigInteger('validated_line_total_millicentavos')->nullable();
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('billing_amount_millicentavos')->nullable();
            $table->unsignedBigInteger('billing_amount_centavos')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn(['billing_amount_millicentavos', 'billing_amount_centavos']);
        });

        Schema::table('work_order_lines', function (Blueprint $table) {
            $table->dropColumn(['validated_quantity_thousandths', 'validated_line_total_millicentavos']);
        });
    }
};
