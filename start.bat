@echo off
title SYNLORA
cd /d "%~dp0"

if not exist ".env" (
  echo File .env belum ada. Salin .env.example menjadi .env lalu jalankan: php artisan key:generate
  pause
  exit /b 1
)

echo [1/3] Menjalankan migrasi database...
php artisan migrate --force
if errorlevel 1 (
  echo Migrasi gagal. Pastikan MySQL Laragon sudah Start dan database synlora_db ada.
  pause
  exit /b 1
)

echo [2/3] Memeriksa model deteksi wajah (lokal)...
php artisan synlora:install-face-model

echo [3/3] Menjalankan SYNLORA di http://localhost:8000
start "" http://localhost:8000
php artisan serve
