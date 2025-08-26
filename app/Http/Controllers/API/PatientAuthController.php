<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\OtpSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class PatientAuthController extends Controller
{
    // ============ APPLE REVIEWER CONFIGURATION (REMOVE AFTER APPROVAL) ============
    private const APPLE_REVIEWER_PHONE = '7620361284';
    private const APPLE_REVIEWER_EMAIL = 'apple.review@webshark.in';
    
    /**
     * Check if this is Apple reviewer account
     */
    private function isAppleReviewer($phone, $email = null)
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
    private function handleAppleReviewerLogin($phone)
    {
        Log::info("Apple reviewer login attempt: " . $phone);
        
        // Find or create reviewer account
        $patient = Patient::where('phone', $phone)->first();
        
        if (!$patient) {
            $patient = $this->createAppleReviewerAccount($phone);
        }
        
        // Create token and return success
        $token = $patient->createToken('patient_token')->plainTextToken;
        
        Log::info("Apple reviewer login successful: " . $phone);
        
        return response()->json([
            'success' => true,
            'message' => 'Apple reviewer login successful',
            'token' => $token,
            'user' => $patient,
            'reviewer_mode' => true
        ]);
    }
    
    /**
     * Create Apple reviewer test account with sample data
     */
    private function createAppleReviewerAccount($phone)
    {
        $patient = Patient::create([
            'name' => 'Apple Reviewer',
            'phone' => $phone,
            'email' => self::APPLE_REVIEWER_EMAIL,
            'password' => Hash::make('TempPassword123!'),
            'gender' => 'other',
            'dob' => '1990-01-01',
            'height' => 175,
            'weight' => 70,
            'blood_group' => 'O+',
            'phone_verified_at' => now(),
            'is_reviewer_account' => true
        ]);
        
        Log::info("Created Apple reviewer account: " . $phone);
        return $patient;
    }
    // ============ END APPLE REVIEWER CODE ============

    /**
     * Register a new patient
     * 
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:255',
            'phone'    => 'required|string|unique:patients',
            'email'    => 'nullable|email|unique:patients,email',
            'password' => 'required|string|min:6',
            'gender'   => 'nullable|in:male,female,other',
            'dob'      => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $patient = Patient::create([
            'name'     => $request->name,
            'phone'    => $request->phone,
            'email'    => $request->email ?? null,
            'password' => Hash::make($request->password),
            'gender'   => $request->gender ?? null,
            'dob'      => $request->dob ?? null,
        ]);

        $token = $patient->createToken('patient_token')->plainTextToken;

        return response()->json([
            'message' => 'Patient registered successfully',
            'token' => $token,
            'user' => $patient
        ], 201);
    }

    /**
     * Login a patient
     * 
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone'    => 'required|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $patient = Patient::where('phone', $request->phone)->first();

        if (!$patient || !Hash::check($request->password, $patient->password)) {
            throw ValidationException::withMessages([
                'phone' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $patient->createToken('patient_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'token' => $token,
            'user' => $patient
        ]);
    }

    /**
     * Send OTP to patient's WhatsApp for login
     * 
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|regex:/^[6-9]\d{9}$/',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // ============ APPLE REVIEWER BYPASS ============
        if ($this->isAppleReviewer($request->phone)) {
            Log::info("Apple reviewer OTP bypass for: " . $request->phone);
            return response()->json([
                'success' => true,
                'message' => 'Apple reviewer mode - OTP bypass enabled',
                'expires_in' => 300,
                'phone' => $request->phone,
                'reviewer_mode' => true
            ]);
        }
        // ============ END APPLE REVIEWER BYPASS ============

        $patient = Patient::where('phone', $request->phone)->first();

        if (!$patient) {
            Log::warning("OTP request for unregistered phone: " . $request->phone);
            return response()->json([
                'success' => false,
                'message' => 'Phone number not registered. Please sign up first.',
                'action' => 'register_required'
            ], 404);
        }

        // Clean up any existing OTP for this phone
        \App\Models\OtpSession::where('phone', $request->phone)->delete();

        // Generate 6-digit OTP
        $otp = rand(100000, 999999);
        
        // Store OTP in database with 5-minute expiry
        \App\Models\OtpSession::create([
            'phone' => $request->phone,
            'otp' => $otp,
            'expires_at' => now()->addMinutes(5)
        ]);

        // Send OTP via WhatsApp
        try {
            $this->sendWhatsAppNotificationOtpForSignin($request->phone, $otp);
            Log::info("WhatsApp OTP sent to phone: " . $request->phone);
        } catch (\Exception $e) {
            Log::error("Failed to send WhatsApp OTP for phone: " . $request->phone, ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to send OTP. Please try again.'
            ], 500);
        }

        Log::info("OTP sent to phone: " . $request->phone);
        
        return response()->json([
            'success' => true,
            'message' => 'OTP sent to your WhatsApp',
            'expires_in' => 300, // 5 minutes
            'phone' => $request->phone
        ]);
    }

    /**
     * Verify OTP and login patient
     * 
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|regex:/^[6-9]\d{9}$/',
            'otp' => 'required|digits:6',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // ============ APPLE REVIEWER BYPASS ============
        if ($this->isAppleReviewer($request->phone)) {
            return $this->handleAppleReviewerLogin($request->phone);
        }
        // ============ END APPLE REVIEWER BYPASS ============

        // Find OTP record
        $otpRecord = \App\Models\OtpSession::where('phone', $request->phone)
            ->where('verified', false)
            ->first();

        if (!$otpRecord) {
            Log::warning("No OTP record found for phone: " . $request->phone);
            return response()->json([
                'success' => false,
                'message' => 'No OTP found. Please request a new OTP.'
            ], 422);
        }

        // Check if OTP has expired
        if ($otpRecord->isExpired()) {
            $otpRecord->delete();
            Log::warning("OTP expired for phone: " . $request->phone);
            return response()->json([
                'success' => false,
                'message' => 'OTP has expired. Please request a new OTP.'
            ], 422);
        }

        // Check if OTP matches
        if ($request->otp != $otpRecord->otp) {
            $otpRecord->incrementAttempts();
            
            // Delete after 3 failed attempts
            if ($otpRecord->attempts_count >= 3) {
                $otpRecord->delete();
                Log::warning("Too many failed OTP attempts for phone: " . $request->phone);
                return response()->json([
                    'success' => false,
                    'message' => 'Too many failed attempts. Please request a new OTP.'
                ], 422);
            }

            Log::warning("Invalid OTP attempt for phone: " . $request->phone);
            return response()->json([
                'success' => false,
                'message' => 'Invalid OTP. Please check and try again.',
                'attempts_remaining' => 3 - $otpRecord->attempts_count
            ], 422);
        }

        // Find patient
        $patient = Patient::where('phone', $request->phone)->first();

        if (!$patient) {
            Log::error("Patient not found during OTP verification: " . $request->phone);
            return response()->json([
                'success' => false,
                'message' => 'Patient account not found.'
            ], 404);
        }

        // Mark phone as verified and OTP as used
        $patient->markPhoneAsVerified();
        $otpRecord->markAsVerified();

        // Create token
        $token = $patient->createToken('patient_token')->plainTextToken;

        // Clean up the OTP record
        $otpRecord->delete();

        Log::info("OTP login successful for phone: " . $request->phone);

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'token' => $token,
            'user' => $patient
        ]);
    }

    /**
     * Send password reset email
     * 
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function forgetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:patients,email',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $patient = Patient::where('email', $request->email)->first();

        if (!$patient) {
            Log::warning("Password reset attempt failed: Email not found - " . $request->email);
            return response()->json([
                'success' => false,
                'message' => 'Email not found in our records.'
            ], 404);
        }

        // Generate Reset Token
        $token = Str::random(64);

        // Store Token in password_resets table
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $request->email],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        // Send Reset Email (You'll need to create this email template)
        try {
            $resetLink = url("/reset-password?token=$token&email={$request->email}");
            
            // For now, just log the reset link (replace with actual email sending)
            Log::info("Password reset link for {$request->email}: " . $resetLink);
            
            // TODO: Send actual email here
            // Mail::send('emails.patient-password-reset', [
            //     'name' => $patient->name,
            //     'resetLink' => $resetLink
            // ], function ($message) use ($patient) {
            //     $message->to($patient->email);
            //     $message->subject('Reset Your Password - Medical App');
            // });

        } catch (\Exception $e) {
            Log::error("Failed to send password reset email: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to send reset email. Please try again.'
            ], 500);
        }

        Log::info("Password reset initiated for email: " . $request->email);

        return response()->json([
            'success' => true,
            'message' => 'Password reset link sent to your email'
        ]);
    }

    /**
     * Reset password using token
     * 
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:patients,email',
            'token' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Find reset record
        $resetRecord = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$resetRecord) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired reset token.'
            ], 422);
        }

        // Check if token matches
        if (!Hash::check($request->token, $resetRecord->token)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid reset token.'
            ], 422);
        }

        // Check if token is not expired (24 hours)
        if (now()->diffInHours($resetRecord->created_at) > 24) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            return response()->json([
                'success' => false,
                'message' => 'Reset token has expired. Please request a new one.'
            ], 422);
        }

        // Update password
        $patient = Patient::where('email', $request->email)->first();
        $patient->password = Hash::make($request->password);
        $patient->save();

        // Delete the password reset entry
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        Log::info("Password reset successful for email: " . $request->email);

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully'
        ]);
    }

    /**
     * Logout a patient
     * 
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }

    /**
     * Get the authenticated patient profile
     * 
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function profile(Request $request)
    {
        $patient = $request->user();
        
        return response()->json([
            'user' => $patient
        ]);
    }

    /**
     * Update patient profile with support for profile photo uploads
     * 
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateProfile(Request $request)
    {
        Log::info('Update Profile Request');
        
        // Get raw input and try to parse manually if needed
        $rawInput = file_get_contents('php://input');
        Log::info('Raw Input', [$rawInput]);
        
        // Check if regular parsing worked
        $parsedData = $request->all();
        Log::info('Parsed Fields', [$parsedData]);
        
        // Check for uploaded files
        $files = $request->allFiles();
        Log::info('Files in request', ['count' => count($files), 'keys' => array_keys($files)]);
        
        // Extract form data if needed
        if (empty($parsedData) || (count($parsedData) === 1 && isset($parsedData['profile_photo']) && empty($parsedData['profile_photo']))) {
            Log::info('Regular parsing failed or only contained empty profile_photo, attempting manual extraction');
            $parsedData = $this->extractFormDataFromRawInput($rawInput);
            Log::info('Manually extracted data', [$parsedData]);
        }
        
        // Validation - DON'T validate the profile_photo here as it might be handled separately
        $validator = Validator::make($parsedData, [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|nullable|email|unique:patients,email,' . $request->user()->id,
            'gender' => 'sometimes|nullable|in:male,female,other',
            'dob' => 'sometimes|nullable|date_format:Y-m-d',  // Strict date format
            'height' => 'sometimes|nullable|numeric',
            'weight' => 'sometimes|nullable|numeric',
            'blood_group' => 'sometimes|nullable|string|max:10',
            'phone' => 'sometimes|nullable|string',
        ]);
    
        if ($validator->fails()) {
            Log::error('Validation failed', ['errors' => $validator->errors()->toArray()]);
            return response()->json(['errors' => $validator->errors()], 422);
        }
    
        $patient = $request->user();
        
        // Update fields from parsed data
        if (!empty($parsedData['name'])) {
            $patient->name = $parsedData['name'];
        }
        
        if (isset($parsedData['email'])) {
            $patient->email = $parsedData['email'];
        }
        
        if (!empty($parsedData['gender'])) {
            // Explicitly check for valid values
            $gender = strtolower($parsedData['gender']);
            if (in_array($gender, ['male', 'female', 'other'])) {
                $patient->gender = $gender;
            }
        }
        
        if (!empty($parsedData['dob'])) {
            // Ensure proper date format
            try {
                $date = new \DateTime($parsedData['dob']);
                $patient->dob = $date->format('Y-m-d');
            } catch (\Exception $e) {
                Log::error('Invalid date format', ['dob' => $parsedData['dob']]);
            }
        }
        
        if (isset($parsedData['height']) && is_numeric($parsedData['height'])) {
            $patient->height = $parsedData['height'];
        }
        
        if (isset($parsedData['weight']) && is_numeric($parsedData['weight'])) {
            $patient->weight = $parsedData['weight'];
        }
        
        if (!empty($parsedData['blood_group'])) {
            $patient->blood_group = $parsedData['blood_group'];
        }
        
        // Process profile photo - check in request
        $profilePhoto = $request->file('profile_photo');
    
        // In the profile photo handling section
        if ($profilePhoto && $profilePhoto->isValid()) {
            try {
                // Create a unique filename
                $filename = 'profile_' . uniqid() . '.jpg';
                
                // Resize the image using Intervention Image library
                // First, install it: composer require intervention/image
                $img = \Intervention\Image\Facades\Image::make($profilePhoto);
                
                // Resize to reasonable dimensions while maintaining aspect ratio
                $img->resize(500, null, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                });
                
                // Save the resized image to storage
                $path = 'profile_photos/' . $filename;
                Storage::disk('public')->put($path, (string) $img->encode('jpg', 70));
                
                // Delete old profile photo if exists
                if ($patient->profile_photo && Storage::disk('public')->exists($patient->profile_photo)) {
                    Storage::disk('public')->delete($patient->profile_photo);
                }
                
                // Update patient record
                $patient->profile_photo = $path;
                Log::info('Profile photo resized and saved', [
                    'original_size' => $profilePhoto->getSize(),
                    'path' => $path,
                    'dimensions' => $img->width() . 'x' . $img->height()
                ]);
            } catch (\Exception $e) {
                Log::error('Error processing profile photo', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        } else {
            // Log that we didn't find a file
            Log::info('No profile photo found in request');
        }
        
        // Save patient data
        try {
            $patient->save();
            Log::info('Patient saved successfully', $patient->toArray());
            
            return response()->json([
                'message' => 'Profile updated successfully',
                'user' => $patient,
                'status' => 'success'
            ]);
        } catch (\Exception $e) {
            Log::error('Error saving patient data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'message' => 'Error updating profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Change password for authenticated patient
     * 
     * @param  Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:6|different:current_password',
            'profile_photo' => 'sometimes|image|mimes:jpeg,png,jpg|max:10048', // 10MB max
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $patient = $request->user();

        if (!Hash::check($request->current_password, $patient->password)) {
            return response()->json([
                'message' => 'Current password is incorrect'
            ], 422);
        }

        $patient->password = Hash::make($request->new_password);
        $patient->save();

        return response()->json([
            'message' => 'Password changed successfully'
        ]);
    }

    /**
     * Send WhatsApp OTP notification for patient signin
     * 
     * @param string $phone
     * @param string $otp
     * @return void
     * @throws \Exception
     */
    private function sendWhatsAppNotificationOtpForSignin($phone, $otp)
    {
        $token = config('services.whatsapp.access_token');
        $phoneNumberId = config('services.whatsapp.phone_number_id');
        $templateName = config('services.whatsapp.template_name');
        $apiVersion = config('services.whatsapp.api_version');

        $url = "https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}/messages";

        // Format the phone number correctly (E.164) - keeping +91 for WhatsApp API
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
     * Helper function to translate upload error codes to messages
     *
     * @param int $code Error code from PHP file upload
     * @return string Human-readable error message
     */
    protected function uploadErrorCodeToMessage($code)
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
                return 'The uploaded file exceeds the upload_max_filesize directive in php.ini';
            case UPLOAD_ERR_FORM_SIZE:
                return 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form';
            case UPLOAD_ERR_PARTIAL:
                return 'The uploaded file was only partially uploaded';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Missing a temporary folder';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Failed to write file to disk';
            case UPLOAD_ERR_EXTENSION:
                return 'A PHP extension stopped the file upload';
            default:
                return 'Unknown upload error';
        }
    }

    /**
     * Helper function to manually extract form data from raw input
     *
     * @param string $rawInput Raw HTTP request body
     * @return array Extracted form data as associative array
     */
    private function extractFormDataFromRawInput($rawInput)
    {
        $data = [];
        
        // Extract form fields using regex pattern
        preg_match_all('/content-disposition: form-data; name=\"([^\"]+)\"\s+\s+([\s\S]+?)(?=--|\Z)/i', $rawInput, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $fieldName = $match[1];
            $fieldValue = trim($match[2]);
            $data[$fieldName] = $fieldValue;
        }
        
        return $data;
    }
}