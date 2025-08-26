<?php

namespace App\Http\Controllers\API\Patient;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;

class FamilyController extends Controller
{
    /**
     * Get all profiles accessible by the authenticated user
     * (Self + Family members for primary accounts)
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getProfiles(Request $request)
    {
        try {
            $currentUser = $request->user();
            $profiles = $currentUser->accessibleProfiles();
            
            $formattedProfiles = $profiles->map(function ($profile) use ($currentUser) {
                return [
                    'id' => $profile->id,
                    'name' => $profile->name,
                    'relationship' => $profile->relationship,
                    'is_current_user' => $profile->id === $currentUser->id,
                    'is_primary' => $profile->is_primary_account,
                    'profile_photo' => $profile->profile_photo,
                    'gender' => $profile->gender,
                    'dob' => $profile->dob,
                    'lock_status' => $profile->getLockStatusInfo(),
                    'display_name' => $profile->display_name,
                    'created_at' => $profile->created_at,
                ];
            });

            // Sort: Current user first, then family members
            $sorted = $formattedProfiles->sortBy(function ($profile) {
                return $profile['is_current_user'] ? 0 : 1;
            })->values();

            return response()->json([
                'success' => true,
                'profiles' => $sorted,
                'total_profiles' => $profiles->count(),
                'can_add_family' => $currentUser->is_primary_account,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get profiles', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load profiles'
            ], 500);
        }
    }

    /**
     * Create a new family member
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createFamilyMember(Request $request)
    {
        try {
            $currentUser = $request->user();

            // Only primary accounts can create family members
            if (!$currentUser->is_primary_account) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only primary account holders can add family members'
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'relationship' => 'required|string|in:father,mother,spouse,son,daughter,brother,sister,grandfather,grandmother,uncle,aunt,cousin,other',
                'gender' => 'nullable|in:male,female,other',
                'dob' => 'nullable|date|before:today',
                'phone' => 'nullable|regex:/^[6-9]\d{9}$/|unique:patients,phone',
                'email' => 'nullable|email|unique:patients,email',
                'blood_group' => 'nullable|string|in:A+,A-,B+,B-,AB+,AB-,O+,O-', 
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            // Check family member limit (optional)
            $familyCount = $currentUser->familyMembers()->count();
            if ($familyCount >= 10) { // Reasonable limit
                return response()->json([
                    'success' => false,
                    'message' => 'Maximum family members limit reached (10)'
                ], 422);
            }

            // Create family member
            $familyMember = $currentUser->createFamilyMember($request->all());

            Log::info('Family member created', [
                'primary_user_id' => $currentUser->id,
                'family_member_id' => $familyMember->id,
                'name' => $familyMember->name,
                'relationship' => $familyMember->relationship
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Family member added successfully',
                'family_member' => [
                    'id' => $familyMember->id,
                    'name' => $familyMember->name,
                    'relationship' => $familyMember->relationship,
                    'gender' => $familyMember->gender,
                    'dob' => $familyMember->dob,
                    'is_primary' => false,
                    'lock_status' => $familyMember->getLockStatusInfo(),
                    'display_name' => $familyMember->display_name,
                    'created_at' => $familyMember->created_at,
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create family member', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id,
                'request_data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create family member'
            ], 500);
        }
    }

    /**
     * ✅ FIXED: Update family member details with photo upload support
     * 
     * @param Request $request
     * @param int $profileId
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateFamilyMember(Request $request, $profileId)
    {
        Log::info('🔄 Family member update request started', [
            'profile_id' => $profileId,
            'user_id' => $request->user()->id,
            'method' => $request->method(),
            'has_file' => $request->hasFile('profile_photo'),
            'content_type' => $request->header('Content-Type')
        ]);

        try {
            $currentUser = $request->user();

            // Check if user can manage this profile
            if (!$currentUser->canManageProfile($profileId)) {
                Log::warning('Unauthorized family member update attempt', [
                    'user_id' => $currentUser->id,
                    'profile_id' => $profileId
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to modify this profile'
                ], 403);
            }

            $profile = Patient::findOrFail($profileId);
            
            Log::info('📝 Found profile for update', [
                'profile_name' => $profile->name,
                'is_primary' => $profile->is_primary_account
            ]);

            // ✅ ENHANCED VALIDATION: Include all fields + profile photo
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'relationship' => 'sometimes|string|in:father,mother,spouse,son,daughter,brother,sister,grandfather,grandmother,uncle,aunt,cousin,other',
                'gender' => 'sometimes|nullable|in:male,female,other',
                'dob' => 'sometimes|nullable|date|before:today',
                'phone' => 'sometimes|nullable|regex:/^[6-9]\d{9}$/|unique:patients,phone,' . $profileId,
                'email' => 'sometimes|nullable|email|unique:patients,email,' . $profileId,
                'height' => 'sometimes|nullable|numeric|min:30|max:300',
                'weight' => 'sometimes|nullable|numeric|min:1|max:500',
                'blood_group' => 'sometimes|nullable|string|in:A+,A-,B+,B-,AB+,AB-,O+,O-',
                'profile_photo' => 'sometimes|image|mimes:jpeg,png,jpg,gif|max:10240', // 10MB max
            ]);

            if ($validator->fails()) {
                Log::error('Family member update validation failed', [
                    'errors' => $validator->errors()->toArray(),
                    'profile_id' => $profileId
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // ✅ UPDATE BASIC FIELDS (same as before but with all fields)
            $fieldsToUpdate = [
                'name', 'relationship', 'gender', 'dob', 'phone', 'email',
                'height', 'weight', 'blood_group'
            ];
            
            $updatedFields = [];
            foreach ($fieldsToUpdate as $field) {
                if ($request->has($field)) {
                    $value = $request->input($field);
                    
                    // Special handling for specific fields
                    if ($field === 'dob' && $value) {
                        try {
                            $date = new \DateTime($value);
                            $profile->$field = $date->format('Y-m-d');
                            $updatedFields[] = $field;
                        } catch (\Exception $e) {
                            Log::warning('Invalid date format', ['dob' => $value]);
                        }
                    } elseif ($field === 'gender' && $value) {
                        $gender = strtolower($value);
                        if (in_array($gender, ['male', 'female', 'other'])) {
                            $profile->$field = $gender;
                            $updatedFields[] = $field;
                        }
                    } elseif (in_array($field, ['height', 'weight']) && $value !== null) {
                        if (is_numeric($value)) {
                            $profile->$field = $value;
                            $updatedFields[] = $field;
                        }
                    } else {
                        $profile->$field = $value;
                        $updatedFields[] = $field;
                    }
                }
            }

            // ✅ NEW: HANDLE PROFILE PHOTO UPLOAD
            if ($request->hasFile('profile_photo')) {
                $profilePhoto = $request->file('profile_photo');
                
                if ($profilePhoto->isValid()) {
                    try {
                        Log::info('📸 Processing profile photo upload', [
                            'original_name' => $profilePhoto->getClientOriginalName(),
                            'size' => $profilePhoto->getSize(),
                            'mime_type' => $profilePhoto->getMimeType(),
                            'profile_id' => $profileId
                        ]);
                        
                        // Create unique filename
                        $filename = 'profile_' . $profileId . '_' . uniqid() . '.jpg';
                        
                        // Process image with Intervention Image (if available)
                        if (class_exists('\Intervention\Image\Facades\Image')) {
                            $img = Image::make($profilePhoto);
                            
                            // Resize to reasonable dimensions while maintaining aspect ratio
                            $img->resize(500, null, function ($constraint) {
                                $constraint->aspectRatio();
                                $constraint->upsize();
                            });
                            
                            // Convert to JPG and compress
                            $img->encode('jpg', 80);
                            
                            // Save to storage
                            $path = 'profile_photos/' . $filename;
                            Storage::disk('public')->put($path, (string) $img);
                            
                            Log::info('✅ Profile photo processed with Intervention Image', [
                                'path' => $path,
                                'dimensions' => $img->width() . 'x' . $img->height()
                            ]);
                        } else {
                            // Fallback: Direct file storage without processing
                            $path = $profilePhoto->store('profile_photos', 'public');
                            Log::info('✅ Profile photo stored directly (no Intervention Image)', [
                                'path' => $path
                            ]);
                        }
                        
                        // Delete old photo if exists
                        if ($profile->profile_photo && Storage::disk('public')->exists($profile->profile_photo)) {
                            Storage::disk('public')->delete($profile->profile_photo);
                            Log::info('🗑️ Deleted old profile photo', ['old_path' => $profile->profile_photo]);
                        }
                        
                        // Update profile with new photo path
                        $profile->profile_photo = $path;
                        $updatedFields[] = 'profile_photo';
                        
                    } catch (\Exception $e) {
                        Log::error('❌ Error processing profile photo', [
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                            'profile_id' => $profileId
                        ]);
                        
                        return response()->json([
                            'success' => false,
                            'message' => 'Failed to process profile photo: ' . $e->getMessage()
                        ], 500);
                    }
                } else {
                    Log::warning('⚠️ Invalid profile photo file', [
                        'error_code' => $profilePhoto->getError(),
                        'profile_id' => $profileId
                    ]);
                }
            }

            // ✅ SAVE THE PROFILE
            try {
                $profile->save();
                
                Log::info('✅ Family member profile updated successfully', [
                    'profile_id' => $profileId,
                    'updated_fields' => $updatedFields,
                    'user_id' => $currentUser->id
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Profile updated successfully',
                    'profile' => [
                        'id' => $profile->id,
                        'name' => $profile->name,
                        'relationship' => $profile->relationship,
                        'gender' => $profile->gender,
                        'dob' => $profile->dob,
                        'phone' => $profile->phone,
                        'email' => $profile->email,
                        'height' => $profile->height,
                        'weight' => $profile->weight,
                        'blood_group' => $profile->blood_group,
                        'profile_photo' => $profile->profile_photo,
                        'is_primary' => $profile->is_primary_account,
                        'lock_status' => $profile->getLockStatusInfo(),
                        'display_name' => $profile->display_name,
                    ]
                ]);

            } catch (\Exception $e) {
                Log::error('❌ Error saving family member profile', [
                    'error' => $e->getMessage(),
                    'profile_id' => $profileId,
                    'updated_fields' => $updatedFields
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to save profile changes'
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('❌ Family member update failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()->id,
                'profile_id' => $profileId
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update profile'
            ], 500);
        }
    }

    /**
     * Delete a family member
     * 
     * @param Request $request
     * @param int $profileId
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteFamilyMember(Request $request, $profileId)
    {
        try {
            $currentUser = $request->user();

            // Cannot delete own profile this way
            if ($currentUser->id == $profileId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete your own profile'
                ], 422);
            }

            // Check if user can manage this profile
            if (!$currentUser->canManageProfile($profileId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to delete this profile'
                ], 403);
            }

            $profile = Patient::findOrFail($profileId);

            // Cannot delete primary accounts
            if ($profile->is_primary_account) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete primary account profiles'
                ], 422);
            }

            // Store info for logging before deletion
            $deletedName = $profile->name;
            $deletedRelationship = $profile->relationship;

            // Delete profile photo if exists
            if ($profile->profile_photo && Storage::disk('public')->exists($profile->profile_photo)) {
                Storage::disk('public')->delete($profile->profile_photo);
                Log::info('🗑️ Deleted profile photo during family member deletion', ['path' => $profile->profile_photo]);
            }

            // Delete the profile (cascade will handle related data)
            $profile->delete();

            Log::info('Family member deleted', [
                'primary_user_id' => $currentUser->id,
                'deleted_profile_id' => $profileId,
                'deleted_name' => $deletedName,
                'deleted_relationship' => $deletedRelationship
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Family member deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to delete family member', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id,
                'profile_id' => $profileId
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete family member'
            ], 500);
        }
    }

    /**
     * Get profile details for a specific family member
     * 
     * @param Request $request
     * @param int $profileId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getProfile(Request $request, $profileId)
    {
        try {
            $currentUser = $request->user();

            // Check if user can access this profile
            if (!$currentUser->canManageProfile($profileId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to view this profile'
                ], 403);
            }

            $profile = Patient::findOrFail($profileId);

            // Get reports count for this profile
            $reportsCount = \App\Models\PatientReport::where('patient_id', $profileId)->count();

            return response()->json([
                'success' => true,
                'profile' => [
                    'id' => $profile->id,
                    'name' => $profile->name,
                    'email' => $profile->email,
                    'phone' => $profile->phone,
                    'gender' => $profile->gender,
                    'dob' => $profile->dob,
                    'height' => $profile->height,
                    'weight' => $profile->weight,
                    'blood_group' => $profile->blood_group,
                    'relationship' => $profile->relationship,
                    'is_primary' => $profile->is_primary_account,
                    'managed_by_primary' => $profile->managed_by_primary,
                    'profile_photo' => $profile->profile_photo,
                    'lock_status' => $profile->getLockStatusInfo(),
                    'display_name' => $profile->display_name,
                    'reports_count' => $reportsCount,
                    'created_at' => $profile->created_at,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get profile details', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id,
                'profile_id' => $profileId
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load profile details'
            ], 500);
        }
    }

    /**
     * Check if user can upload for a specific profile
     * (Used by upload screen for validation)
     * 
     * @param Request $request
     * @param int $profileId
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkUploadPermission(Request $request, $profileId)
    {
        try {
            $currentUser = $request->user();
            $canUpload = $currentUser->canManageProfile($profileId);

            if (!$canUpload) {
                return response()->json([
                    'success' => false,
                    'can_upload' => false,
                    'message' => 'You do not have permission to upload for this profile'
                ], 403);
            }

            $profile = Patient::findOrFail($profileId);

            return response()->json([
                'success' => true,
                'can_upload' => true,
                'profile' => [
                    'id' => $profile->id,
                    'name' => $profile->name,
                    'display_name' => $profile->display_name,
                    'is_locked' => $profile->profile_lock_status,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'can_upload' => false,
                'message' => 'Invalid profile'
            ], 404);
        }
    }
}