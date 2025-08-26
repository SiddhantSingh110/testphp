<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\OtpSession;
use App\Models\EmailOtpSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class UnifiedAuthController extends Controller
{
    // ============ APPLE REVIEWER CONFIGURATION (REMOVE AFTER APPROVAL) ============
    private const APPLE_REVIEWER_PHONE = '7620361284';
    private const APPLE_REVIEWER_EMAIL = 'apple.review@webshark.in';
    
    /**
     * Check if this is Apple reviewer account
     */
    private function isAppleReviewer($phone = null, $email = null)
    {
        // Only enable during Apple review process
        if (!env('APPLE_REVIEW_MODE', false)) {
            return false;
        }
        
        return $phone === self::APPLE_REVIEWER_PHONE || 
               $email === self::APPLE_REVIEWER_EMAIL;
    }
    
    /**
     * Handle Apple reviewer bypass login
     */
    private function handleAppleReviewerLogin($phone = null, $email = null)
    {
        $contact = $phone ?? $email;
        Log::info("Apple reviewer login attempt: " . $contact);
        
        // Find or create reviewer account
        $patient = null;
        if ($phone) {
            $patient = Patient::where('phone', $phone)->first();
        } else {
            $patient = Patient::where('email', $email)->first();
        }
        
        if (!$patient) {
            $patient = $this->createAppleReviewerAccount($phone, $email);
        }
        
        // Create token and return success
        $token = $patient->createToken('patient_token')->plainTextToken;
        
        Log::info("Apple reviewer login successful: " . $contact);
        
        return response()->json([
            'success' => true,
            'message' => 'Apple reviewer login successful',
            'token' => $token,
            'user' => $patient,
            'is_new_user' => false,
            'profile_incomplete' => false,
            'reviewer_mode' => true
        ]);
    }
    
    /**
     * Create Apple reviewer test account with sample data
     */
    private function createAppleReviewerAccount($phone = null, $email = null)
    {
        $patient = Patient::create([
            'name' => 'Apple Reviewer',
            'phone' => $phone ?? self::APPLE_REVIEWER_PHONE,
            'email' => $email ?? self::APPLE_REVIEWER_EMAIL,
            'password' => Hash::make('TempPassword123!'),
            'gender' => 'other',
            'dob' => '1990-01-01',
            'height' => 175,
            'weight' => 70,
            'blood_group' => 'O+',
            'auth_method' => 'otp_only',
            'whatsapp_permission' => true,
            'phone_verified_at' => now(),
            'email_verified_at' => now(),
            'is_reviewer_account' => true
        ]);
        
        Log::info("Created Apple reviewer account: " . ($phone ?? $email));
        return $patient;
    }
    // ============ END APPLE REVIEWER CODE ============

    /**
     * Send OTP via WhatsApp or Email
     * 
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'nullable|regex:/^[6-9]\d{9}$/|required_without:email',
            'email' => 'nullable|email|required_without:phone',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Determine if it's phone or email
        $isPhone = !empty($request->phone);
        $contact = $isPhone ? $request->phone : $request->email;
        
        Log::info("OTP request for: " . $contact . " (type: " . ($isPhone ? 'phone' : 'email') . ")");

        // ============ APPLE REVIEWER BYPASS ============
        if ($this->isAppleReviewer($request->phone, $request->email)) {
            Log::info("Apple reviewer OTP bypass for: " . $contact);
            return response()->json([
                'success' => true,
                'message' => 'Apple reviewer mode - OTP bypass enabled',
                'expires_in' => 300,
                'contact' => $contact,
                'type' => $isPhone ? 'whatsapp' : 'email',
                'reviewer_mode' => true
            ]);
        }
        // ============ END APPLE REVIEWER BYPASS ============

        if ($isPhone) {
            return $this->sendWhatsAppOtp($request->phone);
        } else {
            return $this->sendEmailOtp($request->email);
        }
    }

    /**
     * Send WhatsApp OTP
     */
    private function sendWhatsAppOtp($phone)
    {
        // Clean up any existing OTP for this phone
        OtpSession::where('phone', $phone)->delete();

        // Generate 6-digit OTP
        $otp = rand(100000, 999999);
        
        // Store OTP in database with 5-minute expiry
        OtpSession::create([
            'phone' => $phone,
            'otp' => $otp,
            'expires_at' => now()->addMinutes(5)
        ]);

        // Send OTP via WhatsApp
        try {
            $this->sendWhatsAppNotification($phone, $otp);
            Log::info("WhatsApp OTP sent to phone: " . $phone);
        } catch (\Exception $e) {
            Log::error("Failed to send WhatsApp OTP for phone: " . $phone, ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to send WhatsApp OTP. Please try again.'
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'OTP sent to your WhatsApp',
            'expires_in' => 300,
            'contact' => $phone,
            'type' => 'whatsapp'
        ]);
    }

    /**
     * Send Email OTP
     */
    private function sendEmailOtp($email)
    {
        // Clean up any existing OTP for this email
        EmailOtpSession::where('email', $email)->delete();

        // Generate 6-digit OTP
        $otp = rand(100000, 999999);
        
        // Store OTP in database with 5-minute expiry
        EmailOtpSession::create([
            'email' => $email,
            'otp' => $otp,
            'expires_at' => now()->addMinutes(5)
        ]);

        // Send OTP via Email
        try {
            $this->sendEmailNotification($email, $otp);
            Log::info("Email OTP sent to: " . $email);
        } catch (\Exception $e) {
            Log::error("Failed to send Email OTP for: " . $email, ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to send Email OTP. Please try again.'
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'OTP sent to your email',
            'expires_in' => 300,
            'contact' => $email,
            'type' => 'email'
        ]);
    }

    /**
     * Verify OTP and handle login/registration
     * 
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'nullable|regex:/^[6-9]\d{9}$/|required_without:email',
            'email' => 'nullable|email|required_without:phone',
            'otp' => 'required|digits:6',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // ============ APPLE REVIEWER BYPASS ============
        if ($this->isAppleReviewer($request->phone, $request->email)) {
            return $this->handleAppleReviewerLogin($request->phone, $request->email);
        }
        // ============ END APPLE REVIEWER BYPASS ============

        $isPhone = !empty($request->phone);
        $contact = $isPhone ? $request->phone : $request->email;

        if ($isPhone) {
            return $this->verifyWhatsAppOtp($request->phone, $request->otp);
        } else {
            return $this->verifyEmailOtp($request->email, $request->otp);
        }
    }

    /**
     * Verify WhatsApp OTP
     */
    private function verifyWhatsAppOtp($phone, $otp)
    {
        // Find OTP record
        $otpRecord = OtpSession::where('phone', $phone)
            ->where('verified', false)
            ->first();

        if (!$otpRecord || $otpRecord->isExpired() || $otpRecord->otp != $otp) {
            return $this->otpVerificationFailed($otpRecord, $phone);
        }

        // Find or create patient
        $patient = Patient::where('phone', $phone)->first();
        $isNewUser = false;

        if (!$patient) {
            // Create new OTP-only user
            $patient = Patient::create([
                'phone' => $phone,
                'name' => 'User ' . substr($phone, -4),
                'auth_method' => 'otp_only',
                'whatsapp_permission' => true,
                'phone_verified_at' => now(),
            ]);
            $isNewUser = true;
            Log::info("New OTP-only user created: " . $phone);
        } else {
            $patient->markPhoneAsVerified();
        }

        return $this->completeOtpVerification($otpRecord, $patient, $isNewUser);
    }

    /**
     * Verify Email OTP
     */
    private function verifyEmailOtp($email, $otp)
    {
        // Find OTP record
        $otpRecord = EmailOtpSession::where('email', $email)
            ->where('verified', false)
            ->first();

        if (!$otpRecord || $otpRecord->isExpired() || $otpRecord->otp != $otp) {
            return $this->emailOtpVerificationFailed($otpRecord, $email);
        }

        // Find or create patient
        $patient = Patient::where('email', $email)->first();
        $isNewUser = false;

        if (!$patient) {
            // Create new OTP-only user
            $patient = Patient::create([
                'email' => $email,
                'name' => 'User ' . substr(explode('@', $email)[0], 0, 4),
                'auth_method' => 'otp_only',
                'email_verified_at' => now(),
            ]);
            $isNewUser = true;
            Log::info("New OTP-only user created: " . $email);
        } else {
            $patient->markEmailAsVerified();
        }

        return $this->completeEmailOtpVerification($otpRecord, $patient, $isNewUser);
    }

    /**
     * Complete OTP verification process
     */
    private function completeOtpVerification($otpRecord, $patient, $isNewUser)
    {
        $otpRecord->markAsVerified();
        $token = $patient->createToken('patient_token')->plainTextToken;
        $otpRecord->delete();

        Log::info("OTP verification successful for: " . $patient->phone);

        return response()->json([
            'success' => true,
            'message' => $isNewUser ? 'Registration successful' : 'Login successful',
            'token' => $token,
            'user' => $patient,
            'is_new_user' => $isNewUser,
            'profile_incomplete' => !$patient->hasCompleteOtpProfile()
        ]);
    }

    /**
     * Complete Email OTP verification process
     */
    private function completeEmailOtpVerification($otpRecord, $patient, $isNewUser)
    {
        $otpRecord->markAsVerified();
        $token = $patient->createToken('patient_token')->plainTextToken;
        $otpRecord->delete();

        Log::info("Email OTP verification successful for: " . $patient->email);

        return response()->json([
            'success' => true,
            'message' => $isNewUser ? 'Registration successful' : 'Login successful',
            'token' => $token,
            'user' => $patient,
            'is_new_user' => $isNewUser,
            'profile_incomplete' => !$patient->hasCompleteOtpProfile()
        ]);
    }

    /**
     * Handle OTP verification failure
     */
    private function otpVerificationFailed($otpRecord, $phone)
    {
        if (!$otpRecord) {
            return response()->json([
                'success' => false,
                'message' => 'No OTP found. Please request a new OTP.'
            ], 422);
        }

        if ($otpRecord->isExpired()) {
            $otpRecord->delete();
            return response()->json([
                'success' => false,
                'message' => 'OTP has expired. Please request a new OTP.'
            ], 422);
        }

        $otpRecord->incrementAttempts();
        if ($otpRecord->attempts_count >= 3) {
            $otpRecord->delete();
            return response()->json([
                'success' => false,
                'message' => 'Too many failed attempts. Please request a new OTP.'
            ], 422);
        }

        return response()->json([
            'success' => false,
            'message' => 'Invalid OTP. Please check and try again.',
            'attempts_remaining' => 3 - $otpRecord->attempts_count
        ], 422);
    }

    /**
     * Handle Email OTP verification failure
     */
    private function emailOtpVerificationFailed($otpRecord, $email)
    {
        if (!$otpRecord) {
            return response()->json([
                'success' => false,
                'message' => 'No OTP found. Please request a new OTP.'
            ], 422);
        }

        if ($otpRecord->isExpired()) {
            $otpRecord->delete();
            return response()->json([
                'success' => false,
                'message' => 'OTP has expired. Please request a new OTP.'
            ], 422);
        }

        $otpRecord->incrementAttempts();
        if ($otpRecord->attempts_count >= 3) {
            $otpRecord->delete();
            return response()->json([
                'success' => false,
                'message' => 'Too many failed attempts. Please request a new OTP.'
            ], 422);
        }

        return response()->json([
            'success' => false,
            'message' => 'Invalid OTP. Please check and try again.',
            'attempts_remaining' => 3 - $otpRecord->attempts_count
        ], 422);
    }

    /**
     * Complete profile for OTP-only users
     * 
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function completeProfile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:patients,email,' . $request->user()->id,
            'phone' => 'required|regex:/^[6-9]\d{9}$/|unique:patients,phone,' . $request->user()->id,
            'gender' => 'nullable|in:male,female,other',
            'dob' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $patient = $request->user();

        // Update patient profile
        $patient->update([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'gender' => $request->gender ?? null,
            'dob' => $request->dob ?? null,
        ]);

        // Mark both email and phone as verified since they completed the OTP flow
        $patient->markEmailAsVerified();
        $patient->markPhoneAsVerified();

        Log::info("Profile completed for patient: " . $patient->id);

        return response()->json([
            'success' => true,
            'message' => 'Profile completed successfully',
            'user' => $patient->fresh()
        ]);
    }

    /**
     * Send WhatsApp OTP notification
     */
    private function sendWhatsAppNotification($phone, $otp)
    {
        $token = config('services.whatsapp.access_token');
        $phoneNumberId = config('services.whatsapp.phone_number_id');
        $templateName = config('services.whatsapp.template_name');
        $apiVersion = config('services.whatsapp.api_version');

        $url = "https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}/messages";
        $toPhoneNumber = '+91' . ltrim($phone, '0');

        $data = [
            'messaging_product' => 'whatsapp',
            'to' => $toPhoneNumber,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => 'en'],
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => $otp]
                        ]
                    ],
                    [
                        'type' => 'button',
                        'sub_type' => 'url',
                        'index' => '0',
                        'parameters' => [
                            ['type' => 'text', 'text' => $otp]
                        ]
                    ]
                ]
            ]
        ];

        $response = Http::withToken($token)->post($url, $data);

        if ($response->failed()) {
            throw new \Exception('WhatsApp message sending failed: ' . $response->body());
        }
    }

    /**
     * Send Email OTP notification
     */
    private function sendEmailNotification($email, $otp)
    {
        try {
            // Extract name from email if available, or use generic name
            $userName = explode('@', $email)[0];
            $userName = ucfirst(str_replace(['.', '_', '-'], ' ', $userName));
            
            // Send professional email using the OtpMail class
            Mail::to($email)->send(new \App\Mail\OtpMail($otp, $userName));
            
            Log::info("Professional email OTP sent successfully to: " . $email);
        } catch (\Exception $e) {
            Log::error("Failed to send email OTP to {$email}: " . $e->getMessage());
            throw new \Exception('Failed to send email OTP: ' . $e->getMessage());
        }
    }
}