@echo off
setlocal

set "PORT=8000"
set "FOUND="

echo.
echo Procurando servidores na porta %PORT%...

for /f "tokens=5" %%P in ('netstat -ano ^| findstr /R /C:":%PORT% .*LISTENING"') do (
    set "FOUND=1"
    echo Encerrando processo PID %%P na porta %PORT%...
    taskkill /PID %%P /F
)

if not defined FOUND (
    echo Nenhum processo encontrado na porta %PORT%.
)

echo.
pause
endlocal
