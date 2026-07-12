<?php
/**
 * metrics.php
 *
 * Подсчёт KPI и вспомогательное форматирование чисел для ответов API.
 */

declare(strict_types=1);

/** Сводка по sales_unified. */
function summarize_sales(array $rows): array
{
    $salesTotal = 0.0;
    $profitTotal = 0.0;
    $sales1c = 0.0;
    $salesBitrix = 0.0;
    $count1c = 0;
    $countBitrix = 0;
    $refundSum = 0.0;
    $refundCount = 0;

    foreach ($rows as $row) {
        $sales = (float) ($row['sales_amount'] ?? 0);
        $profit = (float) ($row['profit_ex_vat'] ?? 0);
        $salesTotal += $sales;
        $profitTotal += $profit;
        if (($row['source'] ?? '') === '1c') {
            $sales1c += $sales;
            $count1c++;
            if ($sales < 0) {
                $refundSum += $sales;
                $refundCount++;
            }
        } elseif (($row['source'] ?? '') === 'bitrix') {
            $salesBitrix += $sales;
            $countBitrix++;
        }
    }

    $rowCount = count($rows);

    return [
        'sales_total' => $salesTotal,
        'profit_total' => $profitTotal,
        'margin_pct' => $salesTotal ? $profitTotal / $salesTotal * 100 : null,
        'row_count' => $rowCount,
        'avg_check' => $rowCount ? $salesTotal / $rowCount : null,
        'sales_1c' => $sales1c,
        'sales_bitrix' => $salesBitrix,
        'share_1c_pct' => $salesTotal ? $sales1c / $salesTotal * 100 : null,
        'share_bitrix_pct' => $salesTotal ? $salesBitrix / $salesTotal * 100 : null,
        'count_1c' => $count1c,
        'count_bitrix' => $countBitrix,
        'refund_sum_1c' => $refundSum,
        'refund_count_1c' => $refundCount,
    ];
}

function format_rub($value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $n = (float) $value;
    $neg = $n < 0;
    $n = abs($n);
    $body = number_format($n, 2, ',', ' ');
    return ($neg ? '−' : '') . $body . ' ₽';
}

function format_count($value): string
{
    if ($value === null) {
        return '—';
    }
    return number_format((int) $value, 0, ',', ' ');
}

function format_pct($value): string
{
    if ($value === null) {
        return '—';
    }
    return number_format((float) $value, 1, ',', ' ') . ' %';
}

/** Ключ периода для группировки дат. */
function period_key(string $day, string $granularity): string
{
    $ts = strtotime($day) ?: time();
    if ($granularity === 'month') {
        return date('Y-m', $ts);
    }
    if ($granularity === 'week') {
        return date('o-\WW', $ts);
    }
    return date('Y-m-d', $ts);
}

/** Группировка по полю: сумма продаж/прибыли. */
function group_by_dimension(array $rows, string $field, int $topN = 15): array
{
    $groups = [];
    foreach ($rows as $row) {
        $key = clean_str($row[$field] ?? null) ?? 'Не указано';
        if (!isset($groups[$key])) {
            $groups[$key] = ['label' => $key, 'sales' => 0.0, 'profit' => 0.0, 'count' => 0];
        }
        $groups[$key]['sales'] += (float) ($row['sales_amount'] ?? 0);
        $groups[$key]['profit'] += (float) ($row['profit_ex_vat'] ?? 0);
        $groups[$key]['count']++;
    }
    usort($groups, fn($a, $b) => $b['profit'] <=> $a['profit']);
    return array_slice(array_values($groups), 0, $topN);
}

/** Динамика по периоду (day|week|month). */
function trend_series(array $rows, string $granularity = 'day'): array
{
    $buckets = [];
    foreach ($rows as $row) {
        $date = $row['date'] ?? null;
        if (!$date) {
            continue;
        }
        $ts = strtotime($date);
        if (!$ts) {
            continue;
        }
        if ($granularity === 'month') {
            $period = date('Y-m', $ts);
        } elseif ($granularity === 'week') {
            $period = date('o-\WW', $ts);
        } else {
            $period = date('Y-m-d', $ts);
        }
        if (!isset($buckets[$period])) {
            $buckets[$period] = ['period' => $period, 'sales' => 0.0, 'profit' => 0.0, 'count' => 0];
        }
        $buckets[$period]['sales'] += (float) ($row['sales_amount'] ?? 0);
        $buckets[$period]['profit'] += (float) ($row['profit_ex_vat'] ?? 0);
        $buckets[$period]['count']++;
    }
    ksort($buckets);
    return array_values($buckets);
}

