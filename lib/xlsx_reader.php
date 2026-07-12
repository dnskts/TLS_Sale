<?php
/**
 * xlsx_reader.php
 *
 * Простой читатель Excel (.xlsx) без Composer.
 * Файл xlsx — это ZIP с XML внутри. Мы:
 * 1) открываем ZIP
 * 2) читаем список листов
 * 3) читаем ячейки выбранного листа
 *
 * Возвращает массив строк: каждая строка — массив значений ячеек.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Прочитать лист Excel по имени.
 *
 * @return list<list<mixed>> строки таблицы (включая шапку)
 */
function xlsx_read_sheet(string $path, string $sheetName): array
{
    if (!is_readable($path)) {
        throw new RuntimeException("Файл не найден: {$path}");
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException("Не удалось открыть xlsx: {$path}");
    }

    // Общие строки (sharedStrings) — Excel хранит текст отдельно
    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $ss = @simplexml_load_string($ssXml);
        if ($ss !== false) {
            foreach ($ss->si as $si) {
                // Текст может быть разбит на куски <r><t>
                if (isset($si->t)) {
                    $shared[] = (string) $si->t;
                } else {
                    $parts = [];
                    foreach ($si->r as $r) {
                        $parts[] = (string) $r->t;
                    }
                    $shared[] = implode('', $parts);
                }
            }
        }
    }

    // Найти путь к нужному листу по имени
    $wbXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wbXml === false || $relsXml === false) {
        $zip->close();
        throw new RuntimeException('В xlsx нет workbook.xml');
    }
    $wb = simplexml_load_string($wbXml);
    $rels = simplexml_load_string($relsXml);
    if ($wb === false || $rels === false) {
        $zip->close();
        throw new RuntimeException('Не разобран workbook.xml');
    }

    $wb->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $wb->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
    $relsMap = [];
    foreach ($rels->Relationship as $rel) {
        $relsMap[(string) $rel['Id']] = (string) $rel['Target'];
    }

    $sheetPath = null;
    foreach ($wb->sheets->sheet as $sheet) {
        $name = (string) $sheet['name'];
        if ($name !== $sheetName) {
            continue;
        }
        $attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rid = (string) ($attrs['id'] ?? '');
        $target = $relsMap[$rid] ?? '';
        $sheetPath = 'xl/' . ltrim(str_replace('\\', '/', $target), '/');
        // Иногда Target уже начинается с worksheets/
        if (str_starts_with($target, 'worksheets/')) {
            $sheetPath = 'xl/' . $target;
        } elseif (str_starts_with($target, '/xl/')) {
            $sheetPath = ltrim($target, '/');
        }
        break;
    }
    if ($sheetPath === null) {
        $zip->close();
        throw new RuntimeException("Лист «{$sheetName}» не найден в файле");
    }

    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException("Не удалось прочитать лист {$sheetPath}");
    }

    $sheet = simplexml_load_string($sheetXml);
    if ($sheet === false) {
        throw new RuntimeException('Не разобран XML листа');
    }

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $rowIndex = (int) $row['r'];
        $cells = [];
        foreach ($row->c as $c) {
            $ref = (string) $c['r']; // например A1
            $col = xlsx_col_index($ref);
            $type = (string) ($c['t'] ?? '');
            $value = null;
            if ($type === 's') {
                // Индекс в sharedStrings
                $idx = (int) ($c->v ?? 0);
                $value = $shared[$idx] ?? null;
            } elseif ($type === 'inlineStr') {
                $value = isset($c->is->t) ? (string) $c->is->t : null;
            } elseif ($type === 'b') {
                $value = ((string) ($c->v ?? '0')) === '1';
            } else {
                $raw = isset($c->v) ? (string) $c->v : null;
                if ($raw !== null && $raw !== '' && is_numeric($raw)) {
                    $value = str_contains($raw, '.') ? (float) $raw : (0 + $raw);
                } else {
                    $value = $raw;
                }
            }
            $cells[$col] = $value;
        }
        if ($cells === []) {
            continue;
        }
        $max = max(array_keys($cells));
        $line = [];
        for ($i = 0; $i <= $max; $i++) {
            $line[] = $cells[$i] ?? null;
        }
        // Ключ — номер строки Excel (1-based), потом отсортируем
        $rows[$rowIndex] = $line;
    }
    ksort($rows);
    return array_values($rows);
}

/** Буква колонки из ссылки A12 → индекс 0, B12 → 1, AA1 → 26 */
function xlsx_col_index(string $cellRef): int
{
    if (!preg_match('/^([A-Z]+)/i', $cellRef, $m)) {
        return 0;
    }
    $letters = strtoupper($m[1]);
    $n = 0;
    $len = strlen($letters);
    for ($i = 0; $i < $len; $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return $n - 1;
}
