<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Issue 13: e_calls.pm_task_id now references pm_tasks. Issue 12 stored the column
     * unchecked, so any reference to a PM Task that does not exist is cleared to null first (it never pointed
     * at anything); valid references are kept.
     */
    public function up(): void
    {
        DB::table('e_calls')
            ->whereNotNull('pm_task_id')
            ->whereNotIn('pm_task_id', DB::table('pm_tasks')->select('id'))
            ->update(['pm_task_id' => null]);

        Schema::table('e_calls', function (Blueprint $table) {
            $table->foreign('pm_task_id')->references('id')->on('pm_tasks');
        });
    }

    /**
     * Reverse the migrations. The cleared orphan references are not restored.
     */
    public function down(): void
    {
        Schema::table('e_calls', function (Blueprint $table) {
            $table->dropForeign(['pm_task_id']);
        });
    }
};
