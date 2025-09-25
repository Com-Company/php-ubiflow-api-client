<?php

declare(strict_types=1);

namespace ComCompany\PhpUbiflowApiClient\DTO;

final readonly class Contact
{
    public function __construct(
        public ?int $id,
        public ?int $portalId,
        public ?string $adReference,
        public ?string $urlOnPortal,
        public \DateTimeInterface $createdAt,
        public ?string $civility,
        public ?string $firstName,
        public ?string $name,
        public ?string $identity,
        public ?string $email,
        public ?string $phone,
        public ?string $addressLocality,
        public ?string $postalCode,
        public ?string $additionalInformation,
        public ?string $comment,
    ) {
    }
}
