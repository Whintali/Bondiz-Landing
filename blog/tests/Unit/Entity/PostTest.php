<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Category;
use App\Entity\Post;
use PHPUnit\Framework\TestCase;

final class PostTest extends TestCase
{
    public function testUnNouvelArticleNEstPasALaUneEtEstDateDuJour(): void
    {
        $avant = new \DateTimeImmutable();
        $post = new Post();

        self::assertFalse($post->isFeatured());
        self::assertGreaterThanOrEqual($avant, $post->getCreatedAt());
        self::assertNull($post->getId());
        self::assertNull($post->getCategory());
    }

    public function testLesChampsDeLArticle(): void
    {
        $date = new \DateTimeImmutable('2026-10-01 10:00');
        $post = (new Post())
            ->setTitle('Se faire des amis à Angers')
            ->setExcerpt('Nos conseils')
            ->setContent('<p>Contenu</p>')
            ->setImage('/images/articles/angers.webp')
            ->setReadTime(5)
            ->setIsFeatured(true)
            ->setCreatedAt($date);

        self::assertSame('Se faire des amis à Angers', $post->getTitle());
        self::assertSame('Nos conseils', $post->getExcerpt());
        self::assertSame('<p>Contenu</p>', $post->getContent());
        self::assertSame('/images/articles/angers.webp', $post->getImage());
        self::assertSame(5, $post->getReadTime());
        self::assertTrue($post->isFeatured());
        self::assertSame($date, $post->getCreatedAt());
    }

    public function testAjouterUnArticleAUneCategorieRelieLesDeuxCotes(): void
    {
        $categorie = (new Category())->setName('Conseils');
        $post = new Post();

        $categorie->addPost($post);
        $categorie->addPost($post);

        self::assertSame($categorie, $post->getCategory());
        self::assertCount(1, $categorie->getPosts());
    }

    public function testRetirerUnArticleDeSaCategorie(): void
    {
        $categorie = (new Category())->setName('Conseils');
        $post = new Post();
        $categorie->addPost($post);

        $categorie->removePost($post);

        self::assertNull($post->getCategory());
        self::assertCount(0, $categorie->getPosts());
    }

    public function testRetirerUnArticleDejaDeplaceNeTouchePasSaNouvelleCategorie(): void
    {
        $ancienne = (new Category())->setName('Ancienne');
        $nouvelle = (new Category())->setName('Nouvelle');
        $post = new Post();
        $ancienne->addPost($post);
        $post->setCategory($nouvelle);

        $ancienne->removePost($post);

        self::assertSame($nouvelle, $post->getCategory());
    }

    public function testUneCategorieSAfficheParSonNom(): void
    {
        self::assertSame('Conseils', (string) (new Category())->setName('Conseils'));
        self::assertSame('', (string) new Category());
    }
}
