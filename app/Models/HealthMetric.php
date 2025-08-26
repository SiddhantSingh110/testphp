<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

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

    // Relationships
    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * ✨ ENHANCED: Set metric categories based on type
     * This method is called automatically when creating health metrics
     */
    public function setMetricCategories()
    {
        $categoryMappings = $this->getCategoryMappings();
        
        if (isset($categoryMappings[$this->type])) {
            $this->category = $categoryMappings[$this->type]['category'];
            $this->subcategory = $categoryMappings[$this->type]['subcategory'];
        } else {
            // Default fallback
            $this->category = 'custom';
            $this->subcategory = null;
            
            Log::info('Unknown metric type, assigned to custom category', [
                'type' => $this->type,
                'patient_id' => $this->patient_id
            ]);
        }
    }

    /**
     * ✨ COMPREHENSIVE CATEGORY MAPPINGS
     * Maps metric types to categories for proper organization in the UI
     */
    private function getCategoryMappings()
    {
        return [
            // Heart/Cardiovascular - Organs > Heart
            'hdl' => ['category' => 'organs', 'subcategory' => 'heart'],
            'ldl' => ['category' => 'organs', 'subcategory' => 'heart'],
            'total_cholesterol' => ['category' => 'organs', 'subcategory' => 'heart'],
            'triglycerides' => ['category' => 'organs', 'subcategory' => 'heart'],
            'vldl' => ['category' => 'organs', 'subcategory' => 'heart'],
            'non_hdl_cholesterol' => ['category' => 'organs', 'subcategory' => 'heart'],
            'blood_pressure' => ['category' => 'organs', 'subcategory' => 'heart'],
            'troponin' => ['category' => 'organs', 'subcategory' => 'heart'],
            'ck_mb' => ['category' => 'organs', 'subcategory' => 'heart'],
            'bnp' => ['category' => 'organs', 'subcategory' => 'heart'],

            // Liver Function - Organs > Liver
            'alt' => ['category' => 'organs', 'subcategory' => 'liver'],
            'ast' => ['category' => 'organs', 'subcategory' => 'liver'],
            'alp' => ['category' => 'organs', 'subcategory' => 'liver'],
            'bilirubin' => ['category' => 'organs', 'subcategory' => 'liver'],
            'total_bilirubin' => ['category' => 'organs', 'subcategory' => 'liver'],
            'direct_bilirubin' => ['category' => 'organs', 'subcategory' => 'liver'],
            'indirect_bilirubin' => ['category' => 'organs', 'subcategory' => 'liver'],

            // Kidney Function - Organs > Kidney
            'creatinine' => ['category' => 'organs', 'subcategory' => 'kidney'],
            'blood_urea_nitrogen' => ['category' => 'organs', 'subcategory' => 'kidney'],
            'uric_acid' => ['category' => 'organs', 'subcategory' => 'kidney'],
            'egfr' => ['category' => 'organs', 'subcategory' => 'kidney'],
            'bun' => ['category' => 'organs', 'subcategory' => 'kidney'],

            // Thyroid Function - Organs > Thyroid
            'tsh' => ['category' => 'organs', 'subcategory' => 'thyroid'],
            't3' => ['category' => 'organs', 'subcategory' => 'thyroid'],
            't4' => ['category' => 'organs', 'subcategory' => 'thyroid'],
            'free_t3' => ['category' => 'organs', 'subcategory' => 'thyroid'],
            'free_t4' => ['category' => 'organs', 'subcategory' => 'thyroid'],

            // Endocrine System - Organs > Endocrine
            'insulin' => ['category' => 'organs', 'subcategory' => 'endocrine'],
            'cortisol' => ['category' => 'organs', 'subcategory' => 'endocrine'],
            'testosterone' => ['category' => 'organs', 'subcategory' => 'endocrine'],
            'estrogen' => ['category' => 'organs', 'subcategory' => 'endocrine'],
            'growth_hormone' => ['category' => 'organs', 'subcategory' => 'endocrine'],

            // Blood Tests - Blood Category
            'hemoglobin' => ['category' => 'blood', 'subcategory' => null],
            'hematocrit' => ['category' => 'blood', 'subcategory' => null],
            'rbc_count' => ['category' => 'blood', 'subcategory' => null],
            'wbc_count' => ['category' => 'blood', 'subcategory' => null],
            'platelet_count' => ['category' => 'blood', 'subcategory' => null],
            'glucose_fasting' => ['category' => 'blood', 'subcategory' => null],
            'blood_sugar' => ['category' => 'blood', 'subcategory' => null],
            'hba1c' => ['category' => 'blood', 'subcategory' => null],
            'sodium' => ['category' => 'blood', 'subcategory' => null],
            'potassium' => ['category' => 'blood', 'subcategory' => null],
            'chloride' => ['category' => 'blood', 'subcategory' => null],
            'co2' => ['category' => 'blood', 'subcategory' => null],

            // Vitamins and Minerals - Vitamins Category
            'vitamin_d' => ['category' => 'vitamins', 'subcategory' => null],
            'vitamin_b12' => ['category' => 'vitamins', 'subcategory' => null],
            'vitamin_b6' => ['category' => 'vitamins', 'subcategory' => null],
            'folate' => ['category' => 'vitamins', 'subcategory' => null],
            'folic_acid' => ['category' => 'vitamins', 'subcategory' => null],
            'iron' => ['category' => 'vitamins', 'subcategory' => null],
            'ferritin' => ['category' => 'vitamins', 'subcategory' => null],
            'tibc' => ['category' => 'vitamins', 'subcategory' => null],
            'transferrin_saturation' => ['category' => 'vitamins', 'subcategory' => null],
            'vitamin_c' => ['category' => 'vitamins', 'subcategory' => null],
            'vitamin_e' => ['category' => 'vitamins', 'subcategory' => null],
            'zinc' => ['category' => 'vitamins', 'subcategory' => null],
            'magnesium' => ['category' => 'vitamins', 'subcategory' => null],
            'calcium' => ['category' => 'vitamins', 'subcategory' => null],

            // Custom/Physical Measurements - Custom Category
            'weight' => ['category' => 'custom', 'subcategory' => null],
            'height' => ['category' => 'custom', 'subcategory' => null],
            'bmi' => ['category' => 'custom', 'subcategory' => null],
            'body_fat_percentage' => ['category' => 'custom', 'subcategory' => null],
            'muscle_mass' => ['category' => 'custom', 'subcategory' => null],
        ];
    }

    /**
     * ✨ ENHANCED: Calculate status based on reference ranges and metric type
     */
    public function calculateStatus()
    {
        $referenceRanges = $this->getReferenceRanges($this->type);
        
        if (!$referenceRanges) {
            return 'normal'; // Default if no reference range available
        }

        $value = $this->getNumericValue();
        
        if ($value === null) {
            return 'normal'; // Can't calculate for non-numeric values
        }

        // Check critical ranges first
        if (isset($referenceRanges['criticalLow']) && $value <= $referenceRanges['criticalLow']) {
            return 'high'; // Critical low is concerning
        }
        
        if (isset($referenceRanges['criticalHigh']) && $value >= $referenceRanges['criticalHigh']) {
            return 'high'; // Critical high is concerning
        }

        // Check warning ranges
        if (isset($referenceRanges['warningLow']) && $value <= $referenceRanges['warningLow']) {
            return 'borderline';
        }
        
        if (isset($referenceRanges['warningHigh']) && $value >= $referenceRanges['warningHigh']) {
            return 'borderline';
        }

        // Check normal ranges
        if (isset($referenceRanges['min']) && isset($referenceRanges['max'])) {
            if ($value < $referenceRanges['min'] || $value > $referenceRanges['max']) {
                return 'borderline';
            }
        }

        return 'normal';
    }

    /**
     * Get numeric value from the stored value (handles special cases like blood pressure)
     */
    private function getNumericValue()
    {
        if (is_numeric($this->value)) {
            return floatval($this->value);
        }

        // Handle blood pressure (use systolic value)
        if ($this->type === 'blood_pressure' && strpos($this->value, '/') !== false) {
            $parts = explode('/', $this->value);
            return floatval($parts[0]); // Systolic pressure
        }

        // Handle ranges (take the first number)
        if (preg_match('/^(\d+\.?\d*)/', $this->value, $matches)) {
            return floatval($matches[1]);
        }

        return null; // Can't extract numeric value
    }

    /**
     * ✨ COMPREHENSIVE REFERENCE RANGES
     * Expanded reference ranges for all supported metrics
     */
    public static function getReferenceRanges($type = null)
    {
        $ranges = [
            // Cholesterol Panel
            'hdl' => [
                'min' => 40, 'max' => 60, 'unit' => 'mg/dL',
                'warningLow' => 35, 'criticalLow' => 25
            ],
            'ldl' => [
                'min' => 0, 'max' => 100, 'unit' => 'mg/dL',
                'warningHigh' => 130, 'criticalHigh' => 190
            ],
            'total_cholesterol' => [
                'min' => 125, 'max' => 200, 'unit' => 'mg/dL',
                'warningHigh' => 240, 'criticalHigh' => 300
            ],
            'triglycerides' => [
                'min' => 0, 'max' => 150, 'unit' => 'mg/dL',
                'warningHigh' => 200, 'criticalHigh' => 500
            ],
            'vldl' => [
                'min' => 5, 'max' => 40, 'unit' => 'mg/dL',
                'warningHigh' => 50, 'criticalHigh' => 100
            ],

            // Thyroid Function
            'tsh' => [
                'min' => 0.4, 'max' => 4.0, 'unit' => 'mIU/L',
                'warningLow' => 0.1, 'warningHigh' => 6.0,
                'criticalLow' => 0.01, 'criticalHigh' => 10.0
            ],
            't3' => [
                'min' => 80, 'max' => 200, 'unit' => 'ng/dL',
                'warningLow' => 70, 'warningHigh' => 220
            ],
            't4' => [
                'min' => 5.1, 'max' => 14.1, 'unit' => 'μg/dL',
                'warningLow' => 4.5, 'warningHigh' => 15.0
            ],
            'free_t3' => [
                'min' => 2.0, 'max' => 4.4, 'unit' => 'pg/mL',
                'warningLow' => 1.8, 'warningHigh' => 5.0
            ],
            'free_t4' => [
                'min' => 0.82, 'max' => 1.77, 'unit' => 'ng/dL',
                'warningLow' => 0.7, 'warningHigh' => 2.0
            ],

            // Vitamins
            'vitamin_d' => [
                'min' => 30, 'max' => 100, 'unit' => 'ng/mL',
                'warningLow' => 20, 'criticalLow' => 12,
                'criticalHigh' => 150
            ],
            'vitamin_b12' => [
                'min' => 200, 'max' => 900, 'unit' => 'pg/mL',
                'warningLow' => 300, 'criticalLow' => 200
            ],
            'folate' => [
                'min' => 2.7, 'max' => 17.0, 'unit' => 'ng/mL',
                'warningLow' => 3.0, 'criticalLow' => 2.0
            ],
            'iron' => [
                'min' => 60, 'max' => 170, 'unit' => 'μg/dL',
                'warningLow' => 50, 'criticalLow' => 30,
                'warningHigh' => 200, 'criticalHigh' => 300
            ],
            'ferritin' => [
                'min' => 12, 'max' => 300, 'unit' => 'ng/mL',
                'warningLow' => 15, 'criticalLow' => 10,
                'warningHigh' => 400, 'criticalHigh' => 1000
            ],

            // Blood Count
            'hemoglobin' => [
                'min' => 12.0, 'max' => 17.5, 'unit' => 'g/dL',
                'warningLow' => 11.0, 'criticalLow' => 8.0,
                'warningHigh' => 18.0, 'criticalHigh' => 20.0
            ],
            'hematocrit' => [
                'min' => 36, 'max' => 52, 'unit' => '%',
                'warningLow' => 32, 'criticalLow' => 28,
                'warningHigh' => 54, 'criticalHigh' => 60
            ],
            'rbc_count' => [
                'min' => 4.5, 'max' => 5.5, 'unit' => 'million/µL',
                'warningLow' => 4.0, 'criticalLow' => 3.5,
                'warningHigh' => 6.0, 'criticalHigh' => 7.0
            ],
            'wbc_count' => [
                'min' => 4.5, 'max' => 11.0, 'unit' => 'thousand/µL',
                'warningLow' => 4.0, 'criticalLow' => 2.0,
                'warningHigh' => 12.0, 'criticalHigh' => 20.0
            ],
            'platelet_count' => [
                'min' => 150, 'max' => 450, 'unit' => 'thousand/µL',
                'warningLow' => 100, 'criticalLow' => 50,
                'warningHigh' => 500, 'criticalHigh' => 1000
            ],

            // Glucose/Diabetes
            'glucose_fasting' => [
                'min' => 70, 'max' => 99, 'unit' => 'mg/dL',
                'warningLow' => 65, 'warningHigh' => 125,
                'criticalLow' => 55, 'criticalHigh' => 180
            ],
            'hba1c' => [
                'min' => 4.0, 'max' => 5.6, 'unit' => '%',
                'warningHigh' => 6.4, 'criticalHigh' => 10.0
            ],

            // Liver Function
            'alt' => [
                'min' => 7, 'max' => 40, 'unit' => 'U/L',
                'warningHigh' => 50, 'criticalHigh' => 200
            ],
            'ast' => [
                'min' => 8, 'max' => 40, 'unit' => 'U/L',
                'warningHigh' => 50, 'criticalHigh' => 200
            ],
            'alp' => [
                'min' => 44, 'max' => 147, 'unit' => 'U/L',
                'warningHigh' => 200, 'criticalHigh' => 400
            ],
            'bilirubin' => [
                'min' => 0.1, 'max' => 1.2, 'unit' => 'mg/dL',
                'warningHigh' => 2.0, 'criticalHigh' => 5.0
            ],

            // Kidney Function
            'creatinine' => [
                'min' => 0.7, 'max' => 1.3, 'unit' => 'mg/dL',
                'warningHigh' => 1.5, 'criticalHigh' => 2.0
            ],
            'blood_urea_nitrogen' => [
                'min' => 7, 'max' => 20, 'unit' => 'mg/dL',
                'warningHigh' => 25, 'criticalHigh' => 50
            ],
            'uric_acid' => [
                'min' => 3.4, 'max' => 7.0, 'unit' => 'mg/dL',
                'warningHigh' => 8.0, 'criticalHigh' => 10.0
            ],
            'egfr' => [
                'min' => 90, 'max' => 120, 'unit' => 'mL/min/1.73m²',
                'warningLow' => 60, 'criticalLow' => 30
            ],

            // Electrolytes
            'sodium' => [
                'min' => 136, 'max' => 145, 'unit' => 'mEq/L',
                'warningLow' => 135, 'warningHigh' => 146,
                'criticalLow' => 130, 'criticalHigh' => 150
            ],
            'potassium' => [
                'min' => 3.5, 'max' => 5.0, 'unit' => 'mEq/L',
                'warningLow' => 3.3, 'warningHigh' => 5.2,
                'criticalLow' => 3.0, 'criticalHigh' => 6.0
            ],
            'chloride' => [
                'min' => 98, 'max' => 107, 'unit' => 'mEq/L',
                'warningLow' => 96, 'warningHigh' => 109,
                'criticalLow' => 90, 'criticalHigh' => 115
            ],

            // Cardiac Markers
            'troponin' => [
                'min' => 0, 'max' => 0.04, 'unit' => 'ng/mL',
                'warningHigh' => 0.1, 'criticalHigh' => 2.0
            ],
            'ck_mb' => [
                'min' => 0, 'max' => 3.0, 'unit' => 'ng/mL',
                'warningHigh' => 5.0, 'criticalHigh' => 10.0
            ],
            'bnp' => [
                'min' => 0, 'max' => 100, 'unit' => 'pg/mL',
                'warningHigh' => 300, 'criticalHigh' => 900
            ],

            // Hormones
            'testosterone' => [
                'min' => 300, 'max' => 1000, 'unit' => 'ng/dL',
                'warningLow' => 250, 'criticalLow' => 150
            ],
            'estrogen' => [
                'min' => 15, 'max' => 350, 'unit' => 'pg/mL',
                'warningLow' => 10, 'warningHigh' => 400
            ],
            'cortisol' => [
                'min' => 6.2, 'max' => 19.4, 'unit' => 'μg/dL',
                'warningLow' => 5.0, 'warningHigh' => 23.0,
                'criticalLow' => 3.0, 'criticalHigh' => 30.0
            ],
            'insulin' => [
                'min' => 2.6, 'max' => 24.9, 'unit' => 'μIU/mL',
                'warningHigh' => 30.0, 'criticalHigh' => 50.0
            ],

            // Blood Pressure (special handling)
            'blood_pressure' => [
                'min' => '90/60', 'max' => '120/80', 'unit' => 'mmHg',
                'warningLow' => '90/60', 'warningHigh' => '140/90',
                'criticalHigh' => '180/110'
            ],

            // Physical Measurements
            'weight' => [
                'min' => 40, 'max' => 150, 'unit' => 'kg' // Highly variable, these are just bounds
            ],
            'bmi' => [
                'min' => 18.5, 'max' => 24.9, 'unit' => 'kg/m²',
                'warningLow' => 18.0, 'warningHigh' => 29.9,
                'criticalLow' => 16.0, 'criticalHigh' => 40.0
            ],
        ];

        return $type ? ($ranges[$type] ?? null) : $ranges;
    }

    /**
     * ✨ ENHANCED: Create health metrics from AI extraction
     * This replaces the old createFromAIExtraction method with better handling
     */
    public static function createFromAIExtraction($patientId, $extractedMetrics, $reportDate, $reportId)
    {
        $createdMetrics = [];

        foreach ($extractedMetrics as $metricData) {
            try {
                // Create the metric
                $metric = new self([
                    'patient_id' => $patientId,
                    'type' => $metricData['type'],
                    'value' => $metricData['value'],
                    'unit' => $metricData['unit'],
                    'measured_at' => $reportDate,
                    'notes' => "Auto-extracted from medical report (ID: {$reportId})",
                    'source' => 'report',
                    'context' => 'medical_test',
                ]);

                // Set categories based on type
                $metric->setMetricCategories();
                
                // Calculate status based on reference ranges
                $metric->status = $metric->calculateStatus();
                
                // Save the metric
                $metric->save();
                
                $createdMetrics[] = $metric;

                Log::info('Health metric created from extraction', [
                    'patient_id' => $patientId,
                    'type' => $metric->type,
                    'value' => $metric->value,
                    'category' => $metric->category,
                    'subcategory' => $metric->subcategory,
                    'status' => $metric->status
                ]);

            } catch (\Exception $e) {
                Log::error('Failed to create health metric from extraction', [
                    'patient_id' => $patientId,
                    'metric_data' => $metricData,
                    'error' => $e->getMessage()
                ]);
                continue; // Skip this metric but continue with others
            }
        }

        return $createdMetrics;
    }

    /**
     * ✨ ENHANCED: Get patient metrics with metadata for the health screen
     * This method supports the enhanced health metrics controller
     */
    public static function getPatientMetricsWithMetadata($patientId, $filters = [])
    {
        $query = self::where('patient_id', $patientId);

        // Apply filters
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        
        if (!empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        
        if (!empty($filters['subcategory'])) {
            $query->where('subcategory', $filters['subcategory']);
        }
        
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        
        if (!empty($filters['source'])) {
            $query->where('source', $filters['source']);
        }
        
        if (!empty($filters['from_date'])) {
            $query->where('measured_at', '>=', $filters['from_date']);
        }
        
        if (!empty($filters['to_date'])) {
            $query->where('measured_at', '<=', $filters['to_date']);
        }

        // Get metrics ordered by most recent first
        $metrics = $query->orderBy('measured_at', 'desc')->get();

        // Group by type for the health screen format
        $groupedMetrics = [];
        
        foreach ($metrics as $metric) {
            if (!isset($groupedMetrics[$metric->type])) {
                $groupedMetrics[$metric->type] = [];
            }
            
            // Add metadata for the health screen
            $metricArray = $metric->toArray();
            $metricArray['date'] = $metric->measured_at->format('Y-m-d');
            $metricArray['time'] = $metric->measured_at->format('H:i A');
            $metricArray['is_recent'] = $metric->created_at->diffInDays(now()) <= 7;
            $metricArray['needs_review'] = $metric->source === 'report' && $metric->created_at->diffInDays(now()) <= 7;
            $metricArray['source_display'] = $metric->getSourceDisplay();
            
            $groupedMetrics[$metric->type][] = $metricArray;
        }

        return $groupedMetrics;
    }

    /**
     * Get display name for the metric source
     */
    public function getSourceDisplay()
    {
        $sourceMap = [
            'manual' => 'Manual Entry',
            'report' => 'Medical Report',
            'device' => 'Connected Device'
        ];

        return $sourceMap[$this->source] ?? 'Unknown Source';
    }

    /**
     * Get display name for metric type
     */
    public static function getDisplayName($type)
    {
        $displayNames = [
            'hdl' => 'HDL Cholesterol',
            'ldl' => 'LDL Cholesterol',
            'total_cholesterol' => 'Total Cholesterol',
            'triglycerides' => 'Triglycerides',
            'vldl' => 'VLDL Cholesterol',
            'non_hdl_cholesterol' => 'Non-HDL Cholesterol',
            'vitamin_d' => 'Vitamin D',
            'vitamin_b12' => 'Vitamin B12',
            'vitamin_b6' => 'Vitamin B6',
            'folate' => 'Folate',
            'iron' => 'Iron',
            'ferritin' => 'Ferritin',
            'tibc' => 'TIBC',
            'hemoglobin' => 'Hemoglobin',
            'hematocrit' => 'Hematocrit',
            'rbc_count' => 'RBC Count',
            'wbc_count' => 'WBC Count',
            'platelet_count' => 'Platelet Count',
            'glucose_fasting' => 'Fasting Glucose',
            'blood_sugar' => 'Blood Sugar',
            'hba1c' => 'HbA1c',
            'creatinine' => 'Creatinine',
            'blood_urea_nitrogen' => 'Blood Urea Nitrogen',
            'uric_acid' => 'Uric Acid',
            'egfr' => 'eGFR',
            'alt' => 'ALT',
            'ast' => 'AST',
            'alp' => 'ALP',
            'bilirubin' => 'Bilirubin',
            'tsh' => 'TSH',
            't3' => 'T3',
            't4' => 'T4',
            'free_t3' => 'Free T3',
            'free_t4' => 'Free T4',
            'troponin' => 'Troponin',
            'ck_mb' => 'CK-MB',
            'bnp' => 'BNP',
            'sodium' => 'Sodium',
            'potassium' => 'Potassium',
            'chloride' => 'Chloride',
            'testosterone' => 'Testosterone',
            'estrogen' => 'Estrogen',
            'cortisol' => 'Cortisol',
            'insulin' => 'Insulin',
            'blood_pressure' => 'Blood Pressure',
            'weight' => 'Weight',
            'bmi' => 'BMI'
        ];

        return $displayNames[$type] ?? ucwords(str_replace('_', ' ', $type));
    }

    /**
     * Scope for recent metrics (within specified days)
     */
    public function scopeRecent($query, $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Scope for metrics that need review
     */
    public function scopeNeedsReview($query)
    {
        return $query->where('source', 'report')
                    ->where('created_at', '>=', now()->subDays(7));
    }

    /**
     * Scope for metrics by category
     */
    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope for metrics by subcategory
     */
    public function scopeBySubcategory($query, $subcategory)
    {
        return $query->where('subcategory', $subcategory);
    }
}