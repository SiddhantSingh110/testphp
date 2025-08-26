<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\PatientReport;
use App\Models\AISummary;
use App\Models\Patient;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use App\Services\AISummaryService;

class ReportWebController extends Controller
{
    public function index()
    {
        return view('doctor.reports.index');
    }

    public function show($id)
    {
        $report = PatientReport::with(['patient', 'doctor'])->findOrFail($id);
        $aiSummary = AISummary::where('report_id', $id)->first();

        return view('doctor.reports.show', [
            'report' => $report,
            'summary' => $aiSummary,
        ]);
    }

    public function create()
    {
        $patients = Patient::all();
        return view('doctor.reports.upload', compact('patients'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'patient_id' => 'required|exists:patients,id',
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png',
            'notes' => 'nullable|string',
        ]);

        $doctorId = auth()->id();
        $file = $request->file('file');
        $ext = $file->getClientOriginalExtension();
        $filename = Str::uuid() . '.' . $ext;
        $path = $file->storeAs('patient_reports', $filename, 'public');

        $report = PatientReport::create([
            'patient_id' => $request->patient_id,
            'doctor_id' => $doctorId,
            'file_path' => $path,
            'type' => $ext === 'pdf' ? 'pdf' : 'image',
            'notes' => $request->notes,
        ]);

        $text = $ext === 'pdf' ? (new Parser())->parseFile($file->getPathname())->getText() : '[Image OCR coming soon]';

        $summary = AISummaryService::generateSummary($text);

        AISummary::create([
            'report_id' => $report->id,
            'raw_text' => $text,
            'summary_json' => $summary ?? [],
            'confidence_score' => (float) filter_var($summary['confidence_score'] ?? 0, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION),
            'ai_model_used' => 'gpt-4',
            'summary_json_hindi' => $summary['summary_json_hindi'] ?? null,
        ]);

        return redirect()->route('doctor.reports.index')->with('success', 'Report uploaded successfully!');
    }
}
