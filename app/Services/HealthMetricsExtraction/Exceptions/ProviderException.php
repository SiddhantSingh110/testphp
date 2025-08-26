<?php

namespace App\Services\HealthMetricsExtraction\Exceptions;

use Exception;

class ProviderException extends Exception
{
    protected bool $retryable;
    protected string $providerName;
    protected ?array $context;

    public function __construct(
        string $message = "",
        int $code = 0,
        ?Exception $previous = null,
        bool $retryable = true,
        string $providerName = '',
        ?array $context = null
    ) {
        parent::__construct($message, $code, $previous);
        
        $this->retryable = $retryable;
        $this->providerName = $providerName;
        $this->context = $context;
    }

    /**
     * Check if this exception represents a retryable error
     */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    /**
     * Get the provider name that threw this exception
     */
    public function getProviderName(): string
    {
        return $this->providerName;
    }

    /**
     * Get additional context about the error
     */
    public function getContext(): ?array
    {
        return $this->context;
    }

    /**
     * Create a non-retryable provider exception
     */
    public static function nonRetryable(
        string $message,
        string $providerName = '',
        ?array $context = null
    ): self {
        return new self($message, 0, null, false, $providerName, $context);
    }

    /**
     * Create a retryable provider exception
     */
    public static function retryable(
        string $message,
        string $providerName = '',
        ?array $context = null
    ): self {
        return new self($message, 0, null, true, $providerName, $context);
    }

    /**
     * Create provider exception from another exception
     */
    public static function fromException(
        Exception $exception,
        string $providerName = '',
        bool $retryable = true,
        ?array $context = null
    ): self {
        return new self(
            $exception->getMessage(),
            $exception->getCode(),
            $exception,
            $retryable,
            $providerName,
            $context
        );
    }
}