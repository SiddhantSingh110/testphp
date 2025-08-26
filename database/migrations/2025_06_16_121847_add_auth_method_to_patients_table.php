<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            // Make password nullable for OTP-only users
            $table->string('password')->nullable()->change();
            
            // Add authentication method tracking
            $table->enum('auth_method', ['password', 'otp_only'])->default('otp_only')->after('password');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
            $table->dropColumn('auth_method');
        });
    }
};