<?php

namespace App\Tests\Unit\Partner;

use App\Partner\PartnerApi;
use App\Partner\PartnerApiException;
use App\Security\PartnerUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Client de l'espace partenaire de Strapi : requêtes envoyées et traduction
 * des erreurs en messages affichables.
 */
final class PartnerApiTest extends TestCase
{
    private const BASE_URI = 'http://strapi.test';

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requetes = [];

    private function api(MockResponse ...$reponses): PartnerApi
    {
        $this->requetes = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$reponses): MockResponse {
            $this->requetes[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($reponses) ?? new MockResponse('{}');
        }, self::BASE_URI);

        return new PartnerApi($client);
    }

    private static function json(mixed $body, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }

    private static function partenaire(): PartnerUser
    {
        return new PartnerUser('jeton-strapi', ['documentId' => 'p1', 'email' => 'bar@test.fr']);
    }

    /** @return array<string, string> */
    private function entetes(int $index = 0): array
    {
        $entetes = [];
        foreach ($this->requetes[$index]['options']['normalized_headers'] ?? [] as $nom => $valeurs) {
            $entetes[$nom] = substr($valeurs[0], \strlen($nom) + 2);
        }

        return $entetes;
    }

    private function corps(int $index = 0): mixed
    {
        return json_decode($this->requetes[$index]['options']['body'], true);
    }

    public function testLoginEnvoieLesIdentifiantsSansJeton(): void
    {
        $api = $this->api(self::json(['token' => 'abc', 'partner' => ['name' => 'Bar']]));

        $resultat = $api->login('bar@test.fr', 'secret');

        self::assertSame(['token' => 'abc', 'partner' => ['name' => 'Bar']], $resultat);
        self::assertSame('POST', $this->requetes[0]['method']);
        self::assertSame(self::BASE_URI.'/api/partner-portal/login', $this->requetes[0]['url']);
        self::assertSame(['email' => 'bar@test.fr', 'password' => 'secret'], $this->corps());
        self::assertArrayNotHasKey('authorization', $this->entetes());
    }

    public function testListeDesActivitesAvecLeJetonDuPartenaire(): void
    {
        $api = $this->api(self::json(['data' => [['title' => 'Quiz']]]));

        self::assertSame([['title' => 'Quiz']], $api->listActivities(self::partenaire()));
        self::assertSame('GET', $this->requetes[0]['method']);
        self::assertSame(self::BASE_URI.'/api/partner-portal/activities', $this->requetes[0]['url']);
        self::assertSame('Bearer jeton-strapi', $this->entetes()['authorization']);
    }

    public function testCreationEtModificationEnvoientLesDonneesDansData(): void
    {
        $api = $this->api(self::json(['data' => ['title' => 'Quiz']], 201), self::json(['data' => ['title' => 'Blind test']]));

        self::assertSame(['title' => 'Quiz'], $api->createActivity(self::partenaire(), ['title' => 'Quiz']));
        self::assertSame(['title' => 'Blind test'], $api->updateActivity(self::partenaire(), 'abc', ['title' => 'Blind test']));

        self::assertSame('POST', $this->requetes[0]['method']);
        self::assertSame(['data' => ['title' => 'Quiz']], $this->corps(0));
        self::assertSame('PUT', $this->requetes[1]['method']);
        self::assertSame(self::BASE_URI.'/api/partner-portal/activities/abc', $this->requetes[1]['url']);
        self::assertSame(['data' => ['title' => 'Blind test']], $this->corps(1));
    }

    public function testLIdentifiantDeLActiviteEstEncodeDansLUrl(): void
    {
        $api = $this->api(self::json(['data' => []]));

        $api->getActivity(self::partenaire(), '../users/1');

        self::assertSame(self::BASE_URI.'/api/partner-portal/activities/..%2Fusers%2F1', $this->requetes[0]['url']);
    }

    public function testSuppressionAvecReponseVide(): void
    {
        $api = $this->api(new MockResponse('', ['http_code' => 204]));

        $api->deleteActivity(self::partenaire(), 'abc');

        self::assertSame('DELETE', $this->requetes[0]['method']);
    }

    public function testLeMessageDErreurDeStrapiEstRepris(): void
    {
        $api = $this->api(self::json(['error' => ['status' => 400, 'message' => 'Le titre de l’activité est obligatoire.']], 400));

        try {
            $api->createActivity(self::partenaire(), []);
            self::fail('Une exception était attendue.');
        } catch (PartnerApiException $exception) {
            self::assertSame('Le titre de l’activité est obligatoire.', $exception->getMessage());
            self::assertSame(400, $exception->getStatusCode());
            self::assertFalse($exception->isUnauthorized());
        }
    }

    public function testUneSessionExpireeEstSignalee(): void
    {
        $api = $this->api(self::json(['error' => ['status' => 401, 'message' => 'Unauthorized']], 401));

        try {
            $api->listActivities(self::partenaire());
            self::fail('Une exception était attendue.');
        } catch (PartnerApiException $exception) {
            self::assertTrue($exception->isUnauthorized());
        }
    }

    /**
     * @return iterable<string, array{MockResponse, string, int}>
     */
    public static function erreurs(): iterable
    {
        yield 'trop de tentatives' => [self::json(['error' => ['message' => 'Too many requests']], 429), 'Trop de tentatives. Patientez quelques minutes avant de réessayer.', 429];
        yield 'erreur serveur' => [self::json(['error' => ['message' => 'Internal Server Error']], 500), 'Le service Bondiz a rencontré une erreur. Réessayez dans quelques instants.', 500];
        yield 'refus sans message' => [new MockResponse('', ['http_code' => 403]), 'La requête a été refusée.', 403];
        yield 'Strapi injoignable' => [new MockResponse('', ['error' => 'Connexion refusée']), 'Le service Bondiz est momentanément indisponible. Réessayez dans quelques instants.', 503];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('erreurs')]
    public function testLesErreursSontTraduitesEnMessagesLisibles(MockResponse $reponse, string $message, int $statut): void
    {
        $api = $this->api($reponse);

        try {
            $api->listActivities(self::partenaire());
            self::fail('Une exception était attendue.');
        } catch (PartnerApiException $exception) {
            self::assertSame($message, $exception->getMessage());
            self::assertSame($statut, $exception->getStatusCode());
        }
    }
}
