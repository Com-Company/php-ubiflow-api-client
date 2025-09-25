<?php

declare(strict_types=1);

namespace ComCompany\PhpUbiflowApiClient\DTO;

final class AdPublication
{
    /** @param array<int, string> $publicationIncompatibilities */
    public function __construct(
        public int $id,
        public AdvertiserPublication $advertiserPublication,
        public bool $selected,
        public bool $publishable,
        public array $publicationIncompatibilities,
        public ?\DateTimeInterface $lastPublishedAt = null,
        public ?\DateTimeInterface $unPublishedAt = null,
        public ?string $urlOnPortal = null,
    ) {
    }
}
