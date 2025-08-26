<?php

namespace App\Services\HealthMetricsExtraction\Configuration;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use App\Services\HealthMetricsExtraction\Exceptions\ConfigurationException;

class ProviderConfigManager
{
    protected string $configTable = 'health_metrics_config';
    protected string $cachePrefix = 'health_metrics_config';
    protected int $cacheTtl = 3600; // 1 hour
    
    protected array $defaultConfig;
    protected array $runtimeConfig = [];

    public function __construct()
    {
        $this->defaultConfig = config('health_metrics', []);
        $this->configTable = $this->defaultConfig['runtime_config']['table'] ?? 'health_metrics_config';
        $this->cacheTtl = $this->defaultConfig['runtime_config']['cache_ttl'] ?? 3600;
        
        $this->ensureConfigTableExists();
        $this->loadRuntimeConfig();
    }

    /**
     * Get the current primary provider - ENV file takes precedence
     */
    public function getPrimaryProvider(): string
    {
        // ENV file override takes highest precedence
        if ($this->defaultConfig['runtime_config']['env_override'] ?? true) {
            return $this->defaultConfig['primary_provider'] ?? 'deepseek';
        }
        
        // Fallback to runtime config if ENV override is disabled
        return $this->getRuntimeConfig('primary_provider') 
            ?? $this->defaultConfig['primary_provider'] 
            ?? 'deepseek';
    }

    /**
     * Get the secondary provider from ENV configuration
     */
    public function getSecondaryProvider(): ?string
    {
        // ENV file override takes highest precedence
        if ($this->defaultConfig['runtime_config']['env_override'] ?? true) {
            return $this->defaultConfig['secondary_provider'] ?? null;
        }
        
        // Fallback to runtime config if ENV override is disabled
        return $this->getRuntimeConfig('secondary_provider') ?? null;
    }

    /**
     * Set the primary provider (runtime configuration)
     */
    public function setPrimaryProvider(string $provider): void
    {
        $this->validateProvider($provider);
        
        $this->setRuntimeConfig('primary_provider', $provider);
        
        Log::info("Primary provider changed to: {$provider}", [
            'previous_provider' => $this->getPrimaryProvider(),
            'new_provider' => $provider,
            'changed_by' => auth()->id() ?? 'system'
        ]);
    }

    /**
     * Get provider configuration by name
     */
    public function getProviderConfig(string $provider): array
    {
        $this->validateProvider($provider);
        
        $defaultProviderConfig = $this->defaultConfig['providers'][$provider] ?? [];
        $runtimeProviderConfig = $this->getRuntimeConfig("providers.{$provider}") ?? [];
        
        return array_merge($defaultProviderConfig, $runtimeProviderConfig);
    }

    /**
     * Get all available providers sorted by priority
     */
    public function getAvailableProviders(): array
    {
        $providers = [];
        
        foreach ($this->defaultConfig['providers'] ?? [] as $name => $config) {
            if ($config['enabled'] ?? false) {
                $runtimeConfig = $this->getRuntimeConfig("providers.{$name}") ?? [];
                $fullConfig = array_merge($config, $runtimeConfig);
                
                // Check if provider has required configuration
                if ($this->isProviderConfigured($name, $fullConfig)) {
                    $providers[$name] = $fullConfig;
                }
            }
        }
        
        // Sort by priority
        uasort($providers, function($a, $b) {
            return ($a['priority'] ?? 99) <=> ($b['priority'] ?? 99);
        });
        
        return $providers;
    }

