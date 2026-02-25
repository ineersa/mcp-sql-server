<?php

declare(strict_types=1);

namespace App\Service\Schema;

trait SchemaObjectNameExtractorTrait
{
    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<string>
     */
    private function extractObjectNames(array $rows, string $key): array
    {
        $names = array_map(
            static function (array $row) use ($key): string {
                $name = $row[$key] ?? '';

                return \is_scalar($name) ? (string) $name : '';
            },
            $rows,
        );

        $names = array_values(array_filter($names, static fn (string $name): bool => '' !== trim($name)));

        return array_values(array_unique($names));
    }
}
