<?php

namespace App\Tests\Functional;

use App\Entity\Category;
use App\Entity\Post;
use App\Repository\PostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Pages des articles (/posts, /post/{id}) et requêtes du dépôt, sur une base
 * SQLite recréée à chaque test (voir DATABASE_URL dans .env.test).
 */
final class PostControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $schema = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }

    private function categorie(string $nom): Category
    {
        $categorie = (new Category())->setName($nom);
        $this->em->persist($categorie);

        return $categorie;
    }

    /** Article créé il y a $joursAvant jours (le plus petit est le plus récent). */
    private function article(string $titre, int $joursAvant, ?Category $categorie = null, bool $aLaUne = false): Post
    {
        $post = (new Post())
            ->setTitle($titre)
            ->setExcerpt("Résumé de $titre")
            ->setContent("<p>Contenu de $titre</p>")
            ->setImage('/images/articles/article-angers.webp')
            ->setReadTime(4)
            ->setIsFeatured($aLaUne)
            ->setCategory($categorie)
            ->setCreatedAt(new \DateTimeImmutable("-$joursAvant days"));
        $this->em->persist($post);

        return $post;
    }

    /** @return list<string> */
    private static function titresDeLaListe(Crawler $crawler): array
    {
        return $crawler->filter('.bz-articles .bz-article__titre')->each(fn (Crawler $n) => $n->text());
    }

    public function testSansArticleLaPageLeDit(): void
    {
        $this->client->request('GET', '/posts');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.bz-blog__vide', 'Aucun article pour le moment');
        self::assertSelectorNotExists('.bz-article--une');
    }

    public function testLArticleALaUneEstEnGrandEtPasRepeteDansLaListe(): void
    {
        $this->article('Récent', 1);
        $this->article('À la une', 10, null, true);
        $this->article('Ancien', 20);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/posts');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.bz-article--une .bz-article__titre', 'À la une');
        self::assertSame(['Récent', 'Ancien'], self::titresDeLaListe($crawler));
    }

    public function testSansArticleALaUneLePlusRecentPrendLaGrandeCarte(): void
    {
        $this->article('Ancien', 20);
        $this->article('Récent', 1);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/posts');

        self::assertSelectorTextContains('.bz-article--une .bz-article__titre', 'Récent');
        self::assertSame(['Ancien'], self::titresDeLaListe($crawler));
    }

    public function testFiltrerParCategorie(): void
    {
        $conseils = $this->categorie('Conseils');
        $angers = $this->categorie('Angers');
        $this->article('Conseil récent', 1, $conseils);
        $this->article('Article sur Angers', 2, $angers, true);
        $this->article('Conseil ancien', 3, $conseils);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/posts?category='.$conseils->getId());

        // Dans une catégorie, la grande carte est le plus récent de la catégorie.
        self::assertSelectorTextContains('.bz-article--une .bz-article__titre', 'Conseil récent');
        self::assertSame(['Conseil ancien'], self::titresDeLaListe($crawler));
        self::assertSelectorTextContains('.bz-filtre--actif', 'Conseils');
    }

    public function testUneCategorieVideAfficheLeMessage(): void
    {
        $vide = $this->categorie('Vide');
        $this->article('Ailleurs', 1, $this->categorie('Autre'));
        $this->em->flush();

        $this->client->request('GET', '/posts?category='.$vide->getId());

        self::assertSelectorTextContains('.bz-blog__vide', 'Aucun article');
    }

    public function testVoirPlusAfficheQuatreArticlesDePlus(): void
    {
        $this->article('À la une', 0, null, true);
        for ($i = 1; $i <= 9; ++$i) {
            $this->article("Article $i", $i);
        }
        $this->em->flush();

        $crawler = $this->client->request('GET', '/posts');
        self::assertSame(['Article 1', 'Article 2', 'Article 3', 'Article 4'], self::titresDeLaListe($crawler));
        $lien = $crawler->filter('.bz-blog__plus');
        self::assertCount(1, $lien);
        self::assertStringContainsString('page=2', $lien->attr('href'));

        $crawler = $this->client->request('GET', '/posts?page=2');
        self::assertCount(8, self::titresDeLaListe($crawler));

        // Page 3 : les 9 articles, plus de lien « Voir plus ».
        $crawler = $this->client->request('GET', '/posts?page=3');
        self::assertCount(9, self::titresDeLaListe($crawler));
        self::assertSelectorNotExists('.bz-blog__plus');
    }

    public function testUnNumeroDePageInvalideRevientALaPremiere(): void
    {
        for ($i = 1; $i <= 6; ++$i) {
            $this->article("Article $i", $i);
        }
        $this->em->flush();

        $crawler = $this->client->request('GET', '/posts?page=-4');

        self::assertResponseIsSuccessful();
        self::assertCount(4, self::titresDeLaListe($crawler));
    }

    public function testLaPageDUnArticle(): void
    {
        $post = $this->article('Se faire des amis à Angers', 1, $this->categorie('Conseils'));
        $this->em->flush();

        $this->client->request('GET', '/post/'.$post->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Se faire des amis à Angers');
        self::assertSelectorTextContains('body', 'Contenu de Se faire des amis à Angers');
        self::assertSelectorTextContains('body', 'Conseils');
    }

    public function testUnArticleInexistantRenvoieUne404(): void
    {
        $this->client->request('GET', '/post/999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testLeDepotTrouveLArticleALaUneLePlusRecent(): void
    {
        $this->article('Ancienne une', 10, null, true);
        $this->article('Nouvelle une', 2, null, true);
        $this->article('Normal', 1);
        $this->em->flush();

        $repository = static::getContainer()->get(PostRepository::class);

        self::assertSame('Nouvelle une', $repository->findFeatured()?->getTitle());
    }

    public function testLeDepotListeLesDerniersArticlesHorsUne(): void
    {
        $this->article('Une', 0, null, true);
        for ($i = 1; $i <= 5; ++$i) {
            $this->article("Article $i", $i);
        }
        $this->em->flush();

        $repository = static::getContainer()->get(PostRepository::class);

        self::assertSame(['Article 1', 'Article 2', 'Article 3'], array_map(fn (Post $p) => $p->getTitle(), $repository->findLatest()));
        self::assertCount(5, $repository->findLatest(10));
    }

    public function testLeDepotNeTrouveAucuneUneQuandIlNyEnAPas(): void
    {
        $this->article('Normal', 1);
        $this->em->flush();

        self::assertNull(static::getContainer()->get(PostRepository::class)->findFeatured());
    }
}

