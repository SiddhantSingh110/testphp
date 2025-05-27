<?php

namespace App\Services\HealthMetricsExtraction\Validation;

use Illuminate\Support\Facades\Log;

class MetricValidator
{
    protected array $validationRules;
    protected array $medicalRanges;

    public function __construct()
    {
        $this->initializeValidationRules();
        $this->initializeMedicalRanges();
    }

    /**
     * Validate extracted health metric data
     *
     * @param array $metricData The extracted metric data
     * @return array Validation result with errors and warnings
     */
    public function validateMetric(array $metricData): array
    {
        $result = [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'quality_score' => 1.0,
            'confidence_adjustments' => []
        ];

        // Required field validation
        $this->validateRequiredFields($metricData, $result);
        
        // Data type validation
        $this->validateDataTypes($metricData, $result);
        
        // Medical range validation
        $this->validateMedicalRanges($metricData, $result);
        
        // Unit compatibility validation
        $this->validateUnitCompatibility($metricData, $result);
        
        // Value plausibility validation
        $this->validateValuePlausibility($metricData, $result);
        
        // Calculate final quality score
        $result['quality_score'] = $this->calculateQualityScore($result);
        
        // Overall validity
        $result['valid'] = empty($result['errors']);

        Log::debug('Metric validation completed', [
            'metric_type' => $metricData['type'] ?? 'unknown',
            'valid' => $result['valid'],
            'quality_score' => $result['quality_score'],
            'errors_count' => count($result['errors']),
            'warnings_count' => count($result['warnings'])
        ]);

        return $result;
    }

    /**
     * Validate multiple metrics in batch
     *
     * @param array $metricsData Array of metric data
     * @return array Batch validation results
     */
    public function validateBatch(array $metricsData): array
    {
        $results = [];
        $overallStats = [
            'total_metrics' => count($metricsData),
            'valid_metrics' => 0,
            'invalid_metrics' => 0,
            'average_quality_score' => 0,
            'common_errors' => [],
            'common_warnings' => []
        ];

        foreach ($metricsData as $index => $metricData) {
            $result = $this->validateMetric($metricData);
            $results[$index] = $result;

            // Update overall stats
            if ($result['valid']) {
                $overallStats['valid_metrics']++;
            } else {
                $overallStats['invalid_metrics']++;
            }

            // Collect common errors and warnings
            foreach ($result['errors'] as $error) {
                $overallStats['common_errors'][] = $error['type'];
            }
            foreach ($result['warnings'] as $warning) {
                $overallStats['common_warnings'][] = $warning['type'];
            }
        }

        // Calculate average quality score
        $totalQuality = array_sum(array_column($results, 'quality_score'));
        $overallStats['average_quality_score'] = $overallStats['total_metrics'] > 0 
            ? round($totalQuality / $overallStats['total_metrics'], 3) 
            : 0;

        // Count frequency of common issues
        $overallStats['common_errors'] = array_count_values($overallStats['common_errors']);
        $overallStats['common_warnings'] = array_count_values($overallStats['common_warnings']);

        return [
            'results' => $results,
            'stats' => $overallStats
        ];
    }

    /**
     * Get validation rules for a specific metric type
     */
    public function getValidationRules(string $metricType): array
    {
        return $this->validationRules[$metricType] ?? $this->validationRules['default'];
    }

    /**
     * Validate required fields are present
     */
    protected function validateRequiredFields(array $metricData, array &$result): void
    {
        $requiredFields = ['type', 'value', 'unit'];
        
        foreach ($requiredFields as $field) {
            if (!isset($metricData[$field]) || $metricData[$field] === '') {
                $result['errors'][] = [
                    'type' => 'missing_required_field',
                    'field' => $field,
                    'message' => "Required field '{$field}' is missing or empty",
                    'severity' => 'high'
                ];
            }
        }
    }

    /**
     * Validate data types match expected formats
     */
    protected function validateDataTypes(array $metricData, array &$result): void
    {
        $metricType = $metricData['type'] ?? 'unknown';
        $rules = $this->getValidationRules($metricType);

        // Validate value format
        if (isset($metricData['value'])) {
            $this->validateValueFormat($metricData['value'], $rules, $result);
        }

        // Validate unit format
        if (isset($metricData['unit'])) {
            $this->validateUnitFormat($metricData['unit'], $rules, $result);
        }
    }

