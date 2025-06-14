<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\DoctorAuthController;
use App\Http\Controllers\API\HospitalAuthController;
use App\Http\Controllers\API\Doctor\PatientReportController;
use App\Http\Controllers\API\PatientAuthController;
use App\Http\Controllers\API\Patient\ReportController;
use App\Http\Controllers\API\Patient\HealthMetricsController;

// Patient routes
Route::post('/patient/register', [PatientAuthController::class, 'register']);
Route::post('/patient/login', [PatientAuthController::class, 'login']);

// Patient authenticated routes
Route::middleware('auth:sanctum')->prefix('patient')->group(function () {
    Route::post('/logout', [PatientAuthController::class, 'logout']);
    Route::get('/profile', [PatientAuthController::class, 'profile']);
    Route::match(['put', 'post'], '/profile', [PatientAuthController::class, 'updateProfile']);
    Route::post('/change-password', [PatientAuthController::class, 'changePassword']);
});

// Doctor routes
Route::post('/doctor/register', [DoctorAuthController::class, 'register']);
Route::post('/doctor/login', [DoctorAuthController::class, 'login']);

// Hospital routes
Route::post('/hospital/register', [HospitalAuthController::class, 'register']);
Route::post('/hospital/login', [HospitalAuthController::class, 'login']);

//This route returns the currently authenticated user (patient/doctor/hospital) based on their token.
Route::middleware('auth:sanctum')->get('/me', function (Request $request) {
    return response()->json([
        'user' => $request->user()
    ]);
});

// This route is for uploading patient reports by doctors.
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/doctor/report/upload', [PatientReportController::class, 'upload']);
});

// 📁 Doctor Portal: Authenticated routes to view reports
// - GET /doctor/reports: List all reports uploaded by the doctor
// - GET /doctor/reports/{id}: View detailed report with AI summary & patient info
Route::middleware(['auth:sanctum'])->prefix('doctor')->group(function () {
    Route::get('/reports', [PatientReportController::class, 'index']);
    Route::get('/reports/{id}', [PatientReportController::class, 'show']);
});

// ✨ Enhanced Health metrics routes (protected)
Route::middleware('auth:sanctum')->prefix('patient')->group(function () {
    // Core metrics functionality
    Route::post('/metrics', [HealthMetricsController::class, 'store']);
    Route::get('/metrics', [HealthMetricsController::class, 'index']);
    Route::get('/metrics/trends/{type}', [HealthMetricsController::class, 'trends']);
    Route::delete('/metrics/{id}', [HealthMetricsController::class, 'destroy']);
    Route::get('/metrics/insights', [HealthMetricsController::class, 'insights']);
    Route::get('/metrics/categorized', [HealthMetricsController::class, 'categorizedMetrics']);
    
    // ✨ Enhanced metrics routes
    Route::get('/metrics/recent', [HealthMetricsController::class, 'getRecentMetrics']);
    Route::post('/metrics/mark-reviewed', [HealthMetricsController::class, 'markAsReviewed']);
});

// ✨ Enhanced Patient report routes (protected) - ENHANCED with OCR functionality
Route::middleware('auth:sanctum')->prefix('patient')->group(function () {
    // Core report management
    Route::post('/reports', [ReportController::class, 'upload']);
    Route::get('/reports', [ReportController::class, 'index']);
    Route::get('/reports/{id}', [ReportController::class, 'show']);
    Route::delete('/reports/{id}', [ReportController::class, 'destroy']);

    // ✅ SECURE: File download endpoint (replaces file_url)
    Route::get('/reports/{id}/download', [ReportController::class, 'downloadFile']);
    
    // ✅ DEBUG: File info endpoint for debugging
    Route::get('/reports/{id}/file-info', [ReportController::class, 'getFileInfo']);
   
    
    // AI and finding details
    Route::get('/reports/{id}/summary-pdf', [ReportController::class, 'downloadSummaryPdf']);
    Route::post('/reports/{id}/findings', [ReportController::class, 'getFindingDetails']);
    
    // 🆕 OCR-specific routes
    Route::post('/reports/{id}/retry-ocr', [ReportController::class, 'retryOCR']);
    Route::get('/reports/{id}/ocr-status', [ReportController::class, 'getOCRStatus']);
});

// 🔧 Admin Routes for Health Metrics Provider Management
Route::middleware(['auth:sanctum'])->prefix('admin/health-metrics')->group(function () {
    // Get current configuration and service health
    Route::get('/configuration', [App\Http\Controllers\Admin\HealthMetricsAdminController::class, 'getConfiguration']);
    
    // Switch primary provider (DeepSeek ↔ Claude ↔ OpenAI)
    Route::post('/provider', [App\Http\Controllers\Admin\HealthMetricsAdminController::class, 'switchPrimaryProvider']);
    
    // Update specific provider configuration
    Route::put('/provider/{provider}', [App\Http\Controllers\Admin\HealthMetricsAdminController::class, 'updateProviderConfig'])
        ->where('provider', 'deepseek|claude|openai');
    
    // Enable/disable specific provider
    Route::patch('/provider/{provider}/toggle', [App\Http\Controllers\Admin\HealthMetricsAdminController::class, 'toggleProvider'])
        ->where('provider', 'deepseek|claude|openai');
    
    // Test specific provider with sample data
    Route::post('/provider/{provider}/test', [App\Http\Controllers\Admin\HealthMetricsAdminController::class, 'testProvider'])
        ->where('provider', 'deepseek|claude|openai');
    
    // Get provider performance statistics
    Route::get('/performance', [App\Http\Controllers\Admin\HealthMetricsAdminController::class, 'getProviderPerformance']);
    
    // Reset configuration to defaults (requires confirmation)
    Route::post('/reset', [App\Http\Controllers\Admin\HealthMetricsAdminController::class, 'resetConfiguration']);
});

// 🆕 System utility routes for OCR
Route::middleware('auth:sanctum')->prefix('system')->group(function () {
    // Check OCR service availability
    Route::get('/ocr/status', function () {
        try {
            $ocrService = app(\App\Services\OCRService::class);
            return response()->json([
                'available' => \App\Services\OCRService::isAvailable(),
                'version' => $ocrService->getVersion(),
                'status' => 'operational'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'available' => false,
                'error' => $e->getMessage(),
                'status' => 'error'
            ], 500);
        }
    });
    
    // Get reports that need OCR processing (for background jobs)
    Route::get('/ocr/pending', function () {
        $pendingReports = \App\Models\PatientReport::needsOCR()
            ->with('patient:id,name')
            ->limit(10)
            ->get(['id', 'patient_id', 'file_path', 'original_file_path', 'processing_attempts', 'created_at']);
            
        return response()->json([
            'pending_reports' => $pendingReports,
            'count' => $pendingReports->count()
        ]);
    });
});

// 📊 Public Health Metrics System Status (no auth required)
Route::get('/system/health-metrics/status', function () {
    try {
        $extractionService = app(\App\Services\HealthMetricsExtraction\HealthMetricsExtractionService::class);
        $serviceHealth = $extractionService->getServiceHealth();
        
        return response()->json([
            'service_status' => $serviceHealth['service_status'],
            'primary_provider' => $serviceHealth['primary_provider'],
            'total_providers' => $serviceHealth['total_providers'],
            'fallback_enabled' => $serviceHealth['fallback_enabled'],
            'timestamp' => now()->toISOString()
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'service_status' => 'error',
            'error' => 'Service unavailable',
            'timestamp' => now()->toISOString()
        ], 500);
    }
});