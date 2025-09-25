### Client PHP pour l’API Ubiflow

Client PHP permettant d’utiliser les API Ubiflow afin de publier des annonces et de récupérer les contacts e-mail.

### 1) Prérequis
- PHP >= 8.3
- 3 dépendances PHP, automatiquement installées par Composer :
    - psr/cache
    - symfony/http-client-contracts
    - thecodingmachine/safe

### 2) Installation du package

#### Installation via Composer

```bash
composer require com-company/php-ubiflow-api-client
```

#### Configuration du package

Le client `ComCompany\PhpUbiflowApiClient\Client` nécessite plusieurs paramètres :

- `$httpClient` : un service qui implémente l’interface `Symfony\Contracts\HttpClient\HttpClientInterface`.
- `$ubiflowClientId` : l’identifiant Ubiflow, composé de chiffres.
- `$ubiflowClientCode` : le code Ubiflow, généralement l’identifiant précédé de `ag`, par exemple `agxxxxxx`.
- `$ubiflowClientLogin` : le login utilisé pour les API, généralement identique au `$ubiflowClientCode` et à l’accès au portail web.
- `$ubiflowClientSecret` : le mot de passe pour les API.
- `$cachePool` : optionnel, un service qui implémente l’interface `Psr\Cache\CacheItemPoolInterface` et permet d’éviter des appels inutiles à l’API Ubiflow.

#### Exemple de configuration dans Symfony

```yaml
# config/services.yaml

services:
    # Injection du HttpClient de Symfony
    Symfony\Contracts\HttpClient\HttpClientInterface: '@http_client'

    # Injection du cache (optionnel)
    Psr\Cache\CacheItemPoolInterface: '@cache.app'

    ComCompany\PhpUbiflowApiClient\Client:
        arguments:
            $httpClient: '@Symfony\Contracts\HttpClient\HttpClientInterface'
            $ubiflowClientId: '%env(UBIFLOW_CLIENT_ID)%'
            $ubiflowClientCode: '%env(UBIFLOW_CLIENT_CODE)%'
            $ubiflowClientLogin: '%env(UBIFLOW_CLIENT_LOGIN)%'
            $ubiflowClientSecret: '%env(UBIFLOW_CLIENT_SECRET)%'
            $cachePool: '@Psr\Cache\CacheItemPoolInterface'  # optionnel

    App\Service\MonServiceDePublication:
        arguments:
            $client: '@ComCompany\PhpUbiflowApiClient\Client'
```

Vous pouvez ensuite utiliser votre service :

```php
<?php
namespace App\Service;

use ComCompany\PhpUbiflowApiClient\Client;

class MonServiceDePublication
{
    private Client $ubiflowClient;

    public function __construct(Client $ubiflowClient)
    {
        $this->ubiflowClient = $ubiflowClient;
    }

    // Votre code ici ...
}
```

### 3) Utilisation de l’API pour publier une annonce

#### Récupération des portails disponibles

Le service client dispose de 2 méthodes permettant de récupérer des données de portails :
- `public function getPortals(Universe $universe): Portal[]` : récupère la liste des portails d’un univers donné (IMMO, VOITURE, ...).
- `public function getPortal(int $id): ?Portal` : récupère le détail d’un portail dont vous connaissez l’ID Ubiflow.

Vous obtiendrez un DTO de type `ComCompany\PhpUbiflowApiClient\DTO\Portal`.

Attention :
Ce n’est pas parce que vous avez accès au détail d’un portail que vous avez le droit de publier sur celui-ci. Il faut pour cela donner délégation à Ubiflow pour permettre la publication sur ce portail (échange commercial entre vous et le portail).

#### Publication d’une annonce

##### Les services disponibles

Le service client dispose de plusieurs méthodes permettant de publier une annonce (`Ad`).
- `public function publishAd(Ad $ad): Ad` : permet de publier une annonce ou de la modifier si elle a déjà été publiée.
- `public function unpublishAd(Ad $ad): Ad` : permet de dépublier une annonce auprès des annonceurs, sans la supprimer d’Ubiflow ; utile si l’on souhaite mettre une annonce en pause chez un annonceur spécifique.
- `public function getAdPublications(Ad $ad): AdPublication[]` : permet d’avoir la liste des annonceurs utilisables pour l’annonce, ainsi que le statut de celle-ci (`selected` true/false).
- `public function updateAdPublications(AdPublication $adPublication, bool $selected): void` : permet de changer le statut de publication d’un annonceur spécifique (`selected` true/false) pour l’annonce.
- `public function removeAd(Ad $ad): Ad` : supprime l’annonce chez les annonceurs et chez Ubiflow.

##### Exemple de publication d’une annonce

Voici, en utilisant un service personnalisé, comment vous pouvez publier une annonce via le client :

