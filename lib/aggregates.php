<?php
/**
 * aggregates.php — предрасчёт rollups при pipeline для быстрых API.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/metrics.php';

function aggregates_dir(): string
{
    return project_root() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'aggregates';
}

function aggregates_manifest_path(): string
{
    return aggregates_dir() . DIRECTORY_SEPARATOR . 'manifest.json';
}

/** @return array<string, string> */
function aggregates_file_paths(): array
{
    $dir = aggregates_dir();
    return [
        'sales' => $dir . DIRECTORY_SEPARATOR . 'sales_rollup.json',
        'ops' => $dir . DIRECTORY_SEPARATOR . 'ops_rollup.json',
        'deals' => $dir . DIRECTORY_SEPARATOR . 'deals_rollup.json',
    ];
}

function rollup_bucket_key(array $parts): string
{
    $norm = [];
    foreach ($parts as $p) {
        if (is_array($p)) {
            $copy = $p;
            sort($copy);
            $norm[] = json_encode($copy, JSON_UNESCAPED_UNICODE);
        } else {
            $norm[] = (string) $p;
        }
    }
    return implode("\x1e", $norm);
}

/** @param array<string, mixed> $bucket */
function rollup_merge_bucket(array &$bucket, float $sales, float $profit, int $n = 1): void
{
    $bucket['sales'] = ($bucket['sales'] ?? 0.0) + $sales;
    $bucket['profit'] = ($bucket['profit'] ?? 0.0) + $profit;
    $bucket['n'] = ($bucket['n'] ?? 0) + $n;
}

/**
 * @param list<array> $unified
 * @return list<array>
 */
function build_sales_rollup(array $unified): array
{
    $map = [];
    foreach ($unified as $row) {
        $teams = $row['agent_teams'] ?? [($row['agent_team'] ?? '')];
        $key = rollup_bucket_key([
            $row['date'] ?? '',
            $row['source'] ?? '',
            $row['agent_key'] ?? '',
            $row['agent_team'] ?? '',
            $teams,
            $row['agent_display'] ?? '',
            clean_str($row['category'] ?? null),
            clean_str($row['channel'] ?? null),
            clean_str($row['client_type'] ?? null),
            clean_str($row['card_type'] ?? null),
            clean_str($row['client'] ?? null),
            clean_str($row['partner_or_supplier'] ?? null),
            clean_str($row['request_type'] ?? null),
            clean_str($row['country'] ?? null),
            clean_str($row['city'] ?? null),
            clean_str($row['hotel'] ?? null),
        ]);
        if (!isset($map[$key])) {
            $map[$key] = [
                'd' => $row['date'] ?? null,
                's' => $row['source'] ?? '',
                'ak' => (string) ($row['agent_key'] ?? ''),
                'at' => $row['agent_team'] ?? '',
                'ats' => $teams,
                'ad' => $row['agent_display'] ?? '',
                'cat' => clean_str($row['category'] ?? null),
                'ch' => clean_str($row['channel'] ?? null),
                'ct' => clean_str($row['client_type'] ?? null),
                'card' => clean_str($row['card_type'] ?? null),
                'cl' => clean_str($row['client'] ?? null),
                'pt' => clean_str($row['partner_or_supplier'] ?? null),
                'rt' => clean_str($row['request_type'] ?? null),
                'country' => clean_str($row['country'] ?? null),
                'city' => clean_str($row['city'] ?? null),
                'hotel' => clean_str($row['hotel'] ?? null),
                'sales' => 0.0,
                'profit' => 0.0,
                'n' => 0,
            ];
        }
        rollup_merge_bucket(
            $map[$key],
            (float) ($row['sales_amount'] ?? 0),
            (float) ($row['profit_ex_vat'] ?? 0)
        );
    }
    return array_values($map);
}

/**
 * @param list<array> $ops
 * @return list<array>
 */
