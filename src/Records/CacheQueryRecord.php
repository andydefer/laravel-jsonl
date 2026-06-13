<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl\Queries;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;

/**
 * Query pour rechercher une entrée de cache par sa clé
 *
 * @author Andy Defer
 */
final class CacheKeyQueryRecord extends AbstractRecord
{
    public function __construct(
        public readonly string $key,
    ) {}
}
