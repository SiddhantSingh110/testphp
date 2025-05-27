<?php

namespace App\Services\HealthMetricsExtraction\MetricMapping;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class StandardMetricMapper
{
    protected array $standardMappings;
    protected array $fuzzyMappings;
    protected array $aliasMap;

    public function __construct()
    {
        $this->initializeMappings();
    }

    /**
     * Map raw AI-extracted parameter name to standardized health metric type
     *
     * @param string $rawName Raw parameter name from AI response
     * @param array $context Additional context (value, unit, etc.)
     * @return array|null Standardized metric mapping or null if not found
     */
    public function mapToStandardType(string $rawName, array $context = []): ?array
    {
        $cleanName = $this->cleanParameterName($rawName);
        
        Log::debug('Mapping parameter to standard type', [
            'raw_name' => $rawName,
            'clean_name' => $cleanName,
            'context' => $context
        ]);

        // Step 1: Direct exact match
        $mapping = $this->findExactMatch($cleanName);
        if ($mapping) {
            Log::debug('Found exact match', ['mapping' => $mapping]);
            return $this->enhanceMapping($mapping, $context);
        }

        // Step 2: Alias matching
        $mapping = $this->findAliasMatch($cleanName);
        if ($mapping) {
            Log::debug('Found alias match', ['mapping' => $mapping]);
            return $this->enhanceMapping($mapping, $context);
        }

        // Step 3: Fuzzy matching (partial matches)
        $mapping = $this->findFuzzyMatch($cleanName);
        if ($mapping) {
            Log::debug('Found fuzzy match', ['mapping' => $mapping]);
            return $this->enhanceMapping($mapping, $context);
        }

        // Step 4: Context-based intelligent matching
        $mapping = $this->findContextualMatch($cleanName, $context);
        if ($mapping) {
            Log::debug('Found contextual match', ['mapping' => $mapping]);
            return $this->enhanceMapping($mapping, $context);
        }

        Log::info('No mapping found for parameter', [
            'raw_name' => $rawName,
            'clean_name' => $cleanName
        ]);

        return null;
    }

    /**
     * Get all available metric types grouped by category
     */
    public function getAvailableMetricTypes(): array
    {
        $grouped = [];
        
        foreach ($this->standardMappings as $type => $mapping) {
            $category = $mapping['category'];
            $subcategory = $mapping['subcategory'] ?? 'general';
            
            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            
            if (!isset($grouped[$category][$subcategory])) {
                $grouped[$category][$subcategory] = [];
            }
            
            $grouped[$category][$subcategory][] = [
                'type' => $type,
                'display_name' => $mapping['display_name'],
                'default_unit' => $mapping['default_unit'],
                'priority' => $mapping['priority'] ?? 5
            ];
        }

        // Sort by priority within each subcategory
        foreach ($grouped as $category => $subcategories) {
            foreach ($subcategories as $subcategory => $metrics) {
                usort($grouped[$category][$subcategory], function($a, $b) {
                    return ($a['priority'] ?? 5) <=> ($b['priority'] ?? 5);
                });
            }
        }

        return $grouped;
    }

    /**
     * Get mapping statistics for monitoring
     */
    public function getMappingStatistics(): array
    {
        return [
            'total_mappings' => count($this->standardMappings),
            'categories' => array_unique(array_column($this->standardMappings, 'category')),
            'subcategories' => array_filter(array_unique(array_column($this->standardMappings, 'subcategory'))),
            'aliases_count' => count($this->aliasMap),
            'fuzzy_patterns' => count($this->fuzzyMappings)
        ];
    }

    /**
     * Clean and normalize parameter name for matching
     */
    protected function cleanParameterName(string $rawName): string
    {
        // Convert to lowercase
        $clean = strtolower(trim($rawName));
        
        // Remove common prefixes/suffixes
        $clean = preg_replace('/^(serum|plasma|blood|total|free)\s+/', '', $clean);
        $clean = preg_replace('/\s+(level|concentration|count)$/', '', $clean);
        
        // Normalize whitespace
        $clean = preg_replace('/\s+/', ' ', $clean);
        
        // Remove special characters but keep medical symbols
        $clean = preg_replace('/[^\w\s\-°µ]/', '', $clean);
        
        return trim($clean);
    }

