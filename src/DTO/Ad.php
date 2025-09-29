<?php

declare(strict_types=1);

namespace ComCompany\PhpUbiflowApiClient\DTO;

use ComCompany\PhpUbiflowApiClient\Enum\Data;
use ComCompany\PhpUbiflowApiClient\Enum\Transaction;

final class Ad
{
    /** @var \WeakMap<Data, mixed> */
    private \WeakMap $data;

    /**
     * @param array<int, string> $pictures
     * @param array<int, string> $portals
     */
    public function __construct(
        public ?int $id,
        public readonly string $reference,
        private readonly Transaction $transaction,
        private readonly float $price,
        private readonly int $housingType,
        private readonly string $title,
        private readonly string $description,
        private readonly array $pictures,
        public readonly array $portals,
    ) {
        $this->data = new \WeakMap();
    }

    public function addData(Data $key, mixed $value): self
    {
        $this->data[$key] = $value;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'reference' => $this->reference,
            'status' => 'A',
            'transaction' => [
                'code' => $this->transaction->value,
                'price' => $this->price,
                'privatePrice' => false,
            ],
            'productType' => ['code' => $this->housingType],
            'title' => $this->title,
            'description' => $this->description,
            'data' => iterator_to_array((static function () {
                foreach ($this->data as $key => $value) {
                    yield ['code' => $key->value, 'value' => $value];
                }
            })()),
            'mediaSupports' => [
                'pictures' => array_map(static fn ($picture) => ['sourceUrl' => $picture], $this->pictures),
            ],
            'adPublications' => [
                'adPublications' => [
                    array_map(
                        static fn (string $portal) => ['advertiserPublication' => ['portal' => ['code' => $portal]]],
                        $this->portals,
                    ),
                ],
            ],
        ];
    }
}
