<?php

namespace App\Http\Controllers\API\Patient;

use App\Http\Controllers\Controller;
use App\Models\PatientReport;
use App\Models\AISummary;
use App\Models\HealthMetric;
use App\Services\AISummaryService;
use App\Services\OCRService;
use App\Services\ImageProcessingService;
use App\Services\HealthMetricsExtraction\HealthMetricsExtractionService;
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
    protected $healthMetricsService;

    public function __construct(
        OCRService $ocrService, 
        ImageProcessingService $imageProcessingService,
        HealthMetricsExtractionService $healthMetricsService = null
    ) {
        $this->ocrService = $ocrService;
        $this->imageProcessingService = $imageProcessingService;
        
        // ✨ SAFE INITIALIZATION - Handle if service is not available
        try {
            $this->healthMetricsService = $healthMetricsService ?? app(HealthMetricsExtractionService::class);
        } catch (\Exception $e) {
            Log::warning('HealthMetricsExtractionService not available, falling back to legacy extraction', [
                'error' => $e->getMessage()
            ]);
            $this->healthMetricsService = null;
        }
        
        // Ensure storage directories exist
        ImageProcessingService::ensureDirectoriesExist();
    }

    // ✅ KEEP: Your existing upload method (it's correct)
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
            'is_image' => $isImage,
            'health_metrics_service_available' => !is_null($this->healthMetricsService)
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
                Log::info('🚀 Starting PDF text extraction', ['report_id' => $report->id]);
                $extractedText = $this->extractTextFromPDF($file);
                Log::info('📄 PDF text extraction completed', [
                    'report_id' => $report->id,
                    'text_length' => strlen($extractedText),
                    'preview' => substr($extractedText, 0, 200)
                ]);
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
                Log::info('🤖 Starting AI summary generation', [
                    'report_id' => $report->id,
                    'text_length' => strlen($extractedText)
                ]);
                
                // Clean text for database storage - remove problematic characters
                $cleanedText = $this->cleanTextForDatabase($extractedText);
                $aiSummaryJson = AISummaryService::generateSummary($cleanedText);
                
                Log::info('✅ AI summary generation completed', [
                    'report_id' => $report->id,
                    'has_summary' => !empty($aiSummaryJson),
                    'confidence_score' => $aiSummaryJson['confidence_score'] ?? 'N/A'
                ]);
            }

            // Create AI summary record
            $aiSummary = AISummary::create([
                'report_id' => $report->id,
                'raw_text' => $cleanedText,
                'summary_json' => $aiSummaryJson ?? [],
                'confidence_score' => isset($aiSummaryJson['confidence_score']) 
                    ? (int) filter_var($aiSummaryJson['confidence_score'], FILTER_SANITIZE_NUMBER_INT) 
                    : 0,
                'ai_model_used' => 'gpt-4',
            ]);

            // Step 5: ✨ SAFE HEALTH METRICS EXTRACTION
            if (!empty($aiSummaryJson) && isset($aiSummaryJson['key_findings'])) {
                Log::info('🔬 Starting health metrics extraction', [
                    'report_id' => $report->id,
                    'service_available' => !is_null($this->healthMetricsService),
                    'findings_count' => count($aiSummaryJson['key_findings'])
                ]);
                
                try {
                    $extractedMetrics = $this->extractMetricsFromAISummary($aiSummaryJson, $patientId, $report);
                    $createdMetrics = $extractedMetrics['metrics'];

                    Log::info('✅ Health metrics extraction completed', [
                        'report_id' => $report->id,
                        'metrics_count' => count($createdMetrics),
                        'categories_found' => $extractedMetrics['categories_found']
                    ]);
                } catch (\Exception $e) {
                    Log::error('❌ Health metrics extraction failed, continuing without metrics', [
                        'report_id' => $report->id,
                        'error' => $e->getMessage(),
                        'will_continue' => true
                    ]);
                    // Don't fail the entire upload - just continue without metrics
                    $createdMetrics = [];
                }
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
                        'is_recent' => true,
                        'needs_review' => true,
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

            Log::info('🎉 Report upload completed successfully', [
                'report_id' => $report->id,
                'ocr_status' => $report->ocr_status,
                'metrics_created' => count($createdMetrics),
                'categories_found' => $this->getMetricCategories($createdMetrics)
            ]);

            return response()->json($response, 201);

        } catch (\Exception $e) {
            Log::error('💥 Report upload failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'patient_id' => $patientId,
                'file_type' => $ext
            ]);

            return response()->json([
                'error' => 'Upload failed. Please try again.',
                'message' => config('app.debug') ? $e->getMessage() : 'An error occurred during upload.'
            ], 500);
        }
    }

    /**
     * ✨ SAFE: Extract health metrics with fallback to legacy method
     */
    private function extractMetricsFromAISummary($aiSummaryJson, $patientId, $report)
    {
        // Try new service first if available
        if ($this->healthMetricsService) {
            try {
                Log::info('🚀 Using new HealthMetricsExtractionService', [
                    'report_id' => $report->id,
                    'service_class' => get_class($this->healthMetricsService)
                ]);

                $extractionResult = $this->healthMetricsService->extractMetrics(
                    $report->aiSummary->raw_text ?? '', 
                    $aiSummaryJson, 
                    $patientId, 
                    $report
                );

                if ($extractionResult['success']) {
                    Log::info('✅ New service extraction successful', [
                        'report_id' => $report->id,
                        'provider_used' => $extractionResult['provider_used'],
                        'metrics_count' => count($extractionResult['metrics'])
                    ]);

                    return [
                        'metrics' => $extractionResult['metrics'],
                        'categories_found' => $extractionResult['categories_found'],
                        'extraction_metadata' => [
                            'method' => 'new_service',
                            'provider_used' => $extractionResult['provider_used'],
                            'model_used' => $extractionResult['model_used'],
                            'duration_ms' => $extractionResult['duration_ms']
                        ]
                    ];
                } else {
                    Log::warning('⚠️ New service failed, falling back to legacy', [
                        'report_id' => $report->id,
                        'error' => $extractionResult['error']
                    ]);
                    // Fall through to legacy method
                }
            } catch (\Exception $e) {
                Log::warning('⚠️ New service threw exception, falling back to legacy', [
                    'report_id' => $report->id,
                    'error' => $e->getMessage()
                ]);
                // Fall through to legacy method
            }
        }

        // Fallback to legacy extraction method
        Log::info('🔄 Using legacy extraction method', ['report_id' => $report->id]);
        return $this->legacyExtractMetricsFromAISummary($aiSummaryJson, $patientId, $report);
    }

    /**
     * ✨ LEGACY: Original extraction method as fallback
     */
    private function legacyExtractMetricsFromAISummary($aiSummaryJson, $patientId, $report)
    {
        $extractedMetrics = [];
        $categoriesFound = [];

        if (!isset($aiSummaryJson['key_findings']) || !is_array($aiSummaryJson['key_findings'])) {
            Log::warning('No key_findings in AI response for legacy extraction', ['report_id' => $report->id]);
            return ['metrics' => [], 'categories_found' => []];
        }

        foreach ($aiSummaryJson['key_findings'] as $finding) {
            try {
                // Parse finding (string or array)
                $parsedMetric = $this->parseFinding($finding);
                if (!$parsedMetric) {
                    continue;
                }

                // Map to standard metric type (legacy way)
                $standardType = $this->legacyMapToStandardMetricType($parsedMetric['raw_name']);
                if (!$standardType) {
                    Log::info('Could not map metric to standard type (legacy)', [
                        'raw_name' => $parsedMetric['raw_name'],
                        'report_id' => $report->id
                    ]);
                    continue;
                }

                // Create health metric record
                $metric = HealthMetric::create([
                    'patient_id' => $patientId,
                    'type' => $standardType,
                    'value' => $parsedMetric['value'],
                    'unit' => $parsedMetric['unit'],
                    'measured_at' => $report->report_date ?? now(),
                    'notes' => "Auto-extracted from medical report (ID: {$report->id}) via legacy method",
                    'source' => 'report',
                    'context' => 'medical_test',
                    'status' => $this->mapAIStatusToHealthStatus($parsedMetric['status']),
                ]);

                // Set categories using model method
                $metric->setMetricCategories();
                $metric->save();

                $extractedMetrics[] = $metric;
                if ($metric->category) {
                    $categoriesFound[] = $metric->category;
                }

                Log::debug('Health metric created via legacy method', [
                    'report_id' => $report->id,
                    'raw_name' => $parsedMetric['raw_name'],
                    'standardized_type' => $standardType,
                    'value' => $parsedMetric['value'],
                    'category' => $metric->category
                ]);

            } catch (\Exception $e) {
                Log::error('Failed to process AI finding (legacy)', [
                    'finding' => $finding,
                    'error' => $e->getMessage(),
                    'report_id' => $report->id
                ]);
                continue;
            }
        }

        Log::info('Legacy metrics extraction completed', [
            'report_id' => $report->id,
            'metrics_created' => count($extractedMetrics),
            'categories_found' => array_unique($categoriesFound)
        ]);

        return [
            'metrics' => $extractedMetrics,
            'categories_found' => array_unique($categoriesFound),
            'extraction_metadata' => [
                'method' => 'legacy',
                'provider_used' => 'legacy_parser',
                'model_used' => 'built_in_rules'
            ]
        ];
    }

    /**
     * Parse individual finding from AI response
     */
    protected function parseFinding($finding): ?array
    {
        if (is_string($finding)) {
            return $this->parseStringFinding($finding);
        } elseif (is_array($finding)) {
            return $this->parseArrayFinding($finding);
        }
        
        return null;
    }

    /**
     * Parse string-based finding
     */
    protected function parseStringFinding(string $finding): ?array
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

        return null;
    }

    /**
     * Parse array-based finding
     */
    protected function parseArrayFinding(array $finding): ?array
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
     * ✨ LEGACY: Map raw parameter name to standard metric type
     */
    private function legacyMapToStandardMetricType($rawName)
    {
        $cleanName = strtolower(trim($rawName));
        
        // Remove common prefixes/suffixes
        $cleanName = preg_replace('/^(serum|plasma|blood|total|free)\s+/', '', $cleanName);
        $cleanName = preg_replace('/\s+(level|concentration|count)$/', '', $cleanName);
        
        // Direct mappings
        $mappings = [
            'hdl cholesterol' => 'hdl',
            'hdl' => 'hdl',
            'ldl cholesterol' => 'ldl', 
            'ldl' => 'ldl',
            'total cholesterol' => 'total_cholesterol',
            'cholesterol' => 'total_cholesterol',
            'triglycerides' => 'triglycerides',
            'vldl cholesterol' => 'vldl',
            'vldl' => 'vldl',
            'non hdl cholesterol' => 'non_hdl_cholesterol',
            'vitamin d' => 'vitamin_d',
            'vitamin b12' => 'vitamin_b12',
            'tsh' => 'tsh',
            'alt' => 'alt',
            'ast' => 'ast',
            'creatinine' => 'creatinine',
            'hemoglobin' => 'hemoglobin',
            'glucose' => 'glucose_fasting',
            'hba1c' => 'hba1c'
        ];
        
        return $mappings[$cleanName] ?? null;
    }

    /**
     * Map AI status to health metric status
     */
    protected function mapAIStatusToHealthStatus(string $aiStatus): string
    {
        $statusMap = [
            'normal' => 'normal',
            'elevated' => 'high',
            'high' => 'high',
            'low' => 'high',
            'decreased' => 'high',
            'increased' => 'high',
            'borderline' => 'borderline',
            'slightly' => 'borderline',
            'mild' => 'borderline',
            'unknown' => 'normal'
        ];

        return $statusMap[strtolower($aiStatus)] ?? 'normal';
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
            'needs_review_count' => $count,
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
            // First, try text extraction for digital PDFs
            $text = PdfToText::getText($file->getPathname());
            
            // If we got meaningful text (more than just whitespace/minimal content)
            if (strlen(trim($text)) > 50) {
                Log::info('PDF text extraction successful (digital PDF)', [
                    'text_length' => strlen($text),
                    'method' => 'PdfToText'
                ]);
                return $this->normalizePdfText($text);
            }
            
            // If text extraction failed or returned minimal content, use OCR
            Log::info('PDF text extraction returned minimal content, trying OCR', [
                'text_length' => strlen($text),
                'method' => 'fallback_to_ocr'
            ]);
            
        } catch (\Exception $e) {
            Log::warning('PDF text extraction failed, falling back to OCR', [
                'error' => $e->getMessage(),
                'method' => 'fallback_to_ocr'
            ]);
        }
        
        // Fallback: Use OCR for scanned PDFs
        try {
            Log::info('Processing PDF with OCR', [
                'file_path' => $file->getPathname(),
                'method' => 'OCR'
            ]);
            
            // Use the same OCR service as images
            $ocrResult = $this->ocrService->extractTextFromImage($file->getPathname());
            
            if ($ocrResult['success'] && !empty($ocrResult['text'])) {
                Log::info('PDF OCR processing successful', [
                    'text_length' => strlen($ocrResult['text']),
                    'confidence' => $ocrResult['confidence'],
                    'method' => 'OCR'
                ]);
                return $ocrResult['text'];
            } else {
                Log::error('PDF OCR processing failed', [
                    'success' => $ocrResult['success'],
                    'error' => $ocrResult['error'] ?? 'Unknown OCR error'
                ]);
                throw new \Exception('OCR processing failed: ' . ($ocrResult['error'] ?? 'Unknown error'));
            }
            
        } catch (\Exception $e) {
            Log::error('PDF processing completely failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \Exception('Unable to extract text from PDF. Please try converting to image format.');
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
                    'uploaded_by' => $report->uploaded_by,
                    'doctor_name' => $report->doctor ? $report->doctor->name : null,
                    'ocr_info' => $report->type === 'image' ? $report->getOCRStatusInfo() : null,
                    'file_available' => $report->file_path && Storage::disk('public')->exists($report->file_path),
                    'file_size' => $report->file_path ? $this->getFileSizeInfo($report->file_path) : null,
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
            'file_type' => $report->type,
            'uploaded_by' => $report->uploaded_by,
            'doctor_name' => $report->doctor ? $report->doctor->name : null,
            'ai_summary' => $report->aiSummary->summary_json ?? null,
            'confidence_score' => $report->aiSummary->confidence_score ?? null,
            'ai_model_used' => $report->aiSummary->ai_model_used ?? null,
            'raw_text' => Str::limit($report->aiSummary->raw_text ?? '', 1500),
            'ocr_info' => $report->type === 'image' ? $report->getOCRStatusInfo() : null,
            'file_available' => $report->file_path && Storage::disk('public')->exists($report->file_path),
            'file_size' => $report->file_path ? $this->getFileSizeInfo($report->file_path) : null,
            'download_endpoint' => "/api/patient/reports/{$report->id}/download",
        ]);
    }

    /**
     * ✅ CORRECTED: Download file with proper file structure handling
     */
    public function downloadFile($id)
    {
        try {
            $patientId = auth()->id();
            
            \Log::info('🔍 Download request received', [
                'report_id' => $id,
                'patient_id' => $patientId,
                'timestamp' => now()
            ]);
            
            // Get the report
            $report = PatientReport::where('patient_id', $patientId)
                ->where('id', $id)
                ->first();
                
            if (!$report) {
                \Log::error('❌ Report not found', [
                    'report_id' => $id,
                    'patient_id' => $patientId
                ]);
                
                return response()->json(['error' => 'Report not found'], 404);
            }

            \Log::info('✅ Report found', [
                'report_id' => $report->id,
                'file_path' => $report->file_path,
                'file_type' => $report->type,
                'original_file_path' => $report->original_file_path,
                'compressed_file_path' => $report->compressed_file_path
            ]);

            // Determine which file to serve based on report type
            $filePathToServe = null;
            $fileDescription = '';

            if ($report->type === 'pdf') {
                // For PDF files, use the main file_path (should be in pdfs/ folder)
                $filePathToServe = $report->file_path;
                $fileDescription = 'PDF file';
            } else if ($report->type === 'image') {
                // For image files, prefer original over compressed
                if ($report->original_file_path) {
                    $filePathToServe = $report->original_file_path;
                    $fileDescription = 'Original image file';
                } else if ($report->compressed_file_path) {
                    $filePathToServe = $report->compressed_file_path;
                    $fileDescription = 'Compressed image file';
                } else if ($report->file_path) {
                    $filePathToServe = $report->file_path;
                    $fileDescription = 'Image file';
                }
            } else {
                // Fallback to main file_path
                $filePathToServe = $report->file_path;
                $fileDescription = 'Report file';
            }

            if (!$filePathToServe) {
                \Log::error('❌ No file path available', [
                    'report_id' => $report->id,
                    'type' => $report->type
                ]);
                
                return response()->json(['error' => 'No file associated with this report'], 404);
            }

            \Log::info('🔍 File path determined', [
                'file_path_to_serve' => $filePathToServe,
                'description' => $fileDescription
            ]);

            // Check if file exists in storage
            if (!Storage::disk('public')->exists($filePathToServe)) {
                \Log::error('❌ File not found in storage', [
                    'file_path' => $filePathToServe,
                    'storage_root' => Storage::disk('public')->path(''),
                    'full_path' => Storage::disk('public')->path($filePathToServe)
                ]);
                
                return response()->json([
                    'error' => 'File not found in storage',
                    'file_path' => $filePathToServe
                ], 404);
            }

            // Get the actual file system path
            $fullFilePath = Storage::disk('public')->path($filePathToServe);
            
            \Log::info('✅ File exists, preparing download', [
                'full_file_path' => $fullFilePath,
                'file_size' => filesize($fullFilePath)
            ]);

            // Generate download filename
            $extension = pathinfo($filePathToServe, PATHINFO_EXTENSION);
            $downloadName = ($report->report_title ?? 'Medical_Report') . '.' . $extension;
            $downloadName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $downloadName);

            // Log the download for analytics
            \Log::info('📁 File download started', [
                'patient_id' => $patientId,
                'report_id' => $report->id,
                'file_path' => $filePathToServe,
                'download_name' => $downloadName,
                'file_size' => filesize($fullFilePath),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent()
            ]);

            // Return the file download
            return response()->download($fullFilePath, $downloadName, [
                'Content-Type' => $this->getContentType($extension),
                'Content-Disposition' => 'attachment; filename="' . $downloadName . '"',
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0'
            ]);

        } catch (\Exception $e) {
            \Log::error('❌ Download exception', [
                'patient_id' => auth()->id() ?? 'unknown',
                'report_id' => $id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Download failed',
                'message' => config('app.debug') ? $e->getMessage() : 'Please try again'
            ], 500);
        }
    }

    /**
     * Helper method to get proper content type
     */
    private function getContentType($extension)
    {
        $contentTypes = [
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif'
        ];

        return $contentTypes[strtolower($extension)] ?? 'application/octet-stream';
    }

    /**
     * ✅ DEBUGGING: Add file info endpoint to check what's available
     */
    public function getFileInfo($id)
    {
        try {
            $patientId = auth()->id();
            $report = PatientReport::where('patient_id', $patientId)->where('id', $id)->first();
            
            if (!$report) {
                return response()->json(['error' => 'Report not found'], 404);
            }
            
            $fileInfo = [];
            
            // Check main file_path
            if ($report->file_path) {
                $exists = Storage::disk('public')->exists($report->file_path);
                $fileInfo['main_file'] = [
                    'path' => $report->file_path,
                    'exists' => $exists,
                    'full_path' => Storage::disk('public')->path($report->file_path),
                    'size' => $exists ? Storage::disk('public')->size($report->file_path) : null,
                    'url' => $exists ? Storage::disk('public')->url($report->file_path) : null
                ];
            }
            
            // Check original file (for images)
            if ($report->original_file_path) {
                $exists = Storage::disk('public')->exists($report->original_file_path);
                $fileInfo['original_file'] = [
                    'path' => $report->original_file_path,
                    'exists' => $exists,
                    'full_path' => Storage::disk('public')->path($report->original_file_path),
                    'size' => $exists ? Storage::disk('public')->size($report->original_file_path) : null,
                    'url' => $exists ? Storage::disk('public')->url($report->original_file_path) : null
                ];
            }
            
            // Check compressed file (for images)
            if ($report->compressed_file_path) {
                $exists = Storage::disk('public')->exists($report->compressed_file_path);
                $fileInfo['compressed_file'] = [
                    'path' => $report->compressed_file_path,
                    'exists' => $exists,
                    'full_path' => Storage::disk('public')->path($report->compressed_file_path),
                    'size' => $exists ? Storage::disk('public')->size($report->compressed_file_path) : null,
                    'url' => $exists ? Storage::disk('public')->url($report->compressed_file_path) : null
                ];
            }
            
            return response()->json([
                'report_id' => $report->id,
                'report_type' => $report->type,
                'report_title' => $report->report_title,
                'files' => $fileInfo,
                'storage_root' => Storage::disk('public')->path(''),
                'base_url' => config('app.url')
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to get file info',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ FIXED: Add missing getFileSizeInfo method
     */
    private function getFileSizeInfo($filePath)
    {
        try {
            if (!Storage::disk('public')->exists($filePath)) {
                return null;
            }

            $sizeInBytes = Storage::disk('public')->size($filePath);
            
            // Convert to human readable format
            if ($sizeInBytes >= 1048576) {
                $size = round($sizeInBytes / 1048576, 2) . ' MB';
            } elseif ($sizeInBytes >= 1024) {
                $size = round($sizeInBytes / 1024, 2) . ' KB';
            } else {
                $size = $sizeInBytes . ' bytes';
            }

            return [
                'bytes' => $sizeInBytes,
                'formatted' => $size
            ];
        } catch (\Exception $e) {
            \Log::warning('Failed to get file size', [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);
            return null;
        }
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