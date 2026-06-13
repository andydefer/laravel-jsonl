<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\PhpVo\ValueObjects\DateTimeVO;

/**
 * Record pour les logs - PUR DTO, aucune méthode
 *
 * @author Andy Defer
 */
final class LogJsonlRecord extends AbstractRecord
{
    public function __construct(
        public readonly DateTimeVO $time,
        public readonly string $level,
        public readonly string $type,
        public readonly StrictDataObject $payload,
    ) {}
}
