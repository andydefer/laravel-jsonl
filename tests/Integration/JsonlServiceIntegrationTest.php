<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl\Tests\Integration;

use AndyDefer\DomainStructures\Enums\PhpType;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\LaravelJsonl\Contexts\JsonlContext;
use AndyDefer\LaravelJsonl\Exceptions\JsonlLockException;
use AndyDefer\LaravelJsonl\JsonlService;
use AndyDefer\LaravelJsonl\Records\CacheJsonlRecord;
use AndyDefer\LaravelJsonl\Records\LogJsonlRecord;
use AndyDefer\LaravelJsonl\Strategies\KeyBasedPathStrategy;
use AndyDefer\LaravelJsonl\Strategies\TemporalPathStrategy;
use AndyDefer\LaravelJsonl\Tests\IntegrationTestCase;
use AndyDefer\PhpServices\Contracts\FileSystemInterface;
use AndyDefer\PhpServices\Enums\PermissionMode;
use AndyDefer\PhpServices\Services\FileSystemService;
use AndyDefer\PhpVo\ValueObjects\DateTimeVO;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

#[AllowMockObjectsWithoutExpectations]
final class JsonlServiceIntegrationTest extends IntegrationTestCase
{
    private JsonlService $service;

    private FileSystemService $fileSystem;

    private string $tempDir;

    private TemporalPathStrategy $temporalStrategy;

    private KeyBasedPathStrategy $keyBasedStrategy;

    private JsonlContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fileSystem = new FileSystemService;
        $this->tempDir = sys_get_temp_dir().'/jsonl_test_'.uniqid();
        $this->fileSystem->makeDirectory($this->tempDir, PermissionMode::DIRECTORY, true);

        $this->temporalStrategy = new TemporalPathStrategy($this->tempDir);
        $this->keyBasedStrategy = new KeyBasedPathStrategy($this->tempDir, 2);
        $this->context = new JsonlContext;
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.DIRECTORY_SEPARATOR.$file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function getFileContent(string $filePath): string
    {
        return $this->fileSystem->get($filePath);
    }

    private function createTemporalService(): JsonlService
    {
        return new JsonlService(
            pathStrategy: $this->temporalStrategy,
            fileSystem: $this->fileSystem,
            context: $this->context,
        );
    }

    private function createKeyBasedService(): JsonlService
    {
        return new JsonlService(
            pathStrategy: $this->keyBasedStrategy,
            fileSystem: $this->fileSystem,
            context: $this->context,
        );
    }

    private function createBufferedService(int $bufferSize): JsonlService
    {
        return new JsonlService(
            pathStrategy: $this->temporalStrategy,
            fileSystem: $this->fileSystem,
            context: $this->context,
            defaultBufferSize: $bufferSize,
        );
    }

    // ============================================================
    // Tests for write() with LogJsonlRecord
    // ============================================================

    public function test_write_log_record_creates_file_with_correct_content(): void
    {
        $service = $this->createTemporalService();

        $record = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
            level: 'info',
            type: 'user_login',
            payload: new StrictDataObject(['user_id' => 123, 'username' => 'john_doe']),
        );

        $expectedPath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        $service->write($record);

        $this->assertFileExists($expectedPath);

        $content = $this->getFileContent($expectedPath);
        $lines = explode("\n", trim($content));
        $this->assertCount(1, $lines);

