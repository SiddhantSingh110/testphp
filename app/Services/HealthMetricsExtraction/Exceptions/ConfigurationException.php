<?php

namespace App\Services\HealthMetricsExtraction\Exceptions;

use Exception;

class ConfigurationException extends Exception
{
    protected string $configKey;
    protected ?array $context;

    public function __construct(
        string $message = "",
        int $code = 0,
        ?Exception $previous = null,
        string $configKey = '',
        ?array $context = null
    ) {
        parent::__construct($message, $code, $previous);
        
        $this->configKey = $configKey;
        $this->context = $context;
    }

    /**
     * Get the configuration key that caused the error
     */
    public function getConfigKey(): string
    {
        return $this->configKey;
    }

    /**
     * Get additional context about the configuration error
     */
    public function getContext(): ?array
    {
        return $this->context;
    }

    /**
     * Create a configuration exception for missing config
     */
    public static function missingConfig(
        string $configKey,
        ?array $context = null
    ): self {
        return new self(
            "Missing configuration for: {$configKey}",
            0,
            null,
            $configKey,
            $context
        );
    }

    /**
     * Create a configuration exception for invalid config
     */
    public static function invalidConfig(
        string $configKey,
        string $reason = '',
        ?array $context = null
    ): self {
        $message = "Invalid configuration for: {$configKey}";
        if ($reason) {
            $message .= " - {$reason}";
        }
        
        return new self($message, 0, null, $configKey, $context);
    }
}