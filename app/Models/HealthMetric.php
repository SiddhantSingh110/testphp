<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HealthMetric extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'patient_id',
        'type',
        'custom_type',
        'value',
        'unit',
        'measured_at',
        'notes',
        'source',
        'context',
        'status',
        'category',
        'subcategory'
    ];
    
    protected $casts = [
        'measured_at' => 'datetime',
    ];
    
    /**
     * Core reference ranges for common parameters
     * This list can grow over time as we encounter new parameters
     */
    public static function getCoreReferenceRanges()
    {
        return [
            // === CARDIOVASCULAR (Heart) ===
            'hdl' => [
                'min' => 40, 'max' => 60, 'unit' => 'mg/dL',
                'category' => 'organs', 'subcategory' => 'heart',
                'warningLow' => 35, 'full_name' => 'HDL Cholesterol'
            ],
            'ldl' => [
                'min' => 0, 'max' => 100, 'unit' => 'mg/dL',
                'category' => 'organs', 'subcategory' => 'heart',
                'warningHigh' => 130, 'criticalHigh' => 160,
                'full_name' => 'LDL Cholesterol'
            ],
            'total_cholesterol' => [
                'min' => 125, 'max' => 200, 'unit' => 'mg/dL',
                'category' => 'organs', 'subcategory' => 'heart',
                'warningHigh' => 240, 'criticalHigh' => 300,
                'full_name' => 'Total Cholesterol'
            ],
            'triglycerides' => [
                'min' => 0, 'max' => 150, 'unit' => 'mg/dL',
                'category' => 'organs', 'subcategory' => 'heart',
                'warningHigh' => 200, 'criticalHigh' => 500,
                'full_name' => 'Triglycerides'
            ],
            
            // === COMPLETE BLOOD COUNT (CBC) ===
            'hemoglobin' => [
                'min' => 12.0, 'max' => 17.0, 'unit' => 'g/dL',
                'category' => 'blood', 'subcategory' => 'cbc',
                'warningLow' => 10.0, 'criticalLow' => 8.0,
                'full_name' => 'Hemoglobin'
            ],
            'hematocrit' => [
                'min' => 36, 'max' => 50, 'unit' => '%',
                'category' => 'blood', 'subcategory' => 'cbc',
                'warningLow' => 30, 'criticalLow' => 25,
                'full_name' => 'Hematocrit'
            ],
            'rbc' => [
                'min' => 4.2, 'max' => 5.8, 'unit' => 'million/µL',
                'category' => 'blood', 'subcategory' => 'cbc',
                'full_name' => 'Red Blood Cell Count'
            ],
            'wbc' => [
                'min' => 4.0, 'max' => 11.0, 'unit' => 'thousand/µL',
                'category' => 'blood', 'subcategory' => 'cbc',
                'warningLow' => 3.0, 'warningHigh' => 15.0,
                'full_name' => 'White Blood Cell Count'
            ],
            'platelet_count' => [
                'min' => 150, 'max' => 450, 'unit' => 'thousand/µL',
                'category' => 'blood', 'subcategory' => 'cbc',
                'warningLow' => 100, 'criticalLow' => 50,
                'full_name' => 'Platelet Count'
            ],
            
            // === LIVER FUNCTION ===
            'alt' => [
                'min' => 7, 'max' => 40, 'unit' => 'U/L',
                'category' => 'organs', 'subcategory' => 'liver',
                'warningHigh' => 50, 'criticalHigh' => 200,
                'full_name' => 'Alanine Aminotransferase (ALT)'
            ],
            'ast' => [
                'min' => 8, 'max' => 40, 'unit' => 'U/L',
                'category' => 'organs', 'subcategory' => 'liver',
                'warningHigh' => 50, 'criticalHigh' => 200,
                'full_name' => 'Aspartate Aminotransferase (AST)'
            ],
            'bilirubin_total' => [
                'min' => 0.2, 'max' => 1.2, 'unit' => 'mg/dL',
                'category' => 'organs', 'subcategory' => 'liver',
                'warningHigh' => 2.0, 'criticalHigh' => 5.0,
                'full_name' => 'Total Bilirubin'
            ],
            'alp' => [
                'min' => 44, 'max' => 147, 'unit' => 'U/L',
                'category' => 'organs', 'subcategory' => 'liver',
                'warningHigh' => 200, 'full_name' => 'Alkaline Phosphatase'
            ],
            
            // === KIDNEY FUNCTION ===
            'creatinine' => [
                'min' => 0.7, 'max' => 1.3, 'unit' => 'mg/dL',
                'category' => 'organs', 'subcategory' => 'kidney',
                'warningHigh' => 1.5, 'criticalHigh' => 2.0,
                'full_name' => 'Creatinine'
            ],
            'blood_urea_nitrogen' => [
                'min' => 7, 'max' => 20, 'unit' => 'mg/dL',
                'category' => 'organs', 'subcategory' => 'kidney',
                'warningHigh' => 25, 'criticalHigh' => 50,
                'full_name' => 'Blood Urea Nitrogen (BUN)'
            ],
            'uric_acid' => [
                'min' => 3.0, 'max' => 7.0, 'unit' => 'mg/dL',
                'category' => 'organs', 'subcategory' => 'kidney',
                'warningHigh' => 8.0, 'criticalHigh' => 10.0,
                'full_name' => 'Uric Acid'
            ],
            
            // === THYROID FUNCTION ===
            'tsh' => [
                'min' => 0.4, 'max' => 4.0, 'unit' => 'mIU/L',
                'category' => 'organs', 'subcategory' => 'thyroid',
                'warningLow' => 0.1, 'warningHigh' => 6.0,
                'criticalLow' => 0.01, 'criticalHigh' => 10.0,
                'full_name' => 'Thyroid Stimulating Hormone (TSH)'
            ],
            't3_total' => [
                'min' => 80, 'max' => 200, 'unit' => 'ng/dL',
                'category' => 'organs', 'subcategory' => 'thyroid',
                'warningLow' => 70, 'warningHigh' => 220,
                'full_name' => 'Total T3'
            ],
            't4_total' => [
                'min' => 5.0, 'max' => 12.0, 'unit' => 'μg/dL',
                'category' => 'organs', 'subcategory' => 'thyroid',
                'warningLow' => 4.0, 'warningHigh' => 14.0,
                'full_name' => 'Total T4'
            ],
            
            // === DIABETES PANEL ===
            'glucose_fasting' => [
                'min' => 70, 'max' => 99, 'unit' => 'mg/dL',
                'category' => 'blood', 'subcategory' => 'diabetes',
                'warningHigh' => 125, 'criticalHigh' => 180,
                'full_name' => 'Fasting Glucose'
            ],
            'hba1c' => [
                'min' => 4.0, 'max' => 5.6, 'unit' => '%',
                'category' => 'blood', 'subcategory' => 'diabetes',
                'warningHigh' => 6.4, 'criticalHigh' => 7.0,
                'full_name' => 'Hemoglobin A1c'
            ],
            
            // === VITAMINS & MINERALS ===
            'vitamin_d' => [
                'min' => 30, 'max' => 100, 'unit' => 'ng/mL',
                'category' => 'vitamins', 'subcategory' => 'fat_soluble',
                'warningLow' => 20, 'criticalLow' => 12,
                'full_name' => 'Vitamin D (25-OH)'
            ],
            'vitamin_b12' => [
                'min' => 200, 'max' => 900, 'unit' => 'pg/mL',
                'category' => 'vitamins', 'subcategory' => 'b_complex',
                'warningLow' => 150, 'criticalLow' => 100,
                'full_name' => 'Vitamin B12'
            ],
            'folate' => [
                'min' => 3.0, 'max' => 20.0, 'unit' => 'ng/mL',
                'category' => 'vitamins', 'subcategory' => 'b_complex',
                'warningLow' => 2.0, 'criticalLow' => 1.0,
                'full_name' => 'Folate (Folic Acid)'
            ],
            'iron' => [
                'min' => 60, 'max' => 170, 'unit' => 'μg/dL',
                'category' => 'minerals', 'subcategory' => null,
                'warningLow' => 50, 'criticalLow' => 30,
                'full_name' => 'Iron'
            ],
            'ferritin' => [
                'min' => 12, 'max' => 300, 'unit' => 'ng/mL',
                'category' => 'minerals', 'subcategory' => null,
                'warningLow' => 10, 'criticalLow' => 5,
                'full_name' => 'Ferritin'
            ],
            
            // === INFLAMMATORY MARKERS ===
            'crp' => [
                'min' => 0, 'max' => 3.0, 'unit' => 'mg/L',
                'category' => 'inflammatory', 'subcategory' => null,
                'warningHigh' => 10.0, 'criticalHigh' => 50.0,
                'full_name' => 'C-Reactive Protein (CRP)'
            ],
            'esr' => [
                'min' => 0, 'max' => 20, 'unit' => 'mm/hr',
                'category' => 'inflammatory', 'subcategory' => null,
                'warningHigh' => 30, 'criticalHigh' => 100,
                'full_name' => 'Erythrocyte Sedimentation Rate (ESR)'
            ]
        ];
    }
    
    /**
     * SMART: Try to identify unknown parameters using pattern matching
     * This handles parameters not in our core list
     */
    public static function identifyUnknownParameter($parameterName)
    {
        $name = strtolower(trim($parameterName));
        
        // Remove common prefixes/suffixes
        $name = preg_replace('/\s+(total|free|direct|indirect|serum|plasma|blood|urine)\s*$/i', '', $name);
        $name = preg_replace('/^(serum|plasma|blood|urine)\s+/i', '', $name);
        
        // Pattern matching for categories
        $patterns = [
            // Vitamins
            'vitamins' => [
                'vitamin', 'vit', 'b1', 'b2', 'b6', 'b9', 'thiamine', 'riboflavin', 
                'niacin', 'pyridoxine', 'cobalamin', 'ascorbic', 'tocopherol', 'retinol'
            ],
            // Minerals
            'minerals' => [
                'calcium', 'magnesium', 'phosphorus', 'zinc', 'copper', 'selenium', 
                'manganese', 'chromium', 'iron', 'ferritin'
            ],
            // Hormones
            'hormones' => [
                'testosterone', 'estrogen', 'progesterone', 'cortisol', 'insulin',
                'growth hormone', 'prolactin', 'lh', 'fsh'
            ],
            // Cardiac markers
            'cardiac' => [
                'troponin', 'ck-mb', 'bnp', 'nt-probnp', 'myoglobin'
            ],
            // Liver enzymes
            'liver' => [
                'alt', 'ast', 'alp', 'ggt', 'bilirubin', 'albumin', 'protein'
            ]
        ];
        
        foreach ($patterns as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($name, $keyword) !== false) {
                    return [
                        'category' => $category,
                        'subcategory' => null,
                        'full_name' => ucwords(str_replace('_', ' ', $parameterName)),
                        'identified_by' => 'pattern_matching'
                    ];
                }
            }
        }
        
        // Default category for unidentified parameters
        return [
            'category' => 'unknown',
            'subcategory' => null,
            'full_name' => ucwords(str_replace('_', ' ', $parameterName)),
            'identified_by' => 'fallback'
        ];
    }
    
    /**
     * Get reference ranges - checks core list first, then tries to identify unknown
     */
    public static function getReferenceRanges($type = null)
    {
        $coreRanges = self::getCoreReferenceRanges();
        
        if ($type) {
            // Check if we have this specific type
            if (isset($coreRanges[$type])) {
                return $coreRanges[$type];
            }
            
            // Try to identify unknown parameter
            $identified = self::identifyUnknownParameter($type);
            return array_merge([
                'min' => null,
                'max' => null,
                'unit' => 'unknown'
            ], $identified);
        }
        
        return $coreRanges;
    }
    
    /**
     * Enhanced status calculation that handles unknown parameters gracefully
     */
    public function calculateStatus()
    {
        $range = self::getReferenceRanges($this->type);
        
        // If we don't have reference ranges, return null (will be handled by frontend)
        if (!$range || (!isset($range['min']) && !isset($range['max']))) {
            return null;
        }
        
        $value = $this->value;
        
        // Handle blood pressure specially
        if ($this->type === 'blood_pressure' && strpos($value, '/') !== false) {
            [$systolic, $diastolic] = array_map('intval', explode('/', $value));
            
            if (isset($range['max']) && strpos($range['max'], '/') !== false) {
                [$maxSys, $maxDia] = array_map('intval', explode('/', $range['max']));
                
                if (isset($range['warningHigh'])) {
                    [$warnSys, $warnDia] = array_map('intval', explode('/', $range['warningHigh']));
                    if ($systolic > $warnSys || $diastolic > $warnDia) return 'high';
                }
                
                if ($systolic > $maxSys || $diastolic > $maxDia) return 'borderline';
                return 'normal';
            }
        }
        
        // Handle numeric values
        $numValue = floatval($value);
        
        // Check critical levels first
        if (isset($range['criticalLow']) && $numValue <= $range['criticalLow']) return 'high';
        if (isset($range['criticalHigh']) && $numValue >= $range['criticalHigh']) return 'high';
        
        // Check warning levels
        if (isset($range['warningLow']) && $numValue <= $range['warningLow']) return 'borderline';
        if (isset($range['warningHigh']) && $numValue >= $range['warningHigh']) return 'borderline';
        
        // Check normal range
        if (isset($range['min']) && isset($range['max'])) {
            if ($numValue < $range['min'] || $numValue > $range['max']) return 'borderline';
        }
        
        return 'normal';
    }
    
    /**
     * Auto-populate category and subcategory based on type
     */
    public function setMetricCategories()
    {
        $range = self::getReferenceRanges($this->type);
        
        if ($range && isset($range['category'])) {
            $this->category = $range['category'];
            $this->subcategory = $range['subcategory'];
        }
    }
    
    /**
     * Create metrics from AI report extraction
     * This method handles bulk creation from medical reports
     */
    public static function createFromAIExtraction($patientId, $extractedData)
    {
        $createdMetrics = [];
        
        foreach ($extractedData as $data) {
            $parameterName = strtolower(str_replace([' ', '-', '(', ')'], '_', $data['parameter']));
            
            $metric = new self([
                'patient_id' => $patientId,
                'type' => $parameterName,
                'value' => $data['value'],
                'unit' => $data['unit'] ?? 'unknown',
                'measured_at' => now(),
                'source' => 'report',
                'context' => 'medical_test',
                'notes' => 'Auto-extracted from medical report'
            ]);
            
            // Auto-populate categories
            $metric->setMetricCategories();
            
            // Calculate status
            $metric->status = $metric->calculateStatus();
            
            $metric->save();
            $createdMetrics[] = $metric;
        }
        
        return $createdMetrics;
    }
    
    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }
}