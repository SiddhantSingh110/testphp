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

            // Step 4: Extract health metrics if we have text
            if (!empty($extractedText)) {
                $extractedMetrics = $this->extractHealthMetricsAdvanced($extractedText);
                if (!empty($extractedMetrics)) {
                    $createdMetrics = HealthMetric::createFromAIExtraction(
                        $patientId, 
                        $extractedMetrics, 
                        $report->report_date ?? now(),
                        $report->id // Pass report ID for reference
                    );
                }
            }

            // Step 5: Generate AI summary with cleaned text
            $aiSummaryJson = null;
            $cleanedText = '';
            
            if (!empty($extractedText)) {
                // Clean text for database storage - remove problematic characters
                $cleanedText = $this->cleanTextForDatabase($extractedText);
                $aiSummaryJson = AISummaryService::generateSummary($cleanedText);
            }

            // Create AI summary record
            AISummary::create([
                'report_id' => $report->id,
                'raw_text' => $cleanedText, // Use cleaned text
                'summary_json' => $aiSummaryJson ?? [],
                'confidence_score' => isset($aiSummaryJson['confidence_score']) 
                    ? (int) filter_var($aiSummaryJson['confidence_score'], FILTER_SANITIZE_NUMBER_INT) 
                    : 0,
                'ai_model_used' => 'gpt-4',
            ]);

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
                'health_metrics' => [
                    'total_extracted' => count($createdMetrics),
                    'extraction_successful' => count($createdMetrics) > 0,
                    'metrics_summary' => $this->formatExtractedMetricsSummary($createdMetrics),
                    'categories_found' => $this->getMetricCategories($createdMetrics),
                    'new_metrics_available' => count($createdMetrics) > 0 ? true : false,
                ],
                
                // Individual metric details for frontend
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
                        'is_new' => true, // Mark as new for review badge
                        'needs_review' => true, // Enable review badge
                    ];
                })
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
            'vitamin_d' => 'Vitamin D',
            'vitamin_b12' => 'Vitamin B12',
            'folate' => 'Folate',
            'iron' => 'Iron',
            'ferritin' => 'Ferritin',
            'hemoglobin' => 'Hemoglobin',
            'hematocrit' => 'Hematocrit',
            'glucose_fasting' => 'Fasting Glucose',
            'hba1c' => 'HbA1c',
            'creatinine' => 'Creatinine',
            'blood_urea_nitrogen' => 'Blood Urea Nitrogen',
            'alt' => 'ALT',
            'ast' => 'AST',
            'tsh' => 'TSH',
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
     * Enhanced health metrics extraction
     */
    private function extractHealthMetricsAdvanced($text)
    {
        $extractedMetrics = [];
        
        // Pattern 1: Standard format - "Parameter Name: Value Unit"
        preg_match_all('/([A-Za-z\s\(\)\/\-\.]+):\s*([\d\.]+)\s*([a-zA-Z\/\%µ°]+)?/i', $text, $matches1, PREG_SET_ORDER);
        
        // Pattern 2: Table format - "Parameter Name Value Unit Range"
        preg_match_all('/([A-Z][A-Za-z\s\(\)\/\-\.]{3,})\s+([\d\.]+)\s+([a-zA-Z\/\%µ°]+)\s+([\d\.\-\s\<\>]+)/i', $text, $matches2, PREG_SET_ORDER);
        
        // Pattern 3: Lab format - "Parameter (Unit): Value"
        preg_match_all('/([A-Za-z\s\(\)\/\-\.]+)\s*\(([a-zA-Z\/\%µ°]+)\):\s*([\d\.]+)/i', $text, $matches3, PREG_SET_ORDER);
        
        // Process matches
        foreach ($matches1 as $match) {
            $parameter = trim($match[1]);
            $value = $match[2];
            $unit = isset($match[3]) ? trim($match[3]) : '';
            
            if ($this->isValidHealthParameter($parameter, $value)) {
                $extractedMetrics[] = [
                    'parameter' => $parameter,
                    'value' => $value,
                    'unit' => $unit,
                    'pattern' => 'standard'
                ];
            }
        }
        
        // Remove duplicates
        $uniqueMetrics = [];
        foreach ($extractedMetrics as $metric) {
            $key = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $metric['parameter']));
            if (!isset($uniqueMetrics[$key])) {
                $uniqueMetrics[$key] = $metric;
            }
        }
        
        return array_values($uniqueMetrics);
    }

    /**
     * Validate health parameter
     */
    private function isValidHealthParameter($parameter, $value)
    {
        if (strlen(trim($parameter)) < 3 || strlen(trim($parameter)) > 50) {
            return false;
        }
        
        if (!is_numeric($value)) {
            return false;
        }
        
        $skipList = [
            'page', 'date', 'time', 'phone', 'age', 'year', 'report', 'id', 'number',
            'address', 'name', 'total', 'amount', 'cost', 'price', 'fee'
        ];
        
        $paramLower = strtolower($parameter);
        foreach ($skipList as $skip) {
            if (strpos($paramLower, $skip) !== false) {
                return false;
            }
        }
        
        return true;
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