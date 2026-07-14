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
    $oneCFields = [
        'date_operation', 'datetime_operation', 'agent', 'issuing_agent', 'supplier',
        'card_type', 'case_raw', 'channel', 'category', 'id_crm',
        'case_status_change_date', 'client_from_case',
        // Excel 1C с ~2026: между «Клиент из кейса» и «ID клиента» стоит «Департамент»
        'case_department', 'id_client_from_case', 'related_company',
        'case_cost_codes', 'client', 'service_scheme', 'order_raw', 'department',
        'related_service_type', 'product', 'payment_date', 'realization_date',
        'sales_amount', 'profit', 'profit_ex_vat', 'supplier_commission', 'vat_commission',
        'markup', 'vat_markup', 'service_fee', 'vat_fee', 'sr', 'lr',
        'solid_bank_privilege', 'rs_cashback_points', 'points_ax', 'points_imp',
        'cashless', 'against_salary', 'certificate', 'loss_company', 'loss_employee', 'travelers',
    ];
    $oneCHeaders = [
        'Дата операции', 'Дата операции', 'Агент', 'Выписывающий агент', 'Поставщик',
        'Тип карты', 'Кейс', 'Канал связи', 'Категория', 'I d CRM',
        'Дата смены статуса кейса', 'Клиент из кейса', 'Департамент', 'ID клиента из кейса', 'Связанная компания',
        'Кост коды кейса', 'Клиент', 'Схема реализации услуг', 'Заказ', 'Подразделение',
        'Связанный вид услуги', 'Продукт', 'Дата оплаты', 'Дата реализация',
        'Сумма продажи', 'Прибыль', 'Прибыль без НДС', 'Комиссия поставщика', 'Сумма НДС(Комиссии)',
        'Наценка', 'Сумма НДС(Наценка)', 'Сервисный сбор', 'Сумма НДС(Сбор)', 'SR', 'LR',
        'Привилегия SOLID BANK', 'Баллы RS Cashback', 'Баллы AX', 'Баллы IMP',
        'Безнал', 'В счет ЗП', 'Сертификат', 'Убыток на компанию', 'Убыток на сотрудника', 'Путешественники',
    ];
    $columns = [];
    foreach ($oneCFields as $i => $field) {
        $columns[] = [
            'index' => $i,
            'field' => $field,
            'header' => $oneCHeaders[$i] ?? $field,
            'label' => $oneCHeaders[$i] ?? $field,
        ];
    }

    $dealsExport = [
        'ID' => 'deal_no',
        'Название' => 'deal_title',
        'Дата создания' => 'deal_created_at',
        'Стадия' => 'deal_status',
        'Ответственный' => 'responsible_person',
        '% участия агента в продаже' => 'agent_sale_participation',
        'Тип клиента' => 'client_type',
        'Клиент' => 'client',
        'ID клиента' => 'id_client',
        'Тип карты' => 'card_type',
        'Категория' => 'category',
        'Канал связи' => 'channel',
        'Лид' => 'lead_id',
        'Тип запроса' => 'request_type',
        'Дата оказания услуги' => 'service_date',
        'Дата оплаты Клиентом' => 'client_paid_at',
        'Плановая дата закрытия' => 'planned_close_date',
        'Дата последней активности' => 'last_activity_at',
        'Источник' => 'lead_source',
        'Маркетинговый канал' => 'lead_source',
        'Количество звонков' => 'calls_count',
        'Количество встреч' => 'meetings_count',
        'Результат сделки' => 'deal_result',
        'Причина стадии Сделка проиграна' => 'lost_deal_reason',
        'Полное наименование организации' => 'partner',
        'Сервисный сбор' => 'service_fee',
        'Комиссия' => '_commission',
        'Всего к оплате Клиентом' => '_total_client_pay',
    ];

    $universal = [
        'Номер сделки' => 'deal_no',
        'Название сделки' => 'deal_title',
        'Лид' => 'lead_id',
        '% участия агента в продаже' => 'agent_sale_participation',
        'Дата создания сделки' => 'deal_created_at',
        'Статус сделки' => 'deal_status',
        'Ответственное лицо' => 'responsible_person',
        'Тип клиента' => 'client_type',
        'Клиент' => 'client',
        'ID клиента' => 'id_client',
        'Тип карты' => 'card_type',
        'Полное наименование организации' => 'partner',
        'Категория' => 'category',
        'Канал связи' => 'channel',
        'Маркетинговый канал' => 'lead_source',
        'Тип запроса' => 'request_type',
        'Дата оказания услуги' => 'service_date',
        'Дата платы клиентом' => 'client_paid_at',
        'Сумма продажи' => 'sales_amount',
        'Прибыль' => 'profit',
        'Прибыль без НДС' => 'profit_ex_vat',
        'Комиссия' => '_commission',
        'Сервисный сбор' => 'service_fee',
        'Результат сделки' => 'deal_result',
        'Причина стадии Сделка проиграна' => 'lost_deal_reason',
        'Партнер' => 'partner',
    ];

    return [
        'version' => 1,
        'one_c' => [
            'mode' => 'by_index',
            'sheet_hint' => 'TDSheet',
            'fingerprint' => ['Дата операции', 'Агент', 'Поставщик', 'Сумма продажи'],
            'columns' => $columns,
            'extra_by_header' => ['Тип клиента' => 'client_type'],
        ],
        'bitrix_profiles' => [
            'deals_export' => [
                'label' => 'Отчет по сделкам (новый)',
                'sheet_hint' => 'Отчет по сделкам',
                'fingerprint' => [
                    'ID', 'Ответственный', 'Результат сделки', 'Всего к оплате Клиентом',
                    'Комиссия', 'Сервисный сбор', 'Дата создания', 'Стадия', 'Клиент',
                ],
                'headers' => $dealsExport,
                'enrich' => [
                    'sales_amount_from' => '_total_client_pay',
                    'profit_ex_vat_from' => ['_commission', 'service_fee'],
                ],
            ],
            'universal' => [
                'label' => 'Универсальный отчёт (старый)',
                'sheet_hint' => null,
                'fingerprint' => ['Номер сделки', 'Ответственное лицо', 'Сумма продажи', 'Статус сделки'],
                'headers' => $universal,
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
                array_values($universal),
                ['sales_amount', 'profit', 'profit_ex_vat', 'date_for_sales', 'date_fallback_used', 'source', 'bitrix_format']
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
    }
    return $warnings;
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
            'service_date',
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
            'calls_count', 'meetings_count', '_commission', '_total_client_pay',
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