```php
<?php

namespace App\Service;

use ComCompany\PhpUbiflowApiClient\Client;
use ComCompany\PhpUbiflowApiClient\DTO\Ad;
use ComCompany\PhpUbiflowApiClient\Enum\Data;
use ComCompany\PhpUbiflowApiClient\Enum\TypeProduit;

class MonServiceDePublication
{
    private Client $ubiflowClient;

    public function __construct(Client $ubiflowClient)
    {
        $this->ubiflowClient = $ubiflowClient;
    }

    /**
     * Publication de mon annonce et récupération de l’identifiant Ubiflow
     */
    public function createUbiflowAd(MonAnnonce $data): int
    {
        $ad = new Ad(
            $data->ubiflowId,
            $data->reference,
            $data->prixTTCAvecCharge,
            $data->typeUbiflow ?? TypeProduit::APPARTEMENT->value,
            $data->titreAnnonce,
            $data->descriptionAnnonce,
            array_map(fn ($picture) => $picture->urlPublicDeLImage, $data->pictures),
            array_map(fn ($portail) => $portail->codePortailUbiflow, $data->portails),
        );
        
        $ad->addData(Data::REFERENCE, $data->reference);
        $ad->addData(Data::TITRE, $data->titreAnnonce);
        $ad->addData(Data::TEXTE, $data->descriptionAnnonce);
        $ad->addData(Data::CODE_POSTAL, $data->codePostal);
        $ad->addData(Data::VILLE, $data->ville);
        $ad->addData(Data::DATE_SAISIE, $data->dateDeDiffusion?->format('d/m/Y') ?? null);
        $ad->addData(Data::TELEPHONE_A_AFFICHER, '04 XX XX XX XX');
        $ad->addData(Data::CHARGES_LOCATIVES, $data->charges);
        $ad->addData(Data::LOYER_MENSUEL, $data->prixTTCHorsCharge);
        $ad->addData(Data::LOYER_MENSUEL_CC, $data->prixTTCAvecCharge);
        $ad->addData(Data::LOYER_EST_CC, false);
        $ad->addData(Data::BALCON, false);
        $ad->addData(Data::PISCINE, false);
        $ad->addData(Data::ASCENSEUR, true);
        
        return $this->ubiflowClient->publishAd($ad)->id;  
    }
}
```

Italique TypeProduit et Data :

Il existe de très nombreux types de produits et des `Data` disponibles.
Pour ce qui est des `Data`, Ubiflow préconise un minimum, que vous pouvez retrouver à l’adresse :
https://espace-dev.ubiflow.net/fr/data/Real%20Estate%20Agency
- titre
- code_postal
- reference
- ville

Cette liste dépend des annonceurs : il est très vivement recommandé d’en mettre le plus possible !

### 4) Utilisation de l’API pour récupérer des contacts

##### Le service client dispose d’une méthode `getContacts` permettant de récupérer des contacts.

Celle-ci dispose de 2 paramètres : `public function getContacts(\DateTimeInterface $createdAtAfter, ?Ad $ad = null): Contact[]`
- `\DateTimeInterface $createdAtAfter` : obligatoire, date à partir de laquelle récupérer des contacts.
- `?Ad $ad = null` : facultatif, permet de limiter la récupération des contacts en fonction d’une `Ad` donnée. Le service utilisera `$ad->id` pour filtrer les contacts, en plus de la date.

##### Exemple d’utilisation dans un service périodique (cron) pour récupérer les contacts

Voici, en utilisant une commande Symfony, comment vous pouvez récupérer les contacts :

```php
<?php

declare(strict_types=1);

namespace App\Command;

use ComCompany\PhpUbiflowApiClient\Client;
use ComCompany\PhpUbiflowApiClient\DTO\Contact;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:ubiflow:import-contact',
    description: 'Import contact from Ubiflow',
)]
final readonly class ImportUbiflowContactCommand
{
    public function __construct(private Client $ubiflowClient)
    {
    }
    
    public function __invoke(SymfonyStyle $io): int
    {
        $io->title('Synchronisation des candidats Ubiflow');
        try {
            // Tous les contacts depuis les 2 dernières heures
            $contacts = $this->ubiflowClient->getContacts(
                (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-2 hours')
            );
            foreach ($contacts as $contact) {
                $this->processContact($contact);
            }

            $io->success('Tous les candidats ont été synchronisés avec succès.');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            // Log ici en cas d'erreur
            $io->error(sprintf('Erreur lors de la synchronisation : %s', $e->getMessage()));

            return Command::FAILURE;
        }
    }
    
    private function processContact(Contact $contact): void
    {
        // Votre code métier ici :
        // Exemple :
        // - Recherche si le contact est déjà dans votre base de données via `$contact->id`
        // - Recherche de l'annonce d'origine via votre référence `$contact->adReference`
        // - Récupération possible des informations du portail via `$portal = $this->ubiflowClient->getPortal($contact->portalId);`
        // ...
    }
}
```
