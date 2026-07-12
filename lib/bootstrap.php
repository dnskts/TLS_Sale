<?php
/**
 * bootstrap.php
 *
 * Общие помощники для всего приложения:
 * - пути к папкам проекта
 * - ответ в формате JSON для api/*.php
 * - безопасное чтение тела запроса
 *
 * Подключайте этот файл в начале каждого api-скрипта.
 */

declare(strict_types=1);

/** Корень проекта (папка, где лежит index.php). */
function project_root(): string
{
    return dirname(__DIR__);
}

/**
 * Подключаем нужный файл из lib/, если ещё не подключён.
 * Пример: require_lib('settings.php');
 */
function require_lib(string $file): void
{
    $path = project_root() . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . $file;
    if (!is_file($path)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => "Не найден файл lib/{$file}"], JSON_UNESCAPED_UNICODE);
        exit;
    }
    require_once $path;
}

/** Отправить JSON и завершить скрипт. */
function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Прочитать JSON из тела POST-запроса. */
function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Убрать лишние пробелы и неразрывные пробелы (\xa0),
 * которые часто бывают в выгрузках Excel.
 */
function clean_str($value): ?string
{
    if ($value === null) {
        return null;
    }
    if (is_float($value) && is_nan($value)) {
        return null;
    }
    $text = str_replace("\xc2\xa0", ' ', (string) $value);
    $text = trim($text);
    return $text === '' ? null : $text;
}

/** Превратить значение Excel в число или null. */
function to_float($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_numeric($value)) {
        return (float) $value;
    }
    $text = clean_str($value);
    if ($text === null) {
        return null;
    }
    $text = str_replace([' ', ','], ['', '.'], $text);
    return is_numeric($text) ? (float) $text : null;
}

/**
 * Разобрать дату из Excel / строки.
 * Возвращает строку YYYY-MM-DD или null.
 */
function to_date_string($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    // Excel иногда отдаёт число дней с 1899-12-30
    if (is_numeric($value) && (float) $value > 20000 && (float) $value < 80000) {
        $unix = ((int) round((float) $value) - 25569) * 86400;
        return gmdate('Y-m-d', $unix);
    }
    $text = clean_str($value);
    if ($text === null) {
        return null;
    }
    // dd.mm.yyyy или dd.mm.yyyy hh:mm:ss
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})/', $text, $m)) {
        return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
    }
    // yyyy-mm-dd
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $text, $m)) {
        return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
    }
    $ts = strtotime($text);
    return $ts ? date('Y-m-d', $ts) : null;
}

/**
 * Дата-время → YYYY-MM-DD HH:MM:SS или null.
 */
function to_datetime_string($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_numeric($value) && (float) $value > 20000 && (float) $value < 80000) {
        $unix = (int) round(((float) $value - 25569) * 86400);
        return gmdate('Y-m-d H:i:s', $unix);
    }
    $text = clean_str($value);
    if ($text === null) {
        return null;
    }
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?/', $text, $m)) {
        $h = isset($m[4]) ? (int) $m[4] : 0;
        $i = isset($m[5]) ? (int) $m[5] : 0;
        $s = isset($m[6]) ? (int) $m[6] : 0;
        return sprintf(
            '%04d-%02d-%02d %02d:%02d:%02d',
            (int) $m[3],
            (int) $m[2],
            (int) $m[1],
            $h,
            $i,
            $s
        );
    }
    $ts = strtotime($text);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}
