@echo off
REM Запуск локального дашборда PHP (порт 8080)
cd /d "%~dp0"
echo Starting PHP server at http://127.0.0.1:8080/
"C:\xampp\php\php.exe" start_server.php
pause
