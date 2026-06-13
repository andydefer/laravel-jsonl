<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;

final class SafeKeyVO extends AbstractValueObject
{
    private string $value;

    public function __construct(string $key)
    {
        $this->value = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $key);
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
