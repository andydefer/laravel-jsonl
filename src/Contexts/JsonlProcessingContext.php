<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl\Contexts;

use AndyDefer\DomainStructures\Collections\Utility\IntTypedCollection;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\LaravelJsonl\Enums\OperationType;
use AndyDefer\PhpVo\ValueObjects\DateTimeVO;

final class JsonlProcessingContext
{
    private OperationType $currentOperation;

    private StringTypedCollection $processedFiles;

    private IntTypedCollection $writtenLines;

    private ?string $lastError;

    private int $totalLinesProcessed;

    private float $startTime;

    private ?float $endTime;

    public function __construct()
    {
        $this->currentOperation = OperationType::IDLE;
        $this->processedFiles = new StringTypedCollection;
        $this->writtenLines = new IntTypedCollection;
        $this->lastError = null;
        $this->totalLinesProcessed = 0;
        $this->startTime = microtime(true);
        $this->endTime = null;
    }

    // Getters
    public function getCurrentOperation(): OperationType
    {
        return $this->currentOperation;
    }

    public function getProcessedFiles(): StringTypedCollection
    {
        return $this->processedFiles;
    }

    public function getWrittenLines(): IntTypedCollection
    {
        return $this->writtenLines;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getTotalLinesProcessed(): int
    {
        return $this->totalLinesProcessed;
    }

    public function getStartTime(): float
    {
        return $this->startTime;
    }

    public function getEndTime(): ?float
    {
        return $this->endTime;
    }

    public function getDuration(): ?float
    {
        return $this->endTime !== null ? $this->endTime - $this->startTime : null;
    }

    public function getStartDateTime(): DateTimeVO
    {
        return new DateTimeVO(date('c', (int) $this->startTime));
    }

    // Setters
    public function setCurrentOperation(OperationType $operation): void
    {
        $this->currentOperation = $operation;
    }

    public function addProcessedFile(string $filePath): void
    {
        $this->processedFiles->add($filePath);
    }

    public function addWrittenLines(string $filePath, int $count): void
    {
        $this->writtenLines->add($count);
        $this->totalLinesProcessed += $count;
    }

    public function setLastError(string $error): void
    {
        $this->lastError = $error;
        $this->currentOperation = OperationType::FAILED;
    }

    public function complete(): void
    {
        $this->endTime = microtime(true);
        $this->currentOperation = OperationType::COMPLETED;
    }

    // Méthodes de question
    public function hasError(): bool
    {
        return $this->lastError !== null;
    }

    public function isCompleted(): bool
    {
        return $this->currentOperation === OperationType::COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->currentOperation === OperationType::FAILED;
    }

    public function isIdle(): bool
    {
        return $this->currentOperation === OperationType::IDLE;
    }
}
