@echo off
cd /d "%~dp0.."
php artisan synlora:backup --files --keep=14
if errorlevel 1 (echo Backup GAGAL & exit /b 1)
echo Backup selesai.
