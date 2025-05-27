<?php

namespace App\Services\HealthMetricsExtraction\Providers;

use App\Services\HealthMetricsExtraction\Contracts\ExtractionProviderInterface;
use App\Services\HealthMetricsExtraction\Exceptions\ExtractionException;
use App\Services\HealthMetricsExtraction\Exceptions\ProviderException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

abstract class AbstractExtractionProvider implements ExtractionProviderInterface
{
    protected int $timeout = 30;
    protected int $maxRetries = 3;
    protected array $config = [];
    protected string $name = '';
    protected string $model = '';

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->timeout = $config['timeout'] ?? 30;
        $this->maxRetries = $config['max_retries'] ?? 3;
        $this->initializeProvider();
    }

    /**
     * Initialize provider-specific configuration
     */
    abstract protected function initializeProvider(): void;

    /**
     * Make the actual API call to the AI provider
     *
     * @param string $prompt The formatted prompt
     * @param array $context Additional context
     * @return array Raw response from AI provider
     */
    abstract protected function callAIProvider(string $prompt, array $context = []): array;

    /**
     * Format the raw text into a provider-specific prompt
     *
     * @param string $rawText Medical report text
     * @param array $context Additional context
     * @return string Formatted prompt
     */
    abstract protected function formatPrompt(string $rawText, array $context = []): string;

    /**
     * Main extraction method - implements template pattern
     *
     * @param string $rawText The raw medical report text
     * @param array $context Additional context
     * @return array Structured health metrics data
     */
    public function extractMetrics(string $rawText, array $context = []): array
    {
        $this->validateInput($rawText);
        
        Log::info("Starting health metrics extraction", [
            'provider' => $this->getName(),
            'model' => $this->getModel(),
            'text_length' => strlen($rawText),
            'context' => array_keys($context)
        ]);

        $startTime = microtime(true);

        try {
            $prompt = $this->formatPrompt($rawText, $context);
            $rawResponse = $this->callAIProviderWithRetry($prompt, $context);
            $processedResponse = $this->processResponse($rawResponse);
            
            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2);

            Log::info("Health metrics extraction completed", [
                'provider' => $this->getName(),
                'duration_ms' => $duration,
                'metrics_found' => count($processedResponse['key_findings'] ?? []),
                'confidence_score' => $processedResponse['confidence_score'] ?? 'N/A'
            ]);

            return $processedResponse;

        } catch (ProviderException $e) {
            Log::error("Provider-specific error during extraction", [
                'provider' => $this->getName(),
                'error' => $e->getMessage(),
                'context' => $context
            ]);
            throw $e;
        } catch (\Exception $e) {
            Log::error("Unexpected error during extraction", [
                'provider' => $this->getName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new ExtractionException(
                "Health metrics extraction failed: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Call AI provider with retry logic and exponential backoff
     *
     * @param string $prompt
     * @param array $context
     * @return array
     */
    protected function callAIProviderWithRetry(string $prompt, array $context = []): array
    {
        $lastException = null;
        
        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                return $this->callAIProvider($prompt, $context);
            } catch (ProviderException $e) {
                $lastException = $e;
                
                // Don't retry on authentication or invalid request errors
                if ($e->isRetryable() === false) {
                    throw $e;
                }
                
                if ($attempt < $this->maxRetries) {
                    $delay = $this->calculateBackoffDelay($attempt);
                    Log::warning("AI provider call failed, retrying", [
                        'provider' => $this->getName(),
                        'attempt' => $attempt,
                        'max_retries' => $this->maxRetries,
                        'delay_seconds' => $delay,
                        'error' => $e->getMessage()
                    ]);
                    sleep($delay);
                }
            }
        }
        
        throw new ExtractionException(
            "Failed to extract metrics after {$this->maxRetries} attempts: " . $lastException->getMessage(),
            0,
            $lastException
        );
    }

    /**
     * Calculate exponential backoff delay
     */
    protected function calculateBackoffDelay(int $attempt): int
    {
        return min(pow(2, $attempt - 1), 10); // Max 10 seconds
    }

    /**
     * Validate input text
     *
     * @param string $rawText
     * @throws ExtractionException
     */
    protected function validateInput(string $rawText): void
    {
        if (empty(trim($rawText))) {
            throw new ExtractionException("Input text cannot be empty");
        }
        
        if (strlen($rawText) < 10) {
            throw new ExtractionException("Input text is too short for meaningful analysis");
        }
        
        if (strlen($rawText) > 50000) {
            throw new ExtractionException("Input text is too long for processing");
        }
    }

    /**
     * Process and validate AI response
     *
     * @param array $rawResponse
     * @return array
     */
    protected function processResponse(array $rawResponse): array
    {
        // Ensure required fields exist
        $processed = [
            'patient_name' => $rawResponse['patient_name'] ?? 'N/A',
            'patient_age' => $rawResponse['patient_age'] ?? 'N/A',
            'patient_gender' => $rawResponse['patient_gender'] ?? 'N/A',
            'diagnosis' => $rawResponse['diagnosis'] ?? 'N/A',
            'key_findings' => $rawResponse['key_findings'] ?? [],
            'recommendations' => $rawResponse['recommendations'] ?? [],
            'confidence_score' => $this->normalizeConfidenceScore($rawResponse['confidence_score'] ?? '0'),
            'provider_used' => $this->getName(),
            'model_used' => $this->getModel(),
            'processed_at' => now()->toISOString()
        ];

        // Validate key findings structure
        $processed['key_findings'] = $this->validateKeyFindings($processed['key_findings']);
        
        return $processed;
    }

    /**
     * Validate and normalize key findings
     */
    protected function validateKeyFindings(array $findings): array
    {
        $validated = [];
        
        foreach ($findings as $finding) {
            if (is_string($finding) && !empty(trim($finding))) {
                $validated[] = $finding;
            } elseif (is_array($finding) && isset($finding['finding']) && !empty(trim($finding['finding']))) {
                $validated[] = [
                    'finding' => $finding['finding'],
                    'value' => $finding['value'] ?? '',
                    'unit' => $finding['unit'] ?? '',
                    'reference' => $finding['reference'] ?? '',
                    'status' => $finding['status'] ?? 'unknown',
                    'description' => $finding['description'] ?? $finding['finding']
                ];
            }
        }
        
        return $validated;
    }

    /**
     * Normalize confidence score to a consistent format
     */
    protected function normalizeConfidenceScore($score): string
    {
        if (is_numeric($score)) {
            $numericScore = (float) $score;
            if ($numericScore <= 1) {
                $numericScore *= 100; // Convert decimal to percentage
            }
            return round($numericScore) . '%';
        }
        
        if (is_string($score) && preg_match('/(\d+)/', $score, $matches)) {
            return $matches[1] . '%';
        }
        
        return '0%';
    }

    /**
     * Get the standard medical extraction prompt
     */
    protected function getBasePrompt(): string
    {
        return "Analyze the following medical report and extract health metrics in JSON format with these exact fields:

1. patient_name (full name of the patient)
2. patient_age (age with units if available)
3. patient_gender (male/female/other)
4. diagnosis (brief summary of findings)
5. key_findings (array of objects with: finding, value, unit, reference, status, description)
6. recommendations (array of actionable recommendations)
7. confidence_score (numerical value 0-100, without % symbol)

For key_findings, use this structure:
- finding: name of the test (e.g., 'HDL Cholesterol')
- value: measured value with units (e.g., '48 mg/dL')
- reference: reference range (e.g., '40-60 mg/dL')
- status: one of 'normal', 'borderline', or 'high' (high means clinically significant - either too high OR too low)
- description: clear interpretation (e.g., 'HDL Cholesterol within normal range')

IMPORTANT: 
- Values marked with H or L should have 'high' status
- 'high' status means clinically significant (needs attention)
- Be precise with medical terminology
- Extract ALL measurable values with their units

Return ONLY valid JSON without any additional text or explanations.";
    }

    // Interface implementations
    public function getName(): string
    {
        return $this->name;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function isAvailable(): bool
    {
        return !empty($this->config['api_key']) && !empty($this->config['enabled']);
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function setTimeout(int $timeout): void
    {
        $this->timeout = $timeout;
    }

    public function setMaxRetries(int $maxRetries): void
    {
        $this->maxRetries = $maxRetries;
    }

    /**
     * Clean text for better AI processing
     */
    protected function cleanTextForProcessing(string $text): string
    {
        // Remove excessive whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Remove non-printable characters but keep medical symbols
        $text = preg_replace('/[^\x20-\x7E\n\r\t°±≤≥µ]/', '', $text);
        
        // Normalize line breaks
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        
        return trim($text);
    }
}