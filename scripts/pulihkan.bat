@echo off
rem Pemulihan: scripts\pulihkan.bat storage\app\backups\synlora-YYYYMMDD-HHMMSS-xxxx.sql.gz
cd /d "%~dp0.."
if "%~1"=="" (echo Pemakaian: scripts\pulihkan.bat ^<file .sql.gz^> & exit /b 1)
php artisan synlora:restore "%~1"
