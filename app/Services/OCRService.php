<?php

namespace App\Services;

use thiagoalessio\TesseractOCR\TesseractOCR;
use Intervention\Image\Facades\Image;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class OCRService
{
    private $tesseract;
    
    public function __construct()
    {
        $this->tesseract = new TesseractOCR();
        
        // Configure for medical document processing
        $this->tesseract
            ->lang('eng')  // English language
            ->psm(6)       // Uniform block of text
            ->oem(3)       // Default OCR Engine Mode
            ->dpi(300);    // High DPI for better accuracy
    }
    
    /**
     * Extract text from image with preprocessing for medical documents
     *
     * @param string $imagePath Path to the image file
     * @return array ['text' => string, 'confidence' => int, 'success' => bool]
     */
    public function extractTextFromImage($imagePath)
    {
        try {
            Log::info('Starting OCR processing', ['image_path' => $imagePath]);
            
            // Step 1: Preprocess image for better OCR accuracy
            $preprocessedPath = $this->preprocessImage($imagePath);
            
            // Step 2: Perform OCR
            $this->tesseract->image($preprocessedPath);
            
            // Extract text
            $extractedText = $this->tesseract->run();
            
            // Calculate confidence (Tesseract doesn't provide confidence directly)
            $confidence = $this->calculateConfidence($extractedText);
            
            // Step 3: Post-process text
            $cleanedText = $this->cleanExtractedText($extractedText);
            
            // Step 4: Cleanup preprocessed file
            if ($preprocessedPath !== $imagePath && file_exists($preprocessedPath)) {
                unlink($preprocessedPath);
            }
            
            Log::info('OCR processing completed', [
                'text_length' => strlen($cleanedText),
                'confidence' => $confidence,
                'preview' => substr($cleanedText, 0, 200)
            ]);
            
            return [
                'text' => $cleanedText,
                'confidence' => $confidence,
                'success' => true,
                'error' => null
            ];
            
        } catch (\Exception $e) {
            Log::error('OCR processing failed', [
                'error' => $e->getMessage(),
                'image_path' => $imagePath,
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'text' => '',
                'confidence' => 0,
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Preprocess image for better OCR accuracy
     * Enhances contrast, brightness, and sharpness for medical documents
     *
     * @param string $imagePath
     * @return string Path to preprocessed image
     */
    private function preprocessImage($imagePath)
    {
        try {
            // Load image using Intervention Image
            $image = Image::make($imagePath);
            
            // Get original dimensions
            $originalWidth = $image->width();
            $originalHeight = $image->height();
            
            // Resize if image is too large (maintain aspect ratio)
            $maxDimension = 2000;
            if ($originalWidth > $maxDimension || $originalHeight > $maxDimension) {
                $image->resize($maxDimension, $maxDimension, function ($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                });
                Log::info('Image resized for OCR', [
                    'original' => "{$originalWidth}x{$originalHeight}",
                    'new' => $image->width() . 'x' . $image->height()
                ]);
            }
            
            // Enhance image for better OCR
            $image->greyscale()           // Convert to grayscale
                  ->contrast(15)          // Increase contrast
                  ->brightness(5)         // Slight brightness increase
                  ->sharpen(10);          // Sharpen text
            
            // Save preprocessed image
            $preprocessedPath = sys_get_temp_dir() . '/ocr_preprocessed_' . uniqid() . '.jpg';
            $image->save($preprocessedPath, 95); // High quality for OCR
            
            Log::info('Image preprocessing completed', [
                'preprocessed_path' => $preprocessedPath,
                'file_size' => filesize($preprocessedPath)
            ]);
            
            return $preprocessedPath;
            
        } catch (\Exception $e) {
            Log::warning('Image preprocessing failed, using original', ['error' => $e->getMessage()]);
            return $imagePath; // Return original if preprocessing fails
        }
    }
    
    /**
     * Clean and normalize extracted text
     *
     * @param string $rawText
     * @return string
     */
    private function cleanExtractedText($rawText)
    {
        if (empty($rawText)) {
            return '';
        }
        
        // Remove excessive whitespace and normalize line breaks
        $text = preg_replace('/\s+/', ' ', $rawText);
        $text = preg_replace('/\n\s*\n/', "\n", $text);
        
        // Fix common OCR errors in medical documents
        $text = $this->fixCommonOCRErrors($text);
        
        // Remove non-printable characters but keep medical symbols
        $text = preg_replace('/[^\x20-\x7E\n\r\t°±≤≥]/', '', $text);
        
        return trim($text);
    }
    
    /**
     * Fix common OCR errors in medical documents
     *
     * @param string $text
     * @return string
     */
    private function fixCommonOCRErrors($text)
    {
        // Common character substitutions in medical texts
        $replacements = [
            // Numbers often misread
            'O' => '0',        // O mistaken for 0 in numeric contexts
            'l' => '1',        // l mistaken for 1 in numeric contexts
            'S' => '5',        // S mistaken for 5 in numeric contexts
            
            // Common medical units
            'mg/dL' => 'mg/dL',
            'mmHg' => 'mmHg',
            'mIU/L' => 'mIU/L',
            'ng/mL' => 'ng/mL',
            'μg/dL' => 'μg/dL',
            
            // Fix spacing around colons and values
            ' : ' => ': ',
            ':  ' => ': ',
        ];
        
        // Apply context-aware replacements
        foreach ($replacements as $search => $replace) {
            // Only replace in appropriate contexts
            if (strpos($search, '/') !== false || strpos($search, ':') !== false) {
                $text = str_replace($search, $replace, $text);
            }
        }
        
        // Fix numeric values that might have been corrupted
        $text = preg_replace('/(\d+)\s*[Oo]\s*(\d+)/', '$1.0$2', $text); // Fix decimals
        
        return $text;
    }
    
    /**
     * Calculate confidence score based on text quality
     * Since Tesseract v4+ doesn't provide direct confidence, we estimate it
     *
     * @param string $text
     * @return int Confidence score 0-100
     */
    private function calculateConfidence($text)
    {
        if (empty($text)) {
            return 0;
        }
        
        $confidence = 50; // Base confidence
        
        // Increase confidence based on text characteristics
        
        // Length bonus (longer text usually means better OCR)
        if (strlen($text) > 100) $confidence += 10;
        if (strlen($text) > 500) $confidence += 10;
        
        // Medical keywords bonus
        $medicalKeywords = [
            'report', 'test', 'result', 'normal', 'abnormal', 'level', 'count',
            'blood', 'urine', 'serum', 'plasma', 'mg/dl', 'mmhg', 'laboratory'
        ];
        
        $keywordCount = 0;
        foreach ($medicalKeywords as $keyword) {
            if (stripos($text, $keyword) !== false) {
                $keywordCount++;
            }
        }
        
        $confidence += min($keywordCount * 3, 20); // Max 20 points for keywords
        
        // Numeric values bonus (medical reports have lots of numbers)
        $numericMatches = preg_match_all('/\d+\.?\d*/', $text);
        if ($numericMatches > 5) $confidence += 10;
        
        // Penalty for too many special characters (OCR errors)
        $specialCharCount = preg_match_all('/[^\w\s\.\,\:\;\-\(\)\/]/', $text);
        if ($specialCharCount > strlen($text) * 0.1) {
            $confidence -= 15; // Penalize if >10% special chars
        }
        
        // Ensure confidence is within bounds
        return max(0, min(100, $confidence));
    }
    
    /**
     * Check if OCR is available on the system
     *
     * @return bool
     */
    public static function isAvailable()
    {
        try {
            $tesseract = new TesseractOCR();
            $tesseract->image(storage_path('app/temp_test.jpg')); // This will fail but check if tesseract exists
            return true;
        } catch (\Exception $e) {
            // Check if error is about missing file (tesseract exists) or missing tesseract
            return strpos($e->getMessage(), 'No such file') !== false;
        }
    }
    
    /**
     * Get Tesseract version information
     *
     * @return string
     */
    public function getVersion()
    {
        try {
            return $this->tesseract->version();
        } catch (\Exception $e) {
            return 'Unknown';
        }
    }
}