function build_ops_rollup(array $ops, array $settings): array
{
    $map = [];
    foreach ($ops as $row) {
        $resolved = resolve_agent_1c($row['agent'] ?? null, $settings, $row['client_type'] ?? null);
        $teams = $resolved['teams'] ?? [$resolved['team']];
        $category = clean_str($row['category'] ?? null);
        $sales = (float) ($row['sales_amount'] ?? 0);
        $isRefund = $sales < 0;
        $key = rollup_bucket_key([
            $row['date_operation'] ?? '',
            $resolved['agent_key'],
            $resolved['team'],
            $teams,
            $category,
            clean_str($row['channel'] ?? null),
            clean_str($row['client'] ?? null),
            clean_str($row['supplier'] ?? null),
            clean_str($row['card_type'] ?? null),
            clean_str($row['client_type'] ?? null),
            clean_str($row['request_type'] ?? null),
            $isRefund ? 'refund' : 'sale',
            !empty($row['payment_date']) ? '1' : '0',
        ]);
        if (!isset($map[$key])) {
            $map[$key] = [
                'd' => $row['date_operation'] ?? null,
                'ak' => $resolved['agent_key'],
                'at' => $resolved['team'],
                'ats' => $teams,
                'cat' => $category,
                'ch' => clean_str($row['channel'] ?? null),
                'cl' => clean_str($row['client'] ?? null),
                'pt' => clean_str($row['supplier'] ?? null),
                'card' => clean_str($row['card_type'] ?? null),
                'ct' => clean_str($row['client_type'] ?? null),
                'rt' => clean_str($row['request_type'] ?? null),
                'ref' => $isRefund ? 1 : 0,
                'has_pay' => !empty($row['payment_date']) ? 1 : 0,
                'sales' => 0.0,
                'profit' => 0.0,
                'n' => 0,
            ];
        }
        rollup_merge_bucket($map[$key], $sales, (float) ($row['profit_ex_vat'] ?? 0));
    }
    return array_values($map);
}

/**
 * @param list<array> $deals
 * @return list<array>
 */
function build_deals_rollup(array $deals, array $settings): array
{
    $map = [];
    foreach ($deals as $row) {
        $resolved = resolve_agent_bitrix($row['agent'] ?? null, $settings);
        $teams = $resolved['teams'] ?? [$resolved['team']];
        $created = $row['deal_created_at'] ?? null;
        $day = $created ? substr((string) $created, 0, 10) : '';
        $paid = $row['date_operation'] ?? null;
        $paidDay = $paid ? substr((string) $paid, 0, 10) : '';
        $key = rollup_bucket_key([
            $day,
            $paidDay,
            $resolved['agent_key'],
            $resolved['team'],
            $teams,
            clean_str($row['category'] ?? null),
            clean_str($row['channel'] ?? null),
            clean_str($row['client'] ?? null),
            clean_str($row['supplier'] ?? null),
            clean_str($row['card_type'] ?? null),
            clean_str($row['client_type'] ?? null),
            clean_str($row['request_type'] ?? null),
            clean_str($row['deal_result'] ?? null),
            clean_str($row['deal_status'] ?? null),
            clean_str($row['lost_deal_reason'] ?? null),
        ]);
        if (!isset($map[$key])) {
            $map[$key] = [
                'd' => $day ?: null,
                'paid_d' => $paidDay ?: null,
                'ak' => $resolved['agent_key'],
                'at' => $resolved['team'],
                'ats' => $teams,
                'ad' => $resolved['name_display'],
                'cat' => clean_str($row['category'] ?? null),
                'ch' => clean_str($row['channel'] ?? null),
                'cl' => clean_str($row['client'] ?? null),
                'pt' => clean_str($row['supplier'] ?? null),
                'card' => clean_str($row['card_type'] ?? null),
                'ct' => clean_str($row['client_type'] ?? null),
                'rt' => clean_str($row['request_type'] ?? null),
                'dr' => clean_str($row['deal_result'] ?? null),
                'ds' => clean_str($row['deal_status'] ?? null),
                'ldr' => clean_str($row['lost_deal_reason'] ?? null),
                'n' => 0,
            ];
        }
        $map[$key]['n'] = ($map[$key]['n'] ?? 0) + 1;
    }
    return array_values($map);
}

/**
 * @param list<array> $salesRollup
 * @param list<array> $opsRollup
 * @param list<array> $dealsRollup
 */
