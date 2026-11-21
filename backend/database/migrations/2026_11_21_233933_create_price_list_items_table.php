<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Prices are integer centavos. Items are deactivated, never deleted, and nothing cascades:
     * BOQs and Work Orders copy the unit price when they are made (issues 16 and 18).
     */
    public function up(): void
    {
        Schema::create('price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors');
            $table->string('code')->nullable();
            $table->string('description');
            $table->string('type')->index();
            $table->string('unit_of_measure');
            $table->unsignedBigInteger('unit_price_centavos');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['vendor_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_list_items');
    }
};
