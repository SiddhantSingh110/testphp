<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Patient extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'gender',
        'dob',
        'height',
        'weight',
        'health_flags',
        'profile_photo',
        'blood_group',
        'phone_verified_at',
        'email_verified_at',
        'whatsapp_permission',
        'auth_method',
        // ✨ NEW: Family management fields
        'primary_account_id',
        'relationship',
        'is_primary_account',
        'profile_lock_status',
        'managed_by_primary',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'phone_verified_at' => 'datetime',
        'email_verified_at' => 'datetime',
        'whatsapp_permission' => 'boolean',
        'dob' => 'date',
        'health_flags' => 'array',
        // ✨ NEW: Family casts
        'is_primary_account' => 'boolean',
        'profile_lock_status' => 'boolean',
        'managed_by_primary' => 'boolean',
    ];

    // ============= EXISTING METHODS =============

    /**
     * Check if phone is verified
     */
    public function isPhoneVerified(): bool
    {
        return !is_null($this->phone_verified_at);
    }

    /**
     * Check if email is verified
     */
    public function isEmailVerified(): bool
    {
        return !is_null($this->email_verified_at);
    }

    /**
     * Mark phone as verified
     */
    public function markPhoneAsVerified(): void
    {
        $this->phone_verified_at = now();
        $this->save();
    }

    /**
     * Mark email as verified
     */
    public function markEmailAsVerified(): void
    {
        $this->email_verified_at = now();
        $this->save();
    }

    /**
     * Check if user uses OTP-only authentication
     */
    public function isOtpOnlyUser(): bool
    {
        return $this->auth_method === 'otp_only';
    }

    /**
     * Check if user has complete profile for OTP authentication
     */
    public function hasCompleteOtpProfile(): bool
    {
        if (!$this->isOtpOnlyUser()) {
            return true; // Password users don't need complete OTP profile
        }
        
        return !empty($this->name) && 
               !empty($this->email) && 
               !empty($this->phone) &&
               !str_starts_with($this->name, 'User '); // Not a temporary name
    }

    /**
     * Check if user has WhatsApp permission
     */
    public function hasWhatsAppPermission(): bool
    {
        return $this->whatsapp_permission === true;
    }

    // ============= NEW: FAMILY MANAGEMENT METHODS =============

    /**
     * Get the primary account (parent/owner of this profile)
     */
    public function primaryAccount()
    {
        return $this->belongsTo(Patient::class, 'primary_account_id');
    }

    /**
     * Get all family members managed by this primary account
     */
    public function familyMembers()
    {
        return $this->hasMany(Patient::class, 'primary_account_id');
    }

    /**
     * Get all profiles accessible by this user (self + family members)
     */
    public function accessibleProfiles()
    {
        if ($this->is_primary_account) {
            // Primary account can access self + all family members
            return Patient::where('id', $this->id)
                ->orWhere('primary_account_id', $this->id)
                ->get();
        } else {
            // Sub-account can only access self
            return collect([$this]);
        }
    }

    /**
     * Check if this user can manage the given profile
     */
    public function canManageProfile($profileId): bool
    {
        // Can always manage own profile
        if ($this->id == $profileId) {
            return true;
        }

        // Primary accounts can manage their family members
        if ($this->is_primary_account) {
            return $this->familyMembers()->where('id', $profileId)->exists();
        }

        return false;
    }

    /**
     * Check if profile is unlocked (has uploaded at least one report)
     */
    public function isProfileUnlocked(): bool
    {
        if (!$this->profile_lock_status) {
            return true; // Already unlocked
        }

        // Check if has at least one report
        $hasReports = \App\Models\PatientReport::where('patient_id', $this->id)->exists();

        if ($hasReports) {
            // Auto-unlock the profile
            $this->unlockProfile();
            return true;
        }

        return false;
    }

    /**
     * Unlock the profile (called when first report is uploaded)
     */
    public function unlockProfile(): void
    {
        $this->profile_lock_status = false;
        $this->save();
    }

    /**
     * Create a family member sub-account
     */
    public function createFamilyMember(array $data): Patient
    {
        if (!$this->is_primary_account) {
            throw new \Exception('Only primary accounts can create family members');
        }

        return Patient::create([
            'name' => $data['name'],
            'relationship' => $data['relationship'],
            'gender' => $data['gender'] ?? null,
            'dob' => $data['dob'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            
            // Family relationship fields
            'primary_account_id' => $this->id,
            'is_primary_account' => false,
            'managed_by_primary' => true,
            'profile_lock_status' => true,
            
            // Auth fields
            'auth_method' => 'managed', // New auth method for family members
            'password' => null, // No password for family members
        ]);
    }

    /**
     * Get display name with relationship context
     */
    public function getDisplayNameAttribute(): string
    {
        if ($this->is_primary_account) {
            return $this->name . ' (You)';
        }

        $relationship = $this->relationship ? ucfirst($this->relationship) : '';
        return $this->name . ($relationship ? " ($relationship)" : '');
    }

    /**
     * Get lock status with context
     */
    public function getLockStatusInfo(): array
    {
        $reportsCount = \App\Models\PatientReport::where('patient_id', $this->id)->count();
        
        return [
            'is_locked' => $this->profile_lock_status,
            'reports_count' => $reportsCount,
            'unlock_message' => $reportsCount === 0 
                ? 'Upload your first report to unlock the profile' 
                : 'Profile unlocked with ' . $reportsCount . ' report(s)',
        ];
    }

    /**
     * Scope: Get only primary accounts
     */
    public function scopePrimaryAccounts($query)
    {
        return $query->where('is_primary_account', true);
    }

    /**
     * Scope: Get only family members
     */
    public function scopeFamilyMembers($query)
    {
        return $query->where('is_primary_account', false);
    }

    /**
     * Boot method to set defaults
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($patient) {
            // Set defaults for new patients
            if (is_null($patient->is_primary_account)) {
                $patient->is_primary_account = is_null($patient->primary_account_id);
            }
            
            if (is_null($patient->managed_by_primary)) {
                $patient->managed_by_primary = !is_null($patient->primary_account_id);
            }
        });
    }
}