    /**
     * Validate value format based on metric type
     */
    protected function validateValueFormat(string $value, array $rules, array &$result): void
    {
        $valueType = $rules['value_type'] ?? 'numeric';

        switch ($valueType) {
            case 'numeric':
                if (!is_numeric($value) && !preg_match('/^\d+\.?\d*$/', $value)) {
                    $result['errors'][] = [
                        'type' => 'invalid_value_format',
                        'message' => "Value '{$value}' is not a valid number",
                        'expected' => 'numeric',
                        'received' => $value,
                        'severity' => 'high'
                    ];
                }
                break;

            case 'blood_pressure':
                if (!preg_match('/^\d+\/\d+$/', $value)) {
                    $result['errors'][] = [
                        'type' => 'invalid_blood_pressure_format',
                        'message' => "Blood pressure '{$value}' should be in format 'XXX/YYY'",
                        'expected' => 'XXX/YYY format',
                        'received' => $value,
                        'severity' => 'high'
                    ];
                }
                break;

            case 'range':
                if (!preg_match('/^\d+\.?\d*\s*-\s*\d+\.?\d*$/', $value)) {
                    $result['warnings'][] = [
                        'type' => 'unusual_range_format',
                        'message' => "Range value '{$value}' may not be in standard format",
                        'severity' => 'medium'
                    ];
                }
                break;
        }
    }

    /**
     * Validate unit format and consistency
     */
    protected function validateUnitFormat(string $unit, array $rules, array &$result): void
    {
        $expectedUnits = $rules['valid_units'] ?? [];
        
        if (!empty($expectedUnits) && !in_array($unit, $expectedUnits)) {
            $result['warnings'][] = [
                'type' => 'unexpected_unit',
                'message' => "Unit '{$unit}' is not commonly used for this metric type",
                'expected' => $expectedUnits,
                'received' => $unit,
                'severity' => 'medium'
            ];
        }

        // Check for common unit format issues
        if (strlen($unit) > 20) {
            $result['warnings'][] = [
                'type' => 'unusual_unit_length',
                'message' => "Unit '{$unit}' is unusually long",
                'severity' => 'low'
            ];
        }
    }

    /**
     * Validate values against medical reference ranges
     */
    protected function validateMedicalRanges(array $metricData, array &$result): void
    {
        $metricType = $metricData['type'] ?? '';
        $value = $metricData['value'] ?? '';

        if (!isset($this->medicalRanges[$metricType]) || !is_numeric($value)) {
            return;
        }

        $ranges = $this->medicalRanges[$metricType];
        $numericValue = floatval($value);

        // Check for extremely abnormal values (likely extraction errors)
        if (isset($ranges['critical_min']) && $numericValue < $ranges['critical_min']) {
            $result['errors'][] = [
                'type' => 'critically_low_value',
                'message' => "Value {$value} is critically low for {$metricType}",
                'value' => $numericValue,
                'critical_min' => $ranges['critical_min'],
                'severity' => 'high'
            ];
        }

        if (isset($ranges['critical_max']) && $numericValue > $ranges['critical_max']) {
            $result['errors'][] = [
                'type' => 'critically_high_value',
                'message' => "Value {$value} is critically high for {$metricType}",
                'value' => $numericValue,
                'critical_max' => $ranges['critical_max'],
                'severity' => 'high'
            ];
        }

        // Check for unusual but not impossible values
        if (isset($ranges['unusual_min']) && $numericValue < $ranges['unusual_min']) {
            $result['warnings'][] = [
                'type' => 'unusually_low_value',
                'message' => "Value {$value} is unusually low for {$metricType}",
                'value' => $numericValue,
                'unusual_min' => $ranges['unusual_min'],
                'severity' => 'medium'
            ];
        }

        if (isset($ranges['unusual_max']) && $numericValue > $ranges['unusual_max']) {
            $result['warnings'][] = [
                'type' => 'unusually_high_value',
                'message' => "Value {$value} is unusually high for {$metricType}",
                'value' => $numericValue,
                'unusual_max' => $ranges['unusual_max'],
                'severity' => 'medium'
            ];
        }
    }

    /**
     * Validate unit compatibility with metric type
     */
    protected function validateUnitCompatibility(array $metricData, array &$result): void
    {
        $metricType = $metricData['type'] ?? '';
        $unit = $metricData['unit'] ?? '';

        $compatibilityMap = [
            'hdl' => ['mg/dL', 'mmol/L'],
            'ldl' => ['mg/dL', 'mmol/L'],
            'total_cholesterol' => ['mg/dL', 'mmol/L'],
            'triglycerides' => ['mg/dL', 'mmol/L'],
            'glucose_fasting' => ['mg/dL', 'mmol/L'],
            'hba1c' => ['%', 'mmol/mol'],
            'tsh' => ['mIU/L', 'μIU/mL'],
            'vitamin_d' => ['ng/mL', 'nmol/L'],
            'hemoglobin' => ['g/dL', 'g/L'],
            'creatinine' => ['mg/dL', 'μmol/L'],
            'blood_pressure' => ['mmHg']
        ];

        if (isset($compatibilityMap[$metricType])) {
            $validUnits = $compatibilityMap[$metricType];
            if (!in_array($unit, $validUnits)) {
                $result['warnings'][] = [
                    'type' => 'unit_incompatibility',
                    'message' => "Unit '{$unit}' may not be compatible with {$metricType}",
                    'valid_units' => $validUnits,
                    'received_unit' => $unit,
                    'severity' => 'medium'
                ];
            }
        }
    }