/** Группировка по полю с выбором метрики для сортировки и top-N + «Прочее». */
function group_by_dimension_metric(array $rows, string $field, string $metric = 'sales', int $topN = 8): array
{
    $groups = [];
    foreach ($rows as $row) {
        $key = clean_str($row[$field] ?? null) ?? 'Не указано';
        if (!isset($groups[$key])) {
            $groups[$key] = ['label' => $key, 'sales' => 0.0, 'profit' => 0.0, 'count' => 0];
        }
        $groups[$key]['sales'] += (float) ($row['sales_amount'] ?? 0);
        $groups[$key]['profit'] += (float) ($row['profit_ex_vat'] ?? 0);
        $groups[$key]['count']++;
    }
    $list = array_values($groups);
    usort($list, function ($a, $b) use ($metric) {
        $va = $metric === 'profit' ? $a['profit'] : ($metric === 'count' ? $a['count'] : $a['sales']);
        $vb = $metric === 'profit' ? $b['profit'] : ($metric === 'count' ? $b['count'] : $b['sales']);
        return $vb <=> $va;
    });
    if (count($list) <= $topN) {
        return $list;
    }
    $top = array_slice($list, 0, $topN);
    $rest = array_slice($list, $topN);
    $other = ['label' => 'Прочее', 'sales' => 0.0, 'profit' => 0.0, 'count' => 0];
    foreach ($rest as $r) {
        $other['sales'] += $r['sales'];
        $other['profit'] += $r['profit'];
        $other['count'] += $r['count'];
    }
    if ($other['sales'] != 0.0 || $other['profit'] != 0.0 || $other['count'] > 0) {
        $top[] = $other;
    }
    return $top;
}

/** Топ клиентов по сумме продаж. */
function top_clients_by_sales(array $rows, int $topN = 10): array
{
    return group_by_dimension_metric($rows, 'client', 'sales', $topN);
}

/** Группа воронки обращений (как в Excel «Свод обращения»). */
function deal_appeal_funnel_group(?string $dealResult): string
{
    $r = clean_str($dealResult) ?? '';
    if ($r === 'Успех') {
        return 'Успех';
    }
    $lost = [
        'Клиент попросил отменить',
        'Клиент не вернулся с фидбэком',
        'Дорого',
        'Другое',
        'Нет предложений',
        'Возврат подтверждён',
        'Возврат',
        'Cancelled',
        'Cancelled after confirmed',
        'Cancelled after confirmed alternative choice',
        'Unfulfilled',
    ];
    if (in_array($r, $lost, true)) {
        return 'Отказ';
    }
    return 'В процессе';
}

/** Группировка сделок Битрикс по полю (count). */
function group_deals_by_field(array $rows, string $field, int $topN = 12): array
{
    $groups = [];
    foreach ($rows as $row) {
        $key = clean_str($row[$field] ?? null) ?? 'Не указано';
        if (!isset($groups[$key])) {
            $groups[$key] = ['label' => $key, 'count' => 0];
        }
        $groups[$key]['count']++;
    }
    $list = array_values($groups);
    usort($list, fn($a, $b) => $b['count'] <=> $a['count']);
    return array_slice($list, 0, $topN);
}

/** Группировка сделок по воронке (Успех / Отказ / В процессе). */
function group_deals_by_funnel(array $rows): array
{
    $groups = ['Успех' => 0, 'Отказ' => 0, 'В процессе' => 0];
    foreach ($rows as $row) {
        $g = deal_appeal_funnel_group($row['deal_result'] ?? null);
        $groups[$g]++;
    }
    $out = [];
    foreach ($groups as $label => $count) {
        if ($count > 0) {
            $out[] = ['label' => $label, 'count' => $count];
        }
    }
    return $out;
}

/** Динамика количества сделок по месяцам (deal_created_at). */
function deals_count_trend(array $rows, string $granularity = 'month'): array
{
    $buckets = [];
    foreach ($rows as $row) {
        $created = $row['deal_created_at'] ?? null;
        if (!$created) {
            continue;
        }
        $day = substr((string) $created, 0, 10);
        $ts = strtotime($day);
        if (!$ts) {
            continue;
        }
        if ($granularity === 'month') {
            $period = date('Y-m', $ts);
        } elseif ($granularity === 'week') {
            $period = date('o-\WW', $ts);
        } else {
            $period = date('Y-m-d', $ts);
        }
        if (!isset($buckets[$period])) {
            $buckets[$period] = ['period' => $period, 'count' => 0];
        }
        $buckets[$period]['count']++;
    }
    ksort($buckets);
    return array_values($buckets);
}
