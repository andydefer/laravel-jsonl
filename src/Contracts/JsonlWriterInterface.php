<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl\Contracts;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;

interface JsonlWriterInterface
{
    /**
     * Écrit une entité dans un fichier JSONL
     *
     * @param  AbstractRecord  $entity  L'entité à écrire
     * @param  bool  $lock  Utiliser un verrou exclusif
     *
     * @throws JsonlException Si l'écriture échoue
     */
    public function write(AbstractRecord $entity, bool $lock = true): void;

    /**
     * Écrit plusieurs entités en une seule opération
     *
     * @param  array<AbstractRecord>  $entities  Entités à écrire
     * @param  bool  $lock  Utiliser un verrou exclusif
     *
     * @throws JsonlException Si l'écriture échoue
     */
    public function writeBatch(array $entities, bool $lock = true): void;

    /**
     * Écrit une entité avec buffer (accumulation avant écriture)
     *
     * @param  AbstractRecord  $entity  L'entité à écrire
     */
    public function writeBuffered(AbstractRecord $entity): void;

    /**
     * Vide le buffer pour un fichier spécifique ou tous les fichiers
     *
     * @param  string|null  $filePath  Chemin spécifique ou null pour tout vider
     */
    public function flushBuffer(?string $filePath = null): void;

    /**
     * Active le buffer d'écriture
     *
     * @param  int  $size  Nombre d'entités avant écriture automatique
     */
    public function enableBuffer(int $size = 100): void;

    /**
     * Désactive le buffer et vide son contenu
     */
    public function disableBuffer(): void;

    /**
     * Définit un callback exécuté à chaque flush
     *
     * @param  callable(string $filePath, int $count): void  $callback
     */
    public function onFlush(callable $callback): void;
}
