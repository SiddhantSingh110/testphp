<?php

namespace App\Http\Controllers\API\Doctor;

use App\Services\AISummaryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use App\Models\PatientReport;
use App\Models\AISummary;
use Illuminate\Support\Str;

class PatientReportController extends Controller
{
    public function upload(Request $request)
    {
        $request->validate([
            'patient_id' => 'required|exists:patients,id',
            'file' => 'required|file|mimes:pdf,jpeg,png,jpg',
            'notes' => 'nullable|string',
        ]);

        $doctorId = auth()->id(); // Authenticated doctor

        $file = $request->file('file');
        $ext = $file->getClientOriginalExtension();
        $filename = Str::uuid() . '.' . $ext;

        $path = $file->storeAs('patient_reports', $filename, 'public');

        // Create a new patient report record
        $report = PatientReport::create([
            'patient_id' => $request->patient_id,
            'doctor_id' => $doctorId,
            'file_path' => $path,
            'type' => $ext === 'pdf' ? 'pdf' : 'image',
            'notes' => $request->notes,
        ]);

        // Text extraction
        $text = '';
        if ($ext === 'pdf') {
            $parser = new Parser();
            $pdf = $parser->parseFile($file->getPathname());
            $text = $pdf->getText();
        } elseif (in_array($ext, ['jpg', 'jpeg', 'png'])) {
            $text = '[Image OCR coming soon]'; // Placeholder
        }

        // Generate summary via OpenAI
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
        ]);
    }

    public function index()
    {
        $doctorId = auth()->id();

        $reports = PatientReport::with(['patient', 'aiSummary'])
            ->where('doctor_id', $doctorId)
            ->latest()
            ->get()
            ->map(function ($report) {
                return [
                    'id' => $report->id,
                    'patient_name' => $report->patient->name,
                    'uploaded_at' => $report->created_at->toDateTimeString(),
                    'file_type' => $report->type,
                    'summary_diagnosis' => $report->aiSummary->summary_json['diagnosis'] ?? null,
                    'file_url' => Storage::disk('public')->url($report->file_path),
                ];
            });

        return response()->json([
            'reports' => $reports
        ]);
    }

    public function show($id)
    {
        $doctorId = auth()->id();

        $report = PatientReport::with(['patient', 'aiSummary'])
            ->where('doctor_id', $doctorId)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json([
            'id' => $report->id,
            'patient' => [
                'id' => $report->patient->id,
                'name' => $report->patient->name,
            ],
            'notes' => $report->notes,
            'uploaded_at' => $report->created_at->toDateTimeString(),
            'file_url' => Storage::disk('public')->url($report->file_path),
            'ai_summary' => $report->aiSummary->summary_json ?? null,
            'confidence_score' => $report->aiSummary->confidence_score ?? null,
            'ai_model_used' => $report->aiSummary->ai_model_used ?? null,
            'raw_text' => Str::limit($report->aiSummary->raw_text ?? '', 1500),
        ]);
    }
    public function listReports()
    {
        $doctorId = auth()->id();
    
        $reports = PatientReport::with(['patient', 'aiSummary'])
            ->where('doctor_id', $doctorId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($report) {
                return [
                    'report_id' => $report->id,
                    'patient_name' => $report->patient->name ?? 'N/A',
                    'uploaded_at' => $report->created_at->toDateTimeString(),
                    'file_url' => Storage::disk('public')->url($report->file_path),
                    'notes' => $report->notes,
                    'diagnosis' => $report->aiSummary->summary_json['diagnosis'] ?? null,
                ];
            });
    
        return response()->json([
            'reports' => $reports
        ]);
    }
    
}
