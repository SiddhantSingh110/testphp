<?php

namespace App\Services\HealthMetricsExtraction\Contracts;

interface ExtractionProviderInterface
{
    /**
     * Extract health metrics from raw medical text
     *
     * @param string $rawText The raw medical report text
     * @param array $context Additional context (report type, patient info, etc.)
     * @return array Structured health metrics data
     * @throws \App\Services\HealthMetricsExtraction\Exceptions\ExtractionException
     */
    public function extractMetrics(string $rawText, array $context = []): array;

    /**
     * Get the provider name
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Get the AI model being used
     *
     * @return string
     */
    public function getModel(): string;

    /**
     * Check if the provider is available/configured
     *
     * @return bool
     */
    public function isAvailable(): bool;

    /**
     * Get provider-specific configuration
     *
     * @return array
     */
    public function getConfig(): array;

    /**
     * Set provider timeout
     *
     * @param int $timeout Timeout in seconds
     * @return void
     */
    public function setTimeout(int $timeout): void;

    /**
     * Set maximum retry attempts
     *
     * @param int $maxRetries Maximum number of retries
     * @return void
     */
    public function setMaxRetries(int $maxRetries): void;
}