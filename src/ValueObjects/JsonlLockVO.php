<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;

final class JsonlLockVO extends AbstractValueObject
{
    private $handle;

    private string $lockFilePath;

    public function __construct($handle, string $lockFilePath)
    {
        $this->handle = $handle;
        $this->lockFilePath = $lockFilePath;
    }

    public function getHandle()
    {
        return $this->handle;
    }

    public function getLockFilePath(): string
    {
        return $this->lockFilePath;
    }

    public function isAcquired(): bool
    {
        return true;
    }

    public function getValue(): string
    {
        return $this->lockFilePath;
    }
}
