<?php
/**
 * mapping.php — Excel column → parser field mappings (mapping.json).
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** Полный путь к mapping.json */
function mapping_path(): string
{
    return project_root() . DIRECTORY_SEPARATOR . 'mapping.json';
}

/** @return array<string, mixed>|null */
function &mapping_cache_ref(): ?array
{
    static $cache = null;
    return $cache;
}

function clear_mapping_cache(): void
{
    $cache = &mapping_cache_ref();
    $cache = null;
}

/**
 * Встроенный дефолт = текущий hardcode парсеров (если файла нет).
 *
 * @return array<string, mixed>
 */
function default_mapping(): array
{
    // Только поля 1С, используемые дашбордом или формулами.
    $columns = [
        ['index' => 0, 'field' => 'date_operation', 'header' => 'Дата операции', 'label' => 'Дата операции'],
        ['index' => 2, 'field' => 'agent', 'header' => 'Агент', 'label' => 'Агент'],
        ['index' => 4, 'field' => 'supplier', 'header' => 'Поставщик', 'label' => 'Поставщик'],
        ['index' => 5, 'field' => 'card_type', 'header' => 'Тип карты', 'label' => 'Тип карты'],
        ['index' => 6, 'field' => 'case_id', 'header' => 'Кейс', 'label' => 'Номер кейса / лида'],
        ['index' => 7, 'field' => 'channel', 'header' => 'Канал связи', 'label' => 'Канал связи'],
        ['index' => 9, 'field' => 'client_id', 'header' => 'I d CRM', 'label' => 'ID клиента'],
        ['index' => 16, 'field' => 'client', 'header' => 'Клиент', 'label' => 'Клиент'],
        ['index' => 18, 'field' => 'deal_no', 'header' => 'Заказ', 'label' => 'Номер сделки / заказа'],
        ['index' => 19, 'field' => 'client_type', 'header' => 'Подразделение', 'label' => 'Тип клиента'],
        ['index' => 20, 'field' => 'category', 'header' => 'Связанный вид услуги', 'label' => 'Категория'],
        ['index' => 22, 'field' => 'payment_date', 'header' => 'Дата оплаты', 'label' => 'Дата оплаты'],
        ['index' => 23, 'field' => 'service_date', 'header' => 'Дата реализация', 'label' => 'Дата услуги'],
        ['index' => 24, 'field' => 'sales_amount', 'header' => 'Сумма продажи', 'label' => 'Сумма продажи'],
        ['index' => 26, 'field' => 'profit_ex_vat', 'header' => 'Прибыль без НДС', 'label' => 'Прибыль без НДС'],
        ['index' => 31, 'field' => 'service_fee', 'header' => 'Сервисный сбор', 'label' => 'Сервисный сбор'],
    ];
    $oneCFields = array_column($columns, 'field');

    // Б24 NEW: только поля дашборда, туризма и финансовых формул.
    $dealsExport = [
        'ID' => 'deal_no',
        'Дата создания' => 'deal_created_at',
        'ID клиента' => 'client_id',
        'Тип карты' => 'card_type',
        'Категория' => 'category',
        'Город' => 'city',
        'Клиент' => 'client',
        'Тип клиента' => 'client_type',
        'Канал связи' => 'channel',
        'Страна' => 'country',
        'Причина стадии Сделка проиграна' => 'lost_deal_reason',
        'Результат сделки' => 'deal_result',
        'Стадия' => 'deal_status',
        'Дата окончания' => 'end_date',
        'Отель' => 'hotel',
        'Лид' => 'case_id',
        'Маркетинговый канал' => 'lead_source',
        'Количество ночей' => 'nights_count',
        'Полное наименование организации' => 'supplier',
        'Компания' => 'supplier',
        'Тип запроса' => 'request_type',
        'Ответственный' => 'agent',
        'Количество номеров' => 'rooms_count',
        'Дата оказания услуги' => 'service_date',
        'Дата начала' => 'start_date',
        'Дополнительная выгода' => 'additional_benefit',
        'Сервисный сбор' => 'service_fee',
        'Комиссия' => 'commission',
        'Всего к оплате Клиентом' => 'total_client_pay',
        'Сумма возврата клиенту' => 'client_refund_amount',
        'Возврат комиссии поставщика' => 'commission_refund',
        'Возврат клиенту сбора РС ТЛС' => 'service_fee_refund',
        'Возврат клиенту дополнительной выгоды' => 'additional_benefit_refund',
        'Сбор поставщика за возврат' => 'supplier_return_fee',
        'Штраф поставщика за возврат' => 'supplier_return_penalty',
        'Сбор РС ТЛС за возврат' => 'rstls_return_fee',
        'Штраф РС ТЛС за возврат' => 'rstls_return_penalty',
        // Поле будет добавлено в следующую версию выгрузки.
        'Дата оплаты клиентом' => 'date_operation',
        'Дата оплаты Клиентом' => 'date_operation',
        'Плановая дата закрытия' => 'planned_close_date',
        'Дата последней активности' => 'last_activity_at',
        'Количество звонков' => 'calls_count',
        'Количество встреч' => 'meetings_count',
    ];

    $dealsExportFormulas = [
        'sales_amount' => '"Всего к оплате Клиентом"',
        'profit' => 'NZ("Дополнительная выгода") + NZ("Сервисный сбор") + NZ("Комиссия")',
        'profit_ex_vat' => 'NZ("Дополнительная выгода") + (NZ("Сервисный сбор") + NZ("Комиссия")) / vat_factor',
        'sales_amount_after_refund' => '"Всего к оплате Клиентом" - NZ("Сумма возврата клиенту")',
        'profit_after_refund' => 'NZ("Дополнительная выгода") + NZ("Сервисный сбор") + NZ("Комиссия") - NZ("Возврат клиенту дополнительной выгоды") - NZ("Возврат клиенту сбора РС ТЛС") - NZ("Возврат комиссии поставщика") + NZ("Сбор РС ТЛС за возврат") + NZ("Штраф РС ТЛС за возврат")',
        'profit_after_refund_ex_vat' => '(NZ("Дополнительная выгода") - NZ("Возврат клиенту дополнительной выгоды")) + (NZ("Сервисный сбор") - NZ("Возврат клиенту сбора РС ТЛС") + NZ("Комиссия") - NZ("Возврат комиссии поставщика") + NZ("Сбор РС ТЛС за возврат") + NZ("Штраф РС ТЛС за возврат")) / vat_factor',
        'supplier_retained' => 'NZ("Сбор поставщика за возврат") + NZ("Штраф поставщика за возврат")',
    ];

    return [
        'version' => 1,
        'one_c' => [
            'mode' => 'by_index',
            'sheet_hint' => 'TDSheet',
            'fingerprint' => ['Дата операции', 'Агент', 'Поставщик', 'Сумма продажи'],
            'columns' => $columns,
            'extra_by_header' => [
                'Тип запроса' => 'request_type',
                'Страна' => 'country',
                'Город' => 'city',
                'Отель' => 'hotel',
                'Дата начала' => 'start_date',
                'Дата окончания' => 'end_date',
                'Количество ночей' => 'nights_count',
                'Количество номеров' => 'rooms_count',
            ],
        ],
        'bitrix_profiles' => [
            'deals_export' => [
                'label' => 'Б24 NEW — Отчет по сделкам',
                'sheet_hint' => 'Отчет по сделкам',
                'fingerprint' => [
                    'ID', 'Ответственный', 'Результат сделки', 'Всего к оплате Клиентом',
                    'Комиссия', 'Сервисный сбор', 'Дата создания', 'Стадия', 'Клиент',
                ],
                'headers' => $dealsExport,
                'formulas' => $dealsExportFormulas,
                'vat_rates' => [
                    ['effective_from' => '1900-01-01', 'factor' => 1.20],
                    ['effective_from' => '2026-01-01', 'factor' => 1.22],
                ],
                'enrich' => [
                    'sales_amount_from' => 'sales_amount',
                    'profit_ex_vat_from' => ['profit_ex_vat'],
                ],
            ],
        ],
        'canonical_fields' => [
            'one_c' => array_values(array_unique(array_merge($oneCFields, ['client_type']))),
            'bitrix' => array_values(array_unique(array_merge(
                array_values($dealsExport),
                array_keys($dealsExportFormulas),
                [
                    'vat_factor',
                    'date_for_sales', 'date_fallback_used', 'source', 'bitrix_format',
                ]
            ))),
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function load_mapping(): array
{
    $cache = &mapping_cache_ref();
    if (is_array($cache)) {
        return $cache;
    }
    $path = mapping_path();
    if (!is_readable($path)) {
        $cache = default_mapping();
        return $cache;
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        throw new RuntimeException('mapping.json повреждён (не JSON)');
    }
    $cache = normalize_mapping($data);
    return $cache;
}

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function normalize_mapping(array $data): array
{
    $base = default_mapping();
    if (!isset($data['one_c']) || !is_array($data['one_c'])) {
        $data['one_c'] = $base['one_c'];
    }
    if (!isset($data['bitrix_profiles']) || !is_array($data['bitrix_profiles'])) {
        $data['bitrix_profiles'] = $base['bitrix_profiles'];
    }
    foreach ($data['bitrix_profiles'] as $pid => $profile) {
        if (!is_array($profile)) {
            continue;
        }
        if (!isset($profile['formulas']) || !is_array($profile['formulas'])) {
            $profile['formulas'] = [];
        }
        $normFormulas = [];
        foreach ($profile['formulas'] as $field => $expr) {
            $f = clean_str(is_string($field) ? $field : null);
            $e = is_string($expr) ? trim($expr) : '';
            if ($f !== null && $e !== '') {
                $normFormulas[$f] = $e;
            }
        }
        $profile['formulas'] = $normFormulas;
        $data['bitrix_profiles'][$pid] = $profile;
    }
    if (!isset($data['canonical_fields']) || !is_array($data['canonical_fields'])) {
        $data['canonical_fields'] = $base['canonical_fields'];
    }
    $data['version'] = (int) ($data['version'] ?? 1);
    return $data;
}

/**
 * @param array<string, mixed> $data
 * @return list<string> warnings
 */
function validate_mapping(array $data): array
{
    $warnings = [];
    $oneC = $data['one_c']['columns'] ?? null;
    if (!is_array($oneC) || $oneC === []) {
        $warnings[] = 'one_c.columns пуст';
    } else {
        $seen = [];
        foreach ($oneC as $row) {
            if (!is_array($row)) {
                continue;
            }
            $idx = $row['index'] ?? null;
            $field = clean_str($row['field'] ?? null);
            if ($idx === null || $field === null) {
                $warnings[] = 'one_c: строка без index или field';
                continue;
            }
            $idx = (int) $idx;
            if (isset($seen[$idx])) {
                $warnings[] = 'one_c: дублируется index ' . $idx;
            }
            $seen[$idx] = true;
        }
    }
    foreach (($data['bitrix_profiles'] ?? []) as $pid => $profile) {
        if (!is_array($profile)) {
            $warnings[] = 'bitrix_profiles.' . $pid . ' не объект';
            continue;
        }
        $headers = $profile['headers'] ?? null;
        if (!is_array($headers) || $headers === []) {
            $warnings[] = 'bitrix_profiles.' . $pid . ': headers пуст';
        }
        require_once __DIR__ . '/mapping_formula.php';
        $formulas = $profile['formulas'] ?? [];
        if (is_array($formulas)) {
            foreach ($formulas as $field => $expr) {
                if (!is_string($expr) || !mapping_formula_syntax_ok($expr)) {
                    $warnings[] = 'bitrix_profiles.' . $pid . ': некорректная формула для ' . $field;
                    continue;
                }
                if (preg_match_all('/"([^"]+)"/u', $expr, $matches)) {
                    foreach ($matches[1] as $header) {
                        if (!array_key_exists($header, $headers)) {
                            $warnings[] = 'bitrix_profiles.' . $pid . ': формула ' . $field
                                . ' ссылается на неизвестный заголовок «' . $header . '»';
                        }
                    }
                }
            }
        }
    }
    $oneFields = [];
    foreach (($data['one_c']['columns'] ?? []) as $row) {
        if (is_array($row) && is_string($row['field'] ?? null)) {
            $oneFields[] = $row['field'];
        }
    }
    foreach (['date_operation', 'agent', 'client_type', 'channel', 'category', 'deal_no', 'sales_amount', 'profit_ex_vat'] as $field) {
        if (!in_array($field, $oneFields, true)) {
            $warnings[] = 'one_c: отсутствует обязательное поле ' . $field;
        }
    }
    $newHeaders = $data['bitrix_profiles']['deals_export']['headers'] ?? [];
    $newFields = is_array($newHeaders) ? array_values($newHeaders) : [];
    foreach (['deal_no', 'deal_created_at', 'agent', 'deal_result', 'deal_status', 'category', 'client', 'client_id'] as $field) {
        if (!in_array($field, $newFields, true)) {
            $warnings[] = 'deals_export: отсутствует обязательное поле ' . $field;
        }
    }
    $newFormulas = $data['bitrix_profiles']['deals_export']['formulas'] ?? [];
    foreach (['sales_amount', 'profit', 'profit_ex_vat', 'sales_amount_after_refund', 'profit_after_refund_ex_vat'] as $field) {
        if (!is_array($newFormulas) || !isset($newFormulas[$field])) {
            $warnings[] = 'deals_export: отсутствует формула ' . $field;
        }
    }
    return $warnings;
}

/** Ошибки, при которых маппинг нельзя безопасно сохранять. */
function mapping_validation_errors(array $warnings): array
{
    return array_values(array_filter($warnings, static function (string $warning): bool {
        return str_contains($warning, 'некорректная формула')
            || str_contains($warning, 'неизвестный заголовок')
            || str_contains($warning, 'дублируется index')
            || str_contains($warning, 'строка без index или field')
            || str_contains($warning, 'отсутствует обязательное поле')
            || str_contains($warning, 'отсутствует формула');
    }));
}

function backup_mapping(): string
{
    $dir = project_root() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'backups';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $src = mapping_path();
    $dest = $dir . DIRECTORY_SEPARATOR . 'mapping_' . date('Y-m-d_His') . '.json';
    if (is_readable($src)) {
        copy($src, $dest);
    }
    return $dest;
}

/**
 * @param array<string, mixed> $data
 */
function save_mapping(array $data): void
{
    $data = normalize_mapping($data);
    $path = mapping_path();
    $tmp = $path . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Не удалось закодировать mapping.json');
    }
    if (is_readable($path)) {
        backup_mapping();
    }
    if (file_put_contents($tmp, $json . "\n") === false) {
        throw new RuntimeException('Не удалось записать временный mapping.json');
    }
    if (!@rename($tmp, $path)) {
        // Windows: rename поверх существующего файла часто падает
        if (!@copy($tmp, $path) || !@unlink($tmp)) {
            @unlink($tmp);
            throw new RuntimeException('Не удалось заменить mapping.json (файл занят?)');
        }
    }
    clear_mapping_cache();
}

/**
 * Поля 1С по позиции Excel (0-based). Ключ = index из mapping.json.
 *
 * @return array<int, string> index => field
 */
function one_c_columns_from_mapping(?array $mapping = null): array
{
    $mapping = $mapping ?? load_mapping();
    $cols = $mapping['one_c']['columns'] ?? [];
    if (!is_array($cols) || $cols === []) {
        $cols = default_mapping()['one_c']['columns'];
    }
    $out = [];
    foreach ($cols as $row) {
        if (!is_array($row)) {
            continue;
        }
        $field = clean_str($row['field'] ?? null);
        if ($field === null) {
            continue;
        }
        $idx = (int) ($row['index'] ?? -1);
        if ($idx < 0) {
            continue;
        }
        $out[$idx] = $field;
    }
    ksort($out, SORT_NUMERIC);
    return $out;
}

/** @return array<string, string> */
function one_c_extra_by_header(?array $mapping = null): array
{
    $mapping = $mapping ?? load_mapping();
    $extra = $mapping['one_c']['extra_by_header'] ?? [];
    if (!is_array($extra)) {
        return [];
    }
    $out = [];
    foreach ($extra as $header => $field) {
        $h = clean_str((string) $header);
        $f = clean_str(is_string($field) ? $field : null);
        if ($h !== null && $f !== null) {
            $out[$h] = $f;
        }
    }
    return $out;
}

/** @return list<string> */
function one_c_fingerprint_from_mapping(?array $mapping = null): array
{
    $mapping = $mapping ?? load_mapping();
    $fp = $mapping['one_c']['fingerprint'] ?? [];
    return is_array($fp) ? array_values(array_filter(array_map('strval', $fp))) : [];
}

/**
 * @return array<string, mixed>|null
 */
function bitrix_mapping_profile(string $profileId, ?array $mapping = null): ?array
{
    $mapping = $mapping ?? load_mapping();
    $profiles = $mapping['bitrix_profiles'] ?? [];
    if (!is_array($profiles) || !isset($profiles[$profileId]) || !is_array($profiles[$profileId])) {
        return null;
    }
    return $profiles[$profileId];
}

/** @return array<string, string> */
function bitrix_header_aliases_for_profile(string $profileId, ?array $mapping = null): array
{
    $profile = bitrix_mapping_profile($profileId, $mapping);
    if ($profile === null) {
        return [];
    }
    $headers = $profile['headers'] ?? [];
    if (!is_array($headers)) {
        return [];
    }
    $out = [];
    foreach ($headers as $header => $field) {
        $h = clean_str(str_replace("\n", ' ', (string) $header));
        $f = clean_str(is_string($field) ? $field : null);
        if ($h !== null && $f !== null) {
            $out[$h] = $f;
        }
    }
    return $out;
}

/** @return array<string, string> field => formula expression */
function bitrix_formulas_for_profile(string $profileId, ?array $mapping = null): array
{
    $profile = bitrix_mapping_profile($profileId, $mapping);
    if ($profile === null) {
        return [];
    }
    $formulas = $profile['formulas'] ?? [];
    if (!is_array($formulas)) {
        return [];
    }
    $out = [];
    foreach ($formulas as $field => $expr) {
        $f = clean_str(is_string($field) ? $field : null);
        $e = is_string($expr) ? trim($expr) : '';
        if ($f !== null && $e !== '') {
            $out[$f] = $e;
        }
    }
    return $out;
}

/** @return list<string> */
function bitrix_fingerprint_for_profile(string $profileId, ?array $mapping = null): array
{
    $profile = bitrix_mapping_profile($profileId, $mapping);
    if ($profile === null) {
        return [];
    }
    $fp = $profile['fingerprint'] ?? [];
    return is_array($fp) ? array_values(array_filter(array_map('strval', $fp))) : [];
}

/**
 * @param list<mixed> $headerRow
 */
function detect_bitrix_mapping_profile(array $headerRow, ?array $mapping = null): string
{
    $mapping = $mapping ?? load_mapping();
    $set = [];
    foreach ($headerRow as $cell) {
        $norm = clean_str(str_replace("\n", ' ', (string) $cell)) ?? '';
        if ($norm !== '') {
            $set[$norm] = true;
        }
    }
    $bestId = 'deals_export';
    $bestScore = -1;
    foreach (($mapping['bitrix_profiles'] ?? []) as $pid => $profile) {
        if (!is_array($profile)) {
            continue;
        }
        $fp = $profile['fingerprint'] ?? [];
        if (!is_array($fp) || $fp === []) {
            continue;
        }
        $hits = 0;
        foreach ($fp as $h) {
            if (isset($set[(string) $h])) {
                $hits++;
            }
        }
        $score = (int) round($hits / max(1, count($fp)) * 100);
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestId = (string) $pid;
        }
    }
    return $bestId;
}

/**
 * @return array{sales_amount_from:?string,profit_ex_vat_from:list<string>}
 */
function bitrix_enrich_config_for_profile(string $profileId, ?array $mapping = null): array
{
    $profile = bitrix_mapping_profile($profileId, $mapping);
    $enrich = is_array($profile) ? ($profile['enrich'] ?? []) : [];
    if (!is_array($enrich)) {
        $enrich = [];
    }
    $salesFrom = clean_str($enrich['sales_amount_from'] ?? null);
    $profitFrom = $enrich['profit_ex_vat_from'] ?? [];
    if (!is_array($profitFrom)) {
        $profitFrom = $profitFrom ? [(string) $profitFrom] : [];
    }
    $profitFrom = array_values(array_filter(array_map(
        static fn($x) => clean_str(is_string($x) ? $x : null),
        $profitFrom
    )));
    return [
        'sales_amount_from' => $salesFrom,
        'profit_ex_vat_from' => $profitFrom,
    ];
}

function bitrix_default_profile_id(?array $mapping = null): string
{
    $mapping = $mapping ?? load_mapping();
    $profiles = $mapping['bitrix_profiles'] ?? [];
    if (isset($profiles['deals_export'])) {
        return 'deals_export';
    }
    $keys = array_keys(is_array($profiles) ? $profiles : []);
    return (string) ($keys[0] ?? 'deals_export');
}

/**
 * Тип значения поля парсера для coerce при чтении Excel.
 * Любое поле из mapping.json допускается; неизвестные → string.
 *
 * @return 'string'|'number'|'date'|'datetime'
 */
function mapping_field_kind(string $field): string
{
    static $kinds = null;
    if ($kinds === null) {
        $kinds = [];
        foreach ([
            'date_operation', 'case_status_change_date', 'payment_date', 'realization_date',
            'service_date', 'start_date', 'end_date',
        ] as $f) {
            $kinds[$f] = 'date';
        }
        foreach ([
            'datetime_operation', 'deal_created_at', 'client_paid_at',
            'planned_close_date', 'last_activity_at',
        ] as $f) {
            $kinds[$f] = 'datetime';
        }
        foreach ([
            'sales_amount', 'profit', 'profit_ex_vat', 'supplier_commission', 'vat_commission',
            'markup', 'vat_markup', 'service_fee', 'vat_fee', 'sr', 'lr',
            'solid_bank_privilege', 'rs_cashback_points', 'points_ax', 'points_imp',
            'cashless', 'against_salary', 'loss_company', 'loss_employee',
            'calls_count', 'meetings_count', 'adults_count', 'children_count', 'nights_count',
            'total_nights', 'rooms_count', 'payment_rate', 'net_rub', 'net_supplier_currency',
            'additional_benefit', 'additional_benefit_currency', 'supplier_fee',
            'supplier_fee_currency', 'service_fee_currency', 'commission', 'commission_currency',
            'total_paid_by_client', 'total_supplier_pay', 'total_supplier_pay_currency',
            'total_client_pay', 'total_client_pay_currency', 'client_refund_amount',
            'supplier_refund_amount', 'supplier_refund_currency', 'commission_refund',
            'commission_refund_currency', 'supplier_fee_refund', 'supplier_fee_refund_currency',
            'service_fee_refund', 'service_fee_refund_currency', 'additional_benefit_refund',
            'additional_benefit_refund_currency', 'supplier_return_fee',
            'supplier_return_fee_currency', 'supplier_return_penalty',
            'supplier_return_penalty_currency', 'rstls_return_fee',
            'rstls_return_fee_currency', 'rstls_return_penalty',
            'rstls_return_penalty_currency', 'sales_amount_after_refund',
            'profit_after_refund', 'profit_after_refund_ex_vat', 'supplier_retained',
            'vat_factor',
        ] as $f) {
            $kinds[$f] = 'number';
        }
    }
    return $kinds[$field] ?? 'string';
}

/**
 * Привести ячейку Excel к типу поля парсера.
 */
function mapping_coerce_cell(string $field, mixed $value): mixed
{
    return match (mapping_field_kind($field)) {
        'number' => to_float($value),
        'date' => to_date_string($value),
        'datetime' => to_datetime_string($value),
        default => clean_str($value === null ? null : (string) $value),
    };
}
