<?php

namespace App\Tests\Unit\Partner;

use App\Security\PartnerUser;
use PHPUnit\Framework\TestCase;

final class PartnerUserTest extends TestCase
{
    /** Jeton au format JWT ; seule la date d'expiration est lue par le blog. */
    public static function jeton(int $expiration): string
    {
        $encode = static fn (array $data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

        return $encode(['alg' => 'HS256']).'.'.$encode(['id' => 'p1', 'exp' => $expiration]).'.signature';
    }

    public function testLesInformationsDuPartenaire(): void
    {
        $partenaire = new PartnerUser('jeton', [
            'documentId' => 'p1',
            'name' => 'Le Bar',
            'email' => 'bar@test.fr',
            'city' => 'Angers',
            'category' => 'bar',
            'states' => 'active',
        ]);

        self::assertSame('p1', $partenaire->getDocumentId());
        self::assertSame('Le Bar', $partenaire->getName());
        self::assertSame('bar@test.fr', $partenaire->getUserIdentifier());
        self::assertSame('Angers', $partenaire->getCity());
        self::assertSame('bar', $partenaire->getCategory());
        self::assertSame('active', $partenaire->getState());
        self::assertSame(['ROLE_PARTNER'], $partenaire->getRoles());
    }

    public function testValeursParDefautQuandStrapiNeLesDonnePas(): void
    {
        $partenaire = new PartnerUser('jeton', ['documentId' => 'p1']);

        self::assertSame('p1', $partenaire->getUserIdentifier());
        self::assertSame('p1', $partenaire->getName());
        self::assertSame('', $partenaire->getEmail());
        self::assertNull($partenaire->getCity());
    }

    public function testExpirationDuJeton(): void
    {
        self::assertFalse((new PartnerUser(self::jeton(time() + 3600), []))->isTokenExpired());
        self::assertTrue((new PartnerUser(self::jeton(time() - 1), []))->isTokenExpired());
        self::assertTrue((new PartnerUser('pas-un-jwt', []))->isTokenExpired());
    }
}
