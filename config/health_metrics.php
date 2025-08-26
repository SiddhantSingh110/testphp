<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Primary AI Provider Configuration - ENV File Based Control
    |--------------------------------------------------------------------------
    |
    | Change PRIMARY_AI_PROVIDER and SECONDARY_AI_PROVIDER in .env file
    | to switch providers without touching admin panel or web UI.
    |
    | Supported: "deepseek", "claude", "openai"
    |
    */
    'primary_provider' => env('PRIMARY_AI_PROVIDER', 'deepseek'),
    'secondary_provider' => env('SECONDARY_AI_PROVIDER', 'claude'),

    /*
    |--------------------------------------------------------------------------
    | Fallback Configuration
    |--------------------------------------------------------------------------
    |
    | Enable fallback to secondary providers if primary fails.
    | Providers will be tried in priority order until one succeeds.
    |
    */
    'fallback_enabled' => env('HEALTH_METRICS_FALLBACK_ENABLED', true),
    'stop_on_first_success' => env('HEALTH_METRICS_STOP_ON_SUCCESS', true),

    /*
    |--------------------------------------------------------------------------
    | AI Provider Configurations
    |--------------------------------------------------------------------------
    |
    | Configure each AI provider with their specific settings.
    | Priority is now controlled by PRIMARY_AI_PROVIDER and SECONDARY_AI_PROVIDER
    |
    */
    'providers' => [
        'deepseek' => [
            'enabled' => env('DEEPSEEK_ENABLED', true),
            'api_key' => env('DEEPSEEK_API_KEY'),
            'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'),
            'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
            'priority' => 1, // Will be overridden by ENV-based logic
            'timeout' => env('DEEPSEEK_TIMEOUT', 30),
            'max_retries' => env('DEEPSEEK_MAX_RETRIES', 3),
            'features' => [
                'cost_effective' => true,
                'fast_response' => true,
                'medical_analysis' => true
            ]
        ],

        'claude' => [
            'enabled' => env('CLAUDE_ENABLED', true),
            'api_key' => env('CLAUDE_API_KEY'),
            'base_url' => env('CLAUDE_BASE_URL', 'https://api.anthropic.com/v1'),
            'model' => env('CLAUDE_MODEL', 'claude-3-5-sonnet-20241022'),
            'anthropic_version' => env('CLAUDE_ANTHROPIC_VERSION', '2023-06-01'),
            'priority' => 2, // Will be overridden by ENV-based logic
            'timeout' => env('CLAUDE_TIMEOUT', 30),
            'max_retries' => env('CLAUDE_MAX_RETRIES', 3),
            'features' => [
                'advanced_reasoning' => true,
                'high_accuracy' => true,
                'medical_knowledge' => true,
                'context_awareness' => true
            ]
        ],

        'openai' => [
            'enabled' => env('OPENAI_ENABLED', true),
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4'),
            'priority' => 3, // Will be overridden by ENV-based logic
            'timeout' => env('OPENAI_TIMEOUT', 30),
            'max_retries' => env('OPENAI_MAX_RETRIES', 3),
            'features' => [
                'established_platform' => true,
                'reliable_service' => true,
                'extensive_training' => true,
                'medical_knowledge' => true
            ]
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance & Monitoring
    |--------------------------------------------------------------------------
    |
    | Configuration for performance monitoring and optimization.
    |
    */
    'performance' => [
        'log_provider_performance' => env('HEALTH_METRICS_LOG_PERFORMANCE', true),
        'track_success_rates' => env('HEALTH_METRICS_TRACK_SUCCESS', true),
        'cache_provider_status' => env('HEALTH_METRICS_CACHE_STATUS', true),
        'performance_comparison' => env('HEALTH_METRICS_COMPARE_PROVIDERS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Runtime Configuration Storage
    |--------------------------------------------------------------------------
    |
    | Settings for storing runtime configuration changes in database.
    | NOTE: ENV file settings will override database settings
    |
    */
    'runtime_config' => [
        'enabled' => env('HEALTH_METRICS_RUNTIME_CONFIG', true),
        'table' => 'health_metrics_config',
        'cache_ttl' => 3600, // 1 hour
        'env_override' => true, // ENV variables take precedence
    ],

    /*
    |--------------------------------------------------------------------------
    | Error Handling & Logging
    |--------------------------------------------------------------------------
    |
    | Configure how extraction errors are handled and logged.
    |
    */
    'error_handling' => [
        'log_level' => env('HEALTH_METRICS_LOG_LEVEL', 'info'),
        'max_failures_before_disable' => env('HEALTH_METRICS_MAX_FAILURES', 5),
        'failure_window_minutes' => env('HEALTH_METRICS_FAILURE_WINDOW', 60),
        'notification_on_all_providers_fail' => env('HEALTH_METRICS_NOTIFY_ALL_FAIL', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Metrics Validation
    |--------------------------------------------------------------------------
    |
    | Configuration for validating extracted health metrics.
    |
    */
    'validation' => [
        'strict_mode' => env('HEALTH_METRICS_STRICT_VALIDATION', false),
        'required_fields' => [
            'diagnosis',
            'key_findings',
            'confidence_score'
        ],
        'confidence_threshold' => env('HEALTH_METRICS_MIN_CONFIDENCE', 60),
        'validate_medical_ranges' => env('HEALTH_METRICS_VALIDATE_RANGES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin & Management
    |--------------------------------------------------------------------------
    |
    | Settings for administrative functions and management APIs.
    |
    */
    'admin' => [
        'enable_admin_api' => env('HEALTH_METRICS_ENABLE_ADMIN_API', true),
        'admin_routes_middleware' => ['auth:sanctum', 'admin'], // Customize as needed
        'allow_runtime_provider_switch' => env('HEALTH_METRICS_ALLOW_RUNTIME_SWITCH', false), // Disabled since using ENV
        'log_admin_actions' => env('HEALTH_METRICS_LOG_ADMIN_ACTIONS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Enable or disable specific features for testing and gradual rollout.
    |
    */
    'features' => [
        'ensemble_mode' => env('HEALTH_METRICS_ENSEMBLE_MODE', false), // Future feature
        'provider_health_monitoring' => env('HEALTH_METRICS_HEALTH_MONITORING', true),
        'automatic_provider_switching' => env('HEALTH_METRICS_AUTO_SWITCH', false), // Future feature
        'metrics_caching' => env('HEALTH_METRICS_CACHING', true),
        'performance_analytics' => env('HEALTH_METRICS_ANALYTICS', true),
        'env_based_switching' => true, // New feature - ENV file based switching
    ],
];