    /**
     * Find exact match in standard mappings
     */
    protected function findExactMatch(string $cleanName): ?array
    {
        return $this->standardMappings[$cleanName] ?? null;
    }

    /**
     * Find match through alias system
     */
    protected function findAliasMatch(string $cleanName): ?array
    {
        if (isset($this->aliasMap[$cleanName])) {
            $standardName = $this->aliasMap[$cleanName];
            return $this->standardMappings[$standardName] ?? null;
        }

        return null;
    }

    /**
     * Find fuzzy match using partial string matching
     */
    protected function findFuzzyMatch(string $cleanName): ?array
    {
        foreach ($this->fuzzyMappings as $pattern => $standardType) {
            if (strpos($cleanName, $pattern) !== false || strpos($pattern, $cleanName) !== false) {
                return $this->standardMappings[$standardType] ?? null;
            }
        }

        return null;
    }

    /**
     * Find match using context (unit, value, etc.)
     */
    protected function findContextualMatch(string $cleanName, array $context): ?array
    {
        $unit = strtolower($context['unit'] ?? '');
        $value = $context['value'] ?? '';

        // Blood pressure pattern (xxx/xxx mmHg)
        if (preg_match('/\d+\/\d+/', $value) && strpos($unit, 'mmhg') !== false) {
            return $this->standardMappings['blood_pressure'] ?? null;
        }

        // Unit-based contextual matching
        $unitMappings = [
            'mg/dl' => ['cholesterol', 'glucose', 'creatinine', 'bilirubin'],
            'miu/l' => ['tsh'],
            'ng/ml' => ['vitamin_d', 'ferritin'],
            'pg/ml' => ['vitamin_b12'],
            'u/l' => ['alt', 'ast', 'alp'],
            'g/dl' => ['hemoglobin'],
            '%' => ['hba1c', 'hematocrit']
        ];

        if (isset($unitMappings[$unit])) {
            foreach ($unitMappings[$unit] as $possibleType) {
                if (strpos($cleanName, $possibleType) !== false) {
                    return $this->standardMappings[$possibleType] ?? null;
                }
            }
        }

        return null;
    }

    /**
     * Enhance mapping with additional context and metadata
     */
    protected function enhanceMapping(array $baseMapping, array $context): array
    {
        $enhanced = $baseMapping;
        
        // Add context-specific unit if provided
        if (!empty($context['unit'])) {
            $enhanced['detected_unit'] = $context['unit'];
        }
        
        // Add confidence score based on match type
        $enhanced['mapping_confidence'] = $this->calculateMappingConfidence($baseMapping, $context);
        
        // Add validation hints
        $enhanced['validation_hints'] = $this->getValidationHints($baseMapping, $context);
        
        return $enhanced;
    }

    /**
     * Calculate confidence score for the mapping
     */
    protected function calculateMappingConfidence(array $mapping, array $context): float
    {
        $confidence = 0.8; // Base confidence
        
        // Boost confidence if units match
        if (!empty($context['unit']) && !empty($mapping['default_unit'])) {
            if (strtolower($context['unit']) === strtolower($mapping['default_unit'])) {
                $confidence += 0.15;
            }
        }
        
        // Boost confidence for high-priority mappings
        if (($mapping['priority'] ?? 5) <= 2) {
            $confidence += 0.05;
        }
        
        return min(1.0, $confidence);
    }

