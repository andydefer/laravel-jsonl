<?php

namespace AndyDefer\Directive\Tests\Services;

use AndyDefer\PhpServices\Enums\PrimitiveType;
use AndyDefer\PhpServices\Services\PrimitiveTypeConverterService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PrimitiveTypeConverterServiceTest extends TestCase
{
    private PrimitiveTypeConverterService $converter;

    protected function setUp(): void
    {
        $this->converter = new PrimitiveTypeConverterService;
    }

    public function test_convert_to_bool_from_bool(): void
    {
        $value = true;
        $targetType = PrimitiveType::BOOL;

        $result = $this->converter->convert($value, $targetType);

        $this->assertTrue($result);
    }

    public function test_convert_to_bool_from_true_string(): void
    {
        $value = 'true';
        $targetType = PrimitiveType::BOOL;

        $result = $this->converter->convert($value, $targetType);

        $this->assertTrue($result);
    }

    public function test_convert_to_bool_from_false_string(): void
    {
        $value = 'false';
        $targetType = PrimitiveType::BOOL;

        $result = $this->converter->convert($value, $targetType);

        $this->assertTrue($result);
    }

    public function test_convert_to_bool_from_integer_one(): void
    {
        $value = 1;
        $targetType = PrimitiveType::BOOL;

        $result = $this->converter->convert($value, $targetType);

        $this->assertTrue($result);
    }

    public function test_convert_to_bool_from_integer_zero(): void
    {
        $value = 0;
        $targetType = PrimitiveType::BOOL;

        $result = $this->converter->convert($value, $targetType);

        $this->assertFalse($result);
    }

    public function test_convert_to_bool_from_float(): void
    {
        $value = 1.5;
        $targetType = PrimitiveType::BOOL;

        $result = $this->converter->convert($value, $targetType);

        $this->assertTrue($result);
    }

    public function test_convert_to_bool_from_null(): void
    {
        $value = null;
        $targetType = PrimitiveType::BOOL;

        $result = $this->converter->convert($value, $targetType);

        $this->assertFalse($result);
    }

    public function test_convert_to_string_from_string(): void
    {
        $value = 'hello';
        $targetType = PrimitiveType::STRING;

        $result = $this->converter->convert($value, $targetType);

        $this->assertSame('hello', $result);
    }

    public function test_convert_to_string_from_integer(): void
    {
        $value = 123;
        $targetType = PrimitiveType::STRING;

        $result = $this->converter->convert($value, $targetType);

        $this->assertSame('123', $result);
    }

    public function test_convert_to_string_from_float(): void
    {
        $value = 123.45;
        $targetType = PrimitiveType::STRING;

        $result = $this->converter->convert($value, $targetType);

        $this->assertSame('123.45', $result);
    }

    public function test_convert_to_string_from_bool_true(): void
    {
        $value = true;
        $targetType = PrimitiveType::STRING;

        $result = $this->converter->convert($value, $targetType);

        $this->assertSame('1', $result);
    }

    public function test_convert_to_string_from_bool_false(): void
    {
        $value = false;
        $targetType = PrimitiveType::STRING;

        $result = $this->converter->convert($value, $targetType);

        $this->assertSame('', $result);
    }

    public function test_convert_to_string_from_null(): void
    {
        $value = null;
        $targetType = PrimitiveType::STRING;

        $result = $this->converter->convert($value, $targetType);

        $this->assertSame('', $result);
    }

    public function test_convert_to_int_from_int(): void
    {
        $value = 123;
        $targetType = PrimitiveType::INT;

        $result = $this->converter->convert($value, $targetType);

        $this->assertSame(123, $result);
    }

    public function test_convert_to_int_from_numeric_string(): void
    {
        $value = '123';
        $targetType = PrimitiveType::INT;

        $result = $this->converter->convert($value, $targetType);

        $this->assertSame(123, $result);
    }

    public function test_convert_to_int_from_non_numeric_string(): void
    {
        $value = 'hello';
        $targetType = PrimitiveType::INT;

        $result = $this->converter->convert($value, $targetType);

        $this->assertSame(0, $result);
    }

    public function test_convert_to_int_from_float(): void
    {
        $value = 123.45;
        $targetType = PrimitiveType::INT;

        $result = $this->converter->convert($value, $targetType);

        $this->assertSame(123, $result);
    }

    public function test_convert_to_int_from_bool_true(): void
    {
        $value = true;
        $targetType = PrimitiveType::INT;

        $result = $this->converter->convert($value, $targetType);

        $this->assertSame(1, $result);
    }

    public function test_convert_to_int_from_bool_false(): void
    {
        $value = false;
        $targetType = PrimitiveType::INT;

        $result = $this->converter->convert($value, $targetType);

        $this->assertSame(0, $result);
    }

    public function test_convert_to_int_from_null(): void
    {
        $value = null;
        $targetType = PrimitiveType::INT;

        $result = $this->converter->convert($value, $targetType);

        $this->assertSame(0, $result);
    }

    public function test_convert_to_float_from_float(): void
    {
        $value = 123.45;
        $targetType = PrimitiveType::FLOAT;

        $result = $this->converter->convert($value, $targetType);

        $this->assertSame(123.45, $result);
    }

    public function test_convert_to_float_from_numeric_string(): void
    {
        $value = '123.45';
        $targetType = PrimitiveType::FLOAT;

        $result = $this->converter->convert($value, $targetType);

        $this->assertSame(123.45, $result);
    }

    public function test_convert_to_float_from_integer(): void
    {
        $value = 123;
        $targetType = PrimitiveType::FLOAT;

        $result = $this->converter->convert($value, $targetType);

        $this->assertSame(123.0, $result);
    }

    public function test_convert_to_float_from_non_numeric_string(): void
    {
        $value = 'hello';
        $targetType = PrimitiveType::FLOAT;

        $result = $this->converter->convert($value, $targetType);

        $this->assertSame(0.0, $result);
    }

    public function test_convert_to_float_from_bool_true(): void
    {
        $value = true;
        $targetType = PrimitiveType::FLOAT;

        $result = $this->converter->convert($value, $targetType);

        $this->assertSame(1.0, $result);
    }

    public function test_convert_to_float_from_bool_false(): void
    {
        $value = false;
        $targetType = PrimitiveType::FLOAT;

        $result = $this->converter->convert($value, $targetType);

        $this->assertSame(0.0, $result);
    }

    public function test_convert_to_float_from_null(): void
    {
        $value = null;
        $targetType = PrimitiveType::FLOAT;

        $result = $this->converter->convert($value, $targetType);

        $this->assertSame(0.0, $result);
    }

    public function test_convert_to_null(): void
    {
        $value = 'anything';
        $targetType = PrimitiveType::NULL;

        $result = $this->converter->convert($value, $targetType);

        $this->assertNull($result);
    }

    public function test_convert_or_default_with_valid_conversion(): void
    {
        $value = '123';
        $targetType = PrimitiveType::INT;
        $default = 999;

        $result = $this->converter->convertOrDefault($value, $targetType, $default);

        $this->assertSame(123, $result);
    }

    public function test_convert_or_default_with_custom_default(): void
    {
        $value = 'invalid';
        $targetType = PrimitiveType::INT;
        $default = 999;

        $result = $this->converter->convertOrDefault($value, $targetType, $default);

        $this->assertSame(0, $result);
    }

    public function test_convert_or_default_with_null_default(): void
    {
        $value = 'invalid';
        $targetType = PrimitiveType::INT;
        $default = null;

        $result = $this->converter->convertOrDefault($value, $targetType, $default);

        $this->assertSame(0, $result);
    }

    public function test_detect_type_from_null(): void
    {
        $value = null;

        $result = $this->converter->detectType($value);

        $this->assertEquals(PrimitiveType::NULL, $result);
    }

    public function test_detect_type_from_bool_true(): void
    {
        $value = true;

        $result = $this->converter->detectType($value);

        $this->assertEquals(PrimitiveType::BOOL, $result);
    }

    public function test_detect_type_from_bool_false(): void
    {
        $value = false;

        $result = $this->converter->detectType($value);

        $this->assertEquals(PrimitiveType::BOOL, $result);
    }

    public function test_detect_type_from_integer(): void
    {
        $value = 123;

        $result = $this->converter->detectType($value);

        $this->assertEquals(PrimitiveType::INT, $result);
    }

    public function test_detect_type_from_float(): void
    {
        $value = 123.45;

        $result = $this->converter->detectType($value);

        $this->assertEquals(PrimitiveType::FLOAT, $result);
    }

    public function test_detect_type_from_string(): void
    {
        $value = 'hello';

        $result = $this->converter->detectType($value);

        $this->assertEquals(PrimitiveType::STRING, $result);
    }

    public function test_detect_type_from_array_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to detect type for value of type: array');

        $this->converter->detectType([]);
    }

    public function test_detect_type_from_object_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to detect type for value of type: object');

        $this->converter->detectType(new \stdClass);
    }

    public function test_detect_type_from_resource_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $resource = fopen('php://memory', 'r');
        $this->converter->detectType($resource);
        fclose($resource);
    }
}
