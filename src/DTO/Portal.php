<?php

declare(strict_types=1);

namespace ComCompany\PhpUbiflowApiClient\DTO;

final readonly class Portal
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
    ) {
    }
}