    /**
     * Get validation hints for the mapped metric
     */
    protected function getValidationHints(array $mapping, array $context): array
    {
        $hints = [];
        
        // Add reference range hint
        if (!empty($mapping['reference_range'])) {
            $hints['reference_range'] = $mapping['reference_range'];
        }
        
        // Add unit validation hint
        if (!empty($mapping['default_unit'])) {
            $hints['expected_unit'] = $mapping['default_unit'];
        }
        
        // Add value type hint
        $hints['value_type'] = $mapping['value_type'] ?? 'numeric';
        
        return $hints;
    }

    /**
     * Initialize all mapping configurations
     */
    protected function initializeMappings(): void
    {
        $this->initializeStandardMappings();
        $this->initializeAliasMap();
        $this->initializeFuzzyMappings();
    }

    /**
     * Initialize comprehensive standard mappings (moved from ReportController)
     */
    protected function initializeStandardMappings(): void
    {
        $this->standardMappings = [
            // ===== CHOLESTEROL PANEL =====
            'hdl' => [
                'type' => 'hdl',
                'category' => 'organs',
                'subcategory' => 'heart',
                'display_name' => 'HDL Cholesterol',
                'default_unit' => 'mg/dL',
                'priority' => 1,
                'value_type' => 'numeric',
                'reference_range' => ['min' => 40, 'max' => 60, 'unit' => 'mg/dL']
            ],
            'ldl' => [
                'type' => 'ldl',
                'category' => 'organs',
                'subcategory' => 'heart',
                'display_name' => 'LDL Cholesterol',
                'default_unit' => 'mg/dL',
                'priority' => 1,
                'value_type' => 'numeric',
                'reference_range' => ['min' => 0, 'max' => 100, 'unit' => 'mg/dL']
            ],
            'total_cholesterol' => [
                'type' => 'total_cholesterol',
                'category' => 'organs',
                'subcategory' => 'heart',
                'display_name' => 'Total Cholesterol',
                'default_unit' => 'mg/dL',
                'priority' => 1,
                'value_type' => 'numeric',
                'reference_range' => ['min' => 125, 'max' => 200, 'unit' => 'mg/dL']
            ],
            'triglycerides' => [
                'type' => 'triglycerides',
                'category' => 'organs',
                'subcategory' => 'heart',
                'display_name' => 'Triglycerides',
                'default_unit' => 'mg/dL',
                'priority' => 1,
                'value_type' => 'numeric',
                'reference_range' => ['min' => 0, 'max' => 150, 'unit' => 'mg/dL']
            ],
            'vldl' => [
                'type' => 'vldl',
                'category' => 'organs',
                'subcategory' => 'heart',
                'display_name' => 'VLDL Cholesterol',
                'default_unit' => 'mg/dL',
                'priority' => 2,
                'value_type' => 'numeric',
                'reference_range' => ['min' => 5, 'max' => 40, 'unit' => 'mg/dL']
            ],

            // ===== THYROID PANEL =====
            'tsh' => [
                'type' => 'tsh',
                'category' => 'organs',
                'subcategory' => 'thyroid',
                'display_name' => 'TSH',
                'default_unit' => 'mIU/L',
                'priority' => 1,
                'value_type' => 'numeric',
                'reference_range' => ['min' => 0.4, 'max' => 4.0, 'unit' => 'mIU/L']
            ],
            't3' => [
                'type' => 't3',
                'category' => 'organs',
                'subcategory' => 'thyroid',
                'display_name' => 'T3',
                'default_unit' => 'ng/dL',
                'priority' => 2,
                'value_type' => 'numeric',
                'reference_range' => ['min' => 80, 'max' => 200, 'unit' => 'ng/dL']
            ],
            't4' => [
                'type' => 't4',
                'category' => 'organs',
                'subcategory' => 'thyroid',
                'display_name' => 'T4',
                'default_unit' => 'μg/dL',
                'priority' => 2,
                'value_type' => 'numeric',
                'reference_range' => ['min' => 5.1, 'max' => 14.1, 'unit' => 'μg/dL']
            ],
            'free_t3' => [
                'type' => 'free_t3',
                'category' => 'organs',
                'subcategory' => 'thyroid',
                'display_name' => 'Free T3',
                'default_unit' => 'pg/mL',
                'priority' => 2,
                'value_type' => 'numeric',
                'reference_range' => ['min' => 2.0, 'max' => 4.4, 'unit' => 'pg/mL']
            ],
            'free_t4' => [
                'type' => 'free_t4',
                'category' => 'organs',
                'subcategory' => 'thyroid',
                'display_name' => 'Free T4',
                'default_unit' => 'ng/dL',
                'priority' => 2,
                'value_type' => 'numeric',
                'reference_range' => ['min' => 0.82, 'max' => 1.77, 'unit' => 'ng/dL']
            ],

            // ===== VITAMINS & MINERALS =====
            'vitamin_d' => [
                'type' => 'vitamin_d',
                'category' => 'vitamins',
                'subcategory' => null,
                'display_name' => 'Vitamin D',
                'default_unit' => 'ng/mL',
                'priority' => 1,
                'value_type' => 'numeric',
                'reference_range' => ['min' => 30, 'max' => 100, 'unit' => 'ng/mL']
            ],
            'vitamin_b12' => [
                'type' => 'vitamin_b12',
                'category' => 'vitamins',
                'subcategory' => null,
                'display_name' => 'Vitamin B12',
                'default_unit' => 'pg/mL',
                'priority' => 1,
                'value_type' => 'numeric',
                'reference_range' => ['min' => 200, 'max' => 900, 'unit' => 'pg/mL']
            ],
            'folate' => [
                'type' => 'folate',
                'category' => 'vitamins',
                'subcategory' => null,
                'display_name' => 'Folate',
                'default_unit' => 'ng/mL',
                'priority' => 2,
                'value_type' => 'numeric',
                'reference_range' => ['min' => 2.7, 'max' => 17.0, 'unit' => 'ng/mL']
            ],
            'iron' => [
                'type' => 'iron',
                'category' => 'vitamins',
                'subcategory' => null,
                'display_name' => 'Iron',
                'default_unit' => 'μg/dL',
                'priority' => 2,
                'value_type' => 'numeric',
                'reference_range' => ['min' => 60, 'max' => 170, 'unit' => 'μg/dL']
            ],
            'ferritin' => [
                'type' => 'ferritin',
                'category' => 'vitamins',
                'subcategory' => null,
                'display_name' => 'Ferritin',
                'default_unit' => 'ng/mL',
                'priority' => 2,
                'value_type' => 'numeric',
                'reference_range' => ['min' => 12, 'max' => 300, 'unit' => 'ng/mL']
            ],

            // ===== LIVER FUNCTION =====
            'alt' => [
                'type' => 'alt',
                'category' => 'organs',
                'subcategory' => 'liver',
                'display_name' => 'ALT',
                'default_unit' => 'U/L',
                'priority' => 1,
                'value_type' => 'numeric',
                'reference_range' => ['min' => 7, 'max' => 40, 'unit' => 'U/L']
            ],
            'ast' => [
                'type' => 'ast',
                'category' => 'organs',
                'subcategory' => 'liver',
                'display_name' => 'AST',
                'default_unit' => 'U/L',
                'priority' => 1,
                'value_type' => 'numeric',
                'reference_range' => ['min' => 8, 'max' => 40, 'unit' => 'U/L']
            ],
            'alp' => [
                'type' => 'alp',
                'category' => 'organs',
                'subcategory' => 'liver',
                'display_name' => 'ALP',
                'default_unit' => 'U/L',
                'priority' => 2,
                'value_type' => 'numeric',
                'reference_range' => ['min' => 44, 'max' => 147, 'unit' => 'U/L']
            ],
            'bilirubin' => [
                'type' => 'bilirubin',
                'category' => 'organs',
                'subcategory' => 'liver',
                'display_name' => 'Bilirubin',
                'default_unit' => 'mg/dL',
                'priority' => 1,
                'value_type' => 'numeric',
                'reference_range' => ['min' => 0.1, 'max' => 1.2, 'unit' => 'mg/dL']
            ],

            // ===== KIDNEY FUNCTION =====
            'creatinine' => [
                'type' => 'creatinine',
                'category' => 'organs',
                'subcategory' => 'kidney',
                'display_name' => 'Creatinine',
                'default_unit' => 'mg/dL',
                'priority' => 1,
                'value_type' => 'numeric',
                'reference_range' => ['min' => 0.7, 'max' => 1.3, 'unit' => 'mg/dL']
            ],
            'blood_urea_nitrogen' => [
                'type' => 'blood_urea_nitrogen',
                'category' => 'organs',
                'subcategory' => 'kidney',
                'display_name' => 'Blood Urea Nitrogen',
                'default_unit' => 'mg/dL',
                'priority' => 1,
                'value_type' => 'numeric',
                'reference_range' => ['min' => 7, 'max' => 20, 'unit' => 'mg/dL']
            ],
            'uric_acid' => [
                'type' => 'uric_acid',
                'category' => 'organs',
                'subcategory' => 'kidney',
                'display_name' => 'Uric Acid',
                'default_unit' => 'mg/dL',
                'priority' => 2,
                'value_type' => 'numeric',
                'reference_range' => ['min' => 3.4, 'max' => 7.0, 'unit' => 'mg/dL']
            ],
            'egfr' => [
                'type' => 'egfr',
                'category' => 'organs',
                'subcategory' => 'kidney',
                'display_name' => 'eGFR',
                'default_unit' => 'mL/min/1.73m²',
                'priority' => 2,
                'value_type' => 'numeric',
                'reference_range' => ['min' => 90, 'max' => 120, 'unit' => 'mL/min/1.73m²']
            ],

            // ===== BLOOD COUNT =====
            'hemoglobin' => [
                'type' => 'hemoglobin',
                'category' => 'blood',
                'subcategory' => null,
                'display_name' => 'Hemoglobin',
                'default_unit' => 'g/dL',
                'priority' => 1,
                'value_type' => 'numeric',
                'reference_range' => ['min' => 12.0, 'max' => 17.5, 'unit' => 'g/dL']
            ],
            'hematocrit' => [
                'type' => 'hematocrit',
                'category' => 'blood',
                'subcategory' => null,
                'display_name' => 'Hematocrit',
                'default_unit' => '%',
                'priority' => 2,
                'value_type' => 'numeric',
                'reference_range' => ['min' => 36, 'max' => 52, 'unit' => '%']
            ],
            'rbc_count' => [
                'type' => 'rbc_count',
                'category' => 'blood',
                'subcategory' => null,
                'display_name' => 'RBC Count',
                'default_unit' => 'million/µL',
                'priority' => 2,
                'value_type' => 'numeric',
                'reference_range' => ['min' => 4.5, 'max' => 5.5, 'unit' => 'million/µL']
            ],
            'wbc_count' => [
                'type' => 'wbc_count',
                'category' => 'blood',
                'subcategory' => null,
                'display_name' => 'WBC Count',
                'default_unit' => 'thousand/µL',
                'priority' => 2,
                'value_type' => 'numeric',
                'reference_range' => ['min' => 4.5, 'max' => 11.0, 'unit' => 'thousand/µL']
            ],
            'platelet_count' => [
                'type' => 'platelet_count',
                'category' => 'blood',
                'subcategory' => null,
                'display_name' => 'Platelet Count',
                'default_unit' => 'thousand/µL',
                'priority' => 2,
                'value_type' => 'numeric',
                'reference_range' => ['min' => 150, 'max' => 450, 'unit' => 'thousand/µL']
            ],

            // ===== DIABETES/GLUCOSE =====
            'glucose_fasting' => [
                'type' => 'glucose_fasting',
                'category' => 'blood',
                'subcategory' => null,
                'display_name' => 'Fasting Glucose',
                'default_unit' => 'mg/dL',
                'priority' => 1,
                'value_type' => 'numeric',
                'reference_range' => ['min' => 70, 'max' => 99, 'unit' => 'mg/dL']
            ],
            'hba1c' => [
                'type' => 'hba1c',
                'category' => 'blood',
                'subcategory' => null,
                'display_name' => 'HbA1c',
                'default_unit' => '%',
                'priority' => 1,
                'value_type' => 'numeric',
                'reference_range' => ['min' => 4.0, 'max' => 5.6, 'unit' => '%']
            ],

            // ===== SPECIAL CASES =====
            'blood_pressure' => [
                'type' => 'blood_pressure',
                'category' => 'organs',
                'subcategory' => 'heart',
                'display_name' => 'Blood Pressure',
                'default_unit' => 'mmHg',
                'priority' => 1,
                'value_type' => 'blood_pressure',
                'reference_range' => ['systolic' => ['min' => 90, 'max' => 120], 'diastolic' => ['min' => 60, 'max' => 80], 'unit' => 'mmHg']
            ]
        ];
    }

