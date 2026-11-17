<?php

namespace App\Tests\Unit\Form;

use App\Form\ActivityType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

/**
 * Formulaire d'activité de l'espace partenaire : validation, et conversion des
 * dates entre l'heure de Paris (saisie) et l'UTC (Strapi).
 */
// TypeTestCase crée un dispatcher simulé sans attentes : ce n'est pas notre test.
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class ActivityTypeTest extends TypeTestCase
{
    protected function getExtensions(): array
    {
        return [new ValidatorExtension(Validation::createValidator())];
    }

    /**
     * @param array<string, mixed> $donnees
     */
    private function soumettre(array $donnees): FormInterface
    {
        $form = $this->factory->create(ActivityType::class);
        $form->submit($donnees + [
            'title' => 'Soirée quiz',
            'description' => '',
            'location' => '',
            'startDate' => '',
            'endDate' => '',
            'minParticipants' => '',
            'maxParticipants' => '',
            'price' => '',
        ]);

        return $form;
    }

    /** @return array<string, list<string>> erreurs par champ */
    private static function erreurs(FormInterface $form): array
    {
        $erreurs = [];
        foreach ($form->getErrors(true) as $erreur) {
            $erreurs[$erreur->getOrigin()?->getName() ?? ''][] = $erreur->getMessage();
        }

        return $erreurs;
    }

    public function testUneActiviteCompleteEstEnvoyeeEnUtc(): void
    {
        $form = $this->soumettre([
            'description' => 'Un quiz en équipe',
            'location' => 'Angers',
            'startDate' => '2026-11-20T19:00',
            'endDate' => '2026-11-20T22:30',
            'minParticipants' => '2',
            'maxParticipants' => '8',
            'price' => '12.5',
        ]);

        self::assertTrue($form->isValid(), json_encode(self::erreurs($form)));
        self::assertSame([
            'title' => 'Soirée quiz',
            'description' => 'Un quiz en équipe',
            'location' => 'Angers',
            'startDate' => '2026-11-20T18:00:00+00:00',
            'endDate' => '2026-11-20T21:30:00+00:00',
            'minParticipants' => 2,
            'maxParticipants' => 8,
            'price' => 12.5,
        ], ActivityType::toApiData($form->getData()));
    }

    public function testLesChampsFacultatifsVidesDeviennentNull(): void
    {
        $form = $this->soumettre([]);

        self::assertTrue($form->isValid());
        $donnees = ActivityType::toApiData($form->getData());
        self::assertSame('Soirée quiz', $donnees['title']);
        unset($donnees['title']);
        self::assertSame([null], array_values(array_unique($donnees, \SORT_REGULAR)));
    }

    public function testLHeureDEteEstPriseEnCompte(): void
    {
        $form = $this->soumettre(['startDate' => '2026-07-14T21:00']);

        self::assertSame('2026-07-14T19:00:00+00:00', ActivityType::toApiData($form->getData())['startDate']);
    }

    public function testLeTitreEstObligatoire(): void
    {
        $form = $this->soumettre(['title' => '  ']);

        self::assertFalse($form->isValid());
        self::assertSame(['title' => ['Donnez un titre à l’activité.']], self::erreurs($form));
    }

    public function testLaFinDoitEtreApresLeDebut(): void
    {
        $form = $this->soumettre(['startDate' => '2026-11-20T19:00', 'endDate' => '2026-11-20T18:00']);

        self::assertFalse($form->isValid());
        self::assertSame(['endDate' => ['La fin doit être après le début.']], self::erreurs($form));
    }

    public function testLeMinimumNePeutPasDepasserLeMaximum(): void
    {
        $form = $this->soumettre(['minParticipants' => '10', 'maxParticipants' => '4']);

        self::assertFalse($form->isValid());
        self::assertSame(['minParticipants' => ['Le minimum ne peut pas dépasser le maximum.']], self::erreurs($form));
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function valeursRefusees(): iterable
    {
        yield 'zéro participant' => [['minParticipants' => '0'], 'minParticipants'];
        yield 'maximum négatif' => [['maxParticipants' => '-3'], 'maxParticipants'];
        yield 'prix négatif' => [['price' => '-1'], 'price'];
        yield 'titre trop long' => [['title' => str_repeat('a', 256)], 'title'];
        yield 'date illisible' => [['startDate' => 'demain soir'], 'startDate'];
    }

    /**
     * @param array<string, string> $donnees
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('valeursRefusees')]
    public function testValeursRefusees(array $donnees, string $champ): void
    {
        $form = $this->soumettre($donnees);

        self::assertFalse($form->isValid());
        self::assertArrayHasKey($champ, self::erreurs($form));
    }

    public function testUneActiviteDeStrapiPreRemplitLeFormulaireALHeureDeParis(): void
    {
        $donnees = ActivityType::fromApiData([
            'title' => 'Quiz',
            'description' => null,
            'location' => 'Angers',
            'startDate' => '2026-11-20T18:00:00.000Z',
            'endDate' => null,
            'minParticipants' => 2,
            'maxParticipants' => null,
            'price' => 12.5,
        ]);

        $vue = $this->factory->create(ActivityType::class, $donnees)->createView();

        self::assertSame('2026-11-20T19:00', $vue['startDate']->vars['value']);
        self::assertSame('', $vue['endDate']->vars['value']);
        self::assertSame('Quiz', $vue['title']->vars['value']);
    }

    public function testAllerRetourAvecStrapiSansPerte(): void
    {
        $activite = [
            'title' => 'Quiz',
            'description' => 'Texte',
            'location' => 'Angers',
            'startDate' => '2026-11-20T18:00:00+00:00',
            'endDate' => '2026-11-20T21:00:00+00:00',
            'minParticipants' => 2,
            'maxParticipants' => 6,
            'price' => 10.0,
        ];

        self::assertSame($activite, ActivityType::toApiData(ActivityType::fromApiData($activite)));
    }
}
