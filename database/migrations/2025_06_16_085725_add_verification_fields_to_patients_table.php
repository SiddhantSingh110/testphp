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
            $table->timestamp('phone_verified_at')->nullable()->after('password');
            $table->timestamp('email_verified_at')->nullable()->after('phone_verified_at');
            $table->boolean('whatsapp_permission')->default(false)->after('email_verified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn(['phone_verified_at', 'email_verified_at', 'whatsapp_permission']);
        });
    }
};