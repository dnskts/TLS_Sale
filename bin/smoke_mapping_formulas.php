<?php
/**
 * Smoke-тест бизнес-идентификаторов и формул Б24 NEW.
 * Запуск: php bin/smoke_mapping_formulas.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_lib('mapping_formula.php');
require_lib('mapping.php');
require_lib('parse_1c.php');
require_lib('bitrix_export.php');
require_lib('filters.php');

function smoke_assert_same(mixed $expected, mixed $actual, string $label): void
{
    if (is_float($expected) || is_float($actual)) {
        if (abs((float) $expected - (float) $actual) < 0.00001) {
            return;
        }
    } elseif ($expected === $actual) {
        return;
    }
    throw new RuntimeException(
        $label . ': expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true)
    );
}

smoke_assert_same(
    '210242',
    parse_1c_case_id('Кейс 00000210242 от 10.07.2026 11:30:41'),
    'case_id = lead_id'
);
smoke_assert_same(
    '0000-057583',
    parse_1c_order_no('Счет клиенту(Продажа) 0000-057583 от 10.07.2026 19:57:57'),
    'deal_no = order_no'
);

smoke_assert_same(5.0, mapping_formula_eval('NZ(missing) + 5', [], []), 'NZ(null)');
smoke_assert_same(1.20, bitrix_vat_factor_for_date('2025-12-31'), 'VAT 2025');
smoke_assert_same(1.22, bitrix_vat_factor_for_date('2026-01-01'), 'VAT 2026');

$default = default_mapping();
smoke_assert_same(16, count($default['one_c']['columns']), 'compact 1C columns count');
$oneByIndex = [];
foreach ($default['one_c']['columns'] as $column) {
    $oneByIndex[(int) $column['index']] = $column['field'];
}
smoke_assert_same('channel', $oneByIndex[7] ?? null, '1C index 7');
smoke_assert_same('category', $oneByIndex[20] ?? null, '1C category index 20');
smoke_assert_same(
    'request_type',
    $default['one_c']['extra_by_header']['Тип запроса'] ?? null,
    '1C request type by header'
);
smoke_assert_same(
    'agent',
    $default['bitrix_profiles']['deals_export']['headers']['Ответственный'] ?? null,
    'B24 agent alias'
);
smoke_assert_same(
    'deal_no',
    $default['bitrix_profiles']['deals_export']['headers']['ID'] ?? null,
    'B24 deal alias'
);

$withoutPaidDate = enrich_bitrix_export_row(
    ['deal_created_at' => '2026-07-01 10:00:00'],
    ['sales_amount_from' => null, 'profit_ex_vat_from' => ['profit_ex_vat']],
    'deals_export',
    true,
    true
);
smoke_assert_same(null, $withoutPaidDate['date_for_sales'] ?? null, 'no sales date fallback');
smoke_assert_same(false, $withoutPaidDate['date_fallback_used'] ?? null, 'fallback flag');
smoke_assert_same(
    0,
    count(apply_sales_filters([['date' => null, 'source' => 'bitrix', 'agent_key' => 'unknown:']], [
        'date_from' => '2026-01-01',
        'date_to' => '2026-12-31',
        'source' => 'all',
        'show_unknown_agents' => true,
    ])),
    'undated B24 sale excluded from financial period'
);

$headers = [
    'Всего к оплате Клиентом' => 46200,
    'Дополнительная выгода' => null,
    'Сервисный сбор' => 0,
    'Комиссия' => 4200,
    'Сумма возврата клиенту' => 0,
    'Возврат клиенту дополнительной выгоды' => 0,
    'Возврат клиенту сбора РС ТЛС' => 0,
    'Возврат комиссии поставщика' => 0,
    'Сбор РС ТЛС за возврат' => 0,
    'Штраф РС ТЛС за возврат' => 0,
    'Сбор поставщика за возврат' => 0,
    'Штраф поставщика за возврат' => 0,
];
$row = ['vat_factor' => 1.22];
$row = mapping_apply_formulas(
    $row,
    $default['bitrix_profiles']['deals_export']['formulas'],
    $headers
);
smoke_assert_same(46200.0, $row['sales_amount'] ?? null, 'sales_amount');
smoke_assert_same(4200.0, $row['profit'] ?? null, 'profit');
smoke_assert_same(3442.622950819672, $row['profit_ex_vat'] ?? null, 'profit_ex_vat');
smoke_assert_same(46200.0, $row['sales_amount_after_refund'] ?? null, 'sales_after_refund');

$headers['Сумма возврата клиенту'] = 46200;
$headers['Возврат комиссии поставщика'] = 4200;
$returned = mapping_apply_formulas(
    ['vat_factor' => 1.22],
    $default['bitrix_profiles']['deals_export']['formulas'],
    $headers
);
smoke_assert_same(0.0, $returned['sales_amount_after_refund'] ?? null, 'full refund sales');
smoke_assert_same(0.0, $returned['profit_after_refund'] ?? null, 'full refund profit');
smoke_assert_same(0.0, $returned['profit_after_refund_ex_vat'] ?? null, 'full refund profit ex VAT');

$sample1c = project_root() . DIRECTORY_SEPARATOR . 'выгрузки с прода' . DIRECTORY_SEPARATOR . '1C 14.07.xlsx';
if (is_file($sample1c)) {
    $ops = parse_1c($sample1c, 'TDSheet');
    smoke_assert_same(7309, count($ops), '1C sample rows');
    smoke_assert_same(false, array_key_exists('issuing_agent', $ops[0] ?? []), '1C unused field removed');
    $caseFound = false;
    foreach ($ops as $op) {
        if (($op['case_id'] ?? null) === '210242') {
            smoke_assert_same('0000-057567', $op['deal_no'] ?? null, '1C deal/order');
            smoke_assert_same('Корп', $op['client_type'] ?? null, '1C client type');
            smoke_assert_same('2026-07-10', $op['service_date'] ?? null, '1C service date');
            $caseFound = true;
            break;
        }
    }
    smoke_assert_same(true, $caseFound, '1C control case');
}

$sampleB24 = project_root() . DIRECTORY_SEPARATOR . 'выгрузки с прода' . DIRECTORY_SEPARATOR . 'Б24 NEW.xlsx';
if (is_file($sampleB24)) {
    $deals = parse_bitrix_export($sampleB24, 'Отчет по сделкам', 'deals_export');
    smoke_assert_same(2317, count($deals), 'B24 sample rows');
    smoke_assert_same(false, array_key_exists('deal_title', $deals[0] ?? []), 'B24 unused field removed');
    smoke_assert_same(false, array_key_exists('commission_currency', $deals[0] ?? []), 'B24 currency duplicate removed');
    $byId = [];
    foreach ($deals as $deal) {
        $id = (string) ($deal['deal_no'] ?? '');
        if ($id === '36149' || $id === '36150') {
            $byId[$id] = $deal;
        }
    }
    smoke_assert_same(46200.0, $byId['36149']['sales_amount_after_refund'] ?? null, 'B24 36149 sales');
    smoke_assert_same(3442.622950819672, $byId['36149']['profit_ex_vat'] ?? null, 'B24 36149 profit');
    smoke_assert_same(0.0, $byId['36150']['sales_amount_after_refund'] ?? null, 'B24 36150 refund sales');
    smoke_assert_same(0.0, $byId['36150']['profit_after_refund_ex_vat'] ?? null, 'B24 36150 refund profit');
}

echo "OK mapping formulas\n";
