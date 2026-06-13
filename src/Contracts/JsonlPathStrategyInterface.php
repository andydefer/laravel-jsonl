<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl\Contracts;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;

/**
 * Interface pour la stratégie de chemin de fichiers JSONL
 *
 * L'utilisateur du package doit implémenter cette interface pour définir
 * sa propre logique d'organisation des fichiers.
 *
 * @author Andy Defer
 */
interface JsonlPathStrategyInterface
{
    /**
     * Détermine le chemin du fichier pour une entité
     *
     * @param  AbstractRecord  $entity  L'entité à stocker (LogRecord, CacheRecord, etc.)
     * @return string Chemin absolu du fichier
     *
     * @throws \InvalidArgumentException Si l'entité n'est pas du type attendu
     */
    public function getFilePath(AbstractRecord $entity): string;

    /**
     * Retourne les fichiers à scanner pour une requête
     *
     * @param  AbstractRecord  $query  La requête (LogQueryRecord, CacheKey, etc.)
     * @return array<string> Liste des chemins à scanner
     *
     * @throws \InvalidArgumentException Si la requête n'est pas du type attendu
     */
    public function getFilesToScan(AbstractRecord $query): array;

    /**
     * Retourne le répertoire de base
     *
     * @return string Répertoire racine
     */
    public function getBaseDirectory(): string;
}
