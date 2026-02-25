<?php

declare(strict_types=1);

namespace App\Enum;

enum SchemaDetail: string
{
    case SUMMARY = 'summary';
    case COLUMNS = 'columns';
    case FULL = 'full';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $detail): string => $detail->value,
            self::cases()
        );
    }

    public static function tryFromInput(string $input): ?self
    {
        return self::tryFrom(strtolower(trim($input)));
    }
}
