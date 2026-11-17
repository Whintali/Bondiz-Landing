<?php

namespace App\Tests\Unit\Partner;

use App\Partner\PartnerDashboard;
use PHPUnit\Framework\TestCase;

/**
 * Chiffres et listes de l'espace partenaire, calculés à partir des activités
 * renvoyées par Strapi. La date du jour est fixée au mardi 17 novembre 2026, midi.
 */
final class PartnerDashboardTest extends TestCase
{
    private PartnerDashboard $dashboard;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->dashboard = new PartnerDashboard();
        $this->now = new \DateTimeImmutable('2026-11-17T12:00:00+01:00');
    }

    /**
     * @return array<string, mixed>
     */
    private static function activite(string $titre, ?string $debut, ?string $fin = null, int $reservations = 0, ?int $max = null): array
    {
        return [
            'documentId' => $titre,
            'title' => $titre,
            'startDate' => $debut,
            'endDate' => $fin,
            'bookingsCount' => $reservations,
            'maxParticipants' => $max,
        ];
    }

    /**
     * @param list<array<string, mixed>> $activites
     *
     * @return list<string>
     */
    private static function titres(array $activites): array
    {
        return array_column($activites, 'title');
    }

    public function testListeSepareLesActivitesAVenirEtPassees(): void
    {
        $liste = $this->dashboard->liste([
            self::activite('lointaine', '2026-12-05T18:00:00.000Z'),
            self::activite('passee', '2026-11-10T18:00:00.000Z', '2026-11-10T20:00:00.000Z'),
            self::activite('sans date', null),
            self::activite('prochaine', '2026-11-20T18:00:00.000Z'),
        ], $this->now);

        // Les plus proches d'abord, les activités sans date à la fin.
        self::assertSame(['prochaine', 'lointaine', 'sans date'], self::titres($liste['a_venir']));
        self::assertSame(['passee'], self::titres($liste['passees']));
    }

    public function testUneActiviteEnCoursResteAVenir(): void
    {
        $liste = $this->dashboard->liste([
            self::activite('en cours', '2026-11-17T09:00:00.000Z', '2026-11-17T18:00:00.000Z'),
        ], $this->now);

        self::assertSame(['en cours'], self::titres($liste['a_venir']));
    }

    public function testSansDateDeFinLaDateDeDebutSert(): void
    {
        $liste = $this->dashboard->liste([self::activite('hier', '2026-11-16T18:00:00.000Z')], $this->now);

        self::assertSame(['hier'], self::titres($liste['passees']));
    }

    public function testLesActivitesPasseesSontTrieesDeLaPlusRecente(): void
    {
        $liste = $this->dashboard->liste([
            self::activite('septembre', '2026-09-01T18:00:00.000Z'),
            self::activite('octobre', '2026-10-01T18:00:00.000Z'),
        ], $this->now);

        self::assertSame(['octobre', 'septembre'], self::titres($liste['passees']));
    }

    public function testLaListeAjouteLaDateAffichee(): void
    {
        $liste = $this->dashboard->liste([self::activite('quiz', '2026-12-05T18:00:00.000Z')], $this->now);

        // 18h UTC = 19h à Paris en hiver.
        self::assertSame('Sam. 5 déc 2026 - 19h00', $liste['a_venir'][0]['dateCourte']);
        self::assertSame('2026-12-05T19:00:00+01:00', $liste['a_venir'][0]['debut']->format('c'));
        self::assertNull($this->dashboard->liste([self::activite('libre', null)], $this->now)['a_venir'][0]['dateCourte']);
    }

    public function testDateCourte(): void
    {
        self::assertSame('Lun. 1 juin 2026 - 09h05', PartnerDashboard::dateCourte(new \DateTimeImmutable('2026-06-01T09:05:00')));
    }

    public function testIndicateursDuMoisComparesAuMoisPrecedent(): void
    {
        $tableau = $this->dashboard->build([
            self::activite('novembre 1', '2026-11-05T18:00:00.000Z', null, 3, 10),
            self::activite('novembre 2', '2026-11-25T18:00:00.000Z', null, 1, 10),
            self::activite('octobre', '2026-10-10T18:00:00.000Z', null, 2, 4),
            self::activite('decembre', '2026-12-10T18:00:00.000Z', null, 5, 5),
        ], 4, $this->now);

        $indicateurs = $tableau['indicateurs'];
        self::assertSame(['valeur' => 2, 'evolution' => 100], $indicateurs['activites']);
        self::assertSame(['valeur' => 4, 'evolution' => 100], $indicateurs['participants']);
        // 4 places réservées sur 20 en novembre, contre 2 sur 4 en octobre.
        self::assertSame(['valeur' => 20, 'evolution' => -60], $indicateurs['remplissage']);
    }

    public function testPasDEvolutionQuandLeMoisPrecedentEstVide(): void
    {
        $tableau = $this->dashboard->build([self::activite('novembre', '2026-11-05T18:00:00.000Z', null, 2)], 4, $this->now);

        self::assertSame(['valeur' => 1, 'evolution' => null], $tableau['indicateurs']['activites']);
        // Aucune activité n'a de nombre de places : pas de taux de remplissage.
        self::assertSame(['valeur' => null, 'evolution' => null], $tableau['indicateurs']['remplissage']);
    }

    public function testLesProchainesActivitesSontLesTroisPlusProches(): void
    {
        $tableau = $this->dashboard->build([
            self::activite('passee', '2026-11-01T18:00:00.000Z'),
            self::activite('d', '2027-01-10T18:00:00.000Z'),
            self::activite('b', '2026-11-25T18:00:00.000Z'),
            self::activite('a', '2026-11-18T18:00:00.000Z'),
            self::activite('c', '2026-12-10T18:00:00.000Z'),
        ], 4, $this->now);

        self::assertSame(['a', 'b', 'c'], self::titres($tableau['prochaines']));
        self::assertSame('Mer. 18 nov 2026 - 19h00', $tableau['prochaines'][0]['dateCourte']);
    }

    public function testLesTotauxComptentAussiLesActivitesSansDate(): void
    {
        $tableau = $this->dashboard->build([
            self::activite('datee', '2026-11-05T18:00:00.000Z', null, 2),
            self::activite('libre', null, null, 3),
        ], 4, $this->now);

        self::assertSame(['activites' => 2, 'participants' => 5], $tableau['totaux']);
    }

    public function testLesActivitesPopulairesSontLesDeuxPlusReservees(): void
    {
        $tableau = $this->dashboard->build([
            self::activite('vide', '2026-11-05T18:00:00.000Z', null, 0, 10),
            self::activite('moyenne', '2026-11-06T18:00:00.000Z', null, 3, 10),
            self::activite('complete', '2026-11-07T18:00:00.000Z', null, 8, 6),
            self::activite('sans places', '2026-11-08T18:00:00.000Z', null, 1),
        ], 4, $this->now);

        self::assertSame(['complete', 'moyenne'], self::titres($tableau['populaires']));
        // Surréservation : le taux est plafonné à 100 %.
        self::assertSame(100, $tableau['populaires'][0]['taux']);
        self::assertSame(30, $tableau['populaires'][1]['taux']);
    }

    public function testLeGraphiqueDeParticipationAUnPointParSemaine(): void
    {
        $tableau = $this->dashboard->build([
            self::activite('cette semaine', '2026-11-17T18:00:00.000Z', null, 45),
        ], 8, $this->now);

        $graphique = $tableau['participation'];
        self::assertCount(8, $graphique['points']);
        self::assertSame('16 nov.', $graphique['points'][7]['libelle']);
        self::assertSame(45, $graphique['points'][7]['valeur']);
        // 45 réservations : pas de 20, axe de 0 à 80.
        self::assertSame([80, 60, 40, 20, 0], $graphique['graduations']);
        self::assertSame(56.25, $graphique['points'][7]['hauteur']);
        self::assertSame(0.0, $graphique['points'][0]['hauteur']);
    }

    public function testLeGraphiqueParMoisCouvreLesSixDerniersMois(): void
    {
        $tableau = $this->dashboard->build([], 4, $this->now);

        self::assertSame(['Juin', 'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.'], array_column($tableau['reservationsParMois']['points'], 'libelle'));
        self::assertSame([40, 30, 20, 10, 0], $tableau['reservationsParMois']['graduations']);
    }
}
