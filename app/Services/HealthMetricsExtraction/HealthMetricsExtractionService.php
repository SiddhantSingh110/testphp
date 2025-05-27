<?php

namespace App\Services\HealthMetricsExtraction;

use App\Services\HealthMetricsExtraction\Configuration\ProviderConfigManager;
use App\Services\HealthMetricsExtraction\Providers\DeepseekExtractionProvider;
use App\Services\HealthMetricsExtraction\Providers\ClaudeExtractionProvider;
use App\Services\HealthMetricsExtraction\Providers\OpenAIExtractionProvider;
use App\Services\HealthMetricsExtraction\Configuration\ProviderConfig;
use App\Services\HealthMetricsExtraction\Exceptions\ExtractionException;
use App\Services\HealthMetricsExtraction\Exceptions\ProviderException;
use App\Services\HealthMetricsExtraction\Exceptions\ConfigurationException;
use App\Models\HealthMetric;
use App\Models\PatientReport;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class HealthMetricsExtractionService
{
    protected ProviderConfigManager $configManager;
    protected array $providers = [];
    protected array $providerPerformance = [];

    public function __construct(ProviderConfigManager $configManager = null)
    {
        $this->configManager = $configManager ?? new ProviderConfigManager();
        $this->initializeProviders();
    }

    /**
     * Extract health metrics from raw medical text with multi-provider fallback
     *
     * @param string $rawText Medical report text
     * @param array $aiSummaryJson Optional AI summary JSON
     * @param int $patientId Patient ID
     * @param PatientReport $report Report model instance
     * @return array Extraction results with metrics and metadata
     */
    public function extractMetrics(string $rawText, ?array $aiSummaryJson, int $patientId, PatientReport $report): array
    {
        $startTime = microtime(true);
        $context = $this->buildExtractionContext($aiSummaryJson, $patientId, $report);
        
        Log::info("Starting multi-provider health metrics extraction", [
            'report_id' => $report->id,
            'patient_id' => $patientId,
            'text_length' => strlen($rawText),
            'primary_provider' => $this->configManager->getPrimaryProvider(),
            'fallback_enabled' => $this->configManager->isFallbackEnabled()
        ]);

        $providersToTry = $this->getProvidersToTry();
        $extractionResults = [];
        $lastException = null;

        foreach ($providersToTry as $providerName => $providerConfig) {
            try {
                $provider = $this->getProvider($providerName);
                
                if (!$provider->isAvailable()) {
                    Log::warning("Provider {$providerName} is not available, skipping", [
                        'report_id' => $report->id
                    ]);
                    continue;
                }

                $providerStartTime = microtime(true);
                
                // Attempt extraction with this provider
                $aiResponse = $provider->extractMetrics($rawText, $context);
                
                $providerDuration = round((microtime(true) - $providerStartTime) * 1000, 2);
                
                // Process extracted metrics
                $extractedMetrics = $this->processAIResponse($aiResponse, $patientId, $report);
                
                // Record successful extraction
                $this->recordProviderPerformance($providerName, true, $providerDuration);
                
                $totalDuration = round((microtime(true) - $startTime) * 1000, 2);
                
                Log::info("Health metrics extraction successful", [
                    'report_id' => $report->id,
                    'provider_used' => $providerName,
                    'metrics_extracted' => count($extractedMetrics['metrics']),
                    'categories_found' => count($extractedMetrics['categories_found']),
                    'provider_duration_ms' => $providerDuration,
                    'total_duration_ms' => $totalDuration,
                    'confidence_score' => $aiResponse['confidence_score'] ?? 'N/A'
                ]);

                return [
                    'success' => true,
                    'provider_used' => $providerName,
                    'model_used' => $provider->getModel(),
                    'metrics' => $extractedMetrics['metrics'],
                    'categories_found' => $extractedMetrics['categories_found'],
                    'ai_response' => $aiResponse,
                    'duration_ms' => $totalDuration,
                    'provider_duration_ms' => $providerDuration,
                    'attempts_made' => count($extractionResults) + 1
                ];

            } catch (ProviderException $e) {
                $providerDuration = round((microtime(true) - $providerStartTime) * 1000, 2);
                $this->recordProviderPerformance($providerName, false, $providerDuration, $e->getMessage());
                
                $lastException = $e;
                $extractionResults[] = [
                    'provider' => $providerName,
                    'success' => false,
                    'error' => $e->getMessage(),
                    'retryable' => $e->isRetryable()
                ];

                Log::warning("Provider {$providerName} failed", [
                    'report_id' => $report->id,
                    'error' => $e->getMessage(),
                    'retryable' => $e->isRetryable(),
                    'duration_ms' => $providerDuration
                ]);

                // If fallback is disabled or this was the last provider, break
                if (!$this->configManager->isFallbackEnabled() || 
                    $this->configManager->shouldStopOnFirstSuccess()) {
                    break;
                }

                continue;

            } catch (\Exception $e) {
                $providerDuration = round((microtime(true) - $providerStartTime) * 1000, 2);
                $this->recordProviderPerformance($providerName, false, $providerDuration, $e->getMessage());
                
                $lastException = $e;
                $extractionResults[] = [
                    'provider' => $providerName,
                    'success' => false,
                    'error' => $e->getMessage(),
                    'retryable' => true
                ];

                Log::error("Unexpected error with provider {$providerName}", [
                    'report_id' => $report->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);

                if (!$this->configManager->isFallbackEnabled()) {
                    break;
                }

                continue;
            }
        }

        // All providers failed
        $totalDuration = round((microtime(true) - $startTime) * 1000, 2);
        
        Log::error("All providers failed for health metrics extraction", [
            'report_id' => $report->id,
            'patient_id' => $patientId,
            'providers_tried' => array_column($extractionResults, 'provider'),
            'total_duration_ms' => $totalDuration,
            'last_error' => $lastException?->getMessage()
        ]);

        // Return fallback response
        return [
            'success' => false,
            'provider_used' => null,
            'model_used' => null,
            'metrics' => [],
            'categories_found' => [],
            'ai_response' => null,
            'duration_ms' => $totalDuration,
            'attempts_made' => count($extractionResults),
            'extraction_attempts' => $extractionResults,
            'error' => $lastException?->getMessage() ?? 'All providers failed'
        ];
    }

    /**
     * Process AI response and create health metrics
     */
    protected function processAIResponse(array $aiResponse, int $patientId, PatientReport $report): array
    {
        $extractedMetrics = [];
        $categoriesFound = [];

        if (!isset($aiResponse['key_findings']) || !is_array($aiResponse['key_findings'])) {
            Log::warning('No key_findings in AI response', ['report_id' => $report->id]);
            return ['metrics' => [], 'categories_found' => []];
        }

        foreach ($aiResponse['key_findings'] as $finding) {
            try {
                // Parse finding (string or array)
                $parsedMetric = $this->parseFinding($finding);
                if (!$parsedMetric) {
                    continue;
                }

                // Map to standardized metric type
                $standardizedType = $this->mapToStandardMetricType($parsedMetric['raw_name']);
                if (!$standardizedType) {
                    Log::info('Could not map metric to standard type', [
                        'raw_name' => $parsedMetric['raw_name'],
                        'report_id' => $report->id
                    ]);
                    continue;
                }

                // Create health metric record
                $metric = HealthMetric::create([
                    'patient_id' => $patientId,
                    'type' => $standardizedType['type'],
                    'value' => $parsedMetric['value'],
                    'unit' => $parsedMetric['unit'] ?: $standardizedType['default_unit'],
                    'measured_at' => $report->report_date ?? now(),
                    'notes' => "Auto-extracted from medical report (ID: {$report->id})",
                    'source' => 'report',
                    'context' => 'medical_test',
                    'status' => $this->mapAIStatusToHealthStatus($parsedMetric['status']),
                    'category' => $standardizedType['category'],
                    'subcategory' => $standardizedType['subcategory'],
                ]);

                $extractedMetrics[] = $metric;
                $categoriesFound[] = $standardizedType['category'];

                Log::debug('Health metric created from AI finding', [
                    'report_id' => $report->id,
                    'raw_name' => $parsedMetric['raw_name'],
                    'standardized_type' => $standardizedType['type'],
                    'value' => $parsedMetric['value'],
                    'category' => $standardizedType['category']
                ]);

            } catch (\Exception $e) {
                Log::error('Failed to process AI finding', [
                    'finding' => $finding,
                    'error' => $e->getMessage(),
                    'report_id' => $report->id
                ]);
                continue;
            }
        }

        return [
            'metrics' => $extractedMetrics,
            'categories_found' => array_unique($categoriesFound)
        ];
    }

    /**
     * Parse individual finding from AI response
     */
    protected function parseFinding($finding): ?array
    {
        if (is_string($finding)) {
            return $this->parseStringFinding($finding);
        } elseif (is_array($finding)) {
            return $this->parseArrayFinding($finding);
        }
        
        return null;
    }

    /**
     * Parse string-based finding
     */
    protected function parseStringFinding(string $finding): ?array
    {
        // Pattern 1: "Status level of Parameter Value Unit"
        if (preg_match('/^(normal|elevated|high|low|decreased|increased|borderline|slightly)\s+level\s+of\s+(.+?)\s+([\d\.]+)\s*([a-zA-Z\/\%µ°]+)?/i', $finding, $matches)) {
            return [
                'raw_name' => trim($matches[2]),
                'value' => $matches[3],
                'unit' => $matches[4] ?? '',
                'status' => strtolower($matches[1])
            ];
        }

        // Pattern 2: "Parameter: Value Unit"
        if (preg_match('/^(.+?):\s*([\d\.]+)\s*([a-zA-Z\/\%µ°]+)?/i', $finding, $matches)) {
            return [
                'raw_name' => trim($matches[1]),
                'value' => $matches[2],
                'unit' => $matches[3] ?? '',
                'status' => 'unknown'
            ];
        }

        // Pattern 3: "Parameter Value Unit (status)"
        if (preg_match('/^(.+?)\s+([\d\.]+)\s*([a-zA-Z\/\%µ°]+)?\s*\((normal|high|low|elevated|decreased)\)/i', $finding, $matches)) {
            return [
                'raw_name' => trim($matches[1]),
                'value' => $matches[2],
                'unit' => $matches[3] ?? '',
                'status' => strtolower($matches[4])
            ];
        }

        return null;
    }

    /**
     * Parse array-based finding
     */
    protected function parseArrayFinding(array $finding): ?array
    {
        if (!isset($finding['finding']) || !isset($finding['value'])) {
            return null;
        }

        return [
            'raw_name' => $finding['finding'],
            'value' => $finding['value'],
            'unit' => $finding['unit'] ?? '',
            'status' => $finding['status'] ?? 'unknown'
        ];
    }

    /**
     * Map raw parameter name to standardized health metric type
     * This uses the same comprehensive mapping from your ReportController
     */
    protected function mapToStandardMetricType(string $rawName): ?array
    {
        $rawName = strtolower(trim($rawName));
        
        // Use the same comprehensive mapping from your existing ReportController
        $mappings = [
            // Cholesterol Panel
            'hdl cholesterol' => ['type' => 'hdl', 'category' => 'organs', 'subcategory' => 'heart', 'default_unit' => 'mg/dL'],
            'hdl' => ['type' => 'hdl', 'category' => 'organs', 'subcategory' => 'heart', 'default_unit' => 'mg/dL'],
            'ldl cholesterol' => ['type' => 'ldl', 'category' => 'organs', 'subcategory' => 'heart', 'default_unit' => 'mg/dL'],
            'ldl' => ['type' => 'ldl', 'category' => 'organs', 'subcategory' => 'heart', 'default_unit' => 'mg/dL'],
            'total cholesterol' => ['type' => 'total_cholesterol', 'category' => 'organs', 'subcategory' => 'heart', 'default_unit' => 'mg/dL'],
            'cholesterol' => ['type' => 'total_cholesterol', 'category' => 'organs', 'subcategory' => 'heart', 'default_unit' => 'mg/dL'],
            'triglycerides' => ['type' => 'triglycerides', 'category' => 'organs', 'subcategory' => 'heart', 'default_unit' => 'mg/dL'],

            // Thyroid Panel
            'tsh' => ['type' => 'tsh', 'category' => 'organs', 'subcategory' => 'thyroid', 'default_unit' => 'mIU/L'],
            'thyroid stimulating hormone' => ['type' => 'tsh', 'category' => 'organs', 'subcategory' => 'thyroid', 'default_unit' => 'mIU/L'],
            't3' => ['type' => 't3', 'category' => 'organs', 'subcategory' => 'thyroid', 'default_unit' => 'ng/dL'],
            't4' => ['type' => 't4', 'category' => 'organs', 'subcategory' => 'thyroid', 'default_unit' => 'μg/dL'],

            // Vitamins
            'vitamin d' => ['type' => 'vitamin_d', 'category' => 'vitamins', 'subcategory' => null, 'default_unit' => 'ng/mL'],
            'vitamin b12' => ['type' => 'vitamin_b12', 'category' => 'vitamins', 'subcategory' => null, 'default_unit' => 'pg/mL'],
            'folate' => ['type' => 'folate', 'category' => 'vitamins', 'subcategory' => null, 'default_unit' => 'ng/mL'],

            // Liver Function
            'alt' => ['type' => 'alt', 'category' => 'organs', 'subcategory' => 'liver', 'default_unit' => 'U/L'],
            'ast' => ['type' => 'ast', 'category' => 'organs', 'subcategory' => 'liver', 'default_unit' => 'U/L'],
            'bilirubin' => ['type' => 'bilirubin', 'category' => 'organs', 'subcategory' => 'liver', 'default_unit' => 'mg/dL'],

            // Kidney Function
            'creatinine' => ['type' => 'creatinine', 'category' => 'organs', 'subcategory' => 'kidney', 'default_unit' => 'mg/dL'],
            'blood urea nitrogen' => ['type' => 'blood_urea_nitrogen', 'category' => 'organs', 'subcategory' => 'kidney', 'default_unit' => 'mg/dL'],
            'uric acid' => ['type' => 'uric_acid', 'category' => 'organs', 'subcategory' => 'kidney', 'default_unit' => 'mg/dL'],

            // Blood Count
            'hemoglobin' => ['type' => 'hemoglobin', 'category' => 'blood', 'subcategory' => null, 'default_unit' => 'g/dL'],
            'hematocrit' => ['type' => 'hematocrit', 'category' => 'blood', 'subcategory' => null, 'default_unit' => '%'],
            'glucose' => ['type' => 'glucose_fasting', 'category' => 'blood', 'subcategory' => null, 'default_unit' => 'mg/dL'],
            'blood sugar' => ['type' => 'glucose_fasting', 'category' => 'blood', 'subcategory' => null, 'default_unit' => 'mg/dL'],
        ];

        // Direct match
        if (isset($mappings[$rawName])) {
            return $mappings[$rawName];
        }

        // Fuzzy matching
        foreach ($mappings as $key => $mapping) {
            if (strpos($rawName, $key) !== false || strpos($key, $rawName) !== false) {
                return $mapping;
            }
        }

        return null;
    }

    /**
     * Map AI status to health metric status
     */
    protected function mapAIStatusToHealthStatus(string $aiStatus): string
    {
        $statusMap = [
            'normal' => 'normal',
            'elevated' => 'high',
            'high' => 'high',
            'low' => 'high',
            'decreased' => 'high',
            'increased' => 'high',
            'borderline' => 'borderline',
            'slightly' => 'borderline',
            'mild' => 'borderline',
            'unknown' => 'normal'
        ];

        return $statusMap[strtolower($aiStatus)] ?? 'normal';
    }

    /**
     * Build extraction context from available data
     */
    protected function buildExtractionContext(?array $aiSummaryJson, int $patientId, PatientReport $report): array
    {
        return [
            'report_id' => $report->id,
            'patient_id' => $patientId,
            'report_type' => $report->type,
            'report_date' => $report->report_date?->toDateString(),
            'uploaded_by' => $report->uploaded_by,
            'existing_ai_summary' => $aiSummaryJson,
            'has_ocr_data' => $report->type === 'image' && $report->ocr_status === 'completed'
        ];
    }

    /**
     * Get providers to try in order
     */
    protected function getProvidersToTry(): array
    {
        return $this->configManager->getProvidersInFallbackOrder();
    }

    /**
     * Initialize all providers
     */
    protected function initializeProviders(): void
    {
        $availableProviders = $this->configManager->getAvailableProviders();
        
        foreach ($availableProviders as $name => $config) {
            try {
                $provider = $this->createProvider($name, $config);
                if ($provider && $provider->isAvailable()) {
                    $this->providers[$name] = $provider;
                }
            } catch (\Exception $e) {
                Log::warning("Failed to initialize provider {$name}", [
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        Log::info("Initialized health metrics extraction providers", [
            'available_providers' => array_keys($this->providers),
            'primary_provider' => $this->configManager->getPrimaryProvider()
        ]);
    }

    /**
     * Create provider instance
     */
    protected function createProvider(string $name, array $config)
    {
        switch ($name) {
            case 'deepseek':
                return new DeepseekExtractionProvider($config);
            case 'claude':
                return new ClaudeExtractionProvider($config);
            case 'openai':
                return new OpenAIExtractionProvider($config);
            default:
                throw new ConfigurationException("Unknown provider: {$name}");
        }
    }

    /**
     * Get provider instance
     */
    protected function getProvider(string $name)
    {
        if (!isset($this->providers[$name])) {
            throw new ConfigurationException("Provider {$name} is not available");
        }
        
        return $this->providers[$name];
    }

    /**
     * Record provider performance for monitoring
     */
    protected function recordProviderPerformance(string $provider, bool $success, float $duration, ?string $error = null): void
    {
        if (!config('health_metrics.performance.log_provider_performance', true)) {
            return;
        }

        $performanceData = [
            'provider' => $provider,
            'success' => $success,
            'duration_ms' => $duration,
            'timestamp' => now()->toISOString(),
            'error' => $error
        ];

        // Store in cache for recent performance tracking
        $cacheKey = "health_metrics_performance:{$provider}:" . now()->format('Y-m-d-H');
        $existing = Cache::get($cacheKey, []);
        $existing[] = $performanceData;
        Cache::put($cacheKey, $existing, 3600); // Store for 1 hour

        Log::info("Provider performance recorded", $performanceData);
    }

    /**
     * Get provider performance statistics
     */
    public function getProviderPerformance(string $provider = null, int $hours = 24): array
    {
        $stats = [];
        $startTime = now()->subHours($hours);
        
        $providers = $provider ? [$provider] : array_keys($this->providers);
        
        foreach ($providers as $providerName) {
            $hourlyStats = [];
            
            for ($hour = 0; $hour < $hours; $hour++) {
                $time = $startTime->copy()->addHours($hour);
                $cacheKey = "health_metrics_performance:{$providerName}:" . $time->format('Y-m-d-H');
                $data = Cache::get($cacheKey, []);
                
                if (!empty($data)) {
                    $successful = collect($data)->where('success', true)->count();
                    $total = count($data);
                    $avgDuration = collect($data)->avg('duration_ms');
                    
                    $hourlyStats[] = [
                        'hour' => $time->format('Y-m-d H:00'),
                        'total_requests' => $total,
                        'successful_requests' => $successful,
                        'success_rate' => $total > 0 ? round(($successful / $total) * 100, 2) : 0,
                        'average_duration_ms' => round($avgDuration, 2)
                    ];
                }
            }
            
            $stats[$providerName] = $hourlyStats;
        }
        
        return $stats;
    }

    /**
     * Get service health status
     */
    public function getServiceHealth(): array
    {
        $providers = [];
        
        foreach ($this->providers as $name => $provider) {
            $providers[$name] = [
                'available' => $provider->isAvailable(),
                'model' => $provider->getModel(),
                'config' => $provider->getConfig(),
            ];
        }
        
        return [
            'service_status' => 'operational',
            'primary_provider' => $this->configManager->getPrimaryProvider(),
            'fallback_enabled' => $this->configManager->isFallbackEnabled(),
            'providers' => $providers,
            'total_providers' => count($this->providers),
            'configuration_summary' => $this->configManager->getConfigurationSummary()
        ];
    }
    /**
 * Extract metrics using a specific provider (for admin switching)
 */
    public function extractMetricsWithSpecificProvider(string $rawText, array $context = [], string $providerName = null): array
    {
        try {
            $this->validateInput($rawText);
            
            // Get the specific provider
            $provider = match(strtolower($providerName)) {
                'deepseek' => $this->deepseekProvider,
                'claude' => $this->claudeProvider,
                'openai' => $this->openaiProvider,
                default => null
            };

            if (!$provider) {
                throw new ExtractionException("Provider '{$providerName}' not found");
            }

            return $provider->extractMetrics($rawText, $context);

        } catch (\Exception $e) {
            throw new ExtractionException("Extraction failed: " . $e->getMessage());
        }
    }
}