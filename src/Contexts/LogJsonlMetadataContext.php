<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl\Contexts;

use AndyDefer\LaravelJsonl\Records\LogJsonlRecord;
use AndyDefer\PhpVo\ValueObjects\DateTimeVO;

final class LogJsonlMetadataContext
{
    private string $key;

    private DateTimeVO $timestamp;

    private string $date;

    private string $hour;

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
}
