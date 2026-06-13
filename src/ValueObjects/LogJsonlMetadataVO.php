<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;
use AndyDefer\LaravelJsonl\Records\LogJsonlRecord;
use AndyDefer\PhpVo\ValueObjects\DateTimeVO;

/**
 * Métadonnées extraites d'un LogJsonlRecord
 *
 * @author Andy Defer
 */
final class LogJsonlMetadataVO extends AbstractValueObject
{
    private readonly string $key;

    private readonly DateTimeVO $timestamp;

    private readonly string $date;

    private readonly string $hour;

    public function __construct(LogJsonlRecord $record)
    {
        $this->key = $record->time->getValue().':'.$record->type;
        $this->timestamp = $record->time;
        $this->date = $record->time->format('Y-m-d');
        $this->hour = $record->time->format('H');
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getTimestamp(): DateTimeVO
    {
        return $this->timestamp;
    }

    public function getDate(): string
    {
        return $this->date;
    }

    public function getHour(): string
    {
        return $this->hour;
    }

    public function getValue(): string
    {
        return $this->key;
    }
}
