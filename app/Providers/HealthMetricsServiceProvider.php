<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\HealthMetricsExtraction\HealthMetricsExtractionService;
use App\Services\HealthMetricsExtraction\Configuration\ProviderConfigManager;
use App\Services\HealthMetricsExtraction\MetricMapping\StandardMetricMapper; // ✨ NEW
use App\Services\HealthMetricsExtraction\Providers\DeepseekExtractionProvider;
use App\Services\HealthMetricsExtraction\Providers\ClaudeExtractionProvider;
use App\Services\HealthMetricsExtraction\Providers\OpenAIExtractionProvider;

class HealthMetricsServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // ✨ NEW: Register StandardMetricMapper as Singleton
        $this->app->singleton(StandardMetricMapper::class, function ($app) {
            return new StandardMetricMapper();
        });

        // Register Configuration Manager as Singleton
        $this->app->singleton(ProviderConfigManager::class, function ($app) {
            return new ProviderConfigManager();
        });

        // Register Individual Providers
        $this->app->bind(DeepseekExtractionProvider::class, function ($app) {
            return new DeepseekExtractionProvider();
        });

        $this->app->bind(ClaudeExtractionProvider::class, function ($app) {
            return new ClaudeExtractionProvider();
        });

        $this->app->bind(OpenAIExtractionProvider::class, function ($app) {
            return new OpenAIExtractionProvider();
        });

        // ✨ UPDATED: Register Main Service with StandardMetricMapper dependency
        $this->app->singleton(HealthMetricsExtractionService::class, function ($app) {
            return new HealthMetricsExtractionService(
                $app->make(ProviderConfigManager::class),
                $app->make(StandardMetricMapper::class) // ✨ CHANGED: Use StandardMetricMapper instead of individual providers
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register commands if running in console
        if ($this->app->runningInConsole()) {
            $this->commands([
                // Future: Add Artisan commands here
                // \App\Console\Commands\TestHealthMetricsExtraction::class,
                // \App\Console\Commands\TestStandardMetricMapper::class,
            ]);
        }

        // ✨ NEW: Log successful registration on boot
        $this->app->booted(function () {
            if (config('health_metrics.admin.log_admin_actions', true)) {
                \Illuminate\Support\Facades\Log::info('Health Metrics services registered successfully', [
                    'services' => [
                        'StandardMetricMapper' => 'singleton',
                        'ProviderConfigManager' => 'singleton', 
                        'HealthMetricsExtractionService' => 'singleton',
                        'AI Providers' => ['deepseek', 'claude', 'openai']
                    ],
                    'timestamp' => now()->toISOString()
                ]);
            }
        });
    }
}