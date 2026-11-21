<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Amounts are integer centavos, never floats.
     */
    public function up(): void
    {
        Schema::create('billing_threshold_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lower_threshold_centavos');
            $table->unsignedBigInteger('upper_threshold_centavos');
            $table->timestamps();
        });

        Schema::create('billing_threshold_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('changed_by_user_id')->constrained('users');
            $table->unsignedBigInteger('old_lower_threshold_centavos');
            $table->unsignedBigInteger('old_upper_threshold_centavos');
            $table->unsignedBigInteger('new_lower_threshold_centavos');
            $table->unsignedBigInteger('new_upper_threshold_centavos');
            $table->timestamps();
        });

        DB::table('billing_threshold_settings')->insert([
            'lower_threshold_centavos' => 20_000_000,
            'upper_threshold_centavos' => 100_000_000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_threshold_changes');
        Schema::dropIfExists('billing_threshold_settings');
    }
};
