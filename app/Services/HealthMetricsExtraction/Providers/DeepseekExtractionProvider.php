<?php

namespace App\Services\HealthMetricsExtraction\Providers;

use App\Services\HealthMetricsExtraction\Exceptions\ProviderException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeepseekExtractionProvider extends AbstractExtractionProvider
{
    protected string $name = 'deepseek';
    protected string $model = 'deepseek-chat';
    protected string $baseUrl = 'https://api.deepseek.com';

    protected function initializeProvider(): void
    {
        $this->model = $this->config['model'] ?? 'deepseek-chat';
        $this->baseUrl = $this->config['base_url'] ?? 'https://api.deepseek.com';

        if (empty($this->config['api_key'])) {
            throw new ProviderException("DeepSeek API key is not configured", $this->name, false);
        }
    }

    protected function formatPrompt(string $rawText, array $context = []): string
    {
        $cleanedText = $this->cleanTextForProcessing($rawText);
        $basePrompt = $this->getBasePrompt();
        
        // Add DeepSeek-specific instructions for better medical analysis
        $deepseekPrompt = $basePrompt . "\n\nDeepSeek-specific instructions:
- Focus on numerical values and their units
- Pay special attention to values marked with H (High) or L (Low)
- For blood pressure readings, extract both systolic and diastolic values
- Include relevant context for each finding
- Be precise with reference ranges from the report

Medical Report Text:\n\n" . $cleanedText;

        return $deepseekPrompt;
    }

    protected function callAIProvider(string $prompt, array $context = []): array
    {
        try {
            Log::debug("Making DeepSeek API call", [
                'model' => $this->model,
                'prompt_length' => strlen($prompt)
            ]);

            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->config['api_key'],
                    'Content-Type' => 'application/json',
                ])
                ->post($this->baseUrl . '/chat/completions', [
                    'model' => $this->model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are a medical AI assistant specialized in analyzing medical reports and extracting health metrics. Always respond with valid JSON format.'
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt
                        ]
                    ],
                    'temperature' => 0.1, // Low temperature for consistent medical analysis
                    'max_tokens' => 2000,
                    'stream' => false
                ]);

            if (!$response->successful()) {
                $this->handleDeepSeekError($response);
            }

            $responseData = $response->json();
            
            if (!isset($responseData['choices'][0]['message']['content'])) {
                throw ProviderException::retryable(
                    "Invalid response structure from DeepSeek API",
                    $this->name,
                    ['response' => $responseData]
                );
            }

            $content = $responseData['choices'][0]['message']['content'];
            $parsedContent = $this->parseJsonResponse($content);

            Log::debug("DeepSeek API call successful", [
                'response_length' => strlen($content),
                'parsed_findings' => count($parsedContent['key_findings'] ?? [])
            ]);

            return $parsedContent;

        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error("DeepSeek API call failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw ProviderException::retryable(
                "DeepSeek API call failed: " . $e->getMessage(),
                $this->name,
                ['original_error' => $e->getMessage()]
            );
        }
    }

    protected function handleDeepSeekError($response): void
    {
        $statusCode = $response->status();
        $errorData = $response->json();
        $errorMessage = $errorData['error']['message'] ?? 'Unknown DeepSeek API error';

        switch ($statusCode) {
            case 401:
                throw ProviderException::nonRetryable(
                    "DeepSeek API authentication failed: " . $errorMessage,
                    $this->name,
                    ['status_code' => $statusCode, 'error' => $errorData]
                );
                
            case 403:
                throw ProviderException::nonRetryable(
                    "DeepSeek API access forbidden: " . $errorMessage,
                    $this->name,
                    ['status_code' => $statusCode, 'error' => $errorData]
                );
                
            case 429:
                throw ProviderException::retryable(
                    "DeepSeek API rate limit exceeded: " . $errorMessage,
                    $this->name,
                    ['status_code' => $statusCode, 'error' => $errorData]
                );
                
            case 500:
            case 502:
            case 503:
            case 504:
                throw ProviderException::retryable(
                    "DeepSeek API server error: " . $errorMessage,
                    $this->name,
                    ['status_code' => $statusCode, 'error' => $errorData]
                );
                
            default:
                throw ProviderException::retryable(
                    "DeepSeek API error: " . $errorMessage,
                    $this->name,
                    ['status_code' => $statusCode, 'error' => $errorData]
                );
        }
    }

    protected function parseJsonResponse(string $content): array
    {
        // Clean up common JSON formatting issues
        $content = trim($content);
        
        // Remove code block markers if present
        $content = preg_replace('/^```(?:json)?\s*/', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);
        
        // Try to find JSON content if wrapped in text
        if (!str_starts_with($content, '{')) {
            if (preg_match('/\{.*\}/s', $content, $matches)) {
                $content = $matches[0];
            }
        }

        $decoded = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning("Failed to parse DeepSeek JSON response", [
                'json_error' => json_last_error_msg(),
                'content_preview' => substr($content, 0, 500)
            ]);
            
            throw ProviderException::retryable(
                "Failed to parse DeepSeek response as JSON: " . json_last_error_msg(),
                $this->name,
                ['content_preview' => substr($content, 0, 200)]
            );
        }

        return $decoded;
    }

    public function isAvailable(): bool
    {
        return parent::isAvailable() && !empty($this->baseUrl);
    }

    /**
     * Get DeepSeek-specific configuration info
     */
    public function getProviderInfo(): array
    {
        return [
            'name' => $this->name,
            'model' => $this->model,
            'base_url' => $this->baseUrl,
            'timeout' => $this->timeout,
            'max_retries' => $this->maxRetries,
            'available' => $this->isAvailable(),
            'features' => [
                'medical_analysis' => true,
                'json_output' => true,
                'low_latency' => true,
                'cost_effective' => true
            ]
        ];
    }
}