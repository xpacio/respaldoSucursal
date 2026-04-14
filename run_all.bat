@echo off
echo Iniciando servidor API...
::start "API Server" cmd /c "cd /d %~dp0 && php -S localhost:8000 index.php"

timeout /t 3 /nobreak > nul

echo Iniciando cliente...
php cli.php --server http://localhost:8000 --run-once

echo Deteniendo servidor...
taskkill /fi "WINDOWTITLE eq API Server" /t /f > nul 2>&1

echo Proceso completado.
pause