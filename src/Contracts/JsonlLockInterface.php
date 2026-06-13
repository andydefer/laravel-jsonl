<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl\Contracts;

interface JsonlLockInterface
{
    /**
     * Acquiert un verrou exclusif sur un fichier
     *
     * @param  string  $filePath  Chemin du fichier
     * @param  int  $timeout  Timeout en secondes
     * @return bool True si le verrou est acquis
     *
     * @throws JsonlLockException Si timeout dépassé
     */
    public function acquire(string $filePath, int $timeout = 5): bool;

    /**
     * Libère un verrou
     *
     * @param  string  $filePath  Chemin du fichier
     */
    public function release(string $filePath): void;

    /**
     * Exécute une callback avec verrou
     *
     * @param  string  $filePath  Chemin du fichier
     * @param  callable(): mixed  $callback
     * @return mixed Résultat de la callback
     *
     * @throws JsonlLockException Si le verrou ne peut être acquis
     */
    public function executeWithLock(string $filePath, callable $callback): mixed;

    /**
     * Vérifie si un verrou est actif
     *
     * @param  string  $filePath  Chemin du fichier
     * @return bool True si verrouillé
     */
    public function isLocked(string $filePath): bool;
}
