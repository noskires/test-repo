<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Every user gets an access state (issue 46, ADR 0005) and who set it last, and when. Existing
     * users are backfilled: those with a vendor_id become `vendor`, all others `internal` (Q9).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('access')->default('pending')->index();
            $table->foreignId('access_set_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('access_set_at')->nullable();
        });

        DB::table('users')->whereNotNull('vendor_id')->update(['access' => 'vendor']);
        DB::table('users')->whereNull('vendor_id')->update(['access' => 'internal']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['access_set_by_user_id']);
            $table->dropIndex(['access']);
            $table->dropColumn(['access', 'access_set_by_user_id', 'access_set_at']);
        });
    }
};
