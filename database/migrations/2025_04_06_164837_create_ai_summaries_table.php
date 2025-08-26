<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('ai_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('patient_reports')->onDelete('cascade');
            $table->longText('raw_text');
            $table->json('summary_json');
            $table->integer('confidence_score'); // in percentage (0–100)
            $table->string('ai_model_used')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('ai_summaries');
    }
};
