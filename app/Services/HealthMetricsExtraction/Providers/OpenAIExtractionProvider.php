<?php

namespace App\Services\HealthMetricsExtraction\Providers;

use App\Services\HealthMetricsExtraction\Exceptions\ProviderException;
use OpenAI;
use Illuminate\Support\Facades\Log;

class OpenAIExtractionProvider extends AbstractExtractionProvider
{
    protected string $name = 'openai';
    protected string $model = 'gpt-4';
    protected $client;

    protected function initializeProvider(): void
    {
        $this->model = $this->config['model'] ?? 'gpt-4';

        if (empty($this->config['api_key'])) {
            throw new ProviderException("OpenAI API key is not configured", $this->name, false);
        }

        try {
            $this->client = OpenAI::client($this->config['api_key']);
        } catch (\Exception $e) {
            throw new ProviderException(
                "Failed to initialize OpenAI client: " . $e->getMessage(),
                $this->name,
                false
            );
        }
    }

    protected function formatPrompt(string $rawText, array $context = []): string
    {
        $cleanedText = $this->cleanTextForProcessing($rawText);
        $basePrompt = $this->getBasePrompt();
        
        // Add OpenAI-specific instructions
        $openaiPrompt = $basePrompt . "\n\nOpenAI-specific instructions:
- Leverage your extensive medical training data for accurate analysis
- Focus on extracting precise numerical values with proper units
- Identify all abnormal findings and their clinical significance
- Provide clear status classifications for each measurement
- Ensure all recommendations are evidence-based

Medical Report Text:\n\n" . $cleanedText;

        return $openaiPrompt;
    }

    protected function callAIProvider(string $prompt, array $context = []): array
    {
        try {
            Log::debug("Making OpenAI API call", [
                'model' => $this->model,
                'prompt_length' => strlen($prompt)
            ]);

            $response = $this->client->chat()->create([
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
            ]);

            if (!isset($response->choices[0]->message->content)) {
                throw ProviderException::retryable(
                    "Invalid response structure from OpenAI API",
                    $this->name,
                    ['response' => $response]
                );
            }

            $content = $response->choices[0]->message->content;
            $parsedContent = $this->parseJsonResponse($content);

            Log::debug("OpenAI API call successful", [
                'response_length' => strlen($content),
                'parsed_findings' => count($parsedContent['key_findings'] ?? []),
                'usage' => [
                    'prompt_tokens' => $response->usage->promptTokens ?? null,
                    'completion_tokens' => $response->usage->completionTokens ?? null,
                    'total_tokens' => $response->usage->totalTokens ?? null,
                ]
            ]);

            return $parsedContent;

        } catch (ProviderException $e) {
            throw $e;
        } catch (\OpenAI\Exceptions\ErrorException $e) {
            $this->handleOpenAIError($e);
        } catch (\Exception $e) {
            Log::error("OpenAI API call failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw ProviderException::retryable(
                "OpenAI API call failed: " . $e->getMessage(),
                $this->name,
                ['original_error' => $e->getMessage()]
            );
        }
    }

    protected function handleOpenAIError(\OpenAI\Exceptions\ErrorException $e): void
    {
        $errorMessage = $e->getMessage();
        $errorType = $e->getErrorType();
        $errorCode = $e->getErrorCode();

        switch ($errorType) {
            case 'invalid_api_key':
            case 'invalid_organization':
                throw ProviderException::nonRetryable(
                    "OpenAI API authentication failed: " . $errorMessage,
                    $this->name,
                    ['error_type' => $errorType, 'error_code' => $errorCode]
                );
                
            case 'insufficient_quota':
                throw ProviderException::nonRetryable(
                    "OpenAI API quota exceeded: " . $errorMessage,
                    $this->name,
                    ['error_type' => $errorType, 'error_code' => $errorCode]
                );
                
            case 'rate_limit_exceeded':
                throw ProviderException::retryable(
                    "OpenAI API rate limit exceeded: " . $errorMessage,
                    $this->name,
                    ['error_type' => $errorType, 'error_code' => $errorCode]
                );
                
            case 'server_error':
            case 'service_unavailable':
                throw ProviderException::retryable(
                    "OpenAI API server error: " . $errorMessage,
                    $this->name,
                    ['error_type' => $errorType, 'error_code' => $errorCode]
                );
                
            case 'invalid_request_error':
                throw ProviderException::nonRetryable(
                    "OpenAI API invalid request: " . $errorMessage,
                    $this->name,
                    ['error_type' => $errorType, 'error_code' => $errorCode]
                );
                
            default:
                throw ProviderException::retryable(
                    "OpenAI API error: " . $errorMessage,
                    $this->name,
                    ['error_type' => $errorType, 'error_code' => $errorCode]
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
            Log::warning("Failed to parse OpenAI JSON response", [
                'json_error' => json_last_error_msg(),
                'content_preview' => substr($content, 0, 500)
            ]);
            
            throw ProviderException::retryable(
                "Failed to parse OpenAI response as JSON: " . json_last_error_msg(),
                $this->name,
                ['content_preview' => substr($content, 0, 200)]
            );
        }

        return $decoded;
    }

    public function isAvailable(): bool
    {
        return parent::isAvailable() && !is_null($this->client);
    }

    /**
     * Get OpenAI-specific configuration info
     */
    public function getProviderInfo(): array
    {
        return [
            'name' => $this->name,
            'model' => $this->model,
            'timeout' => $this->timeout,
            'max_retries' => $this->maxRetries,
            'available' => $this->isAvailable(),
            'features' => [
                'established_platform' => true,
                'medical_knowledge' => true,
                'json_output' => true,
                'reliable_service' => true,
                'extensive_training' => true
            ]
        ];
    }
}