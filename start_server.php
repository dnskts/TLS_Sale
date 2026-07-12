<?php
/**
 * start_server.php
 * Запускает встроенный PHP-сервер на 127.0.0.1:8080
 * и пишет диагностику в debug-4a7020.log
 */
declare(strict_types=1);

$logFile = __DIR__ . DIRECTORY_SEPARATOR . 'debug-4a7020.log';

function dbg(string $hypothesisId, string $message, array $data = []): void
{
    global $logFile;
    $line = json_encode([
        'sessionId' => '4a7020',
        'hypothesisId' => $hypothesisId,
        'location' => 'start_server.php',
        'message' => $message,
        'data' => $data,
        'timestamp' => (int) (microtime(true) * 1000),
        'runId' => 'start',
    ], JSON_UNESCAPED_UNICODE);
    file_put_contents($logFile, $line . "\n", FILE_APPEND);
}

$host = '127.0.0.1';
$port = 8080;
$root = __DIR__;

dbg('A', 'start_server invoked', ['cwd' => $root, 'php' => PHP_BINARY, 'sapi' => PHP_SAPI]);
dbg('D', 'index.php exists', ['exists' => is_file($root . '/index.php')]);

// Проверка: занят ли порт
$sock = @fsockopen($host, $port, $errno, $errstr, 0.3);
if ($sock) {
    fclose($sock);
    dbg('B', 'port already in use', ['port' => $port, 'errno' => $errno]);
    echo "Порт {$port} уже занят. Откройте http://{$host}:{$port}/\n";
    exit(0);
}
dbg('A', 'port is free — will start server', ['port' => $port]);

$cmd = escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg("{$host}:{$port}") . ' -t ' . escapeshellarg($root);
dbg('C', 'launching php built-in server', ['cmd' => $cmd]);

echo "Запуск: http://{$host}:{$port}/\n";
echo "Остановите сервер: Ctrl+C\n";
passthru($cmd, $code);
dbg('B', 'server exited', ['code' => $code]);
