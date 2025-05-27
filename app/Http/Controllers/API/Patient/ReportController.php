<?php

namespace App\Http\Controllers\API\Patient;

use App\Http\Controllers\Controller;
use App\Models\PatientReport;
use App\Models\AISummary;
use App\Models\HealthMetric;
use App\Services\AISummaryService;
use App\Services\OCRService;
use App\Services\ImageProcessingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\PdfToText\Pdf as PdfToText;
use Barryvdh\DomPDF\Facade\Pdf as DomPdf;

class ReportController extends Controller
{
    protected $ocrService;
    protected $imageProcessingService;

    public function __construct(OCRService $ocrService, ImageProcessingService $imageProcessingService)
    {
        $this->ocrService = $ocrService;
        $this->imageProcessingService = $imageProcessingService;
        
        // Ensure storage directories exist
        ImageProcessingService::ensureDirectoriesExist();
    }

    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf,jpeg,png,jpg|max:20480', // 20MB max
            'notes' => 'nullable|string',
            'report_date' => 'nullable|date',
            'report_title' => 'nullable|string|max:255',
        ]);

        $patientId = auth()->id();
        $file = $request->file('file');
        $ext = $file->getClientOriginalExtension();
        $isImage = in_array($ext, ['jpg', 'jpeg', 'png']);
        $isPdf = $ext === 'pdf';

        Log::info('Report upload started', [
            'patient_id' => $patientId,
            'file_type' => $ext,
            'file_size' => $file->getSize(),
            'is_image' => $isImage
        ]);

        try {
            // Step 1: Handle file storage based on type
            if ($isImage) {
                // Validate image file
                $validation = $this->imageProcessingService->validateImageFile($file);
                if (!$validation['valid']) {
                    return response()->json(['error' => $validation['error']], 422);
                }

                // Process image (create both high-quality and compressed versions)
                $imageResult = $this->imageProcessingService->processUploadedImage($file, $patientId);
                
                if (!$imageResult['success']) {
                    return response()->json(['error' => 'Failed to process image: ' . $imageResult['error']], 500);
                }

                $filePath = $imageResult['compressed_path']; 
                $originalFilePath = $imageResult['original_path'];
                $compressedFilePath = $imageResult['compressed_path'];
                $ocrStatus = PatientReport::OCR_STATUS_PENDING;

            } else if ($isPdf) {
                // Handle PDF upload (existing logic)
                $filename = Str::uuid() . '.pdf';
                $filePath = $file->storeAs('patient_reports/pdfs', $filename, 'public');
                $originalFilePath = null;
                $compressedFilePath = null;
                $ocrStatus = PatientReport::OCR_STATUS_NOT_REQUIRED;
            } else {
                return response()->json(['error' => 'Unsupported file type'], 422);
            }

            // Step 2: Create report record
            $report = PatientReport::create([
                'patient_id' => $patientId,
                'doctor_id' => null,
                'file_path' => $filePath,
                'original_file_path' => $originalFilePath,
                'compressed_file_path' => $compressedFilePath,
                'type' => $isImage ? 'image' : 'pdf',
                'notes' => $request->notes,
                'report_date' => $request->report_date ?? now(),
                'report_title' => $request->report_title ?? 'Medical Report',
                'uploaded_by' => 'patient',
                'ocr_status' => $ocrStatus,
                'processing_attempts' => 0
            ]);

            // Step 3: Process file for text extraction
            $extractedText = '';
            $createdMetrics = [];
            
            if ($isPdf) {
                // Extract text from PDF
                $extractedText = $this->extractTextFromPDF($file);
            } else if ($isImage) {
                // Process image with OCR
                $ocrResult = $this->processImageWithOCR($report);
                $extractedText = $ocrResult['text'];
                
                // Update report with OCR results
                if ($ocrResult['success']) {
                    $report->markOCRAsCompleted($ocrResult['confidence']);
                } else {
                    $report->markOCRAsFailed();
                    Log::warning('OCR processing failed for report', [
                        'report_id' => $report->id,
                        'error' => $ocrResult['error']
                    ]);
                }
            }

            // Step 4: Generate AI summary with cleaned text
            $aiSummaryJson = null;
            $cleanedText = '';
            
            if (!empty($extractedText)) {
                // Clean text for database storage - remove problematic characters
                $cleanedText = $this->cleanTextForDatabase($extractedText);
                $aiSummaryJson = AISummaryService::generateSummary($cleanedText);
            }

            // Create AI summary record
            $aiSummary = AISummary::create([
                'report_id' => $report->id,
                'raw_text' => $cleanedText, // Use cleaned text
                'summary_json' => $aiSummaryJson ?? [],
                'confidence_score' => isset($aiSummaryJson['confidence_score']) 
                    ? (int) filter_var($aiSummaryJson['confidence_score'], FILTER_SANITIZE_NUMBER_INT) 
                    : 0,
                'ai_model_used' => 'gpt-4',
            ]);

            // Step 5: ✨ NEW - Extract health metrics from AI summary instead of regex
            if (!empty($aiSummaryJson) && isset($aiSummaryJson['key_findings'])) {
                $extractedMetrics = $this->extractMetricsFromAISummary($aiSummaryJson, $patientId, $report);
                $createdMetrics = $extractedMetrics['metrics'];

                Log::info('Health metrics extracted from AI summary', [
                    'report_id' => $report->id,
                    'metrics_count' => count($createdMetrics),
                    'categories_found' => $extractedMetrics['categories_found']
                ]);
            }

            // Step 6: Clean up original file if OCR was successful
            if ($isImage && $report->isOCRComplete()) {
                // Schedule cleanup after successful processing
                $report->cleanupTemporaryFiles();
            }

            // Step 7: Enhanced response with detailed health metrics info
            $response = [
                'message' => 'Report uploaded successfully.',
                'report_id' => $report->id,
                'file_type' => $report->type,
                'ocr_status' => $report->ocr_status,
                'raw_text_preview' => Str::limit($extractedText, 300),
                'summary' => $aiSummaryJson,
                'confidence_score' => $aiSummaryJson['confidence_score'] ?? null,
                
                // ✨ Enhanced health metrics information
                'health_metrics' => $this->createDetailedMetricSummary($createdMetrics),
           
                'extracted_metrics_details' => collect($createdMetrics)->map(function($metric) {
                    return [
                        'id' => $metric->id,
                        'type' => $metric->type,
                        'display_name' => $this->getMetricDisplayName($metric->type),
                        'value' => $metric->value,
                        'unit' => $metric->unit,
                        'status' => $metric->status,
                        'category' => $metric->category,
                        'subcategory' => $metric->subcategory,
                        'measured_at' => $metric->measured_at->toISOString(),
                        'source' => $metric->source,
                        'context' => $metric->context,
                        'reference_range' => $this->getReferenceRangeForMetric($metric->type),
                        'is_recent' => true, // Mark as recent for review badge
                        'needs_review' => true, // Enable review badge
                    ];
                })->toArray()
            ];

            // Add OCR specific information for images
            if ($isImage) {
                $response['ocr_info'] = $report->getOCRStatusInfo();
                if ($report->ocr_confidence) {
                    $response['ocr_confidence'] = $report->ocr_confidence;
                }
            }

            Log::info('Report upload completed', [
                'report_id' => $report->id,
                'ocr_status' => $report->ocr_status,
                'metrics_created' => count($createdMetrics),
                'categories_found' => $this->getMetricCategories($createdMetrics)
            ]);

            return response()->json($response, 201);

        } catch (\Exception $e) {
            Log::error('Report upload failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'patient_id' => $patientId
            ]);

            return response()->json([
                'error' => 'Upload failed. Please try again.',
                'message' => config('app.debug') ? $e->getMessage() : 'An error occurred during upload.'
            ], 500);
        }
    }

    /**
     * ✨ NEW METHOD: Extract health metrics from AI summary instead of regex
     * This replaces the broken extractHealthMetricsAdvanced() method
     */
    private function extractMetricsFromAISummary($aiSummaryJson, $patientId, $report)
    {
        $extractedMetrics = [];
        $categoriesFound = [];

        if (!isset($aiSummaryJson['key_findings']) || !is_array($aiSummaryJson['key_findings'])) {
            Log::warning('No key_findings in AI summary', ['report_id' => $report->id]);
            return ['metrics' => [], 'categories_found' => []];
        }

        foreach ($aiSummaryJson['key_findings'] as $finding) {
            try {
                // Handle both string and array findings
                if (is_string($finding)) {
                    $parsedMetric = $this->parseStringFinding($finding);
                } else if (is_array($finding)) {
                    $parsedMetric = $this->parseArrayFinding($finding);
                } else {
                    continue; // Skip invalid findings
                }

                if (!$parsedMetric) {
                    continue; // Skip if parsing failed
                }

                // Map to standardized metric type
                $standardizedType = $this->mapToStandardMetricType($parsedMetric['raw_name']);
                
                if (!$standardizedType) {
                    Log::info('Could not map metric to standard type', [
                        'raw_name' => $parsedMetric['raw_name'],
                        'report_id' => $report->id
                    ]);
                    continue; // Skip unmappable metrics
                }

                // Create health metric record
                $metric = HealthMetric::create([
                    'patient_id' => $patientId,
                    'type' => $standardizedType['type'],
                    'value' => $parsedMetric['value'],
                    'unit' => $parsedMetric['unit'] ?: $standardizedType['default_unit'],
                    'measured_at' => $report->report_date ?? now(),
                    'notes' => "Auto-extracted from medical report (ID: {$report->id})",
                    'source' => 'report',
                    'context' => 'medical_test',
                    'status' => $this->mapAIStatusToHealthStatus($parsedMetric['status']),
                    'category' => $standardizedType['category'],
                    'subcategory' => $standardizedType['subcategory'],
                ]);

                $extractedMetrics[] = $metric;
                $categoriesFound[] = $standardizedType['category'];

                Log::info('Health metric created from AI finding', [
                    'report_id' => $report->id,
                    'raw_name' => $parsedMetric['raw_name'],
                    'standardized_type' => $standardizedType['type'],
                    'value' => $parsedMetric['value'],
                    'unit' => $parsedMetric['unit'],
                    'category' => $standardizedType['category']
                ]);

            } catch (\Exception $e) {
                Log::error('Failed to process AI finding', [
                    'finding' => $finding,
                    'error' => $e->getMessage(),
                    'report_id' => $report->id
                ]);
                continue; // Skip this finding but continue with others
            }
        }

        return [
            'metrics' => $extractedMetrics,
            'categories_found' => array_unique($categoriesFound)
        ];
    }

    /**
     * Parse string-based finding (e.g., "Normal level of HDL cholesterol 48 mg/dL")
     */
    private function parseStringFinding($finding)
    {
        // Pattern 1: "Status level of Parameter Value Unit"
        if (preg_match('/^(normal|elevated|high|low|decreased|increased|borderline|slightly)\s+level\s+of\s+(.+?)\s+([\d\.]+)\s*([a-zA-Z\/\%µ°]+)?/i', $finding, $matches)) {
            return [
                'raw_name' => trim($matches[2]),
                'value' => $matches[3],
                'unit' => $matches[4] ?? '',
                'status' => strtolower($matches[1])
            ];
        }

        // Pattern 2: "Parameter: Value Unit"
        if (preg_match('/^(.+?):\s*([\d\.]+)\s*([a-zA-Z\/\%µ°]+)?/i', $finding, $matches)) {
            return [
                'raw_name' => trim($matches[1]),
                'value' => $matches[2],
                'unit' => $matches[3] ?? '',
                'status' => 'unknown'
            ];
        }

        // Pattern 3: "Parameter Value Unit (status)"
        if (preg_match('/^(.+?)\s+([\d\.]+)\s*([a-zA-Z\/\%µ°]+)?\s*\((normal|high|low|elevated|decreased)\)/i', $finding, $matches)) {
            return [
                'raw_name' => trim($matches[1]),
                'value' => $matches[2],
                'unit' => $matches[3] ?? '',
                'status' => strtolower($matches[4])
            ];
        }

        return null; // Could not parse
    }

    /**
     * Parse array-based finding from structured AI response
     */
    private function parseArrayFinding($finding)
    {
        if (!isset($finding['finding']) || !isset($finding['value'])) {
            return null;
        }

        return [
            'raw_name' => $finding['finding'],
            'value' => $finding['value'],
            'unit' => $finding['unit'] ?? '',
            'status' => $finding['status'] ?? 'unknown'
        ];
    }

    /**
     * ✨ COMPREHENSIVE MEDICAL PARAMETER MAPPING
     * Maps AI-extracted parameter names to standardized health metric types
     */
    private function mapToStandardMetricType($rawName)
    {
        $rawName = strtolower(trim($rawName));
        
        // Comprehensive mapping for all major medical parameters
        $mappings = [
            // Cholesterol Panel
            'hdl cholesterol' => ['type' => 'hdl', 'category' => 'organs', 'subcategory' => 'heart', 'default_unit' => 'mg/dL'],
            'hdl' => ['type' => 'hdl', 'category' => 'organs', 'subcategory' => 'heart', 'default_unit' => 'mg/dL'],
            'ldl cholesterol' => ['type' => 'ldl', 'category' => 'organs', 'subcategory' => 'heart', 'default_unit' => 'mg/dL'],
            'ldl' => ['type' => 'ldl', 'category' => 'organs', 'subcategory' => 'heart', 'default_unit' => 'mg/dL'],
            'total cholesterol' => ['type' => 'total_cholesterol', 'category' => 'organs', 'subcategory' => 'heart', 'default_unit' => 'mg/dL'],
            'cholesterol' => ['type' => 'total_cholesterol', 'category' => 'organs', 'subcategory' => 'heart', 'default_unit' => 'mg/dL'],
            'triglycerides' => ['type' => 'triglycerides', 'category' => 'organs', 'subcategory' => 'heart', 'default_unit' => 'mg/dL'],
            'vldl cholesterol' => ['type' => 'vldl', 'category' => 'organs', 'subcategory' => 'heart', 'default_unit' => 'mg/dL'],
            'non hdl cholesterol' => ['type' => 'non_hdl_cholesterol', 'category' => 'organs', 'subcategory' => 'heart', 'default_unit' => 'mg/dL'],

            // Thyroid Panel
            'tsh' => ['type' => 'tsh', 'category' => 'organs', 'subcategory' => 'thyroid', 'default_unit' => 'mIU/L'],
            'thyroid stimulating hormone' => ['type' => 'tsh', 'category' => 'organs', 'subcategory' => 'thyroid', 'default_unit' => 'mIU/L'],
            't3' => ['type' => 't3', 'category' => 'organs', 'subcategory' => 'thyroid', 'default_unit' => 'ng/dL'],
            'triiodothyronine' => ['type' => 't3', 'category' => 'organs', 'subcategory' => 'thyroid', 'default_unit' => 'ng/dL'],
            't4' => ['type' => 't4', 'category' => 'organs', 'subcategory' => 'thyroid', 'default_unit' => 'μg/dL'],
            'thyroxine' => ['type' => 't4', 'category' => 'organs', 'subcategory' => 'thyroid', 'default_unit' => 'μg/dL'],
            'free t3' => ['type' => 'free_t3', 'category' => 'organs', 'subcategory' => 'thyroid', 'default_unit' => 'pg/mL'],
            'free t4' => ['type' => 'free_t4', 'category' => 'organs', 'subcategory' => 'thyroid', 'default_unit' => 'ng/dL'],

            // Vitamins
            'vitamin d' => ['type' => 'vitamin_d', 'category' => 'vitamins', 'subcategory' => null, 'default_unit' => 'ng/mL'],
            '25-hydroxy vitamin d' => ['type' => 'vitamin_d', 'category' => 'vitamins', 'subcategory' => null, 'default_unit' => 'ng/mL'],
            'vitamin d3' => ['type' => 'vitamin_d', 'category' => 'vitamins', 'subcategory' => null, 'default_unit' => 'ng/mL'],
            'vitamin b12' => ['type' => 'vitamin_b12', 'category' => 'vitamins', 'subcategory' => null, 'default_unit' => 'pg/mL'],
            'cobalamin' => ['type' => 'vitamin_b12', 'category' => 'vitamins', 'subcategory' => null, 'default_unit' => 'pg/mL'],
            'folate' => ['type' => 'folate', 'category' => 'vitamins', 'subcategory' => null, 'default_unit' => 'ng/mL'],
            'folic acid' => ['type' => 'folate', 'category' => 'vitamins', 'subcategory' => null, 'default_unit' => 'ng/mL'],
            'vitamin b6' => ['type' => 'vitamin_b6', 'category' => 'vitamins', 'subcategory' => null, 'default_unit' => 'ng/mL'],

            // Liver Function Tests
            'alt' => ['type' => 'alt', 'category' => 'organs', 'subcategory' => 'liver', 'default_unit' => 'U/L'],
            'alanine aminotransferase' => ['type' => 'alt', 'category' => 'organs', 'subcategory' => 'liver', 'default_unit' => 'U/L'],
            'sgpt' => ['type' => 'alt', 'category' => 'organs', 'subcategory' => 'liver', 'default_unit' => 'U/L'],
            'ast' => ['type' => 'ast', 'category' => 'organs', 'subcategory' => 'liver', 'default_unit' => 'U/L'],
            'aspartate aminotransferase' => ['type' => 'ast', 'category' => 'organs', 'subcategory' => 'liver', 'default_unit' => 'U/L'],
            'sgot' => ['type' => 'ast', 'category' => 'organs', 'subcategory' => 'liver', 'default_unit' => 'U/L'],
            'alp' => ['type' => 'alp', 'category' => 'organs', 'subcategory' => 'liver', 'default_unit' => 'U/L'],
            'alkaline phosphatase' => ['type' => 'alp', 'category' => 'organs', 'subcategory' => 'liver', 'default_unit' => 'U/L'],
            'bilirubin' => ['type' => 'bilirubin', 'category' => 'organs', 'subcategory' => 'liver', 'default_unit' => 'mg/dL'],
            'total bilirubin' => ['type' => 'bilirubin', 'category' => 'organs', 'subcategory' => 'liver', 'default_unit' => 'mg/dL'],

            // Kidney Function Tests
            'creatinine' => ['type' => 'creatinine', 'category' => 'organs', 'subcategory' => 'kidney', 'default_unit' => 'mg/dL'],
            'serum creatinine' => ['type' => 'creatinine', 'category' => 'organs', 'subcategory' => 'kidney', 'default_unit' => 'mg/dL'],
            'bun' => ['type' => 'blood_urea_nitrogen', 'category' => 'organs', 'subcategory' => 'kidney', 'default_unit' => 'mg/dL'],
            'blood urea nitrogen' => ['type' => 'blood_urea_nitrogen', 'category' => 'organs', 'subcategory' => 'kidney', 'default_unit' => 'mg/dL'],
            'uric acid' => ['type' => 'uric_acid', 'category' => 'organs', 'subcategory' => 'kidney', 'default_unit' => 'mg/dL'],
            'egfr' => ['type' => 'egfr', 'category' => 'organs', 'subcategory' => 'kidney', 'default_unit' => 'mL/min/1.73m²'],

            // Blood Count (Complete Blood Count)
            'hemoglobin' => ['type' => 'hemoglobin', 'category' => 'blood', 'subcategory' => null, 'default_unit' => 'g/dL'],
            'hb' => ['type' => 'hemoglobin', 'category' => 'blood', 'subcategory' => null, 'default_unit' => 'g/dL'],
            'hematocrit' => ['type' => 'hematocrit', 'category' => 'blood', 'subcategory' => null, 'default_unit' => '%'],
            'hct' => ['type' => 'hematocrit', 'category' => 'blood', 'subcategory' => null, 'default_unit' => '%'],
            'rbc count' => ['type' => 'rbc_count', 'category' => 'blood', 'subcategory' => null, 'default_unit' => 'million/µL'],
            'red blood cell count' => ['type' => 'rbc_count', 'category' => 'blood', 'subcategory' => null, 'default_unit' => 'million/µL'],
            'wbc count' => ['type' => 'wbc_count', 'category' => 'blood', 'subcategory' => null, 'default_unit' => 'thousand/µL'],
            'white blood cell count' => ['type' => 'wbc_count', 'category' => 'blood', 'subcategory' => null, 'default_unit' => 'thousand/µL'],
            'platelet count' => ['type' => 'platelet_count', 'category' => 'blood', 'subcategory' => null, 'default_unit' => 'thousand/µL'],
            'platelets' => ['type' => 'platelet_count', 'category' => 'blood', 'subcategory' => null, 'default_unit' => 'thousand/µL'],

            // Diabetes/Glucose
            'glucose' => ['type' => 'glucose_fasting', 'category' => 'blood', 'subcategory' => null, 'default_unit' => 'mg/dL'],
            'fasting glucose' => ['type' => 'glucose_fasting', 'category' => 'blood', 'subcategory' => null, 'default_unit' => 'mg/dL'],
            'blood sugar' => ['type' => 'glucose_fasting', 'category' => 'blood', 'subcategory' => null, 'default_unit' => 'mg/dL'],
            'hba1c' => ['type' => 'hba1c', 'category' => 'blood', 'subcategory' => null, 'default_unit' => '%'],
            'glycated hemoglobin' => ['type' => 'hba1c', 'category' => 'blood', 'subcategory' => null, 'default_unit' => '%'],

            // Iron Studies
            'iron' => ['type' => 'iron', 'category' => 'vitamins', 'subcategory' => null, 'default_unit' => 'μg/dL'],
            'serum iron' => ['type' => 'iron', 'category' => 'vitamins', 'subcategory' => null, 'default_unit' => 'μg/dL'],
            'ferritin' => ['type' => 'ferritin', 'category' => 'vitamins', 'subcategory' => null, 'default_unit' => 'ng/mL'],
            'tibc' => ['type' => 'tibc', 'category' => 'vitamins', 'subcategory' => null, 'default_unit' => 'μg/dL'],

            // Cardiac Markers
            'troponin' => ['type' => 'troponin', 'category' => 'organs', 'subcategory' => 'heart', 'default_unit' => 'ng/mL'],
            'ck-mb' => ['type' => 'ck_mb', 'category' => 'organs', 'subcategory' => 'heart', 'default_unit' => 'ng/mL'],
            'bnp' => ['type' => 'bnp', 'category' => 'organs', 'subcategory' => 'heart', 'default_unit' => 'pg/mL'],

            // Electrolytes
            'sodium' => ['type' => 'sodium', 'category' => 'blood', 'subcategory' => null, 'default_unit' => 'mEq/L'],
            'potassium' => ['type' => 'potassium', 'category' => 'blood', 'subcategory' => null, 'default_unit' => 'mEq/L'],
            'chloride' => ['type' => 'chloride', 'category' => 'blood', 'subcategory' => null, 'default_unit' => 'mEq/L'],

            // Hormones
            'testosterone' => ['type' => 'testosterone', 'category' => 'organs', 'subcategory' => 'endocrine', 'default_unit' => 'ng/dL'],
            'estrogen' => ['type' => 'estrogen', 'category' => 'organs', 'subcategory' => 'endocrine', 'default_unit' => 'pg/mL'],
            'cortisol' => ['type' => 'cortisol', 'category' => 'organs', 'subcategory' => 'endocrine', 'default_unit' => 'μg/dL'],
            'insulin' => ['type' => 'insulin', 'category' => 'organs', 'subcategory' => 'endocrine', 'default_unit' => 'μIU/mL'],
        ];

        // Direct match
        if (isset($mappings[$rawName])) {
            return $mappings[$rawName];
        }

        // Fuzzy matching for variations
        foreach ($mappings as $key => $mapping) {
            if (strpos($rawName, $key) !== false || strpos($key, $rawName) !== false) {
                return $mapping;
            }
        }

        // Check for common variations
        $variations = [
            'cholesterol' => 'total_cholesterol',
            'sugar' => 'glucose_fasting',
            'haemoglobin' => 'hemoglobin',
            'vit d' => 'vitamin_d',
            'vit b12' => 'vitamin_b12',
        ];

        foreach ($variations as $variation => $standard) {
            if (strpos($rawName, $variation) !== false && isset($mappings[$standard])) {
                return $mappings[$standard];
            }
        }

        return null; // No mapping found
    }

    /**
     * Map AI status to health metric status
     */
    private function mapAIStatusToHealthStatus($aiStatus)
    {
        $statusMap = [
            'normal' => 'normal',
            'elevated' => 'high',
            'high' => 'high',
            'low' => 'high', // Both high and low are concerning
            'decreased' => 'high',
            'increased' => 'high',
            'borderline' => 'borderline',
            'slightly' => 'borderline',
            'mild' => 'borderline',
            'unknown' => 'normal'
        ];

        $aiStatus = strtolower($aiStatus);
        return $statusMap[$aiStatus] ?? 'normal';
    }

    /**
     * Format extracted metrics summary for response
     */
    private function formatExtractedMetricsSummary($metrics)
    {
        if (empty($metrics)) {
            return 'No health metrics extracted from this report.';
        }

        $count = count($metrics);
        $categories = collect($metrics)->groupBy('category')->keys()->filter()->values();
        
        if ($categories->count() > 1) {
            return "Extracted {$count} health metrics across " . $categories->count() . " categories: " . $categories->join(', ');
        } else if ($categories->count() === 1) {
            return "Extracted {$count} health metrics from {$categories->first()} tests";
        } else {
            return "Extracted {$count} health metrics from your report";
        }
    }

    /**
     * Get unique categories from extracted metrics
     */
    private function getMetricCategories($metrics)
    {
        return collect($metrics)
            ->pluck('category')
            ->filter()
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Get display name for metric type
     */
    private function getMetricDisplayName($type)
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
            'blood_pressure' => 'Blood Pressure'
        ];

        return $displayNames[$type] ?? ucwords(str_replace('_', ' ', $type));
    }

    /**
     * Get reference range for a specific metric type
     */
    private function getReferenceRangeForMetric($type)
    {
        $ranges = HealthMetric::getReferenceRanges($type);
        
        if (!$ranges || (!isset($ranges['min']) && !isset($ranges['max']))) {
            return null;
        }

        return [
            'min' => $ranges['min'] ?? null,
            'max' => $ranges['max'] ?? null,
            'unit' => $ranges['unit'] ?? '',
            'display' => $this->formatReferenceRangeDisplay($ranges)
        ];
    }
    
    /**
     * Enhanced: Create detailed metric summary for UI
     */
    private function createDetailedMetricSummary($createdMetrics)
    {
        if (empty($createdMetrics)) {
            return [
                'total_extracted' => 0,
                'extraction_successful' => false,
                'metrics_summary' => 'No health metrics found in this report.',
                'categories_found' => [],
                'new_metrics_available' => false,
            ];
        }

        $count = count($createdMetrics);
        $categories = collect($createdMetrics)->groupBy('category')->keys()->filter()->values();
        
        // Enhanced summary message
        if ($categories->count() > 1) {
            $summary = "Successfully extracted {$count} health metrics across " . $categories->count() . " categories: " . $categories->join(', ');
        } else if ($categories->count() === 1) {
            $summary = "Found {$count} health metrics from {$categories->first()} category";
        } else {
            $summary = "Extracted {$count} health metrics from your medical report";
        }

        return [
            'total_extracted' => $count,
            'extraction_successful' => true,
            'metrics_summary' => $summary,
            'categories_found' => $categories->toArray(),
            'new_metrics_available' => true,
            'needs_review_count' => $count, // All new metrics need review initially
        ];
    }

    /**
     * Format reference range for display
     */
    private function formatReferenceRangeDisplay($ranges)
    {
        if (isset($ranges['min']) && isset($ranges['max'])) {
            return "{$ranges['min']} - {$ranges['max']} {$ranges['unit']}";
        } else if (isset($ranges['max'])) {
            return "< {$ranges['max']} {$ranges['unit']}";
        } else if (isset($ranges['min'])) {
            return "> {$ranges['min']} {$ranges['unit']}";
        }
        
        return 'Reference range not available';
    }
    
    /**
     * Clean text for database storage
     */
    private function cleanTextForDatabase($text)
    {
        // Remove problematic characters that cause MySQL encoding issues
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        
        // Remove or replace problematic characters
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        
        // Replace common problematic characters
        $text = str_replace(['�', chr(194), chr(162)], ['', '', ''], $text);
        
        // Ensure valid UTF-8
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'auto');
        }
        
        return $text;
    }

    /**
     * Process image with OCR
     */
    private function processImageWithOCR(PatientReport $report)
    {
        try {
            $report->markOCRAsProcessing();
            
            $ocrFilePath = $report->getOCRFilePath();
            if (!$ocrFilePath || !file_exists($ocrFilePath)) {
                throw new \Exception('OCR file not found: ' . $ocrFilePath);
            }

            Log::info('Starting OCR processing', [
                'report_id' => $report->id,
                'file_path' => $ocrFilePath
            ]);

            // Perform OCR
            $ocrResult = $this->ocrService->extractTextFromImage($ocrFilePath);

            Log::info('OCR processing result', [
                'report_id' => $report->id,
                'success' => $ocrResult['success'],
                'confidence' => $ocrResult['confidence'],
                'text_length' => strlen($ocrResult['text'])
            ]);

            return $ocrResult;

        } catch (\Exception $e) {
            Log::error('OCR processing failed', [
                'report_id' => $report->id,
                'error' => $e->getMessage()
            ]);

            return [
                'text' => '',
                'confidence' => 0,
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Extract text from PDF
     */
    private function extractTextFromPDF($file)
    {
        try {
            $text = PdfToText::getText($file->getPathname());
            return $this->normalizePdfText($text);
        } catch (\Exception $e) {
            Log::error('PDF text extraction failed', ['error' => $e->getMessage()]);
            throw new \Exception('Unable to extract text from PDF. Please ensure it is not password protected.');
        }
    }

    /**
     * Normalize PDF text
     */
    private function normalizePdfText($text)
    {
        $text = preg_replace("/\n([A-Za-z])/m", " $1", $text);
        $text = preg_replace("/\s{2,}/", " ", $text);
        return trim($text);
    }

    /**
     * Retry OCR processing for failed reports
     */
    public function retryOCR(Request $request, $id)
    {
        $patientId = auth()->id();
        $report = PatientReport::where('patient_id', $patientId)
            ->where('id', $id)
            ->firstOrFail();

        if (!$report->canRetryOCR()) {
            return response()->json([
                'error' => 'Cannot retry OCR for this report',
                'reason' => $report->processing_attempts >= 3 ? 'Maximum attempts exceeded' : 'Report status does not allow retry'
            ], 400);
        }

        try {
            // Process image with OCR
            $ocrResult = $this->processImageWithOCR($report);
            
            if ($ocrResult['success']) {
                $report->markOCRAsCompleted($ocrResult['confidence']);
                
                // Re-run AI processing with the new text
                if (!empty($ocrResult['text'])) {
                    $cleanedText = $this->cleanTextForDatabase($ocrResult['text']);
                    $aiSummaryJson = AISummaryService::generateSummary($cleanedText);
                    
                    // Update existing AI summary
                    $report->aiSummary()->updateOrCreate(
                        ['report_id' => $report->id],
                        [
                            'raw_text' => $cleanedText,
                            'summary_json' => $aiSummaryJson ?? [],
                            'confidence_score' => isset($aiSummaryJson['confidence_score']) 
                                ? (int) filter_var($aiSummaryJson['confidence_score'], FILTER_SANITIZE_NUMBER_INT) 
                                : 0,
                            'ai_model_used' => 'gpt-4',
                        ]
                    );
                }
                
                // Clean up original file after successful retry
                $report->cleanupTemporaryFiles();
                
            } else {
                $report->markOCRAsFailed();
            }

            return response()->json([
                'success' => $ocrResult['success'],
                'message' => $ocrResult['success'] ? 'OCR retry successful' : 'OCR retry failed',
                'ocr_info' => $report->getOCRStatusInfo(),
                'text_preview' => $ocrResult['success'] ? Str::limit($ocrResult['text'], 200) : null
            ]);

        } catch (\Exception $e) {
            Log::error('OCR retry failed', [
                'report_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'OCR retry failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get OCR status for a report
     */
    public function getOCRStatus($id)
    {
        $patientId = auth()->id();
        $report = PatientReport::where('patient_id', $patientId)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json([
            'report_id' => $report->id,
            'ocr_info' => $report->getOCRStatusInfo(),
            'file_info' => $report->getFileSizeInfo()
        ]);
    }

    /**
     * Get all reports for the authenticated patient
     */
    public function index()
    {
        $patientId = auth()->id();

        $reports = PatientReport::with(['aiSummary'])
            ->where('patient_id', $patientId)
            ->latest()
            ->get()
            ->map(function ($report) {
                return [
                    'id' => $report->id,
                    'title' => $report->report_title,
                    'uploaded_at' => $report->created_at->toDateTimeString(),
                    'report_date' => $report->report_date,
                    'file_type' => $report->type,
                    'summary_diagnosis' => $report->aiSummary->summary_json['diagnosis'] ?? null,
                    'file_url' => $report->getFileUrl(),
                    'uploaded_by' => $report->uploaded_by,
                    'doctor_name' => $report->doctor ? $report->doctor->name : null,
                    'ocr_info' => $report->type === 'image' ? $report->getOCRStatusInfo() : null,
                ];
            });

        return response()->json([
            'reports' => $reports
        ]);
    }

    /**
     * Get detailed view of a specific report
     */
    public function show($id)
    {
        $patientId = auth()->id();
        $report = PatientReport::with(['aiSummary'])
            ->where('patient_id', $patientId)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json([
            'id' => $report->id,
            'title' => $report->report_title,
            'report_date' => $report->report_date,
            'notes' => $report->notes,
            'uploaded_at' => $report->created_at->toDateTimeString(),
            'file_url' => $report->getFileUrl(),
            'file_type' => $report->type,
            'uploaded_by' => $report->uploaded_by,
            'doctor_name' => $report->doctor ? $report->doctor->name : null,
            'ai_summary' => $report->aiSummary->summary_json ?? null,
            'confidence_score' => $report->aiSummary->confidence_score ?? null,
            'ai_model_used' => $report->aiSummary->ai_model_used ?? null,
            'raw_text' => Str::limit($report->aiSummary->raw_text ?? '', 1500),
            'ocr_info' => $report->type === 'image' ? $report->getOCRStatusInfo() : null,
        ]);
    }

    /**
     * Delete a specific report
     */
    public function destroy($id)
    {
        $patientId = auth()->id();
        $report = PatientReport::where('patient_id', $patientId)
            ->where('id', $id)
            ->firstOrFail();

        // Delete all associated files
        if ($report->file_path && Storage::disk('public')->exists($report->file_path)) {
            Storage::disk('public')->delete($report->file_path);
        }
        
        if ($report->original_file_path && Storage::disk('public')->exists($report->original_file_path)) {
            Storage::disk('public')->delete($report->original_file_path);
        }
        
        if ($report->compressed_file_path && Storage::disk('public')->exists($report->compressed_file_path)) {
            Storage::disk('public')->delete($report->compressed_file_path);
        }

        $report->delete();

        return response()->json([
            'message' => 'Report deleted successfully'
        ]);
    }

    /**
     * Get detailed information for a specific finding
     */
    public function getFindingDetails(Request $request, $id)
    {
        try {
            Log::info('Finding details request', [
                'report_id' => $id,
                'request_data' => $request->all()
            ]);
            
            $validated = $request->validate([
                'finding' => 'required',
            ]);
            
            $finding = $request->input('finding');
            
            $report = PatientReport::where('id', $id)
                ->where('patient_id', auth()->id())
                ->first();
                
            if (!$report) {
                Log::error('Report not found', ['report_id' => $id]);
                return response()->json([
                    'error' => 'Report not found',
                ], 404);
            }
            
            $context = '';
            if ($report->file_path && Storage::exists($report->file_path)) {
                try {
                    $fileContents = Storage::get($report->file_path);
                    $context = substr($fileContents, 0, 1000);
                } catch (\Exception $e) {
                    Log::warning('Could not read report file', [
                        'error' => $e->getMessage(),
                        'report_id' => $id
                    ]);
                }
            }
            
            $details = AISummaryService::generateFindingDetails($finding, $context);
            
            return response()->json([
                'success' => true,
                'details' => $details
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error generating finding details', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'report_id' => $id
            ]);
            
            return response()->json([
                'error' => 'Failed to generate finding details',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Download AI summary as PDF
     */
    public function downloadSummaryPdf($id)
    {
        $report = PatientReport::with('aiSummary')
            ->where('id', $id)
            ->where('patient_id', auth()->id())
            ->firstOrFail();

        $summary = $report->aiSummary->summary_json ?? [];
        $confidence = $report->aiSummary->confidence_score ?? null;

        $pdf = DomPdf::loadView('pdfs.ai_summary', [
            'report' => $report,
            'summary' => $summary,
            'confidence' => $confidence,
        ]);

        return $pdf->download('AI_Summary_Report.pdf');
    }
}