    /**
     * Get providers in fallback order, with ENV-based primary and secondary first
     */
    public function getProvidersInFallbackOrder(): array
    {
        $providers = $this->getAvailableProviders();
        $primaryProvider = $this->getPrimaryProvider();
        $secondaryProvider = $this->getSecondaryProvider();
        
        $ordered = [];
        
        // Add primary provider first if it exists and is available
        if (isset($providers[$primaryProvider])) {
            $ordered[$primaryProvider] = $providers[$primaryProvider];
            unset($providers[$primaryProvider]);
        }
        
        // Add secondary provider second if it exists and is available
        if ($secondaryProvider && isset($providers[$secondaryProvider])) {
            $ordered[$secondaryProvider] = $providers[$secondaryProvider];
            unset($providers[$secondaryProvider]);
        }
        
        // Add remaining providers in priority order
        foreach ($providers as $name => $config) {
            $ordered[$name] = $config;
        }
        
        return $ordered;
    }

    /**
     * Check if fallback is enabled
     */
    public function isFallbackEnabled(): bool
    {
        return $this->getRuntimeConfig('fallback_enabled') 
            ?? $this->defaultConfig['fallback_enabled'] 
            ?? true;
    }

    /**
     * Set fallback enabled/disabled
     */
    public function setFallbackEnabled(bool $enabled): void
    {
        $this->setRuntimeConfig('fallback_enabled', $enabled);
        
        Log::info("Fallback " . ($enabled ? 'enabled' : 'disabled'), [
            'changed_by' => auth()->id() ?? 'system'
        ]);
    }

    /**
     * Check if we should stop on first success
     */
    public function shouldStopOnFirstSuccess(): bool
    {
        return $this->getRuntimeConfig('stop_on_first_success') 
            ?? $this->defaultConfig['stop_on_first_success'] 
            ?? true;
    }

    /**
     * Enable or disable a specific provider
     */
    public function setProviderEnabled(string $provider, bool $enabled): void
    {
        $this->validateProvider($provider);
        
        $this->setRuntimeConfig("providers.{$provider}.enabled", $enabled);
        
        Log::info("Provider {$provider} " . ($enabled ? 'enabled' : 'disabled'), [
            'changed_by' => auth()->id() ?? 'system'
        ]);
    }

    /**
     * Update provider configuration
     */
    public function updateProviderConfig(string $provider, array $config): void
    {
        $this->validateProvider($provider);
        
        // Merge with existing runtime config
        $currentConfig = $this->getRuntimeConfig("providers.{$provider}") ?? [];
        $newConfig = array_merge($currentConfig, $config);
        
        $this->setRuntimeConfig("providers.{$provider}", $newConfig);
        
        Log::info("Provider {$provider} configuration updated", [
            'updated_fields' => array_keys($config),
            'changed_by' => auth()->id() ?? 'system'
        ]);
    }

    /**
     * Get current configuration summary for admin display
     */
    public function getConfigurationSummary(): array
    {
        $providers = $this->getAvailableProviders();
        
        return [
            'primary_provider' => $this->getPrimaryProvider(),
            'secondary_provider' => $this->getSecondaryProvider(),
            'fallback_enabled' => $this->isFallbackEnabled(),
            'stop_on_first_success' => $this->shouldStopOnFirstSuccess(),
            'available_providers' => array_keys($providers),
            'provider_count' => count($providers),
            'configuration_source' => ($this->defaultConfig['runtime_config']['env_override'] ?? true) ? 'ENV File' : 'Database',
            'env_override_enabled' => $this->defaultConfig['runtime_config']['env_override'] ?? true,
            'provider_details' => array_map(function($config) {
                return [
                    'enabled' => $config['enabled'] ?? false,
                    'priority' => $config['priority'] ?? 99,
                    'model' => $config['model'] ?? 'unknown',
                    'has_api_key' => !empty($config['api_key']),
                    'timeout' => $config['timeout'] ?? 30,
                    'max_retries' => $config['max_retries'] ?? 3,
                ];
            }, $providers),
            'last_updated' => $this->getLastConfigUpdate(),
        ];
    }

    /**
     * Reset all runtime configuration to defaults
     */
    public function resetToDefaults(): void
    {
        if ($this->isRuntimeConfigEnabled()) {
            DB::table($this->configTable)->truncate();
        }
        
        Cache::forget($this->getCacheKey());
        $this->runtimeConfig = [];
        
        Log::info("Health metrics configuration reset to defaults", [
            'changed_by' => auth()->id() ?? 'system'
        ]);
    }