function save_aggregates(string $loadedAt, array $salesRollup, array $opsRollup, array $dealsRollup): void
{
    $dir = aggregates_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $paths = aggregates_file_paths();
    $files = [
        'sales' => ['path' => $paths['sales'], 'rows' => $salesRollup],
        'ops' => ['path' => $paths['ops'], 'rows' => $opsRollup],
        'deals' => ['path' => $paths['deals'], 'rows' => $dealsRollup],
    ];
    foreach ($files as $name => $spec) {
        $payload = ['built_at' => $loadedAt, 'rows' => $spec['rows']];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Не удалось сохранить ' . $name . '_rollup.json');
        }
        file_put_contents($spec['path'], $json);
    }
    $manifest = [
        'built_at' => $loadedAt,
        'counts' => [
            'sales_buckets' => count($salesRollup),
            'ops_buckets' => count($opsRollup),
            'deals_buckets' => count($dealsRollup),
        ],
    ];
    $manifestJson = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($manifestJson === false) {
        throw new RuntimeException('Не удалось сохранить manifest.json');
    }
    file_put_contents(aggregates_manifest_path(), $manifestJson);
}

function aggregates_manifest_valid(): bool
{
    $meta = storage_load_meta();
    $loadedAt = $meta['loaded_at'] ?? null;
    if (!$loadedAt) {
        return false;
    }
    $path = aggregates_manifest_path();
    if (!is_readable($path)) {
        return false;
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) && ($data['built_at'] ?? null) === $loadedAt;
}

