<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl\Contracts;

interface JsonlReaderInterface
{
    /**
     * Lit toutes les lignes d'un fichier
     *
     * @param  string  $filePath  Chemin du fichier
     * @return array<array<string, mixed>> Toutes les lignes
     */
    public function readAll(string $filePath): array;

    /**
     * Lit un fichier ligne par ligne (streaming)
     *
     * @param  string  $filePath  Chemin du fichier
     * @param  callable(array<string, mixed> $line): void  $callback
     *
     * @throws JsonlException Si le fichier n'existe pas
     */
    public function readLineByLine(string $filePath, callable $callback): void;

    /**
     * Recherche dans un fichier avec un filtre
     *
     * @param  string  $filePath  Chemin du fichier
     * @param  callable(array<string, mixed> $line): bool  $filter
     * @return array<array<string, mixed>> Lignes qui correspondent
     */
    public function search(string $filePath, callable $filter): array;

    /**
     * Recherche dans plusieurs fichiers
     *
     * @param  array<string>  $filePaths  Liste des chemins
     * @param  callable(array<string, mixed> $line): bool  $filter
     * @return array<array<string, mixed>> Lignes qui correspondent
     */
    public function searchMultiple(array $filePaths, callable $filter): array;

    /**
     * Récupère la dernière ligne d'un fichier
     *
     * @param  string  $filePath  Chemin du fichier
     * @return array<string, mixed>|null Dernière ligne ou null
     */
    public function getLastLine(string $filePath): ?array;

    /**
     * Récupère la première ligne d'un fichier
     *
     * @param  string  $filePath  Chemin du fichier
     * @return array<string, mixed>|null Première ligne ou null
     */
    public function getFirstLine(string $filePath): ?array;
}
