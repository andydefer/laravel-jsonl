<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl\Tests\Unit;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\LaravelJsonl\Contexts\JsonlContext;
use AndyDefer\LaravelJsonl\Contexts\JsonlProcessingContext;
use AndyDefer\LaravelJsonl\Enums\OperationType;
use AndyDefer\LaravelJsonl\Exceptions\JsonlException;
use AndyDefer\LaravelJsonl\JsonlService;
use AndyDefer\LaravelJsonl\Records\CacheJsonlRecord;
use AndyDefer\LaravelJsonl\Records\LogJsonlRecord;
use AndyDefer\LaravelJsonl\Records\TemporalLogQueryRecord;
use AndyDefer\LaravelJsonl\Strategies\KeyBasedPathStrategy;
use AndyDefer\LaravelJsonl\Strategies\TemporalPathStrategy;
use AndyDefer\LaravelJsonl\Tests\Fixtures\Records\InvalidRecordFixture;
use AndyDefer\LaravelJsonl\Tests\UnitTestCase;
use AndyDefer\PhpServices\Contracts\FileSystemInterface;
use AndyDefer\PhpServices\Enums\PermissionMode;
use AndyDefer\PhpVo\ValueObjects\DateTimeVO;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;

#[AllowMockObjectsWithoutExpectations]
final class JsonlServiceTest extends UnitTestCase
{
    private const BASE_PATH = '/test/jsonl';

    private FileSystemInterface&MockObject $fileSystem;

    private TemporalPathStrategy $temporalStrategy;

    private KeyBasedPathStrategy $keyBasedStrategy;