        $data = json_decode($lines[0], true);
        $this->assertSame('2026-01-15 14:35:00', $data['time']);
        $this->assertSame('info', $data['level']);
        $this->assertSame('user_login', $data['type']);
        $this->assertSame(123, $data['payload']['user_id']);
        $this->assertSame('john_doe', $data['payload']['username']);
    }

    public function test_write_multiple_log_records_appends_to_same_file(): void
    {
        $service = $this->createTemporalService();

        $record1 = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
            level: 'info',
            type: 'user_login',
            payload: new StrictDataObject(['user_id' => 123]),
        );

        $record2 = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:36:00+00:00'),
            level: 'info',
            type: 'user_login',
            payload: new StrictDataObject(['user_id' => 456]),
        );

        $expectedPath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        $service->write($record1);
        $service->write($record2);

        $content = $this->getFileContent($expectedPath);
        $lines = explode("\n", trim($content));
        $this->assertCount(2, $lines);

        $data1 = json_decode($lines[0], true);
        $data2 = json_decode($lines[1], true);

        $this->assertSame(123, $data1['payload']['user_id']);
        $this->assertSame(456, $data2['payload']['user_id']);
    }

    public function test_write_log_record_creates_different_files_for_different_days(): void
    {
        $service = $this->createTemporalService();

        $recordDay1 = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
            level: 'info',
            type: 'test',
            payload: new StrictDataObject,
        );

        $recordDay2 = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-16T14:35:00+00:00'),
            level: 'info',
            type: 'test',
            payload: new StrictDataObject,
        );

        $expectedPathDay1 = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        $expectedPathDay2 = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-16',
            '14.jsonl',
        ]);

        $service->write($recordDay1);
        $service->write($recordDay2);

        $this->assertFileExists($expectedPathDay1);
        $this->assertFileExists($expectedPathDay2);
    }

    public function test_write_log_record_creates_different_files_for_different_hours(): void
    {
        $service = $this->createTemporalService();

        $recordHour14 = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
            level: 'info',
            type: 'test',
            payload: new StrictDataObject,
        );

        $recordHour15 = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T15:35:00+00:00'),
            level: 'info',
            type: 'test',
            payload: new StrictDataObject,
        );

        $expectedPathHour14 = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        $expectedPathHour15 = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '15.jsonl',
        ]);

        $service->write($recordHour14);
        $service->write($recordHour15);

        $this->assertFileExists($expectedPathHour14);
        $this->assertFileExists($expectedPathHour15);
    }

    // ============================================================
    // Tests for write() with CacheJsonlRecord
    // ============================================================

    public function test_write_cache_record_creates_file_with_correct_content(): void
    {
        $service = $this->createKeyBasedService();

        $payload = new StrictDataObject(['name' => 'John Doe', 'email' => 'john@example.com']);
        $encodedValue = json_encode($payload->toArray());

        $record = new CacheJsonlRecord(
            key: 'user_123',
            value: $encodedValue,
            expires_at: null,
        );

        $service->write($record);

        $hash = md5('user_123');
        $expectedPath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            $hash[0],
            $hash[1],
            'user_123.jsonl',
        ]);

        $this->assertFileExists($expectedPath);

        $content = $this->getFileContent($expectedPath);
        $data = json_decode(trim($content), true);

        $this->assertSame('user_123', $data['key']);
        $this->assertSame($encodedValue, $data['value']);
        $this->assertNull($data['expires_at']);
    }

    public function test_write_cache_record_with_expiration_creates_file_with_expires_at(): void
    {
        $service = $this->createKeyBasedService();

        $payload = new StrictDataObject(['name' => 'John Doe']);
        $encodedValue = json_encode($payload->toArray());

        $record = new CacheJsonlRecord(
            key: 'session_abc',
            value: $encodedValue,
            expires_at: new DateTimeVO('+1 hour'),
        );

        $service->write($record);

        $hash = md5('session_abc');
        $expectedPath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            $hash[0],
            $hash[1],
            'session_abc.jsonl',
        ]);

        $this->assertFileExists($expectedPath);

        $content = $this->getFileContent($expectedPath);
        $data = json_decode(trim($content), true);

        $this->assertArrayHasKey('expires_at', $data);
        $this->assertNotNull($data['expires_at']);
    }

    public function test_write_cache_record_sanitizes_dangerous_characters_in_key(): void
    {
        $service = $this->createKeyBasedService();

        $record = new CacheJsonlRecord(
            key: 'user/with/slashes?and&special@chars',
            value: json_encode(['test' => true]),
            expires_at: null,
        );

        $service->write($record);

        $hash = md5('user/with/slashes?and&special@chars');
        $expectedPath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            $hash[0],
            $hash[1],
            'user_with_slashes_and_special_chars.jsonl',
        ]);

        $this->assertFileExists($expectedPath);
    }

    // ============================================================
    // Tests for readAll()
    // ============================================================

    public function test_read_all_returns_all_lines_from_file(): void
    {
        $service = $this->createTemporalService();

        $filePath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        $this->fileSystem->ensureDirectoryExists(dirname($filePath));
        $content = '{"time":"2026-01-15T14:35:00+00:00","level":"info","type":"test1"}'."\n".
            '{"time":"2026-01-15T14:36:00+00:00","level":"debug","type":"test2"}'."\n".
            '{"time":"2026-01-15T14:37:00+00:00","level":"warning","type":"test3"}'."\n";
        $this->fileSystem->put($filePath, $content);

        $result = $service->readAll($filePath);

        $this->assertCount(3, $result);
        $this->assertSame('info', $result[0]['level']);
        $this->assertSame('debug', $result[1]['level']);
        $this->assertSame('warning', $result[2]['level']);
    }

    // ============================================================
    // Tests for getFirstLine() and getLastLine()
    // ============================================================

    public function test_get_first_line_returns_first_line_of_file(): void
    {
        $service = $this->createTemporalService();

        $filePath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        $this->fileSystem->ensureDirectoryExists(dirname($filePath));
        $content = '{"time":"2026-01-15T14:35:00+00:00","level":"info","type":"first"}'."\n".
            '{"time":"2026-01-15T14:36:00+00:00","level":"debug","type":"second"}'."\n";
        $this->fileSystem->put($filePath, $content);

        $result = $service->getFirstLine($filePath);

        $this->assertSame('first', $result['type']);
        $this->assertSame('info', $result['level']);
    }

    public function test_get_last_line_returns_last_line_of_file(): void
    {
        $service = $this->createTemporalService();

        $filePath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        $this->fileSystem->ensureDirectoryExists(dirname($filePath));
        $content = '{"time":"2026-01-15T14:35:00+00:00","level":"info","type":"first"}'."\n".
            '{"time":"2026-01-15T14:36:00+00:00","level":"debug","type":"second"}'."\n".
            '{"time":"2026-01-15T14:37:00+00:00","level":"error","type":"last"}'."\n";
        $this->fileSystem->put($filePath, $content);

        $result = $service->getLastLine($filePath);

        $this->assertSame('last', $result['type']);
        $this->assertSame('error', $result['level']);
    }

    // ============================================================
    // Tests for search()
    // ============================================================

    public function test_search_returns_lines_matching_filter(): void
    {
        $service = $this->createTemporalService();

        $filePath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        $this->fileSystem->ensureDirectoryExists(dirname($filePath));
        $content = '{"time":"2026-01-15T14:35:00+00:00","level":"info","user":"john"}'."\n".
            '{"time":"2026-01-15T14:36:00+00:00","level":"info","user":"jane"}'."\n".
            '{"time":"2026-01-15T14:37:00+00:00","level":"debug","user":"john"}'."\n";
        $this->fileSystem->put($filePath, $content);

        $result = $service->search($filePath, function ($line) {
            return $line['user'] === 'john';
        });

        $this->assertCount(2, $result);
        $this->assertSame('john', $result[0]['user']);
        $this->assertSame('john', $result[1]['user']);
    }

    // ============================================================
    // Tests for writeBatch()
    // ============================================================

    public function test_write_batch_writes_multiple_records_at_once(): void
    {
        $service = $this->createTemporalService();

        $records = [
            new LogJsonlRecord(
                time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
                level: 'info',
                type: 'event1',
                payload: new StrictDataObject(['id' => 1]),
            ),
            new LogJsonlRecord(
                time: new DateTimeVO('2026-01-15T14:36:00+00:00'),
                level: 'info',
                type: 'event2',
                payload: new StrictDataObject(['id' => 2]),
            ),
            new LogJsonlRecord(
                time: new DateTimeVO('2026-01-15T14:37:00+00:00'),
                level: 'info',
                type: 'event3',
                payload: new StrictDataObject(['id' => 3]),
            ),
        ];

        $expectedPath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        $service->writeBatch($records);

        $this->assertFileExists($expectedPath);

        $content = $this->getFileContent($expectedPath);
        $lines = explode("\n", trim($content));
        $this->assertCount(3, $lines);

        $data1 = json_decode($lines[0], true);
        $data2 = json_decode($lines[1], true);
        $data3 = json_decode($lines[2], true);

        $this->assertSame('event1', $data1['type']);
        $this->assertSame('event2', $data2['type']);
        $this->assertSame('event3', $data3['type']);
    }

    // ============================================================
    // Tests for buffer
    // ============================================================

    public function test_buffer_writes_after_reaching_size(): void
    {
        $service = $this->createBufferedService(3);

        $record = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
            level: 'info',
            type: 'test',
            payload: new StrictDataObject,
        );

        $expectedPath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        $service->writeBuffered($record);
        $service->writeBuffered($record);

        $this->assertFileDoesNotExist($expectedPath);

        $service->writeBuffered($record);

        $this->assertFileExists($expectedPath);

        $content = $this->getFileContent($expectedPath);
        $lines = explode("\n", trim($content));
        $this->assertCount(3, $lines);
    }

    public function test_flush_buffer_writes_pending_records(): void
    {
        $service = $this->createBufferedService(10);

        $record = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
            level: 'info',
            type: 'test',
            payload: new StrictDataObject,
        );

        $expectedPath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        $service->writeBuffered($record);
        $service->writeBuffered($record);

        $this->assertFileDoesNotExist($expectedPath);

        $service->flushBuffer();

        $this->assertFileExists($expectedPath);

        $content = $this->getFileContent($expectedPath);
        $lines = explode("\n", trim($content));
        $this->assertCount(2, $lines);
    }

    // ============================================================
    // Tests for cleanOlderThan()
    // ============================================================

    public function test_clean_older_than_deletes_files_older_than_specified_days(): void
    {
        $service = $this->createTemporalService();

        $oldFile = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-01',
            '10.jsonl',
        ]);

        $newFile = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        $this->fileSystem->ensureDirectoryExists(dirname($oldFile));
        $this->fileSystem->ensureDirectoryExists(dirname($newFile));
        $this->fileSystem->put($oldFile, '{"test":"old"}');
        $this->fileSystem->put($newFile, '{"test":"new"}');

        touch($oldFile, strtotime('-31 days'));
        touch($newFile, strtotime('-1 day'));

        $deletedCount = $service->cleanOlderThan(30, $this->tempDir);

        $this->assertSame(1, $deletedCount);
        $this->assertFileDoesNotExist($oldFile);
        $this->assertFileExists($newFile);
    }

    // ============================================================
    // Tests for cleanExpired()
    // ============================================================

    public function test_clean_expired_removes_expired_cache_entries(): void
    {
        $service = $this->createKeyBasedService();

        $payload = new StrictDataObject(['data' => 'test']);
        $encodedValue = json_encode($payload->toArray());

        $expiredRecord = new CacheJsonlRecord(
            key: 'expired_key',
            value: $encodedValue,
            expires_at: new DateTimeVO('-1 hour'),
        );

        $validRecord = new CacheJsonlRecord(
            key: 'valid_key',
            value: $encodedValue,
            expires_at: new DateTimeVO('+1 hour'),
        );

        $service->write($expiredRecord);
        $service->write($validRecord);

        $hashExpired = md5('expired_key');
        $expiredFilePath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            $hashExpired[0],
            $hashExpired[1],
            'expired_key.jsonl',
        ]);
        $this->assertFileExists($expiredFilePath, 'Expired record file was not created');

        $hashValid = md5('valid_key');
        $validFilePath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            $hashValid[0],
            $hashValid[1],
            'valid_key.jsonl',
        ]);
        $this->assertFileExists($validFilePath, 'Valid record file was not created');

        $content = $this->getFileContent($expiredFilePath);
        $data = json_decode(trim($content), true);

        $this->assertArrayHasKey('expires_at', $data, 'Expired file should have expires_at key');
        $this->assertNotNull($data['expires_at'], 'Expires_at should not be null for expired record');

        $deletedCount = $service->cleanExpired($this->tempDir, function ($line) {
            if (! isset($line['expires_at'])) {
                return false;
            }
            $expiresAt = new DateTimeVO($line['expires_at']);
            $now = new DateTimeVO;

            return $expiresAt->isBefore($now);
        });

        $this->assertSame(1, $deletedCount, 'Expected 1 expired entry to be deleted');
        $this->assertFileDoesNotExist($expiredFilePath, 'Expired record file should be deleted');
        $this->assertFileExists($validFilePath, 'Valid record file should still exist');
    }

    // ============================================================
    // Tests for cleanByPattern()
    // ============================================================

    public function test_clean_by_pattern_deletes_files_matching_pattern(): void
    {
        $service = $this->createTemporalService();

        $file1 = implode(DIRECTORY_SEPARATOR, [$this->tempDir, '2026-01-15', '14.jsonl']);
        $file2 = implode(DIRECTORY_SEPARATOR, [$this->tempDir, '2026-01-15', '15.jsonl']);
        $file3 = implode(DIRECTORY_SEPARATOR, [$this->tempDir, '2026-01-16', '10.jsonl']);

        $this->fileSystem->ensureDirectoryExists(dirname($file1));
        $this->fileSystem->put($file1, '{"test":1}');
        $this->fileSystem->put($file2, '{"test":2}');
        $this->fileSystem->put($file3, '{"test":3}');

        $pattern = $this->tempDir.DIRECTORY_SEPARATOR.'2026-01-15'.DIRECTORY_SEPARATOR.'*.jsonl';

        $deletedCount = $service->cleanByPattern($pattern);

        $this->assertSame(2, $deletedCount);
        $this->assertFileDoesNotExist($file1);
        $this->assertFileDoesNotExist($file2);
        $this->assertFileExists($file3);
    }

    // ============================================================
    // Tests for dryRun()
    // ============================================================

    public function test_dry_run_returns_files_to_delete_without_deleting(): void
    {
        $service = $this->createTemporalService();

        $file1 = implode(DIRECTORY_SEPARATOR, [$this->tempDir, '2026-01-15', '14.jsonl']);
        $file2 = implode(DIRECTORY_SEPARATOR, [$this->tempDir, '2026-01-15', '15.jsonl']);

        $this->fileSystem->ensureDirectoryExists(dirname($file1));
        $this->fileSystem->put($file1, '{"test":1}');
        $this->fileSystem->put($file2, '{"test":2}');

        touch($file1, strtotime('-31 days'));
        touch($file2, strtotime('-1 day'));

        $filesToDelete = $service->dryRun($this->tempDir, function ($file) {
            return filemtime($file) < strtotime('-30 days');
        });

        $this->assertCount(1, $filesToDelete);
        $this->assertStringContainsString('2026-01-15', $filesToDelete[0]);
        $this->assertStringContainsString('14.jsonl', $filesToDelete[0]);
        $this->assertFileExists($file1);
        $this->assertFileExists($file2);
    }

    // ============================================================
    // Tests for clear()
    // ============================================================

    public function test_clear_deletes_all_jsonl_files_in_directory(): void
    {
        $service = $this->createTemporalService();

        $file1 = implode(DIRECTORY_SEPARATOR, [$this->tempDir, '2026-01-15', '14.jsonl']);
        $file2 = implode(DIRECTORY_SEPARATOR, [$this->tempDir, '2026-01-15', '15.jsonl']);
        $file3 = implode(DIRECTORY_SEPARATOR, [$this->tempDir, '2026-01-16', '10.jsonl']);

        $this->fileSystem->ensureDirectoryExists(dirname($file1));
        $this->fileSystem->put($file1, '{"test":1}');
        $this->fileSystem->put($file2, '{"test":2}');
        $this->fileSystem->put($file3, '{"test":3}');

        $deletedCount = $service->clear($this->tempDir);

        $this->assertSame(3, $deletedCount);
        $this->assertFileDoesNotExist($file1);
        $this->assertFileDoesNotExist($file2);
        $this->assertFileDoesNotExist($file3);
    }

    // ============================================================
    // Tests for isExpired()
    // ============================================================

    public function test_is_expired_returns_true_for_expired_record(): void
    {
        $service = $this->createKeyBasedService();

        $record = new CacheJsonlRecord(
            key: 'test',
            value: '',
            expires_at: new DateTimeVO('-1 hour'),
        );

        $result = $service->isExpired($record);

        $this->assertTrue($result);
    }

    public function test_is_expired_returns_false_for_valid_record(): void
    {
        $service = $this->createKeyBasedService();

        $record = new CacheJsonlRecord(
            key: 'test',
            value: '',
            expires_at: new DateTimeVO('+1 hour'),
        );

        $result = $service->isExpired($record);

        $this->assertFalse($result);
    }

    public function test_is_expired_returns_false_for_record_without_expiration(): void
    {
        $service = $this->createKeyBasedService();

        $record = new CacheJsonlRecord(
            key: 'test',
            value: '',
            expires_at: null,
        );

        $result = $service->isExpired($record);

        $this->assertFalse($result);
    }

    // ============================================================
    // Tests for decodeCacheValue()
    // ============================================================

    public function test_decode_cache_value_returns_strict_data_object(): void
    {
        $service = $this->createKeyBasedService();

        $originalData = ['name' => 'John', 'age' => 30, 'active' => true];
        $encodedValue = json_encode($originalData);

        $result = $service->decodeCacheValue($encodedValue, PhpType::STRING->value);

        $this->assertInstanceOf(StrictDataObject::class, $result);
        $this->assertSame('John', $result->name);
        $this->assertSame(30, $result->age);
        $this->assertTrue($result->active);
    }

    // ============================================================
    // Lock tests
    // ============================================================

    public function test_execute_with_lock_acquires_and_releases_the_lock(): void
    {
        $service = $this->createTemporalService();

        $filePath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        $this->fileSystem->ensureDirectoryExists(dirname($filePath));
        $lockFile = $filePath.'.lock';

        $executed = false;

        $service->executeWithLock($filePath, function () use (&$executed, $lockFile): void {
            $executed = true;

            $this->assertFileExists($lockFile);
        });

        $this->assertTrue($executed);
        $this->assertFileDoesNotExist($lockFile);
    }

    public function test_acquire_returns_true_and_creates_lock_file(): void
    {
        $service = $this->createTemporalService();

        $filePath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        $this->fileSystem->ensureDirectoryExists(dirname($filePath));

        $result = $service->acquire($filePath);

        $this->assertTrue($result);
        $this->assertFileExists($filePath.'.lock');

        $lockPid = (int) $this->fileSystem->get($filePath.'.lock');
        $this->assertSame(getmypid(), $lockPid);

        $service->release($filePath);
    }

    public function test_acquire_removes_orphan_lock_from_dead_process(): void
    {
        if (! function_exists('posix_kill')) {
            $this->markTestSkipped('posix extension is required for orphan lock detection.');
        }

        // Launch a short-lived process and wait for its termination, so its
        // PID is guaranteed not to exist anymore (deterministic on any Linux).
        $process = proc_open(
            ['true'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        $this->assertIsResource($process, 'Failed to spawn a helper process.');

        $status = proc_get_status($process);
        $deadPid = $status['pid'];

        proc_close($process);

        $service = $this->createTemporalService();

        $filePath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        $this->fileSystem->ensureDirectoryExists(dirname($filePath));
        $this->fileSystem->put($filePath.'.lock', (string) $deadPid);

        $result = $service->acquire($filePath);

        $this->assertTrue($result);
        $this->assertSame(getmypid(), (int) $this->fileSystem->get($filePath.'.lock'));

        $service->release($filePath);
    }

    public function test_acquire_throws_timeout_when_lock_is_held_by_living_process(): void
    {
        $service = $this->createTemporalService();

        $filePath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        $this->fileSystem->ensureDirectoryExists(dirname($filePath));
        $this->fileSystem->put($filePath.'.lock', (string) getmypid());

        $this->expectException(JsonlLockException::class);
        $this->expectExceptionMessage('Timeout acquiring lock for');

        $service->acquire($filePath, timeout: 1);
    }

    public function test_acquire_returns_immediately_when_lock_is_already_owned(): void
    {
        $service = $this->createTemporalService();

        $filePath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        $this->fileSystem->ensureDirectoryExists(dirname($filePath));

        $service->acquire($filePath);

        $start = microtime(true);
        $result = $service->acquire($filePath, timeout: 5);
        $elapsed = microtime(true) - $start;

        $this->assertTrue($result);
        $this->assertLessThan(
            0.1,
            $elapsed,
            'Second acquire() should return immediately since the lock is already held in the context.',
        );

        $service->release($filePath);
    }

    public function test_release_removes_lock_file(): void
    {
        $service = $this->createTemporalService();

        $filePath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        $this->fileSystem->ensureDirectoryExists(dirname($filePath));

        $service->acquire($filePath);
        $this->assertFileExists($filePath.'.lock');

        $service->release($filePath);

        $this->assertFileDoesNotExist($filePath.'.lock');
    }

    public function test_execute_with_lock_releases_lock_on_exception(): void
    {
        $service = $this->createTemporalService();

        $filePath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        $this->fileSystem->ensureDirectoryExists(dirname($filePath));

        try {
            $service->executeWithLock($filePath, function (): void {
                throw new \RuntimeException('boom');
            });

            $this->fail('executeWithLock() should have rethrown the exception.');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertFileDoesNotExist($filePath.'.lock');
    }

    public function test_acquire_cleans_a_pid_zero_lock_immediately(): void
    {
        if (! function_exists('posix_kill')) {
            $this->markTestSkipped('posix extension is required for orphan lock detection.');
        }

        $service = $this->createTemporalService();

        $filePath = implode(DIRECTORY_SEPARATOR, [
            $this->tempDir,
            '2026-01-15',
            '14.jsonl',
        ]);

        $this->fileSystem->ensureDirectoryExists(dirname($filePath));
        $this->fileSystem->put($filePath.'.lock', '0');

        $start = microtime(true);
        $result = $service->acquire($filePath, timeout: 2);
        $elapsed = microtime(true) - $start;

        $this->assertTrue($result);
        $this->assertLessThan(
            0.5,
            $elapsed,
            'A PID=0 lock must be cleaned immediately, without waiting for the timeout.',
        );
        $this->assertSame(
            getmypid(),
            (int) $this->fileSystem->get($filePath.'.lock'),
            'The lock should now belong to the current process.',
        );

        $service->release($filePath);
    }

    public function test_acquire_does_not_spin_when_lock_deletion_fails(): void
    {
        if (! function_exists('posix_kill')) {
            $this->markTestSkipped('posix extension is required for orphan lock detection.');
        }

        $fileSystem = $this->createMock(FileSystemInterface::class);

        // Lock file exists, contains PID 0 (orphan).
        $fileSystem->method('exists')->willReturn(true);
        $fileSystem->method('get')->willReturn('0');
        $fileSystem->method('put')->willReturn(1);

        // Deletion always fails → the lock can never be cleared →
        // acquire() must stop at the timeout instead of spinning.
        $fileSystem->method('delete')->willReturn(false);

        $service = new JsonlService(
            pathStrategy: $this->temporalStrategy,
            fileSystem: $fileSystem,
            context: new JsonlContext,
        );

        $start = microtime(true);

        try {
            $service->acquire('/test/file.jsonl', timeout: 1);

            $this->fail('acquire() should have thrown JsonlLockException.');
        } catch (JsonlLockException) {
            $this->assertLessThan(
                2.0,
                microtime(true) - $start,
                'acquire() must respect its timeout even when delete() fails.',
            );
        }
    }
}
