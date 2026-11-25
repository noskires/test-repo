<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Email sign-in (issue 44): a user given a temporary password (by an admin or the first-admin
     * command) must change it before using PSS. The email column keeps its unique index and stays nullable in the
     * database, so a row added before email sign-in keeps no email and can't sign in until an admin gives it one;
     * every path that creates a user requires one.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('password');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
