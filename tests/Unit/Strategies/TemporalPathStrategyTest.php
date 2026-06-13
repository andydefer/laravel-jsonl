<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl\Tests\Unit\Strategies;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\LaravelJsonl\Records\LogJsonlRecord;
use AndyDefer\LaravelJsonl\Records\TemporalLogQueryRecord;
use AndyDefer\LaravelJsonl\Strategies\TemporalPathStrategy;
use AndyDefer\LaravelJsonl\Tests\Fixtures\Records\InvalidRecordFixture;
use AndyDefer\LaravelJsonl\Tests\UnitTestCase;
use AndyDefer\PhpVo\ValueObjects\DateTimeVO;

final class TemporalPathStrategyTest extends UnitTestCase
{
    private const BASE_PATH = '/test/logs/structured';

    public function test_get_file_path_returns_correct_path_for_log_record(): void
    {
        // Arrange
        $strategy = new TemporalPathStrategy(self::BASE_PATH);
        $record = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
            level: 'info',
            type: 'user_login',
            payload: new StrictDataObject(['user_id' => 123]),
        );

        // Act
        $filePath = $strategy->getFilePath($record);

        // Assert
        $expected = implode(DIRECTORY_SEPARATOR, [
            self::BASE_PATH,
            '2026-01-15',
            '14.jsonl',
        ]);
        $this->assertSame($expected, $filePath);
    }

    public function test_get_file_path_returns_correct_path_for_log_record_with_different_timezone(): void
    {
        // Arrange
        $strategy = new TemporalPathStrategy(self::BASE_PATH);
        $record = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T09:35:00+02:00'),
            level: 'info',
            type: 'user_login',
            payload: new StrictDataObject(['user_id' => 123]),
        );

        // Act
        $filePath = $strategy->getFilePath($record);

        // Assert - Le format utilise le fuseau horaire d'origine
        $expected = implode(DIRECTORY_SEPARATOR, [
            self::BASE_PATH,
            '2026-01-15',
            '09.jsonl',
        ]);
        $this->assertSame($expected, $filePath);
    }

    public function test_get_file_path_returns_correct_path_for_log_record_at_midnight(): void
    {
        // Arrange
        $strategy = new TemporalPathStrategy(self::BASE_PATH);
        $record = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T00:00:00+00:00'),
            level: 'info',
            type: 'system_start',
            payload: new StrictDataObject(['message' => 'Service started']),
        );

        // Act
        $filePath = $strategy->getFilePath($record);

        // Assert
        $expected = implode(DIRECTORY_SEPARATOR, [
            self::BASE_PATH,
            '2026-01-15',
            '00.jsonl',
        ]);
        $this->assertSame($expected, $filePath);
    }

    public function test_get_file_path_returns_correct_path_for_log_record_at_end_of_day(): void
    {
        // Arrange
        $strategy = new TemporalPathStrategy(self::BASE_PATH);
        $record = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T23:59:59+00:00'),
            level: 'info',
            type: 'system_shutdown',
            payload: new StrictDataObject(['message' => 'Service stopped']),
        );

        // Act
        $filePath = $strategy->getFilePath($record);

        // Assert
        $expected = implode(DIRECTORY_SEPARATOR, [
            self::BASE_PATH,
            '2026-01-15',
            '23.jsonl',
        ]);
        $this->assertSame($expected, $filePath);
    }

    public function test_get_file_path_throws_exception_for_invalid_entity_type(): void
    {
        // Arrange
        $strategy = new TemporalPathStrategy(self::BASE_PATH);

        // Utilisation de la fixture
        $invalidEntity = new InvalidRecordFixture;

        // Expect
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TemporalPathStrategy expects LogJsonlRecord');

        // Act
        $strategy->getFilePath($invalidEntity);
    }

    public function test_get_files_to_scan_returns_24_files_for_single_day(): void
    {
        // Arrange
        $strategy = new TemporalPathStrategy(self::BASE_PATH);
        $query = new TemporalLogQueryRecord(
            from: new DateTimeVO('2026-01-15T00:00:00+00:00'),
            to: new DateTimeVO('2026-01-15T23:59:59+00:00'),
        );

        // Act
        $files = $strategy->getFilesToScan($query);

        // Assert
        $this->assertCount(24, $files);

        // Vérifier les heures de 00 à 23
        for ($hour = 0; $hour <= 23; $hour++) {
            $hourStr = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);
            $expected = implode(DIRECTORY_SEPARATOR, [
                self::BASE_PATH,
                '2026-01-15',
                $hourStr.'.jsonl',
            ]);
            $this->assertContains($expected, $files);
        }
    }

    public function test_get_files_to_scan_returns_48_files_for_two_days(): void
    {
        // Arrange
        $strategy = new TemporalPathStrategy(self::BASE_PATH);
        $query = new TemporalLogQueryRecord(
            from: new DateTimeVO('2026-01-15T00:00:00+00:00'),
            to: new DateTimeVO('2026-01-16T23:59:59+00:00'),
        );

        // Act
        $files = $strategy->getFilesToScan($query);

        // Assert
        $this->assertCount(48, $files); // 24 heures × 2 jours

        // Vérifier les fichiers du 15
        for ($hour = 0; $hour <= 23; $hour++) {
            $hourStr = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);
            $expected = implode(DIRECTORY_SEPARATOR, [
                self::BASE_PATH,
                '2026-01-15',
                $hourStr.'.jsonl',
            ]);
            $this->assertContains($expected, $files);
        }

        // Vérifier les fichiers du 16
        for ($hour = 0; $hour <= 23; $hour++) {
            $hourStr = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);
            $expected = implode(DIRECTORY_SEPARATOR, [
                self::BASE_PATH,
                '2026-01-16',
                $hourStr.'.jsonl',
            ]);
            $this->assertContains($expected, $files);
        }
    }

    public function test_get_files_to_scan_handles_cross_month_range(): void
    {
        // Arrange
        $strategy = new TemporalPathStrategy(self::BASE_PATH);
        $query = new TemporalLogQueryRecord(
            from: new DateTimeVO('2026-01-31T00:00:00+00:00'),
            to: new DateTimeVO('2026-02-01T23:59:59+00:00'),
        );

        // Act
        $files = $strategy->getFilesToScan($query);

        // Assert
        $this->assertCount(48, $files); // 24 heures × 2 jours

        // Vérifier les fichiers du 31 janvier
        for ($hour = 0; $hour <= 23; $hour++) {
            $hourStr = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);
            $expected = implode(DIRECTORY_SEPARATOR, [
                self::BASE_PATH,
                '2026-01-31',
                $hourStr.'.jsonl',
            ]);
            $this->assertContains($expected, $files);
        }

        // Vérifier les fichiers du 1er février
        for ($hour = 0; $hour <= 23; $hour++) {
            $hourStr = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);
            $expected = implode(DIRECTORY_SEPARATOR, [
                self::BASE_PATH,
                '2026-02-01',
                $hourStr.'.jsonl',
            ]);
            $this->assertContains($expected, $files);
        }
    }

    public function test_get_files_to_scan_handles_cross_year_range(): void
    {
        // Arrange
        $strategy = new TemporalPathStrategy(self::BASE_PATH);
        $query = new TemporalLogQueryRecord(
            from: new DateTimeVO('2026-12-31T00:00:00+00:00'),
            to: new DateTimeVO('2027-01-01T23:59:59+00:00'),
        );

        // Act
        $files = $strategy->getFilesToScan($query);

        // Assert
        $this->assertCount(48, $files); // 24 heures × 2 jours

        // Vérifier les fichiers du 31 décembre 2026
        for ($hour = 0; $hour <= 23; $hour++) {
            $hourStr = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);
            $expected = implode(DIRECTORY_SEPARATOR, [
                self::BASE_PATH,
                '2026-12-31',
                $hourStr.'.jsonl',
            ]);
            $this->assertContains($expected, $files);
        }

        // Vérifier les fichiers du 1er janvier 2027
        for ($hour = 0; $hour <= 23; $hour++) {
            $hourStr = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);
            $expected = implode(DIRECTORY_SEPARATOR, [
                self::BASE_PATH,
                '2027-01-01',
                $hourStr.'.jsonl',
            ]);
            $this->assertContains($expected, $files);
        }
    }

    public function test_get_files_to_scan_returns_single_day_when_from_and_to_are_same(): void
    {
        // Arrange
        $strategy = new TemporalPathStrategy(self::BASE_PATH);
        $query = new TemporalLogQueryRecord(
            from: new DateTimeVO('2026-01-15T10:30:00+00:00'),
            to: new DateTimeVO('2026-01-15T14:45:00+00:00'),
        );

        // Act
        $files = $strategy->getFilesToScan($query);

        // Assert
        $this->assertCount(24, $files); // Toutes les heures du jour

        // Vérifier que tous les fichiers sont pour le 15 janvier
        foreach ($files as $file) {
            $this->assertStringContainsString('2026-01-15', $file);
        }
    }

    public function test_get_files_to_scan_throws_exception_for_invalid_query_type(): void
    {
        // Arrange
        $strategy = new TemporalPathStrategy(self::BASE_PATH);

        // Création d'un query invalide (pas TemporalLogQueryRecord)
        $invalidQuery = new class extends AbstractRecord
        {
            public function __construct(
                public readonly string $from = '2026-01-15',
                public readonly string $to = '2026-01-16',
            ) {}
        };

        // Expect
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TemporalPathStrategy expects TemporalLogQuery');

        // Act
        $strategy->getFilesToScan($invalidQuery);
    }

    public function test_get_files_to_scan_throws_exception_when_query_has_invalid_properties(): void
    {
        // Arrange
        $strategy = new TemporalPathStrategy(self::BASE_PATH);

        // Création d'un query avec des DateTimeVO invalides
        $query = new TemporalLogQueryRecord(
            from: new DateTimeVO('2026-01-15T00:00:00+00:00'),
            to: new DateTimeVO('2026-01-14T23:59:59+00:00'), // from > to
        );

        // Act & Assert - Ne doit pas lever d'exception mais retourner 0 fichier
        $files = $strategy->getFilesToScan($query);

        // La boucle while ne s'exécute pas car from > to
        $this->assertCount(0, $files);
    }

    public function test_get_base_directory_returns_configured_base_path(): void
    {
        // Arrange
        $strategy = new TemporalPathStrategy(self::BASE_PATH);

        // Act
        $baseDirectory = $strategy->getBaseDirectory();

        // Assert
        $this->assertSame(self::BASE_PATH, $baseDirectory);
    }

    public function test_get_file_path_handles_base_path_without_trailing_slash(): void
    {
        // Arrange
        $basePath = '/var/logs/app';
        $strategy = new TemporalPathStrategy($basePath);
        $record = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
            level: 'info',
            type: 'user_login',
            payload: new StrictDataObject(['user_id' => 123]),
        );

        // Act
        $filePath = $strategy->getFilePath($record);

        // Assert
        $expected = implode(DIRECTORY_SEPARATOR, [
            $basePath,
            '2026-01-15',
            '14.jsonl',
        ]);
        $this->assertSame($expected, $filePath);
        $this->assertStringNotContainsString('//', $filePath);
    }

    public function test_get_file_path_handles_base_path_with_trailing_slash(): void
    {
        // Arrange
        $basePath = '/var/logs/app/';
        $strategy = new TemporalPathStrategy($basePath);
        $record = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
            level: 'info',
            type: 'user_login',
            payload: new StrictDataObject(['user_id' => 123]),
        );

        // Act
        $filePath = $strategy->getFilePath($record);

        // Assert
        $expected = implode(DIRECTORY_SEPARATOR, [
            rtrim($basePath, DIRECTORY_SEPARATOR),
            '2026-01-15',
            '14.jsonl',
        ]);
        $this->assertSame($expected, $filePath);
        $this->assertStringNotContainsString('//', $filePath);
    }

    public function test_get_files_to_scan_uses_directory_separator_constant(): void
    {
        // Arrange
        $strategy = new TemporalPathStrategy(self::BASE_PATH);
        $query = new TemporalLogQueryRecord(
            from: new DateTimeVO('2026-01-15T00:00:00+00:00'),
            to: new DateTimeVO('2026-01-15T23:59:59+00:00'),
        );

        // Act
        $files = $strategy->getFilesToScan($query);

        // Assert
        foreach ($files as $file) {
            $this->assertStringContainsString(DIRECTORY_SEPARATOR, $file);
        }
    }

    public function test_get_files_to_scan_returns_hourly_files_correctly_ordered(): void
    {
        // Arrange
        $strategy = new TemporalPathStrategy(self::BASE_PATH);
        $query = new TemporalLogQueryRecord(
            from: new DateTimeVO('2026-01-15T00:00:00+00:00'),
            to: new DateTimeVO('2026-01-15T23:59:59+00:00'),
        );

        // Act
        $files = $strategy->getFilesToScan($query);

        // Assert - Vérifier l'ordre des heures
        for ($hour = 0; $hour <= 23; $hour++) {
            $hourStr = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);
            $expected = implode(DIRECTORY_SEPARATOR, [
                self::BASE_PATH,
                '2026-01-15',
                $hourStr.'.jsonl',
            ]);
            $this->assertSame($expected, $files[$hour]);
        }
    }

    public function test_same_log_record_always_returns_same_file_path(): void
    {
        // Arrange
        $strategy = new TemporalPathStrategy(self::BASE_PATH);
        $record = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
            level: 'info',
            type: 'user_login',
            payload: new StrictDataObject(['user_id' => 123]),
        );

        // Act
        $path1 = $strategy->getFilePath($record);
        $path2 = $strategy->getFilePath($record);

        // Assert
        $this->assertSame($path1, $path2);
    }

    public function test_different_hours_return_different_file_paths(): void
    {
        // Arrange
        $strategy = new TemporalPathStrategy(self::BASE_PATH);
        $recordMorning = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T08:00:00+00:00'),
            level: 'info',
            type: 'event',
            payload: new StrictDataObject,
        );
        $recordEvening = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T20:00:00+00:00'),
            level: 'info',
            type: 'event',
            payload: new StrictDataObject,
        );

        // Act
        $pathMorning = $strategy->getFilePath($recordMorning);
        $pathEvening = $strategy->getFilePath($recordEvening);

        // Assert
        $this->assertNotSame($pathMorning, $pathEvening);
        $this->assertStringContainsString('08.jsonl', $pathMorning);
        $this->assertStringContainsString('20.jsonl', $pathEvening);
    }

    public function test_different_days_return_different_file_paths(): void
    {
        // Arrange
        $strategy = new TemporalPathStrategy(self::BASE_PATH);
        $recordDay1 = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:00:00+00:00'),
            level: 'info',
            type: 'event',
            payload: new StrictDataObject,
        );
        $recordDay2 = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-16T14:00:00+00:00'),
            level: 'info',
            type: 'event',
            payload: new StrictDataObject,
        );

        // Act
        $pathDay1 = $strategy->getFilePath($recordDay1);
        $pathDay2 = $strategy->getFilePath($recordDay2);

        // Assert
        $this->assertNotSame($pathDay1, $pathDay2);
        $this->assertStringContainsString('2026-01-15', $pathDay1);
        $this->assertStringContainsString('2026-01-16', $pathDay2);
    }
}
