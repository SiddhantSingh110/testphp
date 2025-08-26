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
            // OCR processing status
            $table->enum('ocr_status', ['pending', 'processing', 'completed', 'failed', 'not_required'])
                  ->default('not_required')
                  ->after('type');
            
            // OCR confidence score (0-100)
            $table->integer('ocr_confidence')->nullable()->after('ocr_status');
            
            // Processing attempts counter for retry logic
            $table->integer('processing_attempts')->default(0)->after('ocr_confidence');
            
            // Original high-quality file path for OCR processing
            $table->string('original_file_path')->nullable()->after('processing_attempts');
            
            // Compressed file path for final storage
            $table->string('compressed_file_path')->nullable()->after('original_file_path');
            
            // Add index for OCR status queries
            $table->index(['ocr_status', 'processing_attempts']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patient_reports', function (Blueprint $table) {
            $table->dropIndex(['ocr_status', 'processing_attempts']);
            $table->dropColumn([
                'ocr_status', 
                'ocr_confidence', 
                'processing_attempts', 
                'original_file_path', 
                'compressed_file_path'
            ]);
        });
    }
};