<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl\Config;

use AndyDefer\PhpServices\Enums\PermissionMode;

/**
 * Interface pour la configuration du package JSONL
 *
 * @author Andy Defer
 */
interface JsonlConfigInterface
{
    /**
     * Chemin de base pour stocker les fichiers JSONL
     */
    public function basePath(): string;

    /**
     * Taille du buffer d'écriture (nombre de lignes avant écriture disque)
     * Retourne null si le buffer est désactivé
     */
    public function bufferSize(): ?int;

    /**
     * Permissions des dossiers créés
     */
    public function directoryPermission(): PermissionMode;

    /**
     * Vérifie si le buffer est activé
     */
    public function isBufferEnabled(): bool;
}
