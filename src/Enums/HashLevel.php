<?php

declare(strict_types=1);

namespace AndyDefer\LaravelJsonl\Enums;

enum HashLevel: string
{
    case LEVEL_0 = '0';
    case LEVEL_1 = '1';
    case LEVEL_2 = '2';
    case LEVEL_3 = '3';
    case LEVEL_4 = '4';
    case LEVEL_5 = '5';
    case LEVEL_6 = '6';
    case LEVEL_7 = '7';
    case LEVEL_8 = '8';
    case LEVEL_9 = '9';
    case LEVEL_A = 'a';
    case LEVEL_B = 'b';
    case LEVEL_C = 'c';
    case LEVEL_D = 'd';
    case LEVEL_E = 'e';
    case LEVEL_F = 'f';

    public static function fromChar(string $char): self
    {
        return match ($char) {
            '0' => self::LEVEL_0,
            '1' => self::LEVEL_1,
            '2' => self::LEVEL_2,
            '3' => self::LEVEL_3,
            '4' => self::LEVEL_4,
            '5' => self::LEVEL_5,
            '6' => self::LEVEL_6,
            '7' => self::LEVEL_7,
            '8' => self::LEVEL_8,
            '9' => self::LEVEL_9,
            'a' => self::LEVEL_A,
            'b' => self::LEVEL_B,
            'c' => self::LEVEL_C,
            'd' => self::LEVEL_D,
            'e' => self::LEVEL_E,
            'f' => self::LEVEL_F,
            default => throw new \InvalidArgumentException("Invalid hash character: {$char}"),
        };
    }

    public function getValue(): string
    {
        return $this->value;
    }
}