/** @return list<array>|null */
function load_sales_rollup_rows(): ?array
{
    if (!aggregates_manifest_valid()) {
        return null;
    }
    $path = aggregates_file_paths()['sales'];
    if (!is_readable($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        return null;
    }
    $rows = $data['rows'] ?? null;
    return is_array($rows) ? $rows : null;
}

/** @return list<array>|null */
function load_ops_rollup_rows(): ?array
{
    if (!aggregates_manifest_valid()) {
        return null;
    }
    $path = aggregates_file_paths()['ops'];
    if (!is_readable($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        return null;
    }
    $rows = $data['rows'] ?? null;
    return is_array($rows) ? $rows : null;
}

/** @return list<array>|null */
function load_deals_rollup_rows(): ?array
{
    if (!aggregates_manifest_valid()) {
        return null;
    }
    $path = aggregates_file_paths()['deals'];
    if (!is_readable($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        return null;
    }
    $rows = $data['rows'] ?? null;
    return is_array($rows) ? $rows : null;
}

/** @param array<string, mixed> $b */
function sales_rollup_to_row(array $b): array
{
    return [
        'date' => $b['d'] ?? null,
        'source' => $b['s'] ?? '',
        'agent_key' => $b['ak'] ?? '',
        'agent_team' => $b['at'] ?? '',
        'agent_teams' => $b['ats'] ?? [($b['at'] ?? '')],
        'agent_display' => $b['ad'] ?? ($b['ak'] ?? ''),
        'category' => $b['cat'] ?? null,
        'channel' => $b['ch'] ?? null,
        'client_type' => $b['ct'] ?? null,
        'card_type' => $b['card'] ?? null,
        'client' => $b['cl'] ?? null,
        'partner_or_supplier' => $b['pt'] ?? null,
        'request_type' => $b['rt'] ?? null,
        'country' => $b['country'] ?? null,
        'city' => $b['city'] ?? null,
        'hotel' => $b['hotel'] ?? null,
        'sales_amount' => (float) ($b['sales'] ?? 0),
        'profit_ex_vat' => (float) ($b['profit'] ?? 0),
        'rollup_n' => max(1, (int) ($b['n'] ?? 1)),
    ];
}

/** @param array<string, mixed> $b */
function ops_rollup_to_row(array $b): array
{
    $isRefund = !empty($b['ref']);
    $sales = (float) ($b['sales'] ?? 0);
    if ($isRefund && $sales >= 0) {
        $sales = -abs($sales);
    }
    return [
        'date_operation' => $b['d'] ?? null,
        'agent_key' => $b['ak'] ?? '',
        'agent_team' => $b['at'] ?? '',
        'agent_teams' => $b['ats'] ?? [($b['at'] ?? '')],
        'category' => $b['cat'] ?? null,
        'channel' => $b['ch'] ?? null,
        'client' => $b['cl'] ?? null,
        'supplier' => $b['pt'] ?? null,
        'card_type' => $b['card'] ?? null,
        'client_type' => $b['ct'] ?? null,
        'request_type' => $b['rt'] ?? null,
        'sales_amount' => $sales,
        'profit_ex_vat' => (float) ($b['profit'] ?? 0),
        'payment_date' => !empty($b['has_pay']) ? '1' : null,
        'rollup_n' => max(1, (int) ($b['n'] ?? 1)),
    ];
}

/** @param array<string, mixed> $b */
function deals_rollup_to_row(array $b): array
{
    $day = $b['d'] ?? null;
    $paidDay = $b['paid_d'] ?? null;
    return [
        'deal_created_at' => $day ? $day . 'T00:00:00' : null,
        'date_operation' => $paidDay ?: null,
        'agent_key' => $b['ak'] ?? '',
        'agent_display' => $b['ad'] ?? ($b['ak'] ?? ''),
        'agent_team' => $b['at'] ?? '',
        'agent_teams' => $b['ats'] ?? [($b['at'] ?? '')],
        'category' => $b['cat'] ?? null,
        'channel' => $b['ch'] ?? null,
        'client' => $b['cl'] ?? null,
        'supplier' => $b['pt'] ?? null,
        'card_type' => $b['card'] ?? null,
        'client_type' => $b['ct'] ?? null,
        'request_type' => $b['rt'] ?? null,
        'deal_result' => $b['dr'] ?? null,
        'deal_status' => $b['ds'] ?? null,
        'lost_deal_reason' => $b['ldr'] ?? null,
        'rollup_n' => max(1, (int) ($b['n'] ?? 1)),
    ];
}

/**
 * @param list<array> $buckets
 * @return list<array>
 */
function sales_rollup_to_rows(array $buckets): array
{
    $out = [];
    foreach ($buckets as $b) {
        $out[] = sales_rollup_to_row($b);
    }
    return $out;
}

/**
 * @param list<array> $buckets
 * @return list<array>
 */
function ops_rollup_to_rows(array $buckets): array
{
    $out = [];
    foreach ($buckets as $b) {
        $out[] = ops_rollup_to_row($b);
    }
    return $out;
}

/**
 * @param list<array> $buckets
 * @return list<array>
 */
function deals_rollup_to_rows(array $buckets): array
{
    $out = [];
    foreach ($buckets as $b) {
        $out[] = deals_rollup_to_row($b);
    }
    return $out;
}

/**
 * @param array<string, mixed> $filters
 * @return list<array>
 */
function load_filtered_sales(array $filters): array
{
    $rollup = load_sales_rollup_rows();
    if ($rollup !== null) {
        require_once __DIR__ . '/filters.php';
        return apply_sales_filters(sales_rollup_to_rows($rollup), $filters);
    }
    require_once __DIR__ . '/filters.php';
    return apply_sales_filters(storage_load_table('sales_unified'), $filters);
}

/**
 * @param array<string, mixed> $filters
 * @return list<array>
 */
function load_filtered_operations_1c(array $filters): array
{
    $rollup = load_ops_rollup_rows();
    if ($rollup !== null) {
        require_once __DIR__ . '/filters.php';
        $rows = ops_rollup_to_rows($rollup);
        return apply_operations_1c_filters_on_enriched($rows, $filters);
    }
    require_once __DIR__ . '/filters.php';
    return apply_operations_1c_filters(storage_load_table('operations_1c'), $filters);
}

/**
 * @param array<string, mixed> $filters
 * @return list<array>
 */
function load_filtered_deals_bitrix(array $filters): array
{
    $rollup = load_deals_rollup_rows();
    if ($rollup !== null) {
        require_once __DIR__ . '/filters.php';
        $rows = deals_rollup_to_rows($rollup);
        return apply_deals_bitrix_filters_on_enriched($rows, $filters);
    }
    require_once __DIR__ . '/filters.php';
    return apply_deals_bitrix_filters(storage_load_table('deals_bitrix'), $filters);
}

/**
 * @param list<array> $unified
 * @param list<array> $ops
 * @param list<array> $deals
 */
function build_and_save_aggregates(array $unified, array $ops, array $deals, array $settings, string $loadedAt): void
{
    $salesRollup = build_sales_rollup($unified);
    $opsRollup = build_ops_rollup($ops, $settings);
    $dealsRollup = build_deals_rollup($deals, $settings);
    save_aggregates($loadedAt, $salesRollup, $opsRollup, $dealsRollup);
}
