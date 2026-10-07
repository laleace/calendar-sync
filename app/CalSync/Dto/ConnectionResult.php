<?php

namespace App\CalSync\Dto;

use App\Enums\AccountStatus;

final readonly class ConnectionResult
{
    public function __construct(
        public AccountStatus $status,
        public string $message,
    ) {}

    public static function ok(string $message): self
    {
        return new self(AccountStatus::Ok, $message);
    }

    public static function failed(AccountStatus $status, string $message): self
    {
        return new self($status, $message);
    }

    public function successful(): bool
    {
        return $this->status === AccountStatus::Ok;
    }
}
