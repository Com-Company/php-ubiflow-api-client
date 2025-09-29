<?php

declare(strict_types=1);

namespace ComCompany\PhpUbiflowApiClient;

use ComCompany\PhpUbiflowApiClient\DTO\Ad;
use ComCompany\PhpUbiflowApiClient\DTO\AdPublication;
use ComCompany\PhpUbiflowApiClient\DTO\AdvertiserPublication;
use ComCompany\PhpUbiflowApiClient\DTO\Contact;
use ComCompany\PhpUbiflowApiClient\DTO\Portal;
use ComCompany\PhpUbiflowApiClient\Enum\Universe;
use ComCompany\PhpUbiflowApiClient\Exception\ResponseException;
use ComCompany\PhpUbiflowApiClient\Exception\ValidationFailedException;
use Psr\Cache\CacheItemPoolInterface;
use Safe\DateTimeImmutable;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function Safe\json_encode;

final class Client
{
    private ?string $token = null;

    private const string UBIFLOW_API_URL = 'https://api-classifieds.ubiflow.net/api/';
    private const string UBIFLOW_LOGIN_URL = 'https://auth.ubiflow.net/api/login_check';
    private const string  UNIVERSE_CODE = 'universe.code';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $ubiflowClientId,
        private readonly string $ubiflowClientCode,
        private readonly string $ubiflowClientLogin,
        private readonly string $ubiflowClientSecret,
        private readonly ?CacheItemPoolInterface $cachePool = null,
    ) {
    }

    private function authenticate(): string
    {
        if ($this->token) {
            return $this->token;
        }

        $item = $this->cachePool?->getItem(hash('sha1', self::UBIFLOW_LOGIN_URL));
        if ($item && $item->isHit() && is_string($item->get())) {
            return $this->token = $item->get();
        }

        $response = $this->httpClient->request('POST', self::UBIFLOW_LOGIN_URL, [
            'json' => [
                'username' => $this->ubiflowClientLogin,
                'password' => $this->ubiflowClientSecret,
            ],
        ]);

        $data = $response->toArray(false);
        if (!isset($data['token']) || !is_string($data['token'])) {
            throw new ResponseException('Le token est introuvable dans la réponse.');
        }

        $this->token = $data['token'];
        if ($this->cachePool && $item) {
            $item->set($this->token);
            $item->expiresAt(new DateTimeImmutable('+10 hours'));
            $this->cachePool->save($item);
        }

        return $this->token;
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<mixed>
     */
    private function get(string $path, array $query = [], bool $useCache = false): array
    {
        $item = null;
        if ($useCache && $this->cachePool) {
            $key = hash('sha1', json_encode([
                'class' => self::class,
                'path' => $path,
                'query' => $query,
            ]));

            $item = $this->cachePool->getItem(hash('sha1', $key));
            if ($item->isHit() && is_array($item->get())) {
                return $item->get();
            }
        }

        $token = $this->authenticate();
        $response = $this->httpClient->request('GET', self::UBIFLOW_API_URL.$path, [
            'query' => $query,
            'headers' => ['X-AUTH-TOKEN' => 'Bearer '.$token],
        ]);

        $data = $response->toArray(false);
        if ($item) {
            $item->set($data);
            $item->expiresAt(new DateTimeImmutable('+10 hours'));
            $this->cachePool->save($item);
        }

        return $data;
    }

    /**
     * @param array<mixed> $json
     *
     * @return array<mixed>
     */
    private function post(string $path, array $json): array
    {
        $token = $this->authenticate();

        $response = $this->httpClient->request('POST', self::UBIFLOW_API_URL.$path, [
            'json' => $json,
            'headers' => ['X-AUTH-TOKEN' => 'Bearer '.$token],
        ]);

        return $response->toArray(false);
    }

    /**
     * @param array<mixed> $json
     *
     * @return array<mixed>
     */
    private function put(string $path, array $json): array
    {
        $token = $this->authenticate();

        $response = $this->httpClient->request('PUT', self::UBIFLOW_API_URL.$path, [
            'json' => $json,
            'headers' => ['X-AUTH-TOKEN' => 'Bearer '.$token],
        ]);

        return $response->toArray(false);
    }

    /** @return array<mixed> */
    private function delete(string $path): array
    {
        $token = $this->authenticate();
        $response = $this->httpClient->request('DELETE', self::UBIFLOW_API_URL.$path, [
            'headers' => ['X-AUTH-TOKEN' => 'Bearer '.$token],
        ]);

        if ($response->getStatusCode() > 300) {
            throw new ValidationFailedException('Erreur à la suppression. Code : '.$response->getStatusCode());
        }

        return 204 !== $response->getStatusCode() ? $response->toArray(false) : [];
    }

    public function getPortal(int $id): ?Portal
    {
        $portalData = $this->get('portals/'.$id, [], true);
        $id = $portalData['id'] ?? null;
        $code = $portalData['code'] ?? null;
        $name = $portalData['name'] ?? null;

        return is_int($id) && is_string($code) && is_string($name) ? new Portal($id, $code, $name) : null;
    }

    /** @return array<int, Portal> */
    public function getPortals(Universe $universe): array
    {
        $page = 1;
        $hasNext = true;
        $portals = [];

        while ($hasNext) {
            $data = $this->get('portals', ['page' => $page, self::UNIVERSE_CODE => $universe->value], true);

            $portalCount = 0;
            foreach ($data as $portalData) {
                if (!is_array($portalData)) {
                    continue;
                }
                $id = $portalData['id'] ?? null;
                $code = $portalData['code'] ?? null;
                $name = $portalData['name'] ?? null;
                if (!is_int($id) || !is_string($code) || !is_string($name)) {
                    continue;
                }
                $portals[] = new Portal($id, $code, $name);
                ++$portalCount;
            }

            $hasNext = $portalCount > 0;
            ++$page;
        }

        return $portals;
    }

    public function publishAd(Ad $ad): Ad
    {
        $payload = [
            'advertiser' => ['code' => $this->ubiflowClientCode],
            'source' => ['code' => $this->ubiflowClientLogin],
            ...$ad->toArray(),
        ];

        if ($ad->id) {
            $response = $this->put('ads/'.$ad->id, $payload);
        } else {
            $response = $this->post('ads', $payload);
        }

        $adId = $response['id'] ?? null;
        if (!is_int($adId)) {
            throw new ResponseException('Impossible de récupérer l’ID Ubiflow dans la réponse.');
        }
        $ad->id = $adId;

        foreach ($this->getAdPublications($ad) as $adPublication) {
            $this->updateAdPublications($adPublication, in_array($adPublication->advertiserPublication->portal->code, $ad->portals, true));
        }

        return $ad;
    }

    public function unpublishAd(Ad $ad): Ad
    {
        if (!$ad->id) {
            throw new ValidationFailedException('Impossible de retirer une publication d\'une annonce dans identifiant.');
        }

        foreach ($this->getAdPublications($ad) as $adPublication) {
            $this->updateAdPublications($adPublication, false);
        }

        return $ad;
    }

    /** @return array<int, AdPublication> */
    public function getAdPublications(Ad $ad): array
    {
        $adPublications = [];
        foreach ($this->get('ad_publications', ['ad.id' => $ad->id]) as $adPublicationData) {
            if (!is_array($adPublicationData)) {
                continue;
            }
            $id = $adPublicationData['id'] ?? null;
            if (!is_int($id)) {
                continue;
            }
            $advertiserPublicationData = $adPublicationData['advertiserPublication'] ?? null;
            if (!is_array($advertiserPublicationData)) {
                continue;
            }

            $advertiserPublicationPortal = $advertiserPublicationData['portal'] ?? null;
            if (!is_array($advertiserPublicationPortal)) {
                continue;
            }
            $advertiserPublicationId = $advertiserPublicationData['id'] ?? null;
            $portalId = $advertiserPublicationPortal['id'] ?? null;
            $portalCode = $advertiserPublicationPortal['code'] ?? null;
            if (!is_int($advertiserPublicationId) || !is_int($portalId) || !is_string($portalCode)) {
                continue;
            }
            $portal = new Portal($portalId, $portalCode, '');
            $advertiserPublication = new AdvertiserPublication(
                $advertiserPublicationId,
                $portal,
            );
            $selected = $adPublicationData['selected'] ?? null;
            $publishable = $adPublicationData['publishable'] ?? null;
            $publicationIncompatibilitiesData = $adPublicationData['publicationIncompatibilities'] ?? null;
            $publicationIncompatibilitiesData = is_array($publicationIncompatibilitiesData) ? $publicationIncompatibilitiesData : [];
            $publicationIncompatibilities = [];
            foreach ($publicationIncompatibilitiesData as $publicationIncompatibilityData) {
                if (is_array($publicationIncompatibilityData)
                    && isset($publicationIncompatibilityData['description'])
                    && is_string($publicationIncompatibilityData['description'])
                ) {
                    $publicationIncompatibilities[] = $publicationIncompatibilityData['description'];
                }
            }
            $lastPublishedAt = $adPublicationData['lastPublishedAt'] ?? null;
            $unPublishedAt = $adPublicationData['unPublishedAt'] ?? null;
            $urlOnPortal = $adPublicationData['urlOnPortal'] ?? null;

            $adPublications[] = new AdPublication(
                $id,
                $advertiserPublication,
                (bool) $selected,
                (bool) $publishable,
                $publicationIncompatibilities,
                is_string($lastPublishedAt) ? new DateTimeImmutable($lastPublishedAt) : null,
                is_string($unPublishedAt) ? new DateTimeImmutable($unPublishedAt) : null,
                is_string($urlOnPortal) ? $urlOnPortal : null,
            );
        }

        return $adPublications;
    }

    public function updateAdPublications(AdPublication $adPublication, bool $selected): void
    {
        $this->put('ad_publications/'.$adPublication->id, ['selected' => $selected]);
    }

    public function removeAd(Ad $ad): Ad
    {
        if (!$ad->id) {
            throw new ValidationFailedException('Impossible de supprimer une publication d\'une annonce dans identifiant.');
        }

        $this->delete('ads/'.$ad->id);

        return $ad;
    }

    /** @return array<int, Contact> */
    public function getContacts(\DateTimeInterface $createdAtAfter, ?Ad $ad = null): array
    {
        $page = 1;
        $hasNext = true;
        $contacts = [];

        while ($hasNext) {
            try {
                $data = $this->get('mail_tracking_contacts', [
                    'page' => $page,
                    'ad.advertiser.id' => $this->ubiflowClientId,
                    'createdAt' => ['after' => $createdAtAfter->format('Y-m-d\TH:i:sP')],
                    ...($ad ? ['ad.reference' => $ad->reference] : []),
                ]);
            } catch (\Throwable $e) {
                $data = [];
            }
            $contactAdded = 0;
            foreach ($data as $contactData) {
                if (!is_array($contactData)) {
                    continue;
                }
                $id = $contactData['id'] ?? null;
                $portal = $contactData['portal'] ?? null;
                if (!is_array($portal)) {
                    $portal = [];
                }
                $portalId = $portal['id'] ?? null;
                $adData = $contactData['ad'] ?? null;
                if (!is_array($adData)) {
                    $adData = [];
                }
                $adReference = $adData['reference'] ?? null;
                $urlOnPortal = $contactData['urlOnPortal'] ?? null;
                $createdAt = $contactData['createdAt'] ?? null;
                $contactInformation = $contactData['contactInformation'] ?? null;
                if (!is_array($contactInformation)) {
                    $contactInformation = [];
                }
                $civility = $contactInformation['civility'] ?? null;
                $firstName = $contactInformation['firstName'] ?? null;
                $name = $contactInformation['name'] ?? null;
                $identity = $contactInformation['identity'] ?? null;
                $email = $contactInformation['email'] ?? null;
                $phone = $contactInformation['phone'] ?? null;
                $postalAddress = $contactInformation['postalAddress'] ?? null;
                if (!is_array($postalAddress)) {
                    $postalAddress = [];
                }
                $addressLocality = $postalAddress['addressLocality'] ?? null;
                $postalCode = $postalAddress['postalCode'] ?? null;
                $additionalInformation = $contactData['additionalInformation'] ?? null;
                $comment = $contactData['comment'] ?? null;

                $contacts[] = new Contact(
                    is_int($id) ? $id : null,
                    is_int($portalId) ? $portalId : null,
                    is_string($adReference) ? $adReference : null,
                    is_string($urlOnPortal) ? $urlOnPortal : null,
                    new DateTimeImmutable(is_string($createdAt) ? $createdAt : 'now'),
                    is_string($civility) ? $civility : null,
                    is_string($firstName) ? $firstName : null,
                    is_string($name) ? $name : null,
                    is_string($identity) ? $identity : null,
                    is_string($email) ? $email : null,
                    is_string($phone) ? $phone : null,
                    is_string($addressLocality) ? $addressLocality : null,
                    is_string($postalCode) ? $postalCode : null,
                    is_string($additionalInformation) ? $additionalInformation : null,
                    is_string($comment) ? $comment : null,
                );
                ++$contactAdded;
            }

            $hasNext = $contactAdded > 0;
            ++$page;
        }

        return $contacts;
    }
}
