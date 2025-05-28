<?php

namespace App\Services\HealthMetricsExtraction\Contracts;

interface MetricMapperInterface
{
    /**
     * Map raw parameter name to standardized health metric type
     *
     * @param string $rawName Raw parameter name from AI or user input
     * @param array $context Additional context (value, unit, source, etc.)
     * @return array|null Standardized metric mapping or null if not found
     */
    public function mapToStandardType(string $rawName, array $context = []): ?array;

    /**
     * Get all available metric types supported by this mapper
     *
     * @return array Available metric types grouped by category
     */
    public function getAvailableMetricTypes(): array;

    /**
     * Get statistics about the mapping capabilities
     *
     * @return array Mapping statistics (total mappings, categories, etc.)
     */
    public function getMappingStatistics(): array;

    /**
     * Check if this mapper can handle a specific parameter name
     *
     * @param string $rawName Parameter name to check
     * @param array $context Additional context
     * @return bool True if this mapper can handle the parameter
     */
    public function canHandle(string $rawName, array $context = []): bool;

    /**
     * Get the mapper's priority level (lower = higher priority)
     *
     * @return int Priority level (1-10, where 1 is highest priority)
     */
    public function getPriority(): int;

    /**
     * Get the mapper's name/identifier
     *
     * @return string Mapper name
     */
    public function getName(): string;
}