<?php
/**
 * parse_bitrix.php — сделки Битрикс (CRM export xlsx).
 */

declare(strict_types=1);

require_once __DIR__ . '/bitrix_export.php';
require_once __DIR__ . '/settings.php';

/** @deprecated Используйте bitrix_export_header_aliases(). */
function bitrix_header_aliases(): array
{
    $out = [];
    foreach (bitrix_export_header_aliases() as $header => $field) {
        if (!str_starts_with($field, '_')) {
            $out[$header] = $field;
        }
    }
    return $out;
}

/**
 * @return list<array<string,mixed>>
 */
function parse_bitrix(string $path, string $sheetName): array
{
    return parse_bitrix_export($path, $sheetName);
}
