@echo off
setlocal

set "PROJECT_DIR=%~dp0"
set "HOST=127.0.0.1"
set "PORT=8000"
set "URL=http://localhost:%PORT%/consultar_processos.php"

cd /d "%PROJECT_DIR%"

where php >nul 2>nul
if errorlevel 1 (
    echo.
    echo ERRO: PHP nao foi encontrado no PATH.
    echo Instale o PHP ou adicione o executavel php.exe ao PATH do Windows.
    echo.
    pause
    exit /b 1
)

if not exist ".env" (
    if exist ".env.example" (
        copy ".env.example" ".env" >nul
        echo Arquivo .env criado a partir de .env.example.
    )
)

netstat -ano | findstr /R /C:":%PORT% .*LISTENING" >nul
if not errorlevel 1 (
    echo.
    echo ERRO: a porta %PORT% ja esta em uso.
    echo Feche o outro servidor ou altere a variavel PORT neste arquivo.
    echo.
    netstat -ano | findstr /R /C:":%PORT% .*LISTENING"
    echo.
    pause
    exit /b 1
)

echo.
echo Iniciando servidor do portfolio BidMap...
echo Pasta: %PROJECT_DIR%
echo URL:   %URL%
echo.
echo Para parar o servidor, pressione CTRL+C nesta janela.
echo.

start "" "%URL%"
php -S %HOST%:%PORT% -t "%PROJECT_DIR%"

endlocal
