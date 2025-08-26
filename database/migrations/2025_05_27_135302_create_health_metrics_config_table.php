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
        Schema::create('health_metrics_config', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique()->comment('Configuration key (e.g., primary_provider, providers.deepseek.enabled)');
            $table->text('value')->comment('Configuration value (JSON for complex values)');
            $table->timestamp('updated_at')->useCurrent()->comment('When this configuration was last updated');
            
            // Indexes for better performance
            $table->index('key');
            $table->index('updated_at');
        });
        
        // Add some initial configuration if needed
        DB::table('health_metrics_config')->insert([
            [
                'key' => 'primary_provider',
                'value' => 'deepseek',
                'updated_at' => now()
            ],
            [
                'key' => 'fallback_enabled',
                'value' => 'true',
                'updated_at' => now()
            ]
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('health_metrics_config');
    }
};