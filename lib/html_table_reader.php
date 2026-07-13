<?php
/**
 * html_table_reader.php
 *
 * Читает «Excel»-выгрузку Битрикс24 в виде HTML-таблицы (.xls).
 * Возвращает те же list<list<mixed>>, что и xlsx_read_sheet.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function is_html_table_export(string $path): bool
{
    $head = @file_get_contents($path, false, null, 0, 512);
    if ($head === false || $head === '') {
        return false;
    }
    return stripos($head, '<table') !== false
        || stripos($head, '<html') !== false
        || stripos($head, '<meta') !== false;
}

/**
 * @return list<list<mixed>>
 */
function html_table_read_rows(string $path): array
{
    if (!is_readable($path)) {
        throw new RuntimeException("Файл не найден: {$path}");
    }
    $html = file_get_contents($path);
    if ($html === false) {
        throw new RuntimeException("Не удалось прочитать: {$path}");
    }

    $prev = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    if (!$loaded) {
        throw new RuntimeException("Не разобран HTML: {$path}");
    }

    $tables = $dom->getElementsByTagName('table');
    if ($tables->length === 0) {
        throw new RuntimeException("В файле нет HTML-таблицы: {$path}");
    }

    $rows = [];
    /** @var DOMElement $table */
    $table = $tables->item(0);
    foreach ($table->getElementsByTagName('tr') as $tr) {
        $line = [];
        foreach ($tr->childNodes as $cell) {
            if (!($cell instanceof DOMElement)) {
                continue;
            }
            if ($cell->nodeName !== 'th' && $cell->nodeName !== 'td') {
                continue;
            }
            $text = $cell->textContent;
            $text = str_replace("\xc2\xa0", ' ', $text);
            $text = trim($text);
            $line[] = $text === '' ? null : $text;
        }
        if ($line !== []) {
            $rows[] = $line;
        }
    }
    return $rows;
}