    private JsonlContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fileSystem = $this->createMock(FileSystemInterface::class);
        $this->temporalStrategy = new TemporalPathStrategy(self::BASE_PATH);
        $this->keyBasedStrategy = new KeyBasedPathStrategy(self::BASE_PATH, 2);
        $this->context = new JsonlContext;
    }

    // ============================================================
    // Helpers pour créer des services
    // ============================================================

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

    private function createTemporalServiceWithPermission(PermissionMode $permission): JsonlService
    {
        return new JsonlService(
            pathStrategy: $this->temporalStrategy,
            fileSystem: $this->fileSystem,
            context: $this->context,
            defaultBufferSize: null,
            directoryPermission: $permission,
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
    // Tests pour write() - LogJsonlRecord
    // ============================================================

    public function test_write_log_record_writes_correct_json_line(): void
    {
        // Arrange
        $service = $this->createTemporalService();

        $record = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
            level: 'info',
            type: 'user_login',
            payload: new StrictDataObject(['user_id' => 123]),
        );

        $expectedPath = implode(DIRECTORY_SEPARATOR, [
            self::BASE_PATH,
            '2026-01-15',
            '14.jsonl',
        ]);

        $this->fileSystem->expects($this->once())
            ->method('isDirectory')
            ->with(dirname($expectedPath))
            ->willReturn(true);

        $this->fileSystem->expects($this->once())
            ->method('append')
            ->with($expectedPath, $this->callback(function ($content) {
                $data = json_decode(trim($content), true);

                return $data['time'] === '2026-01-15T14:35:00+00:00'
                    && $data['level'] === 'info'
                    && $data['type'] === 'user_login'
                    && $data['payload']['user_id'] === 123;
            }));

        // Act
        $service->write($record);
    }

    public function test_write_log_record_creates_directory_when_not_exists(): void
    {
        // Arrange
        $service = $this->createTemporalService();

        $record = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
            level: 'info',
            type: 'user_login',
            payload: new StrictDataObject(['user_id' => 123]),
        );

        $expectedPath = implode(DIRECTORY_SEPARATOR, [
            self::BASE_PATH,
            '2026-01-15',
            '14.jsonl',
        ]);

        $this->fileSystem->expects($this->once())
            ->method('isDirectory')
            ->with(dirname($expectedPath))
            ->willReturn(false);

        $this->fileSystem->expects($this->once())
            ->method('makeDirectory')
            ->with(dirname($expectedPath), PermissionMode::DIRECTORY, true);

        $this->fileSystem->expects($this->once())
            ->method('append')
            ->with($expectedPath, $this->anything());

        // Act
        $service->write($record);
    }

    public function test_write_log_record_with_custom_directory_permission(): void
    {
        // Arrange
        $service = $this->createTemporalServiceWithPermission(PermissionMode::PRIVATE_DIRECTORY);

        $record = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
            level: 'info',
            type: 'user_login',
            payload: new StrictDataObject(['user_id' => 123]),
        );

        $expectedPath = implode(DIRECTORY_SEPARATOR, [
            self::BASE_PATH,
            '2026-01-15',
            '14.jsonl',
        ]);

        $this->fileSystem->expects($this->once())
            ->method('isDirectory')
            ->with(dirname($expectedPath))
            ->willReturn(false);

        $this->fileSystem->expects($this->once())
            ->method('makeDirectory')
            ->with(dirname($expectedPath), PermissionMode::PRIVATE_DIRECTORY, true);

        $this->fileSystem->expects($this->once())
            ->method('append')
            ->with($expectedPath, $this->anything());

        // Act
        $service->write($record);
    }

    // ============================================================
    // Tests pour write() - CacheJsonlRecord
    // ============================================================

    public function test_write_cache_record_writes_correct_json_line(): void
    {
        // Arrange
        $service = $this->createKeyBasedService();

        $cacheValue = new StrictDataObject(['name' => 'John', 'age' => 30]);
        $encodedValue = json_encode($cacheValue->toArray());

        $record = new CacheJsonlRecord(
            key: 'user_123',
            value: $encodedValue,
            expires_at: new DateTimeVO('+1 hour'),
        );

        $this->fileSystem->expects($this->once())
            ->method('isDirectory')
            ->willReturn(true);

        $this->fileSystem->expects($this->once())
            ->method('append')
            ->with($this->anything(), $this->callback(function ($content) {
                $data = json_decode(trim($content), true);

                return $data['key'] === 'user_123'
                    && isset($data['value']);
            }));

        // Act
        $service->write($record);
    }

    public function test_write_cache_record_without_expiration(): void
    {
        // Arrange
        $service = $this->createKeyBasedService();

        $cacheValue = new StrictDataObject(['name' => 'John']);
        $encodedValue = json_encode($cacheValue->toArray());

        $record = new CacheJsonlRecord(
            key: 'session_abc',
            value: $encodedValue,
            expires_at: null,
        );

        $this->fileSystem->expects($this->once())
            ->method('isDirectory')
            ->willReturn(true);

        $this->fileSystem->expects($this->once())
            ->method('append')
            ->with($this->anything(), $this->callback(function ($content) {
                $data = json_decode(trim($content), true);

                return $data['key'] === 'session_abc'
                    && ! isset($data['expires_at']);
            }));

        // Act
        $service->write($record);
    }

    // ============================================================
    // Tests pour write() avec contexte
    // ============================================================

    public function test_write_updates_context_on_success(): void
    {
        // Arrange
        $service = $this->createTemporalService();
        $context = new JsonlProcessingContext;

        $record = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
            level: 'info',
            type: 'user_login',
            payload: new StrictDataObject(['user_id' => 123]),
        );

        $expectedPath = implode(DIRECTORY_SEPARATOR, [
            self::BASE_PATH,
            '2026-01-15',
            '14.jsonl',
        ]);

        $this->fileSystem->expects($this->once())
            ->method('isDirectory')
            ->willReturn(true);

        $this->fileSystem->expects($this->once())
            ->method('append');

        // Act
        $service->write($record, true, $context);

        // Assert
        $this->assertSame(OperationType::COMPLETED, $context->getCurrentOperation());
        $this->assertFalse($context->hasError());
        $this->assertEquals(1, $context->getTotalLinesProcessed());
        $this->assertCount(1, $context->getProcessedFiles()->toArray());
        $this->assertContains($expectedPath, $context->getProcessedFiles()->toArray());
    }

    public function test_write_updates_context_on_error(): void
    {
        // Arrange
        $service = $this->createTemporalService();
        $context = new JsonlProcessingContext;

        $record = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
            level: 'info',
            type: 'user_login',
            payload: new StrictDataObject(['user_id' => 123]),
        );

        $this->fileSystem->expects($this->once())
            ->method('isDirectory')
            ->willReturn(true);

        $this->fileSystem->expects($this->once())
            ->method('append')
            ->willThrowException(new \Exception('Disk full'));

        // Expect
        $this->expectException(JsonlException::class);
        $this->expectExceptionMessage('Disk full');

        try {
            // Act
            $service->write($record, true, $context);
        } finally {
            // Assert
            $this->assertSame(OperationType::FAILED, $context->getCurrentOperation());
            $this->assertTrue($context->hasError());
            $this->assertSame('Disk full', $context->getLastError());
        }
    }

    // ============================================================
    // Tests pour write() avec lock
    // ============================================================

    public function test_write_acquires_lock_when_lock_true(): void
    {
        // Arrange
        $service = $this->createTemporalService();

        $record = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
            level: 'info',
            type: 'user_login',
            payload: new StrictDataObject(['user_id' => 123]),
        );

        $expectedPath = implode(DIRECTORY_SEPARATOR, [
            self::BASE_PATH,
            '2026-01-15',
            '14.jsonl',
        ]);

        $lockFile = $expectedPath.'.lock';

        $this->fileSystem->expects($this->once())
            ->method('isDirectory')
            ->willReturn(true);

        $this->fileSystem->expects($this->once())
            ->method('exists')
            ->with($lockFile)
            ->willReturn(false);

        $this->fileSystem->expects($this->once())
            ->method('put')
            ->with($lockFile, $this->anything());

        $this->fileSystem->expects($this->once())
            ->method('append');

        $this->fileSystem->expects($this->once())
            ->method('delete')
            ->with($lockFile);

        // Act
        $service->write($record, true);
    }

    public function test_write_does_not_acquire_lock_when_lock_false(): void
    {
        // Arrange
        $service = $this->createTemporalService();

        $record = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
            level: 'info',
            type: 'user_login',
            payload: new StrictDataObject(['user_id' => 123]),
        );

        $this->fileSystem->expects($this->once())
            ->method('isDirectory')
            ->willReturn(true);

        $this->fileSystem->expects($this->once())
            ->method('append');

        $this->fileSystem->expects($this->never())
            ->method('exists');
        $this->fileSystem->expects($this->never())
            ->method('put');
        $this->fileSystem->expects($this->never())
            ->method('delete');

        // Act
        $service->write($record, false);
    }

    // ============================================================
    // Tests pour write() - type non supporté
    // ============================================================

    public function test_write_throws_exception_for_unsupported_record_type(): void
    {
        $service = $this->createTemporalService();
        $unsupportedRecord = new InvalidRecordFixture;

        $this->expectException(JsonlException::class);
        $this->expectExceptionMessage('TemporalPathStrategy expects LogJsonlRecord');

        $service->write($unsupportedRecord);
    }

    // ============================================================
    // Tests pour writeBatch()
    // ============================================================

    public function test_write_batch_writes_multiple_entities(): void
    {
        // Arrange
        $service = $this->createTemporalService();

        $records = [
            new LogJsonlRecord(
                time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
                level: 'info',
                type: 'user_login',
                payload: new StrictDataObject(['user_id' => 1]),
            ),
            new LogJsonlRecord(
                time: new DateTimeVO('2026-01-15T14:36:00+00:00'),
                level: 'info',
                type: 'user_login',
                payload: new StrictDataObject(['user_id' => 2]),
            ),
        ];

        $expectedPath = implode(DIRECTORY_SEPARATOR, [
            self::BASE_PATH,
            '2026-01-15',
            '14.jsonl',
        ]);

        $this->fileSystem->expects($this->once())
            ->method('isDirectory')
            ->willReturn(true);

        $this->fileSystem->expects($this->once())
            ->method('append')
            ->with($expectedPath, $this->callback(function ($content) {
                $lines = explode("\n", trim($content));

                return count($lines) === 2;
            }));

        // Act
        $service->writeBatch($records);
    }

    public function test_write_batch_does_nothing_for_empty_entities(): void
    {
        $service = $this->createTemporalService();

        $this->fileSystem->expects($this->never())
            ->method('append');

        $service->writeBatch([]);
    }

    // ============================================================
    // Tests pour buffer
    // ============================================================

    public function test_write_buffered_buffers_entities_until_buffer_size_reached(): void
    {
        $service = $this->createBufferedService(3);

        $record = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
            level: 'info',
            type: 'user_login',
            payload: new StrictDataObject(['user_id' => 1]),
        );

        $service->writeBuffered($record);
        $service->writeBuffered($record);

        $this->fileSystem->expects($this->once())
            ->method('append')
            ->with($this->anything(), $this->anything());

        $service->writeBuffered($record);
    }

    public function test_flush_buffer_writes_buffered_entities(): void
    {
        $service = $this->createBufferedService(10);

        $record = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
            level: 'info',
            type: 'user_login',
            payload: new StrictDataObject(['user_id' => 1]),
        );

        $expectedPath = implode(DIRECTORY_SEPARATOR, [
            self::BASE_PATH,
            '2026-01-15',
            '14.jsonl',
        ]);

        $service->writeBuffered($record);

        $this->fileSystem->expects($this->once())
            ->method('append')
            ->with($expectedPath, $this->anything());

        $service->flushBuffer();
    }

    public function test_disable_buffer_flushes_and_disables_buffer(): void
    {
        $service = $this->createBufferedService(10);

        $record = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
            level: 'info',
            type: 'user_login',
            payload: new StrictDataObject(['user_id' => 1]),
        );

        $this->fileSystem->expects($this->once())
            ->method('isDirectory')
            ->willReturn(true);

        $service->writeBuffered($record);

        $this->fileSystem->expects($this->exactly(2))
            ->method('append');

        $service->disableBuffer();

        $service->writeBuffered($record);
    }

    // ============================================================
    // Tests pour readAll()
    // ============================================================

    public function test_read_all_returns_lines_when_file_exists(): void
    {
        $service = $this->createTemporalService();
        $filePath = '/test/file.jsonl';

        $content = '{"time":"2026-01-15T14:35:00+00:00","level":"info"}'."\n".
            '{"time":"2026-01-15T14:36:00+00:00","level":"debug"}'."\n";

        $this->fileSystem->expects($this->exactly(2))
            ->method('exists')
            ->with($filePath)
            ->willReturn(true);

        $this->fileSystem->expects($this->once())
            ->method('get')
            ->with($filePath)
            ->willReturn($content);

        $result = $service->readAll($filePath);

        $this->assertCount(2, $result);
        $this->assertSame('info', $result[0]['level']);
        $this->assertSame('debug', $result[1]['level']);
    }

    public function test_read_all_returns_empty_array_when_file_not_exists(): void
    {
        $service = $this->createTemporalService();
        $filePath = '/test/nonexistent.jsonl';

        $this->fileSystem->expects($this->once())
            ->method('exists')
            ->with($filePath)
            ->willReturn(false);

        $result = $service->readAll($filePath);

        $this->assertEmpty($result);
    }

    // ============================================================
    // Tests pour getFirstLine() et getLastLine()
    // ============================================================

    public function test_get_first_line_returns_first_line_when_file_exists(): void
    {
        $service = $this->createTemporalService();
        $filePath = '/test/file.jsonl';

        $content = '{"time":"2026-01-15T14:35:00+00:00","level":"info"}'."\n".
            '{"time":"2026-01-15T14:36:00+00:00","level":"debug"}'."\n";

        $this->fileSystem->expects($this->once())
            ->method('exists')
            ->with($filePath)
            ->willReturn(true);

        $this->fileSystem->expects($this->once())
            ->method('get')
            ->with($filePath)
            ->willReturn($content);

        $result = $service->getFirstLine($filePath);

        $this->assertSame('info', $result['level']);
    }

    public function test_get_last_line_returns_last_line_when_file_exists(): void
    {
        $service = $this->createTemporalService();
        $filePath = '/test/file.jsonl';

        $content = '{"time":"2026-01-15T14:35:00+00:00","level":"info"}'."\n".
            '{"time":"2026-01-15T14:36:00+00:00","level":"debug"}'."\n";

        $this->fileSystem->expects($this->once())
            ->method('exists')
            ->with($filePath)
            ->willReturn(true);

        $this->fileSystem->expects($this->once())
            ->method('get')
            ->with($filePath)
            ->willReturn($content);

        $result = $service->getLastLine($filePath);

        $this->assertSame('debug', $result['level']);
    }

    public function test_get_first_line_returns_null_when_file_empty(): void
    {
        $service = $this->createTemporalService();
        $filePath = '/test/empty.jsonl';

        $this->fileSystem->expects($this->once())
            ->method('exists')
            ->with($filePath)
            ->willReturn(true);

        $this->fileSystem->expects($this->once())
            ->method('get')
            ->with($filePath)
            ->willReturn('');

        $result = $service->getFirstLine($filePath);

        $this->assertNull($result);
    }

    // ============================================================
    // Tests pour search()
    // ============================================================

    public function test_search_returns_filtered_lines(): void
    {
        $service = $this->createTemporalService();
        $filePath = '/test/file.jsonl';

        $content = '{"time":"2026-01-15T14:35:00+00:00","level":"info","user":"john"}'."\n".
            '{"time":"2026-01-15T14:36:00+00:00","level":"info","user":"jane"}'."\n".
            '{"time":"2026-01-15T14:37:00+00:00","level":"debug","user":"john"}'."\n";

        $this->fileSystem->expects($this->once())
            ->method('exists')
            ->with($filePath)
            ->willReturn(true);

        $this->fileSystem->expects($this->once())
            ->method('get')
            ->with($filePath)
            ->willReturn($content);

        $result = $service->search($filePath, function ($line) {
            return $line['user'] === 'john';
        });

        $this->assertCount(2, $result);
        $this->assertSame('john', $result[0]['user']);
        $this->assertSame('john', $result[1]['user']);
    }

    // ============================================================
    // Tests pour getFilePath() et getFilesToScan()
    // ============================================================

    public function test_get_file_path_returns_path_from_strategy(): void
    {
        $service = $this->createTemporalService();

        $record = new LogJsonlRecord(
            time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
            level: 'info',
            type: 'test',
            payload: new StrictDataObject,
        );

        $result = $service->getFilePath($record);

        $expected = implode(DIRECTORY_SEPARATOR, [
            self::BASE_PATH,
            '2026-01-15',
            '14.jsonl',
        ]);
        $this->assertSame($expected, $result);
    }

    public function test_get_files_to_scan_returns_files_from_strategy(): void
    {
        $service = $this->createTemporalService();

        $query = new TemporalLogQueryRecord(
            from: new DateTimeVO('2026-01-15T00:00:00+00:00'),
            to: new DateTimeVO('2026-01-15T23:59:59+00:00'),
        );

        $result = $service->getFilesToScan($query);

        $this->assertCount(24, $result);
    }

    // ============================================================
    // Tests pour isExpired()
    // ============================================================

    public function test_is_expired_returns_true_when_record_expired(): void
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

    public function test_is_expired_returns_false_when_record_not_expired(): void
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

    public function test_is_expired_returns_false_when_record_has_no_expiration(): void
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
    // Tests pour setPathStrategy()
    // ============================================================

    public function test_set_path_strategy_changes_strategy(): void
    {
        $service = $this->createTemporalService();

        $record = new CacheJsonlRecord(
            key: 'user_123',
            value: '',
            expires_at: null,
        );

        $service->setPathStrategy($this->keyBasedStrategy);
        $result = $service->getFilePath($record);

        $this->assertStringContainsString('user_123.jsonl', $result);
        $this->assertStringNotContainsString('2026-01-15', $result);
    }
}
