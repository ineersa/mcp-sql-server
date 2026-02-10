<?php

declare(strict_types=1);

namespace App\Exception;

class ToolUsageError extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?string $hint = null,
        private readonly bool $retryable = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getHint(): ?string
    {
        return $this->hint;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
