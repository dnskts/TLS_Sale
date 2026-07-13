<?php
/**
 * input_files.php — поиск файлов выгрузки в input/ с запасными именами.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Найти первый читаемый файл из списка кандидатов.
 *
 * @param list<string> $fallbackNames
 */
function resolve_input_file(string $dir, string $preferredName, array $fallbackNames = []): ?string
{
    $names = array_merge([$preferredName], $fallbackNames);
    $seen = [];
    foreach ($names as $name) {
        if ($name === '' || isset($seen[$name])) {
            continue;
        }
        $seen[$name] = true;
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        if (is_readable($path)) {
            return $path;
        }
    }
    return null;
}
