<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl\Tests\Unit\Strategies;

use AndyDefer\DomainStructures\Enums\PhpType;
use AndyDefer\LaravelJsonl\Records\CacheJsonlRecord;
use AndyDefer\LaravelJsonl\Records\CacheKeyQueryRecord;
use AndyDefer\LaravelJsonl\Strategies\KeyBasedPathStrategy;
use AndyDefer\LaravelJsonl\Tests\Fixtures\Records\InvalidRecordFixture;
use AndyDefer\LaravelJsonl\Tests\UnitTestCase;
use AndyDefer\PhpVo\ValueObjects\DateTimeVO;

final class KeyBasedPathStrategyTest extends UnitTestCase
{
    private const BASE_PATH = '/test/cache';

    private const HASH_LEVELS = 2;

    public function test_get_file_path_returns_correct_path_for_cache_record(): void
    {
        // Arrange
        $strategy = new KeyBasedPathStrategy(self::BASE_PATH, self::HASH_LEVELS);
        $record = new CacheJsonlRecord(
            key: 'user_123',
            value: '{"name":"John"}',
            value_type: PhpType::STRING,
            expires_at: null,
        );

        // Act
        $filePath = $strategy->getFilePath($record);

        // Assert
        $expectedPattern = '#^/test/cache/[a-f0-9]/[a-f0-9]/user_123\.jsonl$#';
        $this->assertMatchesRegularExpression($expectedPattern, $filePath);
        $this->assertStringEndsWith('.jsonl', $filePath);
        $this->assertStringContainsString('user_123', $filePath);
    }

    public function test_get_file_path_creates_two_hash_levels_by_default(): void
    {
        // Arrange
        $strategy = new KeyBasedPathStrategy(self::BASE_PATH);
        $record = new CacheJsonlRecord(
            key: 'session_abc',
            value: '',
            value_type: PhpType::STRING,
            expires_at: null,
        );

        // Act
        $filePath = $strategy->getFilePath($record);

        // Assert - Le chemin doit contenir 2 niveaux de hash
        $pathParts = explode(DIRECTORY_SEPARATOR, $filePath);
        $hashLevels = array_slice($pathParts, -3, 2);

        $this->assertCount(2, $hashLevels);
        $this->assertEquals(1, strlen($hashLevels[0]));
        $this->assertEquals(1, strlen($hashLevels[1]));
        $this->assertMatchesRegularExpression('/^[0-9a-f]$/', $hashLevels[0]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]$/', $hashLevels[1]);
    }

    public function test_get_file_path_creates_four_hash_levels_when_configured(): void
    {
        // Arrange
        $strategy = new KeyBasedPathStrategy(self::BASE_PATH, 4);
        $record = new CacheJsonlRecord(
            key: 'user_456',
            value: '',
            value_type: PhpType::STRING,
            expires_at: null,
        );

        // Act
        $filePath = $strategy->getFilePath($record);

        // Assert - Le chemin doit contenir 4 niveaux de hash
        $pathParts = explode(DIRECTORY_SEPARATOR, $filePath);
        $hashLevels = array_slice($pathParts, -5, 4);

        $this->assertCount(4, $hashLevels);
        foreach ($hashLevels as $level) {
            $this->assertEquals(1, strlen($level));
            $this->assertMatchesRegularExpression('/^[0-9a-f]$/', $level);
        }
    }

    public function test_get_file_path_replaces_dangerous_characters_in_key(): void
    {
        // Arrange
        $strategy = new KeyBasedPathStrategy(self::BASE_PATH, self::HASH_LEVELS);
        $record = new CacheJsonlRecord(
            key: 'user/with/slashes?and&special@chars',
            value: '',
            value_type: PhpType::STRING,
            expires_at: null,
        );

        // Act
        $filePath = $strategy->getFilePath($record);

        // Assert
        // Vérifier que les caractères dangereux sont remplacés par _
        $this->assertStringContainsString('user_with_slashes_and_special_chars.jsonl', $filePath);
        // Vérifier qu'il n'y a pas de / dans le nom du fichier (mais le chemin en contient forcément)
        $fileName = basename($filePath);
        $this->assertStringNotContainsString('/', $fileName);
        $this->assertStringNotContainsString('?', $fileName);
        $this->assertStringNotContainsString('&', $fileName);
        $this->assertStringNotContainsString('@', $fileName);
    }

