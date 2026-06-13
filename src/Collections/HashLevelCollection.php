<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl\Collections;

use AndyDefer\DomainStructures\Abstracts\AbstractTypedCollection;
use AndyDefer\LaravelJsonl\Enums\HashLevel;

final class HashLevelCollection extends AbstractTypedCollection
{
    public function __construct()
    {
        parent::__construct(HashLevel::class);
    }

    public function toPathString(string $separator = DIRECTORY_SEPARATOR): string
    {
        $parts = [];
        foreach ($this->items as $level) {
            $parts[] = $level->getValue();
        }

        return implode($separator, $parts);
    }
}
