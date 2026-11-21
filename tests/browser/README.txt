Skrip browser (Puppeteer + Chromium). Tidak dipasang bersama aplikasi; hanya untuk pengujian ulang.
  npm i puppeteer-core @sparticuz/chromium      (di folder terpisah)
  php artisan serve  (database terisi: php artisan tinker --execute="require 'tests/load/seed.php';")
  node tests/browser/smoke.mjs     # tab, kamera gagal, dua tab, offline
  node tests/browser/screens.mjs   # tangkapan layar HP/tablet/laptop + deteksi overflow
Sesuaikan BASE di dalam skrip bila perlu.