    /**
     * Validate value plausibility for humans
     */
    protected function validateValuePlausibility(array $metricData, array &$result): void
    {
        $metricType = $metricData['type'] ?? '';
        $value = $metricData['value'] ?? '';
        $unit = $metricData['unit'] ?? '';

        if (!is_numeric($value)) {
            return;
        }

        $numericValue = floatval($value);

        // Plausibility checks for common metrics
        $plausibilityChecks = [
            'hdl' => ['min' => 5, 'max' => 200], // mg/dL
            'ldl' => ['min' => 10, 'max' => 500],
            'total_cholesterol' => ['min' => 50, 'max' => 800],
            'glucose_fasting' => ['min' => 20, 'max' => 800],
            'hemoglobin' => ['min' => 3, 'max' => 25],
            'creatinine' => ['min' => 0.1, 'max' => 15],
            'tsh' => ['min' => 0.01, 'max' => 200],
            'vitamin_d' => ['min' => 1, 'max' => 300]
        ];

        if (isset($plausibilityChecks[$metricType])) {
            $limits = $plausibilityChecks[$metricType];
            
            if ($numericValue < $limits['min'] || $numericValue > $limits['max']) {
                $result['warnings'][] = [
                    'type' => 'implausible_value',
                    'message' => "Value {$value} {$unit} seems implausible for {$metricType}",
                    'value' => $numericValue,
                    'plausible_range' => $limits,
                    'severity' => 'high'
                ];
            }
        }
    }

    /**
     * Calculate overall quality score based on validation results
     */
    protected function calculateQualityScore(array $result): float
    {
        $score = 1.0;

        // Deduct for errors
        foreach ($result['errors'] as $error) {
            switch ($error['severity']) {
                case 'high':
                    $score -= 0.3;
                    break;
                case 'medium':
                    $score -= 0.2;
                    break;
                case 'low':
                    $score -= 0.1;
                    break;
            }
        }

        // Deduct for warnings (less severe)
        foreach ($result['warnings'] as $warning) {
            switch ($warning['severity']) {
                case 'high':
                    $score -= 0.15;
                    break;
                case 'medium':
                    $score -= 0.1;
                    break;
                case 'low':
                    $score -= 0.05;
                    break;
            }
        }

        return max(0.0, round($score, 3));
    }

    /**
     * Initialize validation rules for different metric types
     */
    protected function initializeValidationRules(): void
    {
        $this->validationRules = [
            'default' => [
                'value_type' => 'numeric',
                'valid_units' => [],
                'required_fields' => ['type', 'value', 'unit']
            ],
            'blood_pressure' => [
                'value_type' => 'blood_pressure',
                'valid_units' => ['mmHg'],
                'required_fields' => ['type', 'value', 'unit']
            ],
            'hdl' => [
                'value_type' => 'numeric',
                'valid_units' => ['mg/dL', 'mmol/L'],
                'required_fields' => ['type', 'value', 'unit']
            ],
            'ldl' => [
                'value_type' => 'numeric',
                'valid_units' => ['mg/dL', 'mmol/L'],
                'required_fields' => ['type', 'value', 'unit']
            ],
            'hba1c' => [
                'value_type' => 'numeric',
                'valid_units' => ['%', 'mmol/mol'],
                'required_fields' => ['type', 'value', 'unit']
            ]
        ];
    }

    /**
     * Initialize medical reference ranges for validation
     */
    protected function initializeMedicalRanges(): void
    {
        $this->medicalRanges = [
            'hdl' => [
                'critical_min' => 5,
                'critical_max' => 200,
                'unusual_min' => 20,
                'unusual_max' => 150
            ],
            'ldl' => [
                'critical_min' => 5,
                'critical_max' => 500,
                'unusual_min' => 30,
                'unusual_max' => 300
            ],
            'total_cholesterol' => [
                'critical_min' => 50,
                'critical_max' => 800,
                'unusual_min' => 100,
                'unusual_max' => 500
            ],
            'glucose_fasting' => [
                'critical_min' => 20,
                'critical_max' => 800,
                'unusual_min' => 40,
                'unusual_max' => 400
            ],
            'hemoglobin' => [
                'critical_min' => 3,
                'critical_max' => 25,
                'unusual_min' => 6,
                'unusual_max' => 20
            ],
            'creatinine' => [
                'critical_min' => 0.1,
                'critical_max' => 15,
                'unusual_min' => 0.3,
                'unusual_max' => 8
            ],
            'tsh' => [
                'critical_min' => 0.001,
                'critical_max' => 200,
                'unusual_min' => 0.01,
                'unusual_max' => 50
            ],
            'vitamin_d' => [
                'critical_min' => 1,
                'critical_max' => 300,
                'unusual_min' => 5,
                'unusual_max' => 200
            ]
        ];
    }
}