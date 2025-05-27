<?php

namespace App\Services\HealthMetricsExtraction\Providers;

use App\Services\HealthMetricsExtraction\Exceptions\ProviderException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClaudeExtractionProvider extends AbstractExtractionProvider
{
    protected string $name = 'claude';
    protected string $model = 'claude-3-5-sonnet-20241022';
    protected string $baseUrl = 'https://api.anthropic.com/v1';
    protected string $anthropicVersion = '2023-06-01';

    protected function initializeProvider(): void
    {
        $this->model = $this->config['model'] ?? 'claude-3-5-sonnet-20241022';
        $this->baseUrl = $this->config['base_url'] ?? 'https://api.anthropic.com/v1';
        $this->anthropicVersion = $this->config['anthropic_version'] ?? '2023-06-01';

        if (empty($this->config['api_key'])) {
            throw new ProviderException("Claude API key is not configured", $this->name, false);
        }
    }

    protected function formatPrompt(string $rawText, array $context = []): string
    {
        $cleanedText = $this->cleanTextForProcessing($rawText);
        $basePrompt = $this->getBasePrompt();
        
        // Add Claude-specific instructions for superior medical reasoning
        $claudePrompt = $basePrompt . "\n\nClaude-specific instructions:
- Apply your advanced reasoning capabilities to medical analysis
- Cross-reference findings with medical knowledge for accuracy
- Identify subtle patterns in lab values and vital signs
- Provide comprehensive status assessment for each finding
- Consider interactions between different biomarkers
- Use medical terminology precisely and consistently

Medical Report Text:\n\n" . $cleanedText;

        return $claudePrompt;
    }

    protected function callAIProvider(string $prompt, array $context = []): array
    {
        try {
            Log::debug("Making Claude API call", [
                'model' => $this->model,
                'prompt_length' => strlen($prompt)
            ]);

            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'x-api-key' => $this->config['api_key'],
                    'anthropic-version' => $this->anthropicVersion,
                    'content-type' => 'application/json',
                ])
                ->post($this->baseUrl . '/messages', [
                    'model' => $this->model,
                    'max_tokens' => 2000,
                    'temperature' => 0.1, // Low temperature for consistent medical analysis
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => $prompt
                        ]
                    ]
                ]);

            if (!$response->successful()) {
                $this->handleClaudeError($response);
            }

            $responseData = $response->json();
            
            if (!isset($responseData['content'][0]['text'])) {
                throw ProviderException::retryable(
                    "Invalid response structure from Claude API",
                    $this->name,
                    ['response' => $responseData]
                );
            }

            $content = $responseData['content'][0]['text'];
            $parsedContent = $this->parseJsonResponse($content);

            Log::debug("Claude API call successful", [
                'response_length' => strlen($content),
                'parsed_findings' => count($parsedContent['key_findings'] ?? []),
                'usage' => $responseData['usage'] ?? null
            ]);

            return $parsedContent;

        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error("Claude API call failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw ProviderException::retryable(
                "Claude API call failed: " . $e->getMessage(),
                $this->name,
                ['original_error' => $e->getMessage()]
            );
        }
    }

    protected function handleClaudeError($response): void
    {
        $statusCode = $response->status();
        $errorData = $response->json();
        $errorMessage = $errorData['error']['message'] ?? 'Unknown Claude API error';
        $errorType = $errorData['error']['type'] ?? 'unknown_error';

        switch ($statusCode) {
            case 401:
            case 403:
                throw ProviderException::nonRetryable(
                    "Claude API authentication failed: " . $errorMessage,
                    $this->name,
                    ['status_code' => $statusCode, 'error_type' => $errorType, 'error' => $errorData]
                );
                
            case 429:
                // Claude has different types of rate limits
                $retryAfter = $response->header('retry-after');
                throw ProviderException::retryable(
                    "Claude API rate limit exceeded: " . $errorMessage,
                    $this->name,
                    [
                        'status_code' => $statusCode,
                        'error_type' => $errorType,
                        'retry_after' => $retryAfter,
                        'error' => $errorData
                    ]
                );
                
            case 400:
                // Bad request - usually non-retryable
                throw ProviderException::nonRetryable(
                    "Claude API bad request: " . $errorMessage,
                    $this->name,
                    ['status_code' => $statusCode, 'error_type' => $errorType, 'error' => $errorData]
                );
                
            case 500:
            case 502:
            case 503:
            case 504:
                throw ProviderException::retryable(
                    "Claude API server error: " . $errorMessage,
                    $this->name,
                    ['status_code' => $statusCode, 'error_type' => $errorType, 'error' => $errorData]
                );
                
            default:
                throw ProviderException::retryable(
                    "Claude API error: " . $errorMessage,
                    $this->name,
                    ['status_code' => $statusCode, 'error_type' => $errorType, 'error' => $errorData]
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
        
        // Claude sometimes wraps JSON in explanatory text, extract the JSON part
        if (!str_starts_with($content, '{')) {
            if (preg_match('/\{.*\}/s', $content, $matches)) {
                $content = $matches[0];
            }
        }

        $decoded = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning("Failed to parse Claude JSON response", [
                'json_error' => json_last_error_msg(),
                'content_preview' => substr($content, 0, 500)
            ]);
            
            // Try to fix common Claude JSON issues
            $fixedContent = $this->attemptJsonFix($content);
            if ($fixedContent) {
                $decoded = json_decode($fixedContent, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    Log::info("Successfully fixed Claude JSON response");
                    return $decoded;
                }
            }
            
            throw ProviderException::retryable(
                "Failed to parse Claude response as JSON: " . json_last_error_msg(),
                $this->name,
                ['content_preview' => substr($content, 0, 200)]
            );
        }

        return $decoded;
    }

    protected function attemptJsonFix(string $content): ?string
    {
        // Common fixes for Claude JSON responses
        $fixes = [
            // Remove trailing commas
            '/,(\s*[}\]])/' => '$1',
            // Fix single quotes to double quotes (but preserve existing double quotes)
            "/(?<!\\\\)'/'" => '"',
            // Fix escaped quotes that shouldn't be escaped
            '/\\\\\"/' => '"',
        ];

        $fixed = $content;
        foreach ($fixes as $pattern => $replacement) {
            $fixed = preg_replace($pattern, $replacement, $fixed);
        }

        // Validate that it's now valid JSON
        json_decode($fixed);
        return json_last_error() === JSON_ERROR_NONE ? $fixed : null;
    }

    public function isAvailable(): bool
    {
        return parent::isAvailable() && !empty($this->baseUrl) && !empty($this->anthropicVersion);
    }

    /**
     * Get Claude-specific configuration info
     */
    public function getProviderInfo(): array
    {
        return [
            'name' => $this->name,
            'model' => $this->model,
            'base_url' => $this->baseUrl,
            'anthropic_version' => $this->anthropicVersion,
            'timeout' => $this->timeout,
            'max_retries' => $this->maxRetries,
            'available' => $this->isAvailable(),
            'features' => [
                'advanced_reasoning' => true,
                'medical_knowledge' => true,
                'json_output' => true,
                'high_accuracy' => true,
                'context_awareness' => true
            ]
        ];
    }
}