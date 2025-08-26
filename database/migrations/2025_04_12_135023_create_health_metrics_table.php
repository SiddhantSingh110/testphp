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
        Schema::create('health_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->onDelete('cascade');
            $table->string('type'); // blood_pressure, heart_rate, weight, etc.
            $table->string('custom_type')->nullable(); // For custom metric types
            $table->string('value'); // Storing as string to allow complex values like "120/80"
            $table->string('unit'); // mmHg, bpm, kg, etc.
            $table->timestamp('measured_at');
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->index(['patient_id', 'type', 'measured_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('health_metrics');
    }
};