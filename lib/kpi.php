<?php
/**
 * kpi.php — сборка KPI-полоски (Обзор и api/kpi.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/metrics.php';

/**
 * @param list<array> $rows sales_unified (или rollup-строки)
 * @param list<array> $ops1c
 * @param list<array> $dealsBx
 * @param array<string, mixed> $filters
 * @return array<string, mixed>
 */
function build_kpi_payload(array $rows, array $ops1c, array $dealsBx, array $filters): array
{
    $summary = summarize_sales($rows);
    $source = $filters['source'] ?? 'all';

    $opsTotal = rows_total_weight($ops1c);
    $dealsTotal = rows_total_weight($dealsBx);
    $opsRefunds = 0;
    foreach ($ops1c as $row) {
        if ((float) ($row['sales_amount'] ?? 0) < 0) {
            $opsRefunds += row_weight($row);
        }
    }
    $dealsSuccess = 0;
    foreach ($dealsBx as $row) {
        if (clean_str($row['deal_result'] ?? null) === 'Успех') {
            $dealsSuccess += row_weight($row);
        }
    }

    $dealsCount = '—';
    $dealsSub = '1С и Битрикс';
    $extraTitle = 'Доля 1С / Битрикс';
    $extraValue = '—';
    $extraSub = 'по сумме продаж';

    if ($source === '1c') {
        $dealsCount = format_count($opsTotal);
        $dealsSub = 'операции 1С';
        $extraTitle = 'Доля возвратов';
        $extraValue = format_pct($opsTotal ? $opsRefunds / $opsTotal * 100 : null);
        $extraSub = 'от всех операций';
    } elseif ($source === 'bitrix') {
        $dealsCount = format_count($dealsTotal);
        $dealsSub = 'создано сделок';
        $extraTitle = 'Конверсия';
        $extraValue = format_pct($dealsTotal ? $dealsSuccess / $dealsTotal * 100 : null);
        $extraSub = 'успех / создано';
    } else {
        $dealsCount = '1С: ' . format_count($opsTotal) . ' · Битрикс: ' . format_count($dealsTotal);
        $dealsSub = 'операции 1С · сделки Битрикс';
        $extraValue = ($summary['share_1c_pct'] !== null)
            ? format_pct($summary['share_1c_pct']) . ' / ' . format_pct($summary['share_bitrix_pct'])
            : '—';
    }

    return [
        'sales_total' => format_rub($summary['sales_total']),
        'profit_total' => format_rub($summary['profit_total']),
        'margin' => format_pct($summary['margin_pct']),
        'deals_count' => $dealsCount,
        'deals_sub' => $dealsSub,
        'extra_title' => $extraTitle,
        'extra_value' => $extraValue,
        'extra_sub' => $extraSub,
        'avg_check' => format_rub($summary['avg_check']),
    ];
}
