# Локальный просмотр FixariVan: встроенный сервер PHP.
# Запуск из PowerShell из корня проекта:
#   .\tools\dev-server.ps1
# Или с другим портом:
#   .\tools\dev-server.ps1 -Port 8002

param(
    [int] $Port = 8001,
    [string] $HostName = "localhost"
)

$ErrorActionPreference = "Stop"
$root = Resolve-Path (Join-Path $PSScriptRoot "..")
Set-Location $root

$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) {
    Write-Host ""
    Write-Host "PHP не найден в PATH." -ForegroundColor Yellow
    Write-Host "Установите PHP 8.x, например:" -ForegroundColor Gray
    Write-Host "  winget install --id PHP.PHP.8.4 -e" -ForegroundColor Cyan
    Write-Host "или скачайте с https://windows.php.net/download/" -ForegroundColor Gray
    Write-Host "После установки перезапустите терминал и снова запустите этот скрипт." -ForegroundColor Gray
    Write-Host ""
    exit 1
}

$addr = "${HostName}:${Port}"
Write-Host "Корень: $root" -ForegroundColor DarkGray
Write-Host "Откройте в браузере: http://${addr}/admin/login.php" -ForegroundColor Green
Write-Host "Остановка: Ctrl+C" -ForegroundColor DarkGray
Write-Host ""

& php -S $addr -t $root
