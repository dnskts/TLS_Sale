<?php
/**
 * mapping_formula.php — простые арифметические формулы в ячейках маппинга Б24.
 *
 * Синтаксис: + - * / ( ), NZ(value), числа, идентификаторы полей, "Заголовок Excel".
 * Без eval().
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Ячейка выглядит как формула (а не как заголовок Excel).
 */
function mapping_cell_is_formula(string $s): bool
{
    $s = trim($s);
    if ($s === '') {
        return false;
    }
    if (str_starts_with($s, '=')) {
        return true;
    }
    // Операторы вне кавычек
    $inQuote = false;
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $ch = $s[$i];
        if ($ch === '"') {
            $inQuote = !$inQuote;
            continue;
        }
        if ($inQuote) {
            continue;
        }
        if ($ch === '*' || $ch === '/' || $ch === '+' || $ch === '(' || $ch === ')') {
            return true;
        }
        // Бинарный минус: не первый значимый символ и не часть числа после e/E
        if ($ch === '-') {
            $prev = '';
            for ($j = $i - 1; $j >= 0; $j--) {
                if ($s[$j] !== ' ' && $s[$j] !== "\t") {
                    $prev = $s[$j];
                    break;
                }
            }
            if ($prev !== '' && $prev !== '(' && !preg_match('/[eE]/', $prev)) {
                return true;
            }
        }
    }
    return false;
}

/**
 * @param array<string, mixed> $rowFields parser fields already mapped
 * @param array<string, mixed> $excelByHeader normalized Excel header → cell value
 */
