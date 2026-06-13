<?php

// tests/Unit/Services/FileSystemServiceTest.php

declare(strict_types=1);

namespace AndyDefer\PhpServices\Tests\Unit\Services;

use AndyDefer\PhpServices\Services\FileSystemService;
use AndyDefer\PhpServices\Tests\UnitTestCase;

final class FileSystemServiceTest extends UnitTestCase
{
    private FileSystemService $filesystem;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filesystem = new FileSystemService;
        $this->tempDir = sys_get_temp_dir().'/filesystem_test_'.uniqid();
        mkdir($this->tempDir, 0777, true);
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
            $path = $dir.'/'.$file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function createTempFile(string $filename, string $content = 'test content'): string
    {
        $path = $this->tempDir.'/'.$filename;
        file_put_contents($path, $content);

        return $path;
    }

    // ============================================================================
    // exists() Tests
    // ============================================================================

    public function test_exists_returns_true_for_existing_file(): void
    {
        $path = $this->createTempFile('test.txt');
        $result = $this->filesystem->exists($path);
        $this->assertTrue($result);
    }

    public function test_exists_returns_false_for_nonexistent_file(): void
    {
        $result = $this->filesystem->exists($this->tempDir.'/nonexistent.txt');
        $this->assertFalse($result);
    }

    // ============================================================================
    // get() Tests
    // ============================================================================

    public function test_get_returns_file_content(): void
    {
        $expectedContent = 'Hello World!';
        $path = $this->createTempFile('content.txt', $expectedContent);
        $content = $this->filesystem->get($path);
        $this->assertSame($expectedContent, $content);
    }

    public function test_get_throws_exception_for_nonexistent_file(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->filesystem->get($this->tempDir.'/nonexistent.txt');
    }

    // ============================================================================
    // put() Tests
    // ============================================================================

    public function test_put_creates_file_with_content(): void
    {
        $path = $this->tempDir.'/new_file.txt';
        $content = 'New file content';
        $result = $this->filesystem->put($path, $content);
        $this->assertNotFalse($result);
        $this->assertFileExists($path);
        $this->assertSame($content, file_get_contents($path));
    }

    // ============================================================================
    // append() Tests
    // ============================================================================

    public function test_append_adds_content_to_file(): void
    {
        $path = $this->createTempFile('append.txt', 'Initial content');
        $result = $this->filesystem->append($path, "\nAppended content");
        $this->assertNotFalse($result);
        $content = file_get_contents($path);
        $this->assertStringContainsString('Initial content', $content);
        $this->assertStringContainsString('Appended content', $content);
    }

    // ============================================================================
    // isDirectory() Tests
    // ============================================================================

    public function test_is_directory_returns_true_for_directory(): void
    {
        $result = $this->filesystem->isDirectory($this->tempDir);
        $this->assertTrue($result);
    }

    public function test_is_directory_returns_false_for_file(): void
    {
        $path = $this->createTempFile('file.txt');
        $result = $this->filesystem->isDirectory($path);
        $this->assertFalse($result);
    }

    // ============================================================================
    // isFile() Tests
    // ============================================================================

    public function test_is_file_returns_true_for_file(): void
    {
        $path = $this->createTempFile('file.txt');
        $result = $this->filesystem->isFile($path);
        $this->assertTrue($result);
    }

    public function test_is_file_returns_false_for_directory(): void
    {
        $result = $this->filesystem->isFile($this->tempDir);
        $this->assertFalse($result);
    }

    // ============================================================================
    // isReadable() Tests
    // ============================================================================

    public function test_is_readable_returns_true_for_readable_file(): void
    {
        $path = $this->createTempFile('readable.txt');
        $result = $this->filesystem->isReadable($path);
        $this->assertTrue($result);
    }

    // ============================================================================
    // isWritable() Tests
    // ============================================================================

    public function test_is_writable_returns_true_for_writable_file(): void
    {
        $path = $this->createTempFile('writable.txt');
        $result = $this->filesystem->isWritable($path);
        $this->assertTrue($result);
    }

    // ============================================================================
    // makeDirectory() Tests
    // ============================================================================

