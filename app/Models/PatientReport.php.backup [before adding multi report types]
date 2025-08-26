<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory; 
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class PatientReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'patient_id', 
        'doctor_id', 
        'file_path', 
        'type', 
        'notes',
        'report_date',
        'report_title',
        'uploaded_by',
        'ocr_status',
        'ocr_confidence',
        'processing_attempts',
        'original_file_path',
        'compressed_file_path'
    ];

    protected $casts = [
        'report_date' => 'date',
        'ocr_confidence' => 'integer',
        'processing_attempts' => 'integer'
    ];

    // OCR Status constants
    const OCR_STATUS_PENDING = 'pending';
    const OCR_STATUS_PROCESSING = 'processing';
    const OCR_STATUS_COMPLETED = 'completed';
    const OCR_STATUS_FAILED = 'failed';
    const OCR_STATUS_NOT_REQUIRED = 'not_required';

    public function aiSummary()
    {
        return $this->hasOne(AISummary::class, 'report_id');
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor()
    {
        return $this->belongsTo(Doctor::class);
    }

    /**
     * Check if this report requires OCR processing
     *
     * @return bool
     */
    public function requiresOCR()
    {
        return $this->type === 'image' && 
               in_array($this->ocr_status, [self::OCR_STATUS_PENDING, self::OCR_STATUS_FAILED]);
    }

    /**
     * Check if OCR processing is complete
     *
     * @return bool
     */
    public function isOCRComplete()
    {
        return $this->ocr_status === self::OCR_STATUS_COMPLETED;
    }

    /**
     * Check if OCR has failed and can be retried
     *
     * @return bool
     */
    public function canRetryOCR()
    {
        return $this->ocr_status === self::OCR_STATUS_FAILED && 
               $this->processing_attempts < 3;
    }

    /**
     * Mark OCR as starting
     */
    public function markOCRAsProcessing()
    {
        $this->update([
            'ocr_status' => self::OCR_STATUS_PROCESSING,
            'processing_attempts' => $this->processing_attempts + 1
        ]);
    }

    /**
     * Mark OCR as completed
     *
     * @param int $confidence
     */
    public function markOCRAsCompleted($confidence = null)
    {
        $this->update([
            'ocr_status' => self::OCR_STATUS_COMPLETED,
            'ocr_confidence' => $confidence
        ]);
    }

    /**
     * Mark OCR as failed
     */
    public function markOCRAsFailed()
    {
        $this->update([
            'ocr_status' => self::OCR_STATUS_FAILED
        ]);
    }

    /**
     * Get the primary file URL for display
     * Returns compressed version if available, otherwise original
     *
     * @return string|null
     */
    public function getFileUrl()
    {
        if ($this->type === 'image') {
            // For images, prefer compressed version
            if ($this->compressed_file_path && Storage::disk('public')->exists($this->compressed_file_path)) {
                return Storage::disk('public')->url($this->compressed_file_path);
            }
            // Fallback to original if compressed doesn't exist
            if ($this->original_file_path && Storage::disk('public')->exists($this->original_file_path)) {
                return Storage::disk('public')->url($this->original_file_path);
            }
        }
        
        // For PDFs or fallback, use the main file_path
        if ($this->file_path && Storage::disk('public')->exists($this->file_path)) {
            return Storage::disk('public')->url($this->file_path);
        }
        
        return null;
    }

    /**
     * Get the high-quality file path for OCR processing
     *
     * @return string|null
     */
    public function getOCRFilePath()
    {
        if ($this->type === 'image' && $this->original_file_path) {
            return Storage::disk('public')->path($this->original_file_path);
        }
        
        // For PDFs, use the main file path
        if ($this->type === 'pdf' && $this->file_path) {
            return Storage::disk('public')->path($this->file_path);
        }
        
        return null;
    }

    /**
     * Clean up temporary files after successful processing
     */
    public function cleanupTemporaryFiles()
    {
        // Remove original high-quality image after successful OCR
        if ($this->type === 'image' && 
            $this->ocr_status === self::OCR_STATUS_COMPLETED && 
            $this->original_file_path &&
            $this->compressed_file_path) {
            
            if (Storage::disk('public')->exists($this->original_file_path)) {
                Storage::disk('public')->delete($this->original_file_path);
                
                // Update the record to reflect cleanup
                $this->update(['original_file_path' => null]);
            }
        }
    }

    /**
     * Get OCR status with human-readable description
     *
     * @return array
     */
    public function getOCRStatusInfo()
    {
        $statusMap = [
            self::OCR_STATUS_PENDING => [
                'status' => 'pending',
                'description' => 'Waiting for OCR processing',
                'can_retry' => false,
                'color' => 'orange'
            ],
            self::OCR_STATUS_PROCESSING => [
                'status' => 'processing',
                'description' => 'OCR processing in progress',
                'can_retry' => false,
                'color' => 'blue'
            ],
            self::OCR_STATUS_COMPLETED => [
                'status' => 'completed',
                'description' => 'OCR processing completed successfully',
                'can_retry' => false,
                'color' => 'green'
            ],
            self::OCR_STATUS_FAILED => [
                'status' => 'failed',
                'description' => 'OCR processing failed',
                'can_retry' => $this->canRetryOCR(),
                'color' => 'red'
            ],
            self::OCR_STATUS_NOT_REQUIRED => [
                'status' => 'not_required',
                'description' => 'OCR not required for this file type',
                'can_retry' => false,
                'color' => 'gray'
            ]
        ];

        $info = $statusMap[$this->ocr_status] ?? $statusMap[self::OCR_STATUS_NOT_REQUIRED];
        
        // Add additional information
        $info['attempts'] = $this->processing_attempts;
        $info['confidence'] = $this->ocr_confidence;
        
        return $info;
    }

    /**
     * Scope for reports that need OCR processing
     */
    public function scopeNeedsOCR($query)
    {
        return $query->where('type', 'image')
                    ->whereIn('ocr_status', [self::OCR_STATUS_PENDING, self::OCR_STATUS_FAILED])
                    ->where('processing_attempts', '<', 3);
    }

    /**
     * Scope for failed OCR reports that can be retried
     */
    public function scopeCanRetryOCR($query)
    {
        return $query->where('type', 'image')
                    ->where('ocr_status', self::OCR_STATUS_FAILED)
                    ->where('processing_attempts', '<', 3);
    }

    /**
     * Get file size information
     */
    public function getFileSizeInfo()
    {
        $info = [];
        
        // Main file size
        if ($this->file_path && Storage::disk('public')->exists($this->file_path)) {
            $info['main'] = Storage::disk('public')->size($this->file_path);
        }
        
        // Original file size (for images)
        if ($this->original_file_path && Storage::disk('public')->exists($this->original_file_path)) {
            $info['original'] = Storage::disk('public')->size($this->original_file_path);
        }
        
        // Compressed file size (for images)
        if ($this->compressed_file_path && Storage::disk('public')->exists($this->compressed_file_path)) {
            $info['compressed'] = Storage::disk('public')->size($this->compressed_file_path);
        }
        
        return $info;
    }
}