function mapping_formula_eval(string $expr, array $rowFields, array $excelByHeader): ?float
{
    $expr = trim($expr);
    if (str_starts_with($expr, '=')) {
        $expr = trim(substr($expr, 1));
    }
    if ($expr === '') {
        return null;
    }
    try {
        $tokens = mapping_formula_tokenize($expr);
        if ($tokens === []) {
            return null;
        }
        $pos = 0;
        $value = mapping_formula_parse_expr($tokens, $pos, $rowFields, $excelByHeader);
        if ($pos !== count($tokens)) {
            return null;
        }
        if ($value === null || !is_finite($value)) {
            return null;
        }
        return $value;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @param array<string, string> $formulas field => expression
 * @param array<string, mixed> $row
 * @param array<string, mixed> $excelByHeader
 * @return array<string, mixed>
 */
function mapping_apply_formulas(array $row, array $formulas, array $excelByHeader): array
{
    foreach ($formulas as $field => $expr) {
        $field = clean_str(is_string($field) ? $field : null);
        $expr = is_string($expr) ? trim($expr) : '';
        if ($field === null || $expr === '') {
            continue;
        }
        $val = mapping_formula_eval($expr, $row, $excelByHeader);
        if ($val !== null) {
            $row[$field] = $val;
        }
    }
    return $row;
}

/**
 * Проверка синтаксиса (без резолва значений). true = ок.
 */
function mapping_formula_syntax_ok(string $expr): bool
{
    $expr = trim($expr);
    if (str_starts_with($expr, '=')) {
        $expr = trim(substr($expr, 1));
    }
    if ($expr === '') {
        return false;
    }
    try {
        $tokens = mapping_formula_tokenize($expr);
        $pos = 0;
        mapping_formula_parse_expr($tokens, $pos, [], []);
        return $pos === count($tokens);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return list<array{type:string,value:?string}>
 */
function mapping_formula_tokenize(string $expr): array
{
    $tokens = [];
    $len = strlen($expr);
    $i = 0;
    while ($i < $len) {
        $ch = $expr[$i];
        if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r") {
            $i++;
            continue;
        }
        if (str_contains('+-*/()', $ch)) {
            $tokens[] = ['type' => 'op', 'value' => $ch];
            $i++;
            continue;
        }
        if ($ch === '"') {
            $i++;
            $buf = '';
            while ($i < $len && $expr[$i] !== '"') {
                $buf .= $expr[$i];
                $i++;
            }
            if ($i >= $len) {
                throw new RuntimeException('Незакрытая кавычка в формуле');
            }
            $i++; // closing "
            $tokens[] = ['type' => 'header', 'value' => $buf];
            continue;
        }
        if (preg_match('/[0-9]/', $ch) || ($ch === '.' && $i + 1 < $len && preg_match('/[0-9]/', $expr[$i + 1]))) {
            $buf = '';
            while ($i < $len && preg_match('/[0-9.]/', $expr[$i])) {
                $buf .= $expr[$i];
                $i++;
            }
            if (!is_numeric($buf)) {
                throw new RuntimeException('Некорректное число: ' . $buf);
            }
            $tokens[] = ['type' => 'number', 'value' => $buf];
            continue;
        }
        if (preg_match('/[A-Za-z_]/', $ch)) {
            $buf = '';
            while ($i < $len && preg_match('/[A-Za-z0-9_]/', $expr[$i])) {
                $buf .= $expr[$i];
                $i++;
            }
            $tokens[] = ['type' => 'ident', 'value' => $buf];
            continue;
        }
        throw new RuntimeException('Неожиданный символ в формуле: ' . $ch);
    }
    return $tokens;
}

/**
 * @param list<array{type:string,value:?string}> $tokens
 * @param array<string, mixed> $rowFields
 * @param array<string, mixed> $excelByHeader
 */
function mapping_formula_parse_expr(array $tokens, int &$pos, array $rowFields, array $excelByHeader): ?float
{
    $left = mapping_formula_parse_term($tokens, $pos, $rowFields, $excelByHeader);
    while ($pos < count($tokens)) {
        $op = $tokens[$pos]['value'] ?? '';
        if ($op !== '+' && $op !== '-') {
            break;
        }
        $pos++;
        $right = mapping_formula_parse_term($tokens, $pos, $rowFields, $excelByHeader);
        if ($left === null || $right === null) {
            return null;
        }
        $left = $op === '+' ? $left + $right : $left - $right;
    }
    return $left;
}

/**
 * @param list<array{type:string,value:?string}> $tokens
 * @param array<string, mixed> $rowFields
 * @param array<string, mixed> $excelByHeader
 */
function mapping_formula_parse_term(array $tokens, int &$pos, array $rowFields, array $excelByHeader): ?float
{
    $left = mapping_formula_parse_factor($tokens, $pos, $rowFields, $excelByHeader);
    while ($pos < count($tokens)) {
        $op = $tokens[$pos]['value'] ?? '';
        if ($op !== '*' && $op !== '/') {
            break;
        }
        $pos++;
        $right = mapping_formula_parse_factor($tokens, $pos, $rowFields, $excelByHeader);
        if ($left === null || $right === null) {
            return null;
        }
        if ($op === '/') {
            if ($right == 0.0) {
                return null;
            }
            $left = $left / $right;
        } else {
            $left = $left * $right;
        }
    }
    return $left;
}

/**
 * @param list<array{type:string,value:?string}> $tokens
 * @param array<string, mixed> $rowFields
 * @param array<string, mixed> $excelByHeader
 */
function mapping_formula_parse_factor(array $tokens, int &$pos, array $rowFields, array $excelByHeader): ?float
{
    if ($pos >= count($tokens)) {
        throw new RuntimeException('Неожиданный конец формулы');
    }
    $tok = $tokens[$pos];

    if (($tok['value'] ?? '') === '+') {
        $pos++;
        return mapping_formula_parse_factor($tokens, $pos, $rowFields, $excelByHeader);
    }
    if (($tok['value'] ?? '') === '-') {
        $pos++;
        $v = mapping_formula_parse_factor($tokens, $pos, $rowFields, $excelByHeader);
        return $v === null ? null : -$v;
    }
    if (($tok['value'] ?? '') === '(') {
        $pos++;
        $v = mapping_formula_parse_expr($tokens, $pos, $rowFields, $excelByHeader);
        if ($pos >= count($tokens) || ($tokens[$pos]['value'] ?? '') !== ')') {
            throw new RuntimeException('Ожидалась )');
        }
        $pos++;
        return $v;
    }
    if ($tok['type'] === 'number') {
        $pos++;
        return (float) $tok['value'];
    }
    if ($tok['type'] === 'header') {
        $pos++;
        return mapping_formula_resolve_header((string) $tok['value'], $excelByHeader);
    }
    if ($tok['type'] === 'ident') {
        $pos++;
        $ident = (string) $tok['value'];
        if ($pos < count($tokens) && ($tokens[$pos]['value'] ?? '') === '(') {
            $pos++;
            $arg = mapping_formula_parse_expr($tokens, $pos, $rowFields, $excelByHeader);
            if ($pos >= count($tokens) || ($tokens[$pos]['value'] ?? '') !== ')') {
                throw new RuntimeException('Ожидалась ) после функции ' . $ident);
            }
            $pos++;
            if (strcasecmp($ident, 'NZ') === 0) {
                return $arg ?? 0.0;
            }
            throw new RuntimeException('Неизвестная функция: ' . $ident);
        }
        return mapping_formula_resolve_ident($ident, $rowFields, $excelByHeader);
    }
    throw new RuntimeException('Неожиданный токен');
}

/**
 * @param array<string, mixed> $excelByHeader
 */
function mapping_formula_resolve_header(string $header, array $excelByHeader): ?float
{
    $norm = clean_str(str_replace("\n", ' ', $header));
    if ($norm === null) {
        return null;
    }
    if (!array_key_exists($norm, $excelByHeader)) {
        return null;
    }
    return to_float($excelByHeader[$norm]);
}

/**
 * @param array<string, mixed> $rowFields
 * @param array<string, mixed> $excelByHeader
 */
function mapping_formula_resolve_ident(string $id, array $rowFields, array $excelByHeader): ?float
{
    if (array_key_exists($id, $rowFields) && $rowFields[$id] !== null && $rowFields[$id] !== '') {
        return to_float($rowFields[$id]);
    }
    if (array_key_exists($id, $excelByHeader)) {
        return to_float($excelByHeader[$id]);
    }
    return null;
}
