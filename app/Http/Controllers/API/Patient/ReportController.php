<?php

namespace App\Http\Controllers\API\Patient;

use App\Http\Controllers\Controller;
use App\Models\PatientReport;
use App\Models\AISummary;
use App\Models\HealthMetric;
use App\Services\AISummaryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\PdfToText\Pdf as PdfToText;
use Barryvdh\DomPDF\Facade\Pdf as DomPdf;

class ReportController extends Controller
{
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf,jpeg,png,jpg',
            'notes' => 'nullable|string',
            'report_date' => 'nullable|date',
            'report_title' => 'nullable|string|max:255',
        ]);

        $patientId = auth()->id();
        $file = $request->file('file');
        $ext = $file->getClientOriginalExtension();
        $filename = Str::uuid() . '.' . $ext;
        $path = $file->storeAs('patient_reports', $filename, 'public');

        $report = PatientReport::create([
            'patient_id' => $patientId,
            'doctor_id' => null,
            'file_path' => $path,
            'type' => $ext === 'pdf' ? 'pdf' : 'image',
            'notes' => $request->notes,
            'report_date' => $request->report_date ?? now(),
            'report_title' => $request->report_title ?? 'Medical Report',
            'uploaded_by' => 'patient',
        ]);

        $text = '';
        $createdMetrics = [];
        
        if ($ext === 'pdf') {
            try {
                $text = PdfToText::getText($file->getPathname());
                $text = $this->normalizePdfText($text);
                
                // ENHANCED: Extract health metrics using improved method
                $extractedMetrics = $this->extractHealthMetricsAdvanced($text);
                
                // Create health metrics from extracted data
                if (!empty($extractedMetrics)) {
                    $createdMetrics = HealthMetric::createFromAIExtraction($patientId, $extractedMetrics);
                }
                
                Log::info('Health metrics extracted and created', [
                    'report_id' => $report->id,
                    'metrics_count' => count($createdMetrics),
                    'extracted_data' => $extractedMetrics
                ]);

            } catch (\Exception $e) {
                Log::error('PDF processing failed', ['error' => $e->getMessage()]);
                return response()->json([
                    'error' => 'Unable to extract text from PDF. Please upload a valid or unlocked PDF.'
                ], 422);
            }
        }

        // Generate AI summary
        $aiSummaryJson = AISummaryService::generateSummary($text);
        AISummary::create([
            'report_id' => $report->id,
            'raw_text' => $text,
            'summary_json' => $aiSummaryJson ?? [],
            'confidence_score' => isset($aiSummaryJson['confidence_score']) 
                ? (int) filter_var($aiSummaryJson['confidence_score'], FILTER_SANITIZE_NUMBER_INT) 
                : 0,
            'ai_model_used' => 'gpt-4',
        ]);

        return response()->json([
            'message' => 'Report uploaded successfully.',
            'report_id' => $report->id,
            'raw_text_preview' => Str::limit($text, 300),
            'summary' => $aiSummaryJson,
            'confidence_score' => $aiSummaryJson['confidence_score'] ?? null,
            'health_metrics_created' => count($createdMetrics),
            'created_metrics' => $createdMetrics->map(function($metric) {
                return [
                    'type' => $metric->type,
                    'value' => $metric->value,
                    'unit' => $metric->unit,
                    'status' => $metric->status,
                    'category' => $metric->category
                ];
            })
        ], 201);
    }

    private function normalizePdfText($text)
    {
        $text = preg_replace("/\n([A-Za-z])/m", " $1", $text);
        $text = preg_replace("/\s{2,}/", " ", $text);
        return trim($text);
    }

    /**
     * ENHANCED: Advanced health metrics extraction
     * Handles various report formats and parameter naming conventions
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
        
        // Process Pattern 1 matches
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
        
        // Process Pattern 2 matches
        foreach ($matches2 as $match) {
            $parameter = trim($match[1]);
            $value = $match[2];
            $unit = trim($match[3]);
            $range = trim($match[4]);
            
            if ($this->isValidHealthParameter($parameter, $value)) {
                $extractedMetrics[] = [
                    'parameter' => $parameter,
                    'value' => $value,
                    'unit' => $unit,
                    'reference_range' => $range,
                    'pattern' => 'table'
                ];
            }
        }
        
        // Process Pattern 3 matches
        foreach ($matches3 as $match) {
            $parameter = trim($match[1]);
            $unit = trim($match[2]);
            $value = $match[3];
            
            if ($this->isValidHealthParameter($parameter, $value)) {
                $extractedMetrics[] = [
                    'parameter' => $parameter,
                    'value' => $value,
                    'unit' => $unit,
                    'pattern' => 'lab'
                ];
            }
        }
        
        // Remove duplicates based on parameter name
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
     * Validate if extracted parameter is a valid health metric
     */
    private function isValidHealthParameter($parameter, $value)
    {
        // Skip if parameter is too short or too long
        if (strlen(trim($parameter)) < 3 || strlen(trim($parameter)) > 50) {
            return false;
        }
        
        // Skip if value is not numeric
        if (!is_numeric($value)) {
            return false;
        }
        
        // Skip common non-health parameters
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
        
        // Additional validation: health-related keywords
        $healthKeywords = [
            'blood', 'serum', 'plasma', 'urine', 'level', 'count', 'rate', 'ratio',
            'glucose', 'cholesterol', 'protein', 'vitamin', 'mineral', 'enzyme',
            'hormone', 'pressure', 'sugar', 'iron', 'calcium', 'sodium'
        ];
        
        foreach ($healthKeywords as $keyword) {
            if (strpos($paramLower, $keyword) !== false) {
                return true;
            }
        }
        
        // Check if parameter matches common health test names
        $commonTests = [
            'hdl', 'ldl', 'alt', 'ast', 'tsh', 'hemoglobin', 'hematocrit', 'creatinine',
            'bilirubin', 'albumin', 'globulin', 'triglycerides', 'uric', 'ferritin',
            'folate', 'b12', 'vitamin', 'rbc', 'wbc', 'platelet', 'mcv', 'mch', 'mchc'
        ];
        
        foreach ($commonTests as $test) {
            if (strpos($paramLower, $test) !== false) {
                return true;
            }
        }
        
        return false;
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
                    'file_url' => Storage::disk('public')->url($report->file_path),
                    'uploaded_by' => $report->uploaded_by,
                    'doctor_name' => $report->doctor ? $report->doctor->name : null,
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
            'file_url' => Storage::disk('public')->url($report->file_path),
            'file_type' => $report->type,
            'uploaded_by' => $report->uploaded_by,
            'doctor_name' => $report->doctor ? $report->doctor->name : null,
            'ai_summary' => $report->aiSummary->summary_json ?? null,
            'confidence_score' => $report->aiSummary->confidence_score ?? null,
            'ai_model_used' => $report->aiSummary->ai_model_used ?? null,
            'raw_text' => Str::limit($report->aiSummary->raw_text ?? '', 1500),
        ]);
    }

    public function destroy($id)
    {
        $patientId = auth()->id();
        $report = PatientReport::where('patient_id', $patientId)
            ->where('id', $id)
            ->firstOrFail();

        Storage::disk('public')->delete($report->file_path);
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