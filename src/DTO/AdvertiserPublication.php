<?php

declare(strict_types=1);

namespace ComCompany\PhpUbiflowApiClient\DTO;

final readonly class AdvertiserPublication
{
    public function __construct(
        public int $id,
        public Portal $portal,
    ) {
    }
}
