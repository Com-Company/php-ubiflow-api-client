<?php

declare(strict_types=1);

namespace ComCompany\PhpUbiflowApiClient\Tests;

use ComCompany\PhpUbiflowApiClient\Client;
use ComCompany\PhpUbiflowApiClient\DTO\Ad;
use ComCompany\PhpUbiflowApiClient\DTO\AdPublication;
use ComCompany\PhpUbiflowApiClient\DTO\Contact;
use ComCompany\PhpUbiflowApiClient\DTO\Portal;
use ComCompany\PhpUbiflowApiClient\Enum\Data;
use ComCompany\PhpUbiflowApiClient\Enum\Transaction;
use ComCompany\PhpUbiflowApiClient\Enum\TypeProduit;
use ComCompany\PhpUbiflowApiClient\Enum\Universe;
use ComCompany\PhpUbiflowApiClient\Exception\ValidationFailedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Safe\DateTimeImmutable;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ClientTest extends TestCase
{
    private HttpClientInterface&MockObject $httpClient;

    private Client $client;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);

        $this->client = new Client(
            httpClient: $this->httpClient,
            ubiflowClientId: 'client-id',
            ubiflowClientCode: 'client-code',
            ubiflowClientLogin: 'client-login',
            ubiflowClientSecret: 'client-secret',
            cachePool: null // ✅ Pas de cache
        );
    }

    /** @param array<mixed> $returnData  */
    private function createResponseMock(array $returnData): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn($returnData);

        return $response;
    }

    private function mockAuthResponse(): ResponseInterface
    {
        return $this->createResponseMock(['token' => 'token-value']);
    }

    /**
     * @param array<int, array{
     *     0: string,
     *     1: string,
     *     2: ResponseInterface,
     *     3?: callable(array<string, mixed>): bool
     * }> $routes
     *
     * @return callable(string, string, array<string, mixed>): ResponseInterface
     */
    private function createHttpClientCallback(array $routes): callable
    {
        return function (string $method, string $url, array $options = []) use ($routes): ResponseInterface {
            foreach ($routes as $route) {
                /** @var string $expectedMethod */
                $expectedMethod = $route[0];

                /** @var string $urlSubstring */
                $urlSubstring = $route[1];

                /** @var ResponseInterface $response */
                $response = $route[2];

                /** @var (callable(array<string, mixed>): bool)|null $condition */
                $condition = $route[3] ?? null;

                $methodMatches = $expectedMethod === $method;
                $urlMatches = str_contains($url, $urlSubstring);

                /** @var array<string, mixed> $options */
                $conditionMatches = null === $condition || $condition($options);

                if ($methodMatches && $urlMatches && $conditionMatches) {
                    return $response;
                }
            }

            throw new \RuntimeException("Unexpected request: $method $url");
        };
    }

    /**
     * @param array<int, array{
     *     0: string,
     *     1: string,
     *     2: ResponseInterface,
     *     3?: callable(array<string, mixed>): bool
     * }> $routes
     *
     * @return array<int, array{
     *      0: string,
     *      1: string,
     *      2: ResponseInterface,
     *      3?: callable(array<string, mixed>): bool
     *  }>
     */
    private function withAuthStub(array $routes): array
    {
        return array_merge(
            [['POST', 'login_check', $this->mockAuthResponse()]],
            $routes
        );
    }

    public function testGetPortalReturnsPortal(): void
    {
        $portalId = 123;
        $portalResponse = $this->createResponseMock([
            'id' => $portalId,
            'code' => 'portal-code',
            'name' => 'Test Portal',
        ]);

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback($this->createHttpClientCallback(
                $this->withAuthStub([
                    ['GET', 'portals', $portalResponse],
                ])
            ));

        $portal = $this->client->getPortal($portalId);

        $this->assertInstanceOf(Portal::class, $portal);
        $this->assertSame($portalId, $portal->id);
        $this->assertSame('portal-code', $portal->code);
        $this->assertSame('Test Portal', $portal->name);
    }

    public function testPublishAdCreatesAd(): void
    {
        $adId = 999;

        $postResponse = $this->createResponseMock(['id' => $adId]);
        $adPublicationResponse = $this->createResponseMock([[
            'id' => 456,
            'advertiserPublication' => [
                'portal' => [
                    'id' => 12,
                    'code' => 'ABC',
                ],
                'id' => 1,
            ],
            'selected' => true,
            'publishable' => true,
            'publicationIncompatibilities' => [],
            'lastPublishedAt' => 'now',
            'unPublishedAt' => null,
            'urlOnPortal' => 'http://www.my-site.com/my-picture.jpg',
        ]]);

        $this->httpClient
            ->expects($this->exactly(4))
            ->method('request')
            ->willReturnCallback($this->createHttpClientCallback(
                $this->withAuthStub([
                    ['POST', 'ads', $postResponse],
                    ['GET', 'ad_publications', $adPublicationResponse],
                    ['PUT', 'ad_publications/456', $this->createResponseMock([])],
                ])
            ));

        $returnedAd = $this->client->publishAd($this->createMockAd());

        $this->assertSame($adId, $returnedAd->id);
    }

    private function createMockAd(): Ad
    {
        $pictures = [
            (object) ['urlPublicDeLImage' => 'http://example.com/pic1.jpg'],
            (object) ['urlPublicDeLImage' => 'http://example.com/pic2.jpg'],
        ];
        $portails = [
            (object) ['codePortailUbiflow' => 'portal1'],
            (object) ['codePortailUbiflow' => 'portal2'],
        ];

        $ad = new Ad(
            null,
            'AD-001',
            Transaction::SALE,
            1000,
            TypeProduit::APPARTEMENT,
            'Super Appartement',
            'Description détaillée de l\'appartement',
            array_map(fn ($picture) => $picture->urlPublicDeLImage, $pictures),
            array_map(fn ($portail) => $portail->codePortailUbiflow, $portails),
        );

        $ad->addData(Data::REFERENCE, 'AD-001');
        $ad->addData(Data::TITRE, 'Super Appartement');
        $ad->addData(Data::CODE_POSTAL, '75001');
        $ad->addData(Data::VILLE, 'Paris');
        $ad->addData(Data::DATE_SAISIE, (new DateTimeImmutable('2025-10-01'))->format('d/m/Y'));
        $ad->addData(Data::TELEPHONE_A_AFFICHER, '04 XX XX XX XX');
        $ad->addData(Data::CHARGES_LOCATIVES, 150);
        $ad->addData(Data::LOYER_MENSUEL, 850);
        $ad->addData(Data::LOYER_MENSUEL_CC, 1000);
        $ad->addData(Data::LOYER_EST_CC, false);
        $ad->addData(Data::BALCON, false);
        $ad->addData(Data::PISCINE, false);
        $ad->addData(Data::ASCENSEUR, true);

        return $ad;
    }

    public function testUnpublishAdThrowsWithoutId(): void
    {
        $this->expectException(ValidationFailedException::class);
        $this->expectExceptionMessage("Impossible de retirer une publication d'une annonce sans identifiant.");

        $ad = $this->createMockAd();
        $this->client->unpublishAd($ad);
    }

    public function testUnpublishAd(): void
    {
        $ad = $this->createMockAd();
        $ad->id = 12;

        $adPublicationResponse = $this->createResponseMock([[
            'id' => 456,
            'advertiserPublication' => [
                'portal' => [
                    'id' => 12,
                    'code' => 'ABC',
                ],
                'id' => 1,
            ],
            'selected' => true,
            'publishable' => true,
            'publicationIncompatibilities' => [],
            'lastPublishedAt' => 'now',
            'unPublishedAt' => null,
            'urlOnPortal' => 'http://www.my-site.com/my-picture.jpg',
        ]]);

        $this->httpClient
            ->expects($this->exactly(3))
            ->method('request')
            ->willReturnCallback($this->createHttpClientCallback(
                $this->withAuthStub([
                    ['GET', 'ad_publications', $adPublicationResponse],
                    ['PUT', 'ad_publications/456', $this->createResponseMock([])],
                ])
            ));

        $this->client->unpublishAd($ad);
    }

    public function testRemoveAdThrowsWithoutId(): void
    {
        $this->expectException(ValidationFailedException::class);
        $this->expectExceptionMessage('Impossible de supprimer une annonce sans identifiant.');

        $ad = $this->createMockAd();
        $this->client->removeAd($ad);
    }

    public function testRemoveAd(): void
    {
        $ad = $this->createMockAd();
        $ad->id = 12;
        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback($this->createHttpClientCallback(
                $this->withAuthStub([
                    ['DELETE', 'ads/12', $this->createResponseMock([])],
                ])
            ));

        $this->client->removeAd($ad);
    }

    public function testGetAdPublicationsReturnsList(): void
    {
        $ad = $this->createMockAd();
        $ad->id = 789;

        $adPublicationsResponse = $this->createResponseMock([
            [
                'id' => 1,
                'selected' => true,
                'publishable' => true,
                'advertiserPublication' => [
                    'id' => 101,
                    'portal' => [
                        'id' => 555,
                        'code' => 'portal-code',
                    ],
                ],
                'publicationIncompatibilities' => [],
                'lastPublishedAt' => '2023-01-01T12:00:00+00:00',
                'unPublishedAt' => null,
                'urlOnPortal' => 'https://portal.example.com/ad/123',
            ],
        ]);

        $this->httpClient
            ->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback($this->createHttpClientCallback(
                $this->withAuthStub([
                    ['GET', 'ad_publications', $adPublicationsResponse],
                ])
            ));

        $publications = $this->client->getAdPublications($ad);
        $this->assertCount(1, $publications);
        $this->assertInstanceOf(AdPublication::class, $publications[0]);
    }

    /**
     * @return callable(array<string, mixed>): bool
     */
    private function hasPage(int $expectedPage): callable
    {
        return static function (array $options) use ($expectedPage): bool {
            if (!isset($options['query']) || !is_array($options['query'])) {
                return false;
            }

            return isset($options['query']['page']) && $options['query']['page'] === $expectedPage;
        };
    }

    public function testGetPortals(): void
    {
        $responsePage1 = $this->createResponseMock([
            ['id' => 1, 'code' => 'portal_1', 'name' => 'Portal One'],
            ['id' => 2, 'code' => 'portal_2', 'name' => 'Portal Two'],
        ]);
        $responsePage2 = $this->createResponseMock([
            ['id' => 3, 'code' => 'portal_3', 'name' => 'Portal Three'],
        ]);
        $this->httpClient->expects($this->exactly(4))
            ->method('request')
            ->willReturnCallback($this->createHttpClientCallback(
                $this->withAuthStub([
                    ['GET', 'portals', $responsePage1, $this->hasPage(1)],
                    ['GET', 'portals', $responsePage2, $this->hasPage(2)],
                    ['GET', 'portals', $this->createResponseMock([]), $this->hasPage(3)],
                ])
            ));

        $portals = $this->client->getPortals(Universe::IMMO);

        $this->assertCount(3, $portals);

        $this->assertInstanceOf(Portal::class, $portals[0]);
        $this->assertSame(1, $portals[0]->id);
        $this->assertSame('portal_1', $portals[0]->code);
        $this->assertSame('Portal One', $portals[0]->name);

        $this->assertInstanceOf(Portal::class, $portals[1]);
        $this->assertSame(2, $portals[1]->id);
        $this->assertSame('portal_2', $portals[1]->code);
        $this->assertSame('Portal Two', $portals[1]->name);

        $this->assertInstanceOf(Portal::class, $portals[2]);
        $this->assertSame(3, $portals[2]->id);
        $this->assertSame('portal_3', $portals[2]->code);
        $this->assertSame('Portal Three', $portals[2]->name);
    }

    public function testGetContacts(): void
    {
        $responsePage1 = $this->createResponseMock([
            ['id' => 1, 'portal' => ['id' => 1], 'ad' => ['reference' => 'ABC'], 'contactInformation' => ['name' => 'contact_1']],
            ['id' => 2, 'portal' => ['id' => 2], 'ad' => ['reference' => 'ABC'], 'contactInformation' => ['name' => 'contact_2']],
        ]);
        $responsePage2 = $this->createResponseMock([
            ['id' => 3, 'portal' => ['id' => 2], 'ad' => ['reference' => 'ABC'], 'contactInformation' => ['name' => 'contact_3']],
        ]);
        $this->httpClient->expects($this->exactly(4))
            ->method('request')
            ->willReturnCallback($this->createHttpClientCallback(
                $this->withAuthStub([
                    ['GET', 'mail_tracking_contacts', $responsePage1, $this->hasPage(1)],
                    ['GET', 'mail_tracking_contacts', $responsePage2, $this->hasPage(2)],
                    ['GET', 'mail_tracking_contacts', $this->createResponseMock([]), $this->hasPage(3)],
                ])
            ));

        $contacts = $this->client->getContacts(new DateTimeImmutable());

        $this->assertCount(3, $contacts);

        $this->assertInstanceOf(Contact::class, $contacts[0]);
        $this->assertSame(1, $contacts[0]->id);
        $this->assertSame('contact_1', $contacts[0]->name);

        $this->assertInstanceOf(Contact::class, $contacts[1]);
        $this->assertSame(2, $contacts[1]->id);
        $this->assertSame('contact_2', $contacts[1]->name);

        $this->assertInstanceOf(Contact::class, $contacts[2]);
        $this->assertSame(3, $contacts[2]->id);
        $this->assertSame('contact_3', $contacts[2]->name);
    }
}
