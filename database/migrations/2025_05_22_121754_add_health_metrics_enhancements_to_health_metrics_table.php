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
        Schema::table('health_metrics', function (Blueprint $table) {
            // Add source tracking
            $table->enum('source', ['manual', 'report', 'device'])->default('manual')->after('notes');
            
            // Add context for measurements
            $table->string('context', 50)->nullable()->after('source');
            
            // Add calculated status
            $table->enum('status', ['normal', 'borderline', 'high'])->nullable()->after('context');
            
            // Add category grouping
            $table->string('category', 50)->nullable()->after('status');
            
            // Add subcategory for detailed classification
            $table->string('subcategory', 50)->nullable()->after('category');
            
            // Add index for better performance
            $table->index(['patient_id', 'category', 'subcategory']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('health_metrics', function (Blueprint $table) {
            $table->dropIndex(['patient_id', 'category', 'subcategory']);
            $table->dropColumn(['source', 'context', 'status', 'category', 'subcategory']);
        });
    }
};