    /**
     * Initialize alias mappings for common variations
     */
    protected function initializeAliasMap(): void
    {
        $this->aliasMap = [
            // Cholesterol variations
            'hdl cholesterol' => 'hdl',
            'hdl-c' => 'hdl',
            'high density lipoprotein' => 'hdl',
            'ldl cholesterol' => 'ldl',
            'ldl-c' => 'ldl',
            'low density lipoprotein' => 'ldl',
            'cholesterol' => 'total_cholesterol',
            'chol' => 'total_cholesterol',
            'triglyceride' => 'triglycerides',
            'tg' => 'triglycerides',
            
            // Thyroid variations
            'thyroid stimulating hormone' => 'tsh',
            'thyrotropin' => 'tsh',
            'triiodothyronine' => 't3',
            'thyroxine' => 't4',
            
            // Vitamin variations
            'vit d' => 'vitamin_d',
            '25-hydroxy vitamin d' => 'vitamin_d',
            'vitamin d3' => 'vitamin_d',
            'vit b12' => 'vitamin_b12',
            'cobalamin' => 'vitamin_b12',
            'folic acid' => 'folate',
            
            // Liver function variations
            'alanine aminotransferase' => 'alt',
            'sgpt' => 'alt',
            'aspartate aminotransferase' => 'ast',
            'sgot' => 'ast',
            'alkaline phosphatase' => 'alp',
            'total bilirubin' => 'bilirubin',
            
            // Kidney function variations
            'serum creatinine' => 'creatinine',
            'bun' => 'blood_urea_nitrogen',
            'estimated gfr' => 'egfr',
            
            // Blood variations
            'hb' => 'hemoglobin',
            'haemoglobin' => 'hemoglobin',
            'hct' => 'hematocrit',
            'red blood cell count' => 'rbc_count',
            'white blood cell count' => 'wbc_count',
            'platelets' => 'platelet_count',
            
            // Glucose variations
            'glucose' => 'glucose_fasting',
            'blood sugar' => 'glucose_fasting',
            'fasting glucose' => 'glucose_fasting',
            'glycated hemoglobin' => 'hba1c',
            'glycosylated hemoglobin' => 'hba1c',
            
            // Blood pressure variations
            'bp' => 'blood_pressure',
            'systolic' => 'blood_pressure',
            'diastolic' => 'blood_pressure'
        ];
    }

    /**
     * Initialize fuzzy matching patterns
     */
    protected function initializeFuzzyMappings(): void
    {
        $this->fuzzyMappings = [
            'cholesterol' => 'total_cholesterol',
            'vitamin' => 'vitamin_d', // Most common vitamin in medical reports
            'sugar' => 'glucose_fasting',
            'pressure' => 'blood_pressure',
            'hemoglobin' => 'hemoglobin',
            'creatinine' => 'creatinine',
            'bilirubin' => 'bilirubin',
            'thyroid' => 'tsh' // Most common thyroid test
        ];
    }
}