    public function test_make_directory_creates_directory(): void
    {
        $newDir = $this->tempDir.'/new_directory';
        $result = $this->filesystem->makeDirectory($newDir);
        $this->assertTrue($result);
        $this->assertDirectoryExists($newDir);
    }

    // ============================================================================
    // ensureDirectoryExists() Tests
    // ============================================================================

    public function test_ensure_directory_exists_creates_missing_directory(): void
    {
        $newDir = $this->tempDir.'/missing_dir';
        $this->filesystem->ensureDirectoryExists($newDir);
        $this->assertDirectoryExists($newDir);
    }

    // ============================================================================
    // copy() Tests
    // ============================================================================

    public function test_copy_copies_file(): void
    {
        $source = $this->createTempFile('source.txt', 'Source content');
        $destination = $this->tempDir.'/destination.txt';
        $result = $this->filesystem->copy($source, $destination);
        $this->assertTrue($result);
        $this->assertFileExists($destination);
        $this->assertSame('Source content', file_get_contents($destination));
    }

    // ============================================================================
    // move() Tests
    // ============================================================================

    public function test_move_moves_file(): void
    {
        $source = $this->createTempFile('move_source.txt', 'Move content');
        $destination = $this->tempDir.'/move_destination.txt';
        $result = $this->filesystem->move($source, $destination);
        $this->assertTrue($result);
        $this->assertFileDoesNotExist($source);
        $this->assertFileExists($destination);
        $this->assertSame('Move content', file_get_contents($destination));
    }

    // ============================================================================
    // glob() Tests
    // ============================================================================

    public function test_glob_returns_matching_files(): void
    {
        $this->createTempFile('file1.txt');
        $this->createTempFile('file2.txt');
        $this->createTempFile('file3.log');
        $result = $this->filesystem->glob($this->tempDir.'/*.txt');
        $this->assertCount(2, $result);
    }

    // ============================================================================
    // delete() Tests
    // ============================================================================

    public function test_delete_removes_file(): void
    {
        $path = $this->createTempFile('to_delete.txt');
        $result = $this->filesystem->delete($path);
        $this->assertTrue($result);
        $this->assertFileDoesNotExist($path);
    }

    // ============================================================================
    // deleteDirectory() Tests
    // ============================================================================

    public function test_delete_directory_removes_non_empty_directory(): void
    {
        $dir = $this->tempDir.'/non_empty_dir';
        mkdir($dir);
        $this->createTempFile('non_empty_dir/file1.txt');
        $result = $this->filesystem->deleteDirectory($dir);
        $this->assertTrue($result);
        $this->assertDirectoryDoesNotExist($dir);
    }

    // ============================================================================
    // size() Tests
    // ============================================================================

    public function test_size_returns_file_size(): void
    {
        $content = '12345';
        $path = $this->createTempFile('size.txt', $content);
        $result = $this->filesystem->size($path);
        $this->assertSame(strlen($content), $result);
    }

    // ============================================================================
    // lastModified() Tests
    // ============================================================================

    public function test_last_modified_returns_timestamp(): void
    {
        $path = $this->createTempFile('modified.txt');
        $result = $this->filesystem->lastModified($path);
        $this->assertIsInt($result);
        $this->assertGreaterThan(0, $result);
    }

    // ============================================================================
    // extension() Tests
    // ============================================================================

    public function test_extension_returns_file_extension(): void
    {
        $path = $this->tempDir.'/file.txt';
        $result = $this->filesystem->extension($path);
        $this->assertSame('txt', $result);
    }

    public function test_extension_returns_empty_string_for_no_extension(): void
    {
        $path = $this->tempDir.'/file_without_extension';
        $result = $this->filesystem->extension($path);
        $this->assertSame('', $result);
    }

    // ============================================================================
    // basename() Tests
    // ============================================================================

    public function test_basename_returns_basename(): void
    {
        $path = $this->tempDir.'/subdir/file.txt';
        $result = $this->filesystem->basename($path);
        $this->assertSame('file.txt', $result);
    }

    // ============================================================================
    // dirname() Tests
    // ============================================================================

    public function test_dirname_returns_directory_name(): void
    {
        $path = $this->tempDir.'/subdir/file.txt';
        $result = $this->filesystem->dirname($path);
        $this->assertStringContainsString('subdir', $result);
    }
}
