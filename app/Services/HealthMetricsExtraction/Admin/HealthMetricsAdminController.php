<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\HealthMetricsExtraction\HealthMetricsExtractionService;
use App\Services\HealthMetricsExtraction\Configuration\ProviderConfigManager;
use App\Services\HealthMetricsExtraction\Exceptions\ConfigurationException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class HealthMetricsAdminController extends Controller
{
    protected ProviderConfigManager $configManager;
    protected HealthMetricsExtractionService $extractionService;

    public function __construct(
        ProviderConfigManager $configManager,
        HealthMetricsExtractionService $extractionService
    ) {
        $this->configManager = $configManager;
        $this->extractionService = $extractionService;
    }

    /**
     * Get current configuration status
     */
    public function getConfiguration(): JsonResponse
    {
        try {
            $configuration = $this->configManager->getConfigurationSummary();
            $serviceHealth = $this->extractionService->getServiceHealth();
            
            return response()->json([
                'success' => true,
                'configuration' => $configuration,
                'service_health' => $serviceHealth,
                'timestamp' => now()->toISOString()
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get configuration', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve configuration',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Switch primary provider
     */
    public function switchPrimaryProvider(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'primary_provider' => 'required|string|in:deepseek,claude,openai',
            'fallback_enabled' => 'sometimes|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $previousProvider = $this->configManager->getPrimaryProvider();
            $newProvider = $request->input('primary_provider');
            
            // Switch primary provider
            $this->configManager->setPrimaryProvider($newProvider);
            
            // Update fallback setting if provided
            if ($request->has('fallback_enabled')) {
                $this->configManager->setFallbackEnabled($request->boolean('fallback_enabled'));
            }
            
            Log::info('Primary provider switched via admin API', [
                'previous_provider' => $previousProvider,
                'new_provider' => $newProvider,
                'fallback_enabled' => $this->configManager->isFallbackEnabled(),
                'changed_by' => auth()->id(),
                'user_email' => auth()->user()?->email
            ]);
            
            return response()->json([
                'success' => true,
                'message' => "Primary provider switched to {$newProvider}",
                'data' => [
                    'previous_provider' => $previousProvider,
                    'new_provider' => $newProvider,
                    'fallback_enabled' => $this->configManager->isFallbackEnabled(),
                    'effective_immediately' => true
                ]
            ]);
            
        } catch (ConfigurationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Configuration error: ' . $e->getMessage()
            ], 400);
        } catch (\Exception $e) {
            Log::error('Failed to switch primary provider', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
                'requested_provider' => $request->input('primary_provider')
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to switch provider',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update provider configuration
     */
    public function updateProviderConfig(Request $request, string $provider): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'enabled' => 'sometimes|boolean',
            'timeout' => 'sometimes|integer|min:5|max:300',
            'max_retries' => 'sometimes|integer|min:1|max:10',
            'priority' => 'sometimes|integer|min:1|max:10'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $updates = $request->only(['enabled', 'timeout', 'max_retries', 'priority']);
            
            if (!empty($updates)) {
                $this->configManager->updateProviderConfig($provider, $updates);
            }
            
            Log::info("Provider {$provider} configuration updated via admin API", [
                'updates' => $updates,
                'changed_by' => auth()->id(),
                'user_email' => auth()->user()?->email
            ]);
            
            return response()->json([
                'success' => true,
                'message' => "Provider {$provider} configuration updated",
                'data' => [
                    'provider' => $provider,
                    'updates_applied' => $updates,
                    'current_config' => $this->configManager->getProviderConfig($provider)
                ]
            ]);
            
        } catch (ConfigurationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Configuration error: ' . $e->getMessage()
            ], 400);
        } catch (\Exception $e) {
            Log::error("Failed to update provider {$provider} configuration", [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
                'updates' => $request->all()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update provider configuration',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Enable or disable a provider
     */
    public function toggleProvider(Request $request, string $provider): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'enabled' => 'required|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $enabled = $request->boolean('enabled');
            $this->configManager->setProviderEnabled($provider, $enabled);
            
            Log::info("Provider {$provider} " . ($enabled ? 'enabled' : 'disabled') . " via admin API", [
                'provider' => $provider,
                'enabled' => $enabled,
                'changed_by' => auth()->id(),
                'user_email' => auth()->user()?->email
            ]);
            
            return response()->json([
                'success' => true,
                'message' => "Provider {$provider} " . ($enabled ? 'enabled' : 'disabled'),
                'data' => [
                    'provider' => $provider,
                    'enabled' => $enabled
                ]
            ]);
            
        } catch (ConfigurationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Configuration error: ' . $e->getMessage()
            ], 400);
        } catch (\Exception $e) {
            Log::error("Failed to toggle provider {$provider}", [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
                'requested_state' => $request->boolean('enabled')
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle provider',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get provider performance statistics
     */
    public function getProviderPerformance(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'provider' => 'sometimes|string|in:deepseek,claude,openai',
            'hours' => 'sometimes|integer|min:1|max:168' // Max 1 week
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $provider = $request->input('provider');
            $hours = $request->input('hours', 24);
            
            $performance = $this->extractionService->getProviderPerformance($provider, $hours);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'performance_stats' => $performance,
                    'time_range' => [
                        'hours' => $hours,
                        'from' => now()->subHours($hours)->toISOString(),
                        'to' => now()->toISOString()
                    ]
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to get provider performance', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve performance data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test a specific provider
     */
    public function testProvider(Request $request, string $provider): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'test_text' => 'sometimes|string|max:5000'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Use sample medical text if not provided
            $testText = $request->input('test_text', $this->getSampleMedicalText());
            
            // Create a temporary test context
            $context = [
                'test_mode' => true,
                'requested_by' => auth()->id(),
                'timestamp' => now()->toISOString()
            ];
            
            // Test the specific provider directly
            $providerConfig = $this->configManager->getProviderConfig($provider);
            $providerInstance = $this->createProviderInstance($provider, $providerConfig);
            
            if (!$providerInstance->isAvailable()) {
                return response()->json([
                    'success' => false,
                    'message' => "Provider {$provider} is not available or properly configured"
                ], 400);
            }
            
            $startTime = microtime(true);
            $result = $providerInstance->extractMetrics($testText, $context);
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            Log::info("Provider {$provider} test completed via admin API", [
                'provider' => $provider,
                'duration_ms' => $duration,
                'success' => true,
                'tested_by' => auth()->id()
            ]);
            
            return response()->json([
                'success' => true,
                'message' => "Provider {$provider} test successful",
                'data' => [
                    'provider' => $provider,
                    'model' => $providerInstance->getModel(),
                    'duration_ms' => $duration,
                    'test_result' => [
                        'confidence_score' => $result['confidence_score'] ?? 'N/A',
                        'findings_count' => count($result['key_findings'] ?? []),
                        'has_diagnosis' => !empty($result['diagnosis']),
                        'has_recommendations' => !empty($result['recommendations'])
                    ],
                    'full_response' => $result // Include full response for debugging
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error("Provider {$provider} test failed", [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
                'provider' => $provider
            ]);
            
            return response()->json([
                'success' => false,
                'message' => "Provider {$provider} test failed: " . $e->getMessage(),
                'data' => [
                    'provider' => $provider,
                    'error_type' => get_class($e),
                    'error_message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    /**
     * Reset configuration to defaults
     */
    public function resetConfiguration(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'confirm' => 'required|boolean|accepted'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Confirmation required',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $this->configManager->resetToDefaults();
            
            Log::warning('Health metrics configuration reset to defaults', [
                'reset_by' => auth()->id(),
                'user_email' => auth()->user()?->email,
                'timestamp' => now()->toISOString()
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Configuration reset to defaults',
                'data' => [
                    'reset_at' => now()->toISOString(),
                    'new_configuration' => $this->configManager->getConfigurationSummary()
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to reset configuration', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to reset configuration',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create provider instance for testing
     */
    protected function createProviderInstance(string $provider, array $config)
    {
        switch ($provider) {
            case 'deepseek':
                return new \App\Services\HealthMetricsExtraction\Providers\DeepseekExtractionProvider($config);
            case 'claude':
                return new \App\Services\HealthMetricsExtraction\Providers\ClaudeExtractionProvider($config);
            case 'openai':
                return new \App\Services\HealthMetricsExtraction\Providers\OpenAIExtractionProvider($config);
            default:
                throw new ConfigurationException("Unknown provider: {$provider}");
        }
    }

    /**
     * Get sample medical text for testing
     */
    protected function getSampleMedicalText(): string
    {
        return "COMPREHENSIVE METABOLIC PANEL

Patient: John Doe
Age: 45 years
Gender: Male
Date: " . now()->format('Y-m-d') . "

TEST RESULTS:
- Glucose: 95 mg/dL (Normal: 70-99 mg/dL)
- Total Cholesterol: 220 mg/dL (Normal: <200 mg/dL) [H]
- HDL Cholesterol: 45 mg/dL (Normal: >40 mg/dL)
- LDL Cholesterol: 145 mg/dL (Normal: <100 mg/dL) [H]
- Triglycerides: 180 mg/dL (Normal: <150 mg/dL) [H]
- Vitamin D: 18 ng/mL (Normal: 30-100 ng/mL) [L]
- ALT: 35 U/L (Normal: 7-40 U/L)
- Creatinine: 1.1 mg/dL (Normal: 0.7-1.3 mg/dL)

CLINICAL NOTES: Borderline high cholesterol levels observed. Vitamin D deficiency noted.

RECOMMENDATIONS: 
1. Dietary modifications to reduce cholesterol
2. Vitamin D supplementation
3. Follow-up in 3 months";
    }
}