    /**
     * Check if runtime configuration is enabled
     */
    protected function isRuntimeConfigEnabled(): bool
    {
        return $this->defaultConfig['runtime_config']['enabled'] ?? true;
    }

    /**
     * Get cache key for runtime configuration
     */
    protected function getCacheKey(): string
    {
        return $this->cachePrefix . ':runtime_config';
    }

    /**
     * Get timestamp of last configuration update
     */
    protected function getLastConfigUpdate(): ?string
    {
        if (!$this->isRuntimeConfigEnabled()) {
            return null;
        }

        try {
            $lastUpdate = DB::table($this->configTable)
                ->max('updated_at');
            
            return $lastUpdate;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Create the runtime configuration table if it doesn't exist
     */
    public function ensureConfigTableExists(): void
    {
        if (!$this->isRuntimeConfigEnabled()) {
            return;
        }

        try {
            if (!Schema::hasTable($this->configTable)) {
                Schema::create($this->configTable, function($table) {
                    $table->id();
                    $table->string('key')->unique();
                    $table->text('value');
                    $table->timestamp('updated_at')->useCurrent();
                    $table->index('key');
                });
                
                Log::info("Created health metrics configuration table: {$this->configTable}");
            }
        } catch (\Exception $e) {
            Log::error("Failed to create configuration table", [
                'table' => $this->configTable,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Load runtime configuration from database
     */
    protected function loadRuntimeConfig(): void
    {
        if (!$this->isRuntimeConfigEnabled()) {
            return;
        }

        $cacheKey = $this->getCacheKey();
        $this->runtimeConfig = Cache::remember($cacheKey, $this->cacheTtl, function() {
            try {
                $configs = DB::table($this->configTable)
                    ->pluck('value', 'key')
                    ->toArray();
                
                // Convert JSON values back to arrays/objects
                return array_map(function($value) {
                    $decoded = json_decode($value, true);
                    return $decoded !== null ? $decoded : $value;
                }, $configs);
            } catch (\Exception $e) {
                Log::warning("Failed to load runtime config from database", [
                    'error' => $e->getMessage()
                ]);
                return [];
            }
        });
    }

    /**
     * Get a runtime configuration value
     */
    protected function getRuntimeConfig(string $key, $default = null)
    {
        return data_get($this->runtimeConfig, $key, $default);
    }

    /**
     * Set a runtime configuration value
     */
    protected function setRuntimeConfig(string $key, $value): void
    {
        if (!$this->isRuntimeConfigEnabled()) {
            return;
        }

        try {
            // Store in database
            DB::table($this->configTable)->updateOrInsert(
                ['key' => $key],
                [
                    'value' => is_array($value) ? json_encode($value) : $value,
                    'updated_at' => now()
                ]
            );

            // Update cache
            data_set($this->runtimeConfig, $key, $value);
            Cache::put($this->getCacheKey(), $this->runtimeConfig, $this->cacheTtl);

        } catch (\Exception $e) {
            Log::error("Failed to save runtime config to database", [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Validate that a provider exists in configuration
     */
    protected function validateProvider(string $provider): void
    {
        if (!isset($this->defaultConfig['providers'][$provider])) {
            throw new ConfigurationException("Unknown provider: {$provider}");
        }
    }

    /**
     * Check if a provider is properly configured
     */
    protected function isProviderConfigured(string $provider, array $config): bool
    {
        // Check for required fields
        if (empty($config['api_key'])) {
            return false;
        }
        
        // Provider-specific checks
        switch ($provider) {
            case 'claude':
                return !empty($config['base_url']) && !empty($config['model']);
            case 'deepseek':
                return !empty($config['base_url']) && !empty($config['model']);
            case 'openai':
                return !empty($config['model']);
            default:
                return true;
        }
    }
}