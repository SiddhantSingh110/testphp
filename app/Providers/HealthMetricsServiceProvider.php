<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\HealthMetricsExtraction\HealthMetricsExtractionService;
use App\Services\HealthMetricsExtraction\Configuration\ProviderConfigManager;
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

        // Register Main Service as Singleton
        $this->app->singleton(HealthMetricsExtractionService::class, function ($app) {
            return new HealthMetricsExtractionService(
                $app->make(ProviderConfigManager::class),
                $app->make(DeepseekExtractionProvider::class),
                $app->make(ClaudeExtractionProvider::class),
                $app->make(OpenAIExtractionProvider::class)
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
            ]);
        }
    }
}