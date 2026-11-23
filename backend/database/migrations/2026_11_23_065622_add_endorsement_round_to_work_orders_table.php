<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Each submit starts a new endorsement round (issue 38). endorsement_round_after_record_id is
     * the highest Approval Record ID at that submit: the round's records are the ones with a higher ID. That stays
     * exact even when a Return and a resubmit happen in the same second, which a timestamp could not tell apart.
     * submitted_at records when the latest submit happened. Adds columns only; no rows are rewritten.
     */
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('endorsement_round_after_record_id')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn(['submitted_at', 'endorsement_round_after_record_id']);
        });
    }
};
