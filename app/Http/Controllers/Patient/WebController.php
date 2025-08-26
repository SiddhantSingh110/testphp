<?php

namespace App\Http\Controllers\Patient;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class WebController extends Controller
{
    /**
     * Show patient registration form
     */
    public function showRegisterForm()
    {
        return view('patient.auth.register');
    }
    
    /**
     * Show patient login form
     */
    public function showLoginForm()
    {
        return view('patient.auth.login');
    }
    
    /**
     * Show patient dashboard
     */
    public function dashboard()
    {
        return view('patient.dashboard');
    }
    
    /**
     * Show report upload form
     */
    public function showUploadForm()
    {
        return view('patient.reports.upload');
    }

    public function login(Request $request)
{
    $credentials = $request->only('phone', 'password');

    if (Auth::guard('patient')->attempt($credentials)) {
        $request->session()->regenerate();
        return redirect()->route('patient.dashboard');
    }

    return back()->withErrors([
        'phone' => 'Invalid credentials.',
    ]);
}
public function logout(Request $request)
{
    Auth::guard('patient')->logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();
    return redirect()->route('patient.login');
}
public function allReports()
{
    $patient = auth()->guard('patient')->user();

    return view('patient.reports.all', compact('patient'));
}
public function showReport($id)
{
    $patient = auth()->guard('patient')->user();
    $report = \App\Models\PatientReport::findOrFail($id);

    return view('patient.reports.show', compact('patient', 'report'));
}
public function healthMetricsView()
{
    return view('patient.metrics.index');
}

}