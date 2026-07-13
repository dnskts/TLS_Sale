<?php
/**
 * bench.php — замеры производительности типичных сценариев дашборда.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/filters.php';
require_once __DIR__ . '/metrics.php';
require_once __DIR__ . '/insights.php';

/** Токен для install/bench.php (первые 16 символов settings_auth_token). */
function bench_access_token(): string
{
    return substr(settings_auth_token(), 0, 16);
}

function bench_token_ok(?string $token): bool
{
    $expected = bench_access_token();
    return $token !== null && $token !== '' && hash_equals($expected, $token);
}

function bench_format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}

function bench_ms_class(float $ms): string
{
    if ($ms < 500) {
        return 'ok';
    }
    if ($ms < 2000) {
        return 'warn';
    }
    return 'bad';
}

/** @return array<string, mixed> */
function bench_default_filters(): array
{
    $settings = load_settings();
    $today = date('Y-m-d');
    $monthStart = date('Y-m-01');
    return [
        'date_from' => $monthStart,
        'date_to' => $today,
        'source' => $settings['defaults']['source'] ?? 'all',
        'teams' => [],
        'agents' => [],
        'show_inactive_agents' => !empty($settings['defaults']['show_inactive_agents']),
        'show_unknown_agents' => false,
        'categories' => [],
        'channels' => [],
        'card_types' => [],
        'client_types' => [],
        'request_types' => [],
        'clients' => [],
        'partners' => [],
        'granularity' => 'month',
    ];
}

final class BenchRunner
{
    /** @var array<string, int> */
    private $loadCounts = [];

    /** @var array<string, mixed> */
    private $filters;

    public function __construct(array $filters)
    {
        $this->filters = $filters;
    }

    /** @return list<array> */
    public function trackedLoad(string $table): array
    {
        $this->loadCounts[$table] = ($this->loadCounts[$table] ?? 0) + 1;
        return storage_load_table($table);
    }

  /** @return array<string, int> */
    public function loadCounts(): array
    {
        return $this->loadCounts;
    }

    private function resetCounts(): void
    {
        $this->loadCounts = [];
    }

    /** @return array{ms: float, peak_mb: float, load_counts: array<string, int>, rows_after: int} */
    private function measure(callable $fn): array
    {
        $this->resetCounts();
        $memBefore = memory_get_usage(true);
        $t0 = microtime(true);
        $rowsAfter = (int) $fn();
        $ms = (microtime(true) - $t0) * 1000;
        $peakMb = memory_get_peak_usage(true) / 1048576;
        unset($memBefore);
        return [
            'ms' => round($ms, 1),
            'peak_mb' => round($peakMb, 2),
            'load_counts' => $this->loadCounts,
            'rows_after' => $rowsAfter,
        ];
    }

