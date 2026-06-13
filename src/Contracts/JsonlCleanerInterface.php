<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl\Contracts;

interface JsonlCleanerInterface
{
    /**
     * Nettoie les fichiers plus vieux que X jours
     *
     * @param  int  $days  Âge maximum en jours
     * @param  string  $basePath  Chemin de base
     * @return int Nombre de fichiers supprimés
     */
    public function cleanOlderThan(int $days, string $basePath): int;

    /**
     * Nettoie les entrées expirées (pour le cache)
     *
     * @param  string  $basePath  Chemin de base
     * @param  callable(array<string, mixed> $line): bool  $isExpired
     * @return int Nombre d'entrées supprimées
     */
    public function cleanExpired(string $basePath, callable $isExpired): int;

    /**
     * Nettoie les fichiers correspondant à un pattern
     *
     * @param  string  $pattern  Pattern glob
     * @return int Nombre de fichiers supprimés
     */
    public function cleanByPattern(string $pattern): int;

    /**
     * Simulation de nettoyage (dry run)
     *
     * @param  string  $basePath  Chemin de base
     * @param  callable(string $filePath): bool  $filter
     * @return array<string> Liste des fichiers qui seraient supprimés
     */
    public function dryRun(string $basePath, callable $filter): array;

    /**
     * Vide complètement un répertoire
     *
     * @param  string  $basePath  Chemin de base
     * @return int Nombre de fichiers supprimés
     */
    public function clear(string $basePath): int;
}
