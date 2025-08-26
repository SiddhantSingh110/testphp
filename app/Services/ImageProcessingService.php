<?php

namespace App\Services;

use Intervention\Image\Facades\Image;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ImageProcessingService
{
    /**
     * Process uploaded image for medical report storage
     * Creates both high-quality version (for OCR) and compressed version (for storage)
     *
     * @param \Illuminate\Http\UploadedFile $uploadedFile
     * @param int $patientId
     * @return array ['original_path' => string, 'compressed_path' => string, 'success' => bool]
     */
    public function processUploadedImage($uploadedFile, $patientId)
    {
        try {
            Log::info('Starting image processing', [
                'original_name' => $uploadedFile->getClientOriginalName(),
                'size' => $uploadedFile->getSize(),
                'patient_id' => $patientId
            ]);
            
            // Generate unique filename
            $timestamp = now()->format('Y-m-d_H-i-s');
            $uniqueId = Str::random(8);
            $baseFilename = "patient_{$patientId}_{$timestamp}_{$uniqueId}";
            
            // Step 1: Save high-quality version for OCR processing
            $originalPath = $this->saveHighQualityVersion($uploadedFile, $baseFilename);
            
            // Step 2: Create compressed version for long-term storage
            $compressedPath = $this->createCompressedVersion($originalPath, $baseFilename);
            
            Log::info('Image processing completed', [
                'original_path' => $originalPath,
                'compressed_path' => $compressedPath,
                'original_size' => Storage::disk('public')->size($originalPath),
                'compressed_size' => Storage::disk('public')->size($compressedPath)
            ]);
            
            return [
                'original_path' => $originalPath,
                'compressed_path' => $compressedPath,
                'success' => true,
                'error' => null
            ];
            
        } catch (\Exception $e) {
            Log::error('Image processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'original_path' => null,
                'compressed_path' => null,
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Save high-quality version for OCR processing
     * Maintains maximum quality and resolution for accurate text extraction
     *
     * @param \Illuminate\Http\UploadedFile $uploadedFile
     * @param string $baseFilename
     * @return string Storage path
     */
    private function saveHighQualityVersion($uploadedFile, $baseFilename)
    {
        // Load the image
        $image = Image::make($uploadedFile->getPathname());
        
        // Get original dimensions
        $originalWidth = $image->width();
        $originalHeight = $image->height();
        
        // For OCR, we want high resolution but not excessively large
        $maxDimension = 2500; // Higher than compressed version
        
        if ($originalWidth > $maxDimension || $originalHeight > $maxDimension) {
            $image->resize($maxDimension, $maxDimension, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
            
            Log::info('High-quality image resized', [
                'original' => "{$originalWidth}x{$originalHeight}",
                'resized' => $image->width() . 'x' . $image->height()
            ]);
        }
        
        // Apply minimal processing to maintain quality
        $image->sharpen(5); // Light sharpening for text clarity
        
        // Save with high quality
        $originalPath = "patient_reports/originals/{$baseFilename}_original.jpg";
        Storage::disk('public')->put(
            $originalPath, 
            (string) $image->encode('jpg', 95) // 95% quality for OCR
        );
        
        return $originalPath;
    }
    
    /**
     * Create compressed version for long-term storage
     * Optimized for file size while maintaining readability
     *
     * @param string $originalPath
     * @param string $baseFilename
     * @return string Storage path
     */
    private function createCompressedVersion($originalPath, $baseFilename)
    {
        // Load the original high-quality image
        $originalFullPath = Storage::disk('public')->path($originalPath);
        $image = Image::make($originalFullPath);
        
        // Resize for storage (similar to frontend compression)
        $maxDimension = 1500; // Match frontend compression
        
        if ($image->width() > $maxDimension || $image->height() > $maxDimension) {
            $image->resize($maxDimension, $maxDimension, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
        }
        
        // Apply compression optimizations
        $image->sharpen(3)      // Light sharpening
              ->contrast(5);    // Slight contrast enhancement
        
        // Save compressed version
        $compressedPath = "patient_reports/compressed/{$baseFilename}_compressed.jpg";
        Storage::disk('public')->put(
            $compressedPath, 
            (string) $image->encode('jpg', 80) // 80% quality for storage
        );
        
        Log::info('Compressed version created', [
            'dimensions' => $image->width() . 'x' . $image->height(),
            'quality' => '80%'
        ]);
        
        return $compressedPath;
    }
    
    /**
     * Clean up original high-quality file after successful OCR
     *
     * @param string $originalPath
     * @return bool
     */
    public function cleanupOriginalFile($originalPath)
    {
        try {
            if (Storage::disk('public')->exists($originalPath)) {
                Storage::disk('public')->delete($originalPath);
                Log::info('Original file cleaned up', ['path' => $originalPath]);
                return true;
            }
            return false;
        } catch (\Exception $e) {
            Log::warning('Failed to cleanup original file', [
                'path' => $originalPath,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Validate image file for medical report processing
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @return array ['valid' => bool, 'error' => string|null]
     */
    public function validateImageFile($file)
    {
        // Check file size (max 20MB for high-quality medical images)
        $maxSize = 20 * 1024 * 1024; // 20MB
        if ($file->getSize() > $maxSize) {
            return [
                'valid' => false,
                'error' => 'Image file is too large. Maximum size is 20MB.'
            ];
        }
        
        // Check file type
        $allowedMimes = ['image/jpeg', 'image/jpg', 'image/png'];
        if (!in_array($file->getMimeType(), $allowedMimes)) {
            return [
                'valid' => false,
                'error' => 'Invalid file type. Only JPEG and PNG images are allowed.'
            ];
        }
        
        // Check if it's actually an image
        try {
            $image = Image::make($file->getPathname());
            
            // Check minimum dimensions (too small images won't OCR well)
            if ($image->width() < 200 || $image->height() < 200) {
                return [
                    'valid' => false,
                    'error' => 'Image is too small. Minimum dimensions are 200x200 pixels.'
                ];
            }
            
            // Check maximum dimensions (prevent memory issues)
            if ($image->width() > 5000 || $image->height() > 5000) {
                return [
                    'valid' => false,
                    'error' => 'Image is too large. Maximum dimensions are 5000x5000 pixels.'
                ];
            }
            
        } catch (\Exception $e) {
            return [
                'valid' => false,
                'error' => 'Invalid or corrupted image file.'
            ];
        }
        
        return ['valid' => true, 'error' => null];
    }
    
    /**
     * Get image information for logging/debugging
     *
     * @param string $imagePath
     * @return array
     */
    public function getImageInfo($imagePath)
    {
        try {
            $fullPath = Storage::disk('public')->path($imagePath);
            $image = Image::make($fullPath);
            
            return [
                'width' => $image->width(),
                'height' => $image->height(),
                'size' => Storage::disk('public')->size($imagePath),
                'mime' => $image->mime(),
                'exists' => true
            ];
        } catch (\Exception $e) {
            return [
                'exists' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Create storage directories if they don't exist
     */
    public static function ensureDirectoriesExist()
    {
        $directories = [
            'patient_reports/originals',
            'patient_reports/compressed',
            'patient_reports/pdfs'
        ];
        
        foreach ($directories as $directory) {
            if (!Storage::disk('public')->exists($directory)) {
                Storage::disk('public')->makeDirectory($directory);
                Log::info('Created storage directory', ['directory' => $directory]);
            }
        }
    }
}