<?php

namespace App\Services\HealthMetricsExtraction\Exceptions;

use Exception;

/**
 * Base exception for health metrics extraction failures
 */
class ExtractionException extends Exception
{
    protected array $context = [];

    public function __construct(string $message = "", int $code = 0, ?\Throwable $previous = null, array $context = [])
    {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function setContext(array $context): void
    {
        $this->context = $context;
    }
}

/**
 * Provider-specific exception for AI API failures
 */
class ProviderException extends ExtractionException
{
    protected bool $retryable = true;
    protected string $providerName = '';

    public function __construct(
        string $message = "",
        string $providerName = '',
        bool $retryable = true,
        int $code = 0,
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous, $context);
        $this->providerName = $providerName;
        $this->retryable = $retryable;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function setRetryable(bool $retryable): void
    {
        $this->retryable = $retryable;
    }

    public function getProviderName(): string
    {
        return $this->providerName;
    }

    /**
     * Create a non-retryable provider exception (e.g., authentication errors)
     */
    public static function nonRetryable(string $message, string $providerName, array $context = []): self
    {
        return new self($message, $providerName, false, 0, null, $context);
    }

    /**
     * Create a retryable provider exception (e.g., rate limits, temporary failures)
     */
    public static function retryable(string $message, string $providerName, array $context = []): self
    {
        return new self($message, $providerName, true, 0, null, $context);
    }
}

/**
 * Configuration-related exception
 */
class ConfigurationException extends ExtractionException
{
    //
}

/**
 * Validation-related exception
 */
class ValidationException extends ExtractionException
{
    protected array $validationErrors = [];

    public function __construct(string $message = "", array $validationErrors = [], int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->validationErrors = $validationErrors;
    }

    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }
}