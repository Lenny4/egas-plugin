<?php

declare(strict_types=1);

namespace Egas\resources;

use WP_Error;

final class ImportResourceResult
{
    private function __construct(
        private readonly bool      $success,
        private readonly string    $message,
        private readonly int|string|null $id,
        private readonly ?int      $status,
        private readonly ?WP_Error $error,
    )
    {
    }

    public static function success(int|string|null $id, string $message = '', ?int $status = null): self
    {
        return new self(true, $message, $id, $status, null);
    }

    public static function failure(string $message, ?WP_Error $error = null, ?int $status = null): self
    {
        return new self(false, $message, null, $status, $error);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getId(): int|string|null
    {
        return $this->id;
    }

    public function getStatus(): ?int
    {
        return $this->status;
    }

    public function getError(): ?WP_Error
    {
        return $this->error;
    }
}
