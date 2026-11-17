<?php

namespace App\Tests;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Strapi simulé pour les tests fonctionnels : les réponses sont fixées par
 * « MÉTHODE /chemin », et chaque requête reçue est notée.
 */
final class StrapiSimule
{
    /** @var array<string, MockResponse> */
    public array $reponses = [];

    /** @var list<array{method: string, path: string, body: mixed, headers: array<string, list<string>>}> */
    public array $requetes = [];

    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        $chemin = (string) parse_url($url, \PHP_URL_PATH);
        $this->requetes[] = [
            'method' => $method,
            'path' => $chemin,
            'body' => isset($options['body']) && \is_string($options['body']) ? json_decode($options['body'], true) : null,
            'headers' => $options['normalized_headers'] ?? [],
        ];

        return $this->reponses["$method $chemin"]
            ?? new MockResponse(json_encode(['error' => ['message' => "Route non simulée : $method $chemin"]]), ['http_code' => 404]);
    }

    /** @return list<array{method: string, path: string, body: mixed, headers: array<string, list<string>>}> */
    public function requetes(string $method): array
    {
        return array_values(array_filter($this->requetes, static fn (array $r) => $r['method'] === $method));
    }
}