    /** @return array<string, mixed> */
    public function environment(): array
    {
        $root = project_root();
        $backend = storage_backend();
        $files = [];

        $candidates = [
            'settings.json' => $root . DIRECTORY_SEPARATOR . 'settings.json',
            'last_load.json' => $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'last_load.json',
        ];
        if ($backend === 'sqlite') {
            $settings = load_settings();
            $rel = $settings['paths']['sqlite'] ?? 'data/dashboard.db';
            $candidates['dashboard.db'] = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
        } else {
            $tablesDir = $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'tables';
            foreach (['sales_unified', 'operations_1c', 'deals_bitrix', 'agents_dim'] as $table) {
                $candidates[$table . '.json'] = $tablesDir . DIRECTORY_SEPARATOR . $table . '.json';
            }
        }
        foreach ($candidates as $label => $path) {
            $files[$label] = [
                'path' => $path,
                'exists' => is_file($path),
                'size' => is_file($path) ? (int) filesize($path) : 0,
                'size_human' => is_file($path) ? bench_format_bytes((int) filesize($path)) : '—',
            ];
        }

        return [
            'php_version' => PHP_VERSION,
            'storage_backend' => $backend,
            'memory_limit' => ini_get('memory_limit') ?: '—',
            'opcache' => function_exists('opcache_get_status') && @opcache_get_status(false) ? 'on' : 'off',
            'meta' => storage_load_meta(),
            'files' => $files,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function tableLoads(): array
    {
        $tables = ['sales_unified', 'operations_1c', 'deals_bitrix', 'agents_dim'];
        $out = [];
        foreach ($tables as $table) {
            $memBefore = memory_get_usage(true);
            $t0 = microtime(true);
            $rows = storage_load_table($table);
            $ms = (microtime(true) - $t0) * 1000;
            $memDelta = memory_get_usage(true) - $memBefore;
            $out[] = [
                'table' => $table,
                'ms' => round($ms, 1),
                'ms_class' => bench_ms_class($ms),
                'rows' => count($rows),
                'memory_delta' => bench_format_bytes(max(0, $memDelta)),
            ];
            unset($rows);
        }

        $t0 = microtime(true);
        storage_load_table('sales_unified');
        $firstMs = (microtime(true) - $t0) * 1000;
        $t0 = microtime(true);
        storage_load_table('sales_unified');
        $secondMs = (microtime(true) - $t0) * 1000;
        $out[] = [
            'table' => 'sales_unified (повтор #2)',
            'ms' => round($secondMs, 1),
            'ms_class' => bench_ms_class($secondMs),
            'rows' => $out[0]['rows'] ?? 0,
            'memory_delta' => '—',
            'note' => '1-й проход: ' . round($firstMs, 1) . ' ms — без in-request кэша повтор не быстрее',
        ];
        return $out;
    }

    /** @return int rows processed */
    private function runOverview(): int
    {
        $filters = $this->filters;
        $granularity = $filters['granularity'] ?? 'month';
        $source = $filters['source'] ?? 'all';

        $rows = apply_sales_filters($this->trackedLoad('sales_unified'), $filters);
        $summary = summarize_sales($rows);
        $ops1c = apply_operations_1c_filters($this->trackedLoad('operations_1c'), $filters);
        $dealsBx = apply_deals_bitrix_filters($this->trackedLoad('deals_bitrix'), $filters);

        $opsTotal = count($ops1c);
        $dealsTotal = count($dealsBx);
        $opsRefunds = 0;
        foreach ($ops1c as $row) {
            if ((float) ($row['sales_amount'] ?? 0) < 0) {
                $opsRefunds++;
            }
        }
        $dealsSuccess = 0;
        foreach ($dealsBx as $row) {
            if (clean_str($row['deal_result'] ?? null) === 'Успех') {
                $dealsSuccess++;
            }
        }

        trend_series($rows, $granularity);
        group_by_dimension_metric($rows, 'agent_team', 'sales', 8);
        group_deals_by_field($dealsBx, 'deal_result', 10);
        group_deals_by_funnel($dealsBx);
        deals_count_trend($dealsBx, $granularity);
        top_clients_by_sales($rows, 10);

        return count($rows) + $opsTotal + $dealsTotal;
    }

    /** @return int */
    private function runFilters(): int
    {
        $settings = load_settings();
        $sales = $this->trackedLoad('sales_unified');
        $teams = [];
        $clients = [];
        foreach ($sales as $row) {
            foreach ($row['agent_teams'] ?? [($row['agent_team'] ?? '')] as $t) {
                if ($t) {
                    $teams[$t] = true;
                }
            }
            if (!empty($row['agent_team'])) {
                $teams[$row['agent_team']] = true;
            }
            foreach (['client', 'partner_or_supplier', 'category', 'channel', 'card_type', 'client_type', 'request_type'] as $col) {
                $v = clean_str($row[$col] ?? null);
                if ($v) {
                    if ($col === 'client') {
                        $clients[$v] = true;
                    }
                }
            }
        }
        foreach ($settings['agents'] ?? [] as $a) {
            foreach (agent_teams($a) as $t) {
                if ($t) {
                    $teams[$t] = true;
                }
            }
        }
        return count($sales);
    }

    /** @return int */
    private function runAgents(): int
    {
        $rows = apply_sales_filters($this->trackedLoad('sales_unified'), $this->filters);
        $teams = [];
        foreach ($rows as $row) {
            $team = $row['agent_team'] ?? 'Без команды';
            if (!isset($teams[$team])) {
                $teams[$team] = 0;
            }
            $teams[$team]++;
        }
        return count($rows);
    }

    /** @return int */
    private function runDetailsSales(): int
    {
        $rows = apply_sales_filters($this->trackedLoad('sales_unified'), $this->filters);
        $slice = array_slice($rows, 0, 2000);
        return count($slice);
    }

    /** @return int */
    private function runFunnelUnified(): int
    {
        $filters = $this->filters;
        $granularity = $filters['granularity'] ?? 'month';
        $ops = apply_operations_1c_filters($this->trackedLoad('operations_1c'), $filters);
        $deals = apply_deals_bitrix_filters($this->trackedLoad('deals_bitrix'), $filters);
        foreach ($ops as $row) {
            $day = $row['date_operation'] ?? null;
            if ($day) {
                period_key($day, $granularity);
            }
        }
        foreach ($deals as $row) {
            $day = !empty($row['deal_created_at']) ? substr((string) $row['deal_created_at'], 0, 10) : null;
            if ($day) {
                period_key($day, $granularity);
            }
        }
        return count($ops) + count($deals);
    }

    /** @return int */
    private function runInsights(): int
    {
        $payload = compute_insights_payload_with_loader($this->filters, function (string $table): array {
            return $this->trackedLoad($table);
        });
        return (int) (($payload['counts']['sales_rows'] ?? 0));
    }

    /** @return list<array<string, mixed>> */
    public function scenarios(): array
    {
        $defs = [
            ['id' => 'filters', 'label' => 'filters.php', 'desc' => 'Списки фильтров при загрузке страницы', 'fn' => function () {
                return $this->runFilters();
            }],
            ['id' => 'overview', 'label' => 'overview.php', 'desc' => 'Вкладка «Обзор» / KPI', 'fn' => function () {
                return $this->runOverview();
            }],
            ['id' => 'overview_x2', 'label' => 'overview.php ×2', 'desc' => 'Как в UI: refreshKpi + TabOverview', 'fn' => function () {
                $this->runOverview();
                return $this->runOverview();
            }],
            ['id' => 'insights', 'label' => 'insights.php', 'desc' => 'Советы руководителю', 'fn' => function () {
                return $this->runInsights();
            }],
            ['id' => 'agents', 'label' => 'agents.php', 'desc' => 'Агенты и команды', 'fn' => function () {
                return $this->runAgents();
            }],
            ['id' => 'details_sales', 'label' => 'details.php (sales)', 'desc' => 'Детализация продаж', 'fn' => function () {
                return $this->runDetailsSales();
            }],
            ['id' => 'funnel_unified', 'label' => 'funnel_unified.php', 'desc' => 'Сводная воронка', 'fn' => function () {
                return $this->runFunnelUnified();
            }],
        ];

        $out = [];
        foreach ($defs as $def) {
            $result = $this->measure($def['fn']);
            $loads = $result['load_counts'];
            $totalLoads = array_sum($loads);
            $loadsText = [];
            foreach ($loads as $table => $cnt) {
                $loadsText[] = $table . '×' . $cnt;
            }
            $out[] = [
                'id' => $def['id'],
                'label' => $def['label'],
                'desc' => $def['desc'],
                'ms' => $result['ms'],
                'ms_class' => bench_ms_class($result['ms']),
                'peak_mb' => $result['peak_mb'],
                'load_total' => $totalLoads,
                'load_detail' => $loadsText ? implode(', ', $loadsText) : '—',
                'rows_after' => $result['rows_after'],
            ];
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $scenarios
     * @param array<string, mixed> $env
     * @return list<string>
     */
    public static function recommendations(array $scenarios, array $env): array
    {
        $tips = [];
        $backend = $env['storage_backend'] ?? 'json';
        $byId = [];
        foreach ($scenarios as $s) {
            $byId[$s['id']] = $s;
        }

        $unifiedSize = 0;
        foreach ($env['files'] ?? [] as $name => $info) {
            if ($name === 'sales_unified.json' && !empty($info['size'])) {
                $unifiedSize = (int) $info['size'];
            }
        }
        if ($unifiedSize > 10 * 1048576) {
            $tips[] = 'Файл sales_unified.json больше 10 MB — основное узкое место чтение и json_decode с диска.';
        }
        if ($backend === 'json') {
            $tips[] = 'Хранилище JSON: каждый API-запрос читает целые файлы. Включите pdo_sqlite на сервере или добавьте кэш/предрасчёт.';
        }
        if (isset($byId['overview']) && $byId['overview']['ms'] > 2000) {
            $tips[] = 'overview.php медленнее 2 с — оптимизируйте загрузку таблиц или предрасчитывайте агрегаты при pipeline.';
        }
        if (isset($byId['overview'], $byId['overview_x2'])) {
            $ratio = $byId['overview']['ms'] > 0
                ? $byId['overview_x2']['ms'] / $byId['overview']['ms']
                : 0;
            if ($ratio > 1.7) {
                $tips[] = 'overview вызывается дважды (KPI + вкладка) — объедините запросы в app.js для ~2× ускорения «Обзора».';
            }
        }
        if (isset($byId['insights']) && ($byId['insights']['load_total'] ?? 0) >= 4) {
            $tips[] = 'insights загружает таблицы несколько раз — кандидат на in-request кэш storage_load_table.';
        }
        if (($env['opcache'] ?? 'off') === 'off') {
            $tips[] = 'OPcache выключен — включите на проде для ускорения PHP.';
        }
        if ($tips === []) {
            $tips[] = 'Критичных проблем не обнаружено. Сравните самые медленные сценарии в таблице выше.';
        }
        return $tips;
    }
}

/**
 * compute_insights_payload с подменяемым загрузчиком таблиц (для bench).
 *
 * @param callable(string): array $loader
 * @return array<string, mixed>
 */
function compute_insights_payload_with_loader(array $filters, callable $loader): array
{
    $rows = apply_sales_filters($loader('sales_unified'), $filters);
    $deals = apply_deals_bitrix_filters($loader('deals_bitrix'), $filters);
    $ops = apply_operations_1c_filters($loader('operations_1c'), $filters);

    $prevFilters = filters_for_previous_period($filters);
    $prevRows = apply_sales_filters($loader('sales_unified'), $prevFilters);

    $summary = summarize_sales($rows);
    $prevSummary = summarize_sales($prevRows);
    $refund = insight_refund_stats($ops);
    $concentration = insight_client_concentration($rows);
    $agents = insight_agent_stats($rows);
    $dealsByAgent = insight_deals_by_agent($deals);

    $margins = array_values(array_filter(array_map(fn($a) => $a['margin_pct'], $agents), fn($m) => $m !== null));
    sort($margins);
    $medianMargin = count($margins) ? $margins[(int) floor(count($margins) / 2)] : null;

    $unknownRows = 0;
    foreach ($rows as $row) {
        if (str_starts_with((string) ($row['agent_key'] ?? ''), 'unknown:')) {
            $unknownRows++;
        }
    }

    $dealsSuccess = 0;
    foreach ($deals as $row) {
        if (clean_str($row['deal_result'] ?? null) === 'Успех') {
            $dealsSuccess++;
        }
    }

    $lostTop = insight_top_lost_reasons($deals);

    build_insight_signals([
        'summary' => $summary,
        'prev_summary' => $prevSummary,
        'refund' => $refund,
        'deals_total' => count($deals),
        'deals_success' => $dealsSuccess,
        'concentration' => $concentration,
        'unknown_agent_rows' => $unknownRows,
        'lost_top' => $lostTop,
    ]);
    build_agent_coaching_cards($agents, $dealsByAgent, $medianMargin);
    build_team_comparison($rows, $ops);

    return [
        'counts' => [
            'sales_rows' => count($rows),
            'deals_rows' => count($deals),
            'ops_rows' => count($ops),
        ],
    ];
}

/** @return array<string, mixed> */
function bench_run_full_report(): array
{
    $filters = bench_default_filters();
    $runner = new BenchRunner($filters);
    $env = $runner->environment();
    $tableLoads = $runner->tableLoads();
    $scenarios = $runner->scenarios();
    return [
        'generated_at' => date('c'),
        'filters' => $filters,
        'environment' => $env,
        'table_loads' => $tableLoads,
        'scenarios' => $scenarios,
        'recommendations' => BenchRunner::recommendations($scenarios, $env),
        'access_token_hint' => bench_access_token(),
    ];
}