    public function test_get_file_path_sanitizes_key_with_unicode_characters(): void
    {
        // Arrange
        $strategy = new KeyBasedPathStrategy(self::BASE_PATH, self::HASH_LEVELS);
        $record = new CacheJsonlRecord(
            key: 'user_éàç_€',
            value: '',
            value_type: PhpType::STRING,
            expires_at: null,
        );

        // Act
        $filePath = $strategy->getFilePath($record);

        // Assert
        $fileName = basename($filePath);
        // Le nombre de _ peut varier selon le nombre de caractères Unicode
        $this->assertMatchesRegularExpression('/user_.+\.jsonl/', $fileName);
        $this->assertStringEndsWith('.jsonl', $fileName);
    }

    public function test_get_file_path_preserves_allowed_characters_in_key(): void
    {
        // Arrange
        $strategy = new KeyBasedPathStrategy(self::BASE_PATH, self::HASH_LEVELS);
        $record = new CacheJsonlRecord(
            key: 'user-123_abc.def',
            value: '',
            value_type: PhpType::STRING,
            expires_at: null,
        );

        // Act
        $filePath = $strategy->getFilePath($record);

        // Assert
        $this->assertStringContainsString('user-123_abc.def.jsonl', $filePath);
    }

    public function test_get_files_to_scan_returns_single_file_path_for_cache_key_query(): void
    {
        // Arrange
        $strategy = new KeyBasedPathStrategy(self::BASE_PATH, self::HASH_LEVELS);
        $query = new CacheKeyQueryRecord(key: 'user_123');

        // Act
        $files = $strategy->getFilesToScan($query);

        // Assert
        $this->assertCount(1, $files);
        $this->assertStringContainsString('user_123.jsonl', $files[0]);
        $this->assertStringStartsWith(self::BASE_PATH, $files[0]);
    }

    public function test_get_files_to_scan_returns_different_paths_for_different_keys(): void
    {
        // Arrange
        $strategy = new KeyBasedPathStrategy(self::BASE_PATH, self::HASH_LEVELS);
        $query1 = new CacheKeyQueryRecord(key: 'user_123');
        $query2 = new CacheKeyQueryRecord(key: 'user_456');

        // Act
        $file1 = $strategy->getFilesToScan($query1)[0];
        $file2 = $strategy->getFilesToScan($query2)[0];

        // Assert
        $this->assertNotSame($file1, $file2);
    }

    public function test_get_file_path_throws_exception_for_invalid_entity_type(): void
    {
        // Arrange
        $strategy = new KeyBasedPathStrategy(self::BASE_PATH, self::HASH_LEVELS);

        // Utilisation de la fixture (pas de mock)
        $invalidEntity = new InvalidRecordFixture;

        // Expect
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('KeyBasedPathStrategy expects CacheJsonlRecord');

        // Act
        $strategy->getFilePath($invalidEntity);
    }

    public function test_get_files_to_scan_throws_exception_for_invalid_query_type(): void
    {
        // Arrange
        $strategy = new KeyBasedPathStrategy(self::BASE_PATH, self::HASH_LEVELS);

        // Utilisation de la fixture (pas de classe anonyme)
        $invalidQuery = new InvalidRecordFixture;

        // Expect
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('KeyBasedPathStrategy expects CacheKeyQuery');

        // Act
        $strategy->getFilesToScan($invalidQuery);
    }

