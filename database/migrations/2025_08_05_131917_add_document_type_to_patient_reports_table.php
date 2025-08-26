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
        Schema::table('patient_reports', function (Blueprint $table) {
            // Add document_type column with safe default
            $table->enum('document_type', [
                'lab_report', 
                'prescription', 
                'discharge_summary', 
                'vaccine_certificate', 
                'insurance', 
                'scan', 
                'other'
            ])->default('lab_report')->after('type');
            
            // Add index for performance
            $table->index('document_type');
        });
        
        // Update existing records to maintain functionality
        DB::statement("UPDATE patient_reports SET document_type = 'lab_report' WHERE document_type IS NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patient_reports', function (Blueprint $table) {
            $table->dropIndex(['document_type']);
            $table->dropColumn('document_type');
        });
    }
};