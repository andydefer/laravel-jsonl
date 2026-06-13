<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\PhpVo\ValueObjects\DateTimeVO;

/**
 * Record pour le cache - PUR DTO, aucune méthode
 *
 * @author Andy Defer
 */
final class CacheJsonlRecord extends AbstractRecord
{
    public function __construct(
        public readonly string $key,
        public readonly string $value,
        public readonly ?DateTimeVO $expires_at = null,
    ) {}
}
