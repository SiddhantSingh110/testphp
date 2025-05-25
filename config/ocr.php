<?php

// config/ocr.php
return [
    /*
    |--------------------------------------------------------------------------
    | OCR Service Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration options for the OCR (Optical Character Recognition) service
    | used for processing medical report images.
    |
    */

    'enabled' => env('OCR_SERVICE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Tesseract Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Tesseract OCR engine
    |
    */
    'tesseract' => [
        'path' => env('TESSERACT_PATH', '/opt/homebrew/bin/tesseract'), // Default Homebrew path for macOS
        'language' => env('TESSERACT_LANGUAGE', 'eng'),
        'dpi' => env('TESSERACT_DPI', 300),
        'psm' => env('TESSERACT_PSM', 6), // Page segmentation mode: 6 = uniform block of text
        'oem' => env('TESSERACT_OEM', 3), // OCR Engine mode: 3 = default
    ],

    /*
    |--------------------------------------------------------------------------
    | Image Processing Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for image preprocessing and compression
    |
    */
    'image_processing' => [
        'max_dimension_ocr' => env('OCR_MAX_DIMENSION', 2500), // Max dimension for OCR processing
        'max_dimension_storage' => env('STORAGE_MAX_DIMENSION', 1500), // Max dimension for storage
        'quality_ocr' => env('OCR_IMAGE_QUALITY', 95), // JPEG quality for OCR (0-100)
        'quality_storage' => env('STORAGE_IMAGE_QUALITY', 80), // JPEG quality for storage
        'max_file_size' => env('OCR_MAX_FILE_SIZE', 20 * 1024 * 1024), // 20MB max
        'allowed_mimes' => ['image/jpeg', 'image/jpg', 'image/png'],
        'min_dimensions' => [
            'width' => 200,
            'height' => 200
        ],
        'max_dimensions' => [
            'width' => 5000,
            'height' => 5000
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | Processing Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for OCR processing behavior
    |
    */
    'processing' => [
        'max_attempts' => env('OCR_MAX_PROCESSING_ATTEMPTS', 3),
        'timeout' => env('OCR_PROCESSING_TIMEOUT', 60), // seconds
        'cleanup_originals' => env('OCR_CLEANUP_ORIGINALS', true),
        'cleanup_delay' => env('OCR_CLEANUP_DELAY', 24), // hours to wait before cleanup
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Configuration
    |--------------------------------------------------------------------------
    |
    | File storage paths and settings
    |
    */
    'storage' => [
        'disk' => env('OCR_STORAGE_DISK', 'public'),
        'paths' => [
            'originals' => 'patient_reports/originals',
            'compressed' => 'patient_reports/compressed',
            'pdfs' => 'patient_reports/pdfs'
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | Confidence Thresholds
    |--------------------------------------------------------------------------
    |
    | Confidence score thresholds for OCR quality assessment
    |
    */
    'confidence' => [
        'minimum' => env('OCR_MIN_CONFIDENCE', 60), // Minimum acceptable confidence
        'good' => env('OCR_GOOD_CONFIDENCE', 80), // Good confidence threshold
        'excellent' => env('OCR_EXCELLENT_CONFIDENCE', 90), // Excellent confidence threshold
    ],

    /*
    |--------------------------------------------------------------------------
    | Enhancement Settings
    |--------------------------------------------------------------------------
    |
    | Image enhancement settings for better OCR accuracy
    |
    */
    'enhancement' => [
        'enable_preprocessing' => env('OCR_ENABLE_PREPROCESSING', true),
        'contrast_boost' => env('OCR_CONTRAST_BOOST', 15), // 0-100
        'brightness_boost' => env('OCR_BRIGHTNESS_BOOST', 5), // -100 to 100
        'sharpening' => env('OCR_SHARPENING', 10), // 0-100
        'grayscale_conversion' => env('OCR_GRAYSCALE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Error Handling
    |--------------------------------------------------------------------------
    |
    | Configuration for error handling and recovery
    |
    */
    'error_handling' => [
        'log_level' => env('OCR_LOG_LEVEL', 'info'), // debug, info, warning, error
        'retry_delay' => env('OCR_RETRY_DELAY', 300), // seconds between retries
        'fallback_enabled' => env('OCR_FALLBACK_ENABLED', true),
        'notification_on_failure' => env('OCR_NOTIFY_ON_FAILURE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Settings
    |--------------------------------------------------------------------------
    |
    | Performance optimization settings
    |
    */
    'performance' => [
        'memory_limit' => env('OCR_MEMORY_LIMIT', '512M'),
        'execution_time_limit' => env('OCR_EXECUTION_TIME_LIMIT', 120), // seconds
        'concurrent_processing' => env('OCR_CONCURRENT_PROCESSING', false),
        'queue_processing' => env('OCR_QUEUE_PROCESSING', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Medical Document Specific Settings
    |--------------------------------------------------------------------------
    |
    | Settings optimized for medical document processing
    |
    */
    'medical' => [
        'enable_medical_keywords' => env('OCR_MEDICAL_KEYWORDS', true),
        'medical_units_correction' => env('OCR_MEDICAL_UNITS', true),
        'numeric_value_correction' => env('OCR_NUMERIC_CORRECTION', true),
        'parameter_extraction' => env('OCR_PARAMETER_EXTRACTION', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Development & Testing
    |--------------------------------------------------------------------------
    |
    | Settings for development and testing
    |
    */
    'development' => [
        'save_preprocessed_images' => env('OCR_SAVE_PREPROCESSED', false),
        'debug_mode' => env('OCR_DEBUG_MODE', false),
        'test_mode' => env('OCR_TEST_MODE', false),
        'mock_responses' => env('OCR_MOCK_RESPONSES', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Future Upgrade Settings
    |--------------------------------------------------------------------------
    |
    | Settings for future Google Vision API integration
    |
    */
    'google_vision' => [
        'enabled' => env('GOOGLE_VISION_ENABLED', false),
        'api_key' => env('GOOGLE_VISION_API_KEY'),
        'fallback_to_tesseract' => env('GOOGLE_VISION_FALLBACK', true),
        'confidence_threshold' => env('GOOGLE_VISION_CONFIDENCE', 0.8),
    ],
];
