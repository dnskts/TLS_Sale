<?php
/**
 * start_server.php
 * Запускает встроенный PHP-сервер на 127.0.0.1:8080
 */
declare(strict_types=1);

$host = '127.0.0.1';
$port = 8080;
$root = __DIR__;

$sock = @fsockopen($host, $port, $errno, $errstr, 0.3);
if ($sock) {
    fclose($sock);
    echo "Порт {$port} уже занят. Откройте http://{$host}:{$port}/\n";
    exit(0);
}

$cmd = escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg("{$host}:{$port}") . ' -t ' . escapeshellarg($root);

echo "Запуск: http://{$host}:{$port}/\n";
echo "Остановите сервер: Ctrl+C\n";
passthru($cmd, $code);
exit($code);
