<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});


use App\Http\Controllers\Doctor\ReportWebController;

Route::middleware('auth:doctor')->prefix('doctor')->group(function () {
    Route::get('/reports', [ReportWebController::class, 'index'])->name('doctor.reports.index');

    // ⬇ Place this BEFORE the /reports/{id} route
    Route::get('/reports/upload', [ReportWebController::class, 'create'])->name('doctor.reports.upload');
    Route::post('/reports/upload', [ReportWebController::class, 'store'])->name('doctor.reports.upload.submit');

    Route::get('/reports/{id}', [ReportWebController::class, 'show'])->name('doctor.reports.show');
});

use App\Http\Controllers\Doctor\AuthController;

Route::prefix('doctor')->name('doctor.')->group(function () {
    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.submit');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::middleware('auth:doctor')->group(function () {
        Route::get('/reports', [ReportWebController::class, 'index'])->name('reports.index');
    });
});

// Global /login route handler (optional fallback based on role detection)
Route::get('/login', function () {
    if (auth('doctor')->check()) {
        return redirect()->route('doctor.reports.index');
    } elseif (auth('patient')->check()) {
        return redirect()->route('patient.dashboard');
    }

    // Default fallback - redirect to a general landing page or ask role
    return redirect('/choose-role'); // or show a "choose role" page
})->name('login');

use App\Http\Controllers\Patient\WebController;
use App\Http\Controllers\API\Patient\HealthMetricsController;

// Patient web routes for testing
Route::prefix('patient')->name('patient.')->group(function () {
    Route::get('/register', [WebController::class, 'showRegisterForm'])->name('register');
    Route::get('/login', [WebController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [WebController::class, 'login'])->name('login.submit'); // Handle login form submission
    Route::post('/logout', [WebController::class, 'logout'])->name('logout'); // Handle logout
    // Protected routes
    Route::middleware('auth:patient')->group(function () {
        Route::get('/dashboard', [WebController::class, 'dashboard'])->name('dashboard');
        Route::get('/upload', [WebController::class, 'showUploadForm'])->name('upload');
        Route::post('/upload', [\App\Http\Controllers\API\Patient\ReportController::class, 'upload'])->name('reports.upload');
        Route::get('/reports/all', [WebController::class, 'allReports'])->name('reports.all');
        Route::get('/reports/{id}', [WebController::class, 'showReport'])->name('reports.show');
        Route::get('/metrics', [WebController::class, 'healthMetricsView'])->name('metrics.index');
    });
});