<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;
use AndyDefer\DomainStructures\Utils\StrictDataObject;

final class CacheValueVO extends AbstractValueObject
{
    private string $encodedValue;

    public function __construct(StrictDataObject $value)
    {

        $this->encodedValue = json_encode($value->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($this->encodedValue === false) {
            throw new \InvalidArgumentException('Cannot encode value to JSON');
        }
    }

    public function getValue(): StrictDataObject
    {
        $decoded = json_decode($this->encodedValue, true);

        return new StrictDataObject($decoded);
    }

    public function getEncodedValue(): string
    {
        return $this->encodedValue;
    }

    public function toArray(): array
    {
        return [
            'value' => $this->encodedValue,
        ];
    }
}
