<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl\Queries;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\PhpVo\ValueObjects\DateTimeVO;

/**
 * Query pour rechercher des logs sur une plage de dates
 *
 * @author Andy Defer
 */
final class TemporalLogQueryRecord extends AbstractRecord
{
    public function __construct(
        public readonly DateTimeVO $from,
        public readonly DateTimeVO $to,
        public readonly ?string $type = null,
        public readonly ?string $level = null,
    ) {}
}
