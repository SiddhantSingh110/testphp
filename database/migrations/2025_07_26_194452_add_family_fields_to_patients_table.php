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
            // Family relationship fields
            $table->unsignedBigInteger('primary_account_id')->nullable()->after('auth_method');
            $table->string('relationship', 50)->nullable()->after('primary_account_id');
            $table->boolean('is_primary_account')->default(true)->after('relationship');
            $table->boolean('profile_lock_status')->default(true)->after('is_primary_account');
            $table->boolean('managed_by_primary')->default(false)->after('profile_lock_status');
            
            // Add foreign key constraint
            $table->foreign('primary_account_id')->references('id')->on('patients')->onDelete('cascade');
            
            // Add indexes for performance
            $table->index('primary_account_id');
            $table->index('profile_lock_status');
            $table->index(['is_primary_account', 'managed_by_primary']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            // Drop foreign key first
            $table->dropForeign(['primary_account_id']);
            
            // Drop indexes
            $table->dropIndex(['primary_account_id']);
            $table->dropIndex(['profile_lock_status']);
            $table->dropIndex(['is_primary_account', 'managed_by_primary']);
            
            // Drop columns
            $table->dropColumn([
                'primary_account_id',
                'relationship', 
                'is_primary_account',
                'profile_lock_status',
                'managed_by_primary'
            ]);
        });
    }
};