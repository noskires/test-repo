<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. The FSR + BOQ revision the EIC approved (the latest one at approval time). Null until
     * an approval. Issue 18 builds the Work Order from it. Adds a nullable column only; no rows are rewritten.
     */
    public function up(): void
    {
        Schema::table('e_calls', function (Blueprint $table) {
            $table->foreignId('approved_proposal_id')->nullable()->constrained('e_call_proposals');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('e_calls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_proposal_id');
        });
    }
};
