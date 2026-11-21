@echo off
rem Mendaftarkan backup harian jam 02:00 di Windows Task Scheduler (jalankan sekali sebagai Administrator).
cd /d "%~dp0.."
schtasks /Create /SC DAILY /TN "SYNLORA Backup" /TR "\"%CD%\scripts\backup.bat\"" /ST 02:00 /F
echo Selesai. Untuk menghapus: schtasks /Delete /TN "SYNLORA Backup" /F
pause