    public function test_get_base_directory_returns_configured_base_path(): void
    {
        // Arrange
        $strategy = new KeyBasedPathStrategy(self::BASE_PATH, self::HASH_LEVELS);

        // Act
        $baseDirectory = $strategy->getBaseDirectory();

        // Assert
        $this->assertSame(self::BASE_PATH, $baseDirectory);
    }

    public function test_same_key_always_returns_same_file_path(): void
    {
        // Arrange
        $strategy = new KeyBasedPathStrategy(self::BASE_PATH, self::HASH_LEVELS);
        $record1 = new CacheJsonlRecord(
            key: 'consistent_key',
            value: '',
            value_type: PhpType::STRING,
            expires_at: null,
        );
        $record2 = new CacheJsonlRecord(
            key: 'consistent_key',
            value: 'different_value',
            value_type: PhpType::STRING,
            expires_at: null,
        );

        // Act
        $path1 = $strategy->getFilePath($record1);
        $path2 = $strategy->getFilePath($record2);

        // Assert
        $this->assertSame($path1, $path2);
    }

    public function test_different_keys_return_different_paths(): void
    {
        // Arrange
        $strategy = new KeyBasedPathStrategy(self::BASE_PATH, self::HASH_LEVELS);
        $record1 = new CacheJsonlRecord(
            key: 'key_one',
            value: '',
            value_type: PhpType::STRING,
            expires_at: null,
        );
        $record2 = new CacheJsonlRecord(
            key: 'key_two',
            value: '',
            value_type: PhpType::STRING,
            expires_at: null,
        );

        // Act
        $path1 = $strategy->getFilePath($record1);
        $path2 = $strategy->getFilePath($record2);

        // Assert
        $this->assertNotSame($path1, $path2);
    }

    public function test_file_path_always_ends_with_jsonl_extension(): void
    {
        // Arrange
        $strategy = new KeyBasedPathStrategy(self::BASE_PATH, self::HASH_LEVELS);
        $keys = ['a', 'long_key_name', 'key-with-dashes', 'key_with_underscores', 'key.with.dots'];

        foreach ($keys as $key) {
            $record = new CacheJsonlRecord(
                key: $key,
                value: '',
                value_type: PhpType::STRING,
                expires_at: null,
            );

            // Act
            $filePath = $strategy->getFilePath($record);

            // Assert
            $this->assertStringEndsWith('.jsonl', $filePath);
        }
    }

    public function test_file_path_contains_directory_separator(): void
    {
        // Arrange
        $strategy = new KeyBasedPathStrategy(self::BASE_PATH, self::HASH_LEVELS);
        $record = new CacheJsonlRecord(
            key: 'test_key',
            value: '',
            value_type: PhpType::STRING,
            expires_at: null,
        );

        // Act
        $filePath = $strategy->getFilePath($record);

        // Assert
        $this->assertStringContainsString(DIRECTORY_SEPARATOR, $filePath);
    }

    public function test_get_file_path_with_null_expires_at_works_correctly(): void
    {
        // Arrange
        $strategy = new KeyBasedPathStrategy(self::BASE_PATH, self::HASH_LEVELS);
        $record = new CacheJsonlRecord(
            key: 'test_key',
            value: '',
            value_type: PhpType::STRING,
            expires_at: null,
        );

        // Act & Assert - Ne doit pas lever d'exception
        $filePath = $strategy->getFilePath($record);
        $this->assertIsString($filePath);
        $this->assertNotEmpty($filePath);
    }

    public function test_get_file_path_with_expires_at_value_works_correctly(): void
    {
        // Arrange
        $strategy = new KeyBasedPathStrategy(self::BASE_PATH, self::HASH_LEVELS);
        $record = new CacheJsonlRecord(
            key: 'test_key',
            value: '',
            value_type: PhpType::STRING,
            expires_at: new DateTimeVO('+1 hour'),
        );

        // Act & Assert - Ne doit pas lever d'exception
        $filePath = $strategy->getFilePath($record);
        $this->assertIsString($filePath);
        $this->assertNotEmpty($filePath);
    }
}
