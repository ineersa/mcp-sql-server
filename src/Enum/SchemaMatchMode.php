<?php

declare(strict_types=1);

namespace App\Enum;

enum SchemaMatchMode: string
{
    case CONTAINS = 'contains';
    case PREFIX = 'prefix';
    case EXACT = 'exact';
    case GLOB = 'glob';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $mode): string => $mode->value,
            self::cases()
        );
    }

    public static function tryFromInput(string $input): ?self
    {
        return self::tryFrom(strtolower(trim($input)));
    }
}
