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

    abstract protected function initializeProvider(): void;
    abstract protected function callAIProvider(string $prompt, array $context = []): array;
    abstract protected function formatPrompt(string $rawText, array $context = []): string;

    public function extractMetrics(string $rawText, array $context = []): array
    {
        $this->validateInput($rawText);
        
        Log::info("Starting health metrics extraction", [
            'provider' => $this->getName(),
            'model' => $this->getModel(),
            'text_length' => strlen($rawText),
            'context' => array_keys($context),
            'encoding_valid' => mb_check_encoding($rawText, 'UTF-8')
        ]);

        $startTime = microtime(true);

        try {
            // ✨ CLEAN TEXT BEFORE PROCESSING
            $cleanedText = $this->cleanTextForProcessing($rawText);
            
            Log::info("Text cleaned for AI processing", [
                'provider' => $this->getName(),
                'original_length' => strlen($rawText),
                'cleaned_length' => strlen($cleanedText),
                'encoding_fixed' => mb_check_encoding($cleanedText, 'UTF-8')
            ]);
            
            $prompt = $this->formatPrompt($cleanedText, $context);
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
     * ✨ CLEAN TEXT FOR AI PROCESSING - Fix encoding issues
     */
    protected function cleanTextForProcessing(string $text): string
    {
        // Step 1: Convert to UTF-8 if needed
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'auto');
            Log::info("Text encoding converted to UTF-8", ['provider' => $this->getName()]);
        }
        
        // Step 2: Remove problematic control characters
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        
        // Step 3: Replace common problematic characters
        $text = str_replace([
            '�',           // Replacement character
            chr(194),      // UTF-8 BOM parts
            chr(162),      // UTF-8 BOM parts
            "\xC2\xAD",    // Soft hyphen
            "\xE2\x80\x8B", // Zero width space
        ], ['', '', '', '', ''], $text);
        
        // Step 4: Normalize whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        
        // Step 5: Limit length for AI processing
        if (strlen($text) > 10000) {
            // Keep medical sections when truncating
            $medicalSection = $this->extractMedicalSection($text);
            if (!empty($medicalSection)) {
                $text = $medicalSection;
            } else {
                $text = substr($text, 0, 10000) . '...';
            }
        }
        
        // Step 6: Final validation
        if (!mb_check_encoding($text, 'UTF-8')) {
            // Last resort: remove all non-ASCII characters
            $text = preg_replace('/[^\x20-\x7E\s]/', '', $text);
            Log::warning("Had to remove non-ASCII characters", ['provider' => $this->getName()]);
        }
        
        return $text;
    }
    
    /**
     * Extract medical section from large text
     */
    protected function extractMedicalSection(string $text): string
    {
        $lines = explode("\n", $text);
        $medicalLines = [];
        $lineCount = 0;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Keep lines with medical content
            if (preg_match('/\d+\.?\d*\s*(mg\/dl|mmol\/l|g\/dl|%|miu\/l|ng\/ml|u\/l)/i', $line) ||
                preg_match('/(cholesterol|glucose|hemoglobin|creatinine|vitamin|thyroid|sodium|potassium)/i', $line) ||
                preg_match('/\b(normal|high|low|elevated|decreased|abnormal)\b/i', $line)) {
                
                $medicalLines[] = $line;
                $lineCount++;
                
                // Limit to reasonable size
                if ($lineCount > 100) break;
            }
        }
        
        return implode("\n", $medicalLines);
    }

    protected function callAIProviderWithRetry(string $prompt, array $context = []): array
    {
        $lastException = null;
        
        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                return $this->callAIProvider($prompt, $context);
            } catch (ProviderException $e) {
                $lastException = $e;
                
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

    protected function calculateBackoffDelay(int $attempt): int
    {
        return min(pow(2, $attempt - 1), 10);
    }

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

    protected function processResponse(array $rawResponse): array
    {
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

        $processed['key_findings'] = $this->validateKeyFindings($processed['key_findings']);
        
        return $processed;
    }

    protected function validateKeyFindings(array $findings): array
    {
        $validated = [];
        
        foreach ($findings as $finding) {
            if (is_string($finding) && !empty(trim($finding))) {
                // Skip N/A findings
                if (strtolower(trim($finding)) !== 'n/a') {
                    $validated[] = $finding;
                }
            } elseif (is_array($finding) && isset($finding['finding']) && !empty(trim($finding['finding']))) {
                // Skip N/A findings in arrays too
                if (strtolower(trim($finding['finding'])) !== 'n/a') {
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
        }
        
        return $validated;
    }

    protected function normalizeConfidenceScore($score): string
    {
        if (is_numeric($score)) {
            $numericScore = (float) $score;
            if ($numericScore <= 1) {
                $numericScore *= 100;
            }
            return round($numericScore) . '%';
        }
        
        if (is_string($score) && preg_match('/(\d+)/', $score, $matches)) {
            return $matches[1] . '%';
        }
        
        return '0%';
    }

    protected function getBasePrompt(): string
    {
        return "Analyze this medical report and extract health metrics in JSON format.

CRITICAL INSTRUCTIONS:
1. Find ALL numerical values with medical units (mg/dL, g/dL, %, mmol/L, etc.)
2. Extract ALL test results, even if normal
3. DO NOT return 'N/A' for key_findings - find actual medical data
4. If no medical data is found, return empty array for key_findings

Required JSON structure:
{
  \"patient_name\": \"[Name or N/A]\",
  \"patient_age\": \"[Age or N/A]\",
  \"patient_gender\": \"[Gender or N/A]\",
  \"diagnosis\": \"[Brief summary]\",
  \"key_findings\": [
    {
      \"finding\": \"[Test name]\",
      \"value\": \"[Value with unit]\",
      \"unit\": \"[Unit]\",
      \"reference\": \"[Normal range]\",
      \"status\": \"[normal/borderline/high]\",
      \"description\": \"[Clear explanation]\"
    }
  ],
  \"recommendations\": [\"[Medical advice]\"],
  \"confidence_score\": [0-100 number without %]
}

EXAMPLES of what to extract:
- 'Cholesterol: 180 mg/dL' → Extract as cholesterol finding
- 'Glucose 95 mg/dL (Normal: 70-99)' → Extract as glucose finding
- 'HDL 45, LDL 120' → Extract both as separate findings

Return valid JSON only, no explanations.";
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
}