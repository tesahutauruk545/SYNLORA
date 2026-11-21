<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="15">
    <meta name="theme-color" content="#0b0a1c">
    <title>SYNLORA sedang tidak dapat dijangkau</title>
    <style>
        :root{--ink:#0b0a1c;--cream:#fdf7f1;--amber:#ffb870;--amber-2:#ffd9a3;--ease:cubic-bezier(.16,1,.3,1)}
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;min-height:100svh;display:grid;place-items:center;padding:24px;color:var(--cream);font-family:"Plus Jakarta Sans Variable","Plus Jakarta Sans",system-ui,-apple-system,"Segoe UI",sans-serif;
            background:radial-gradient(800px 480px at 85% -5%,rgba(139,92,246,.28),transparent 70%),radial-gradient(700px 420px at 5% 105%,rgba(255,184,112,.12),transparent 70%),linear-gradient(180deg,#100e26,var(--ink))}
        main{width:100%;max-width:32rem;text-align:center;padding:2.4rem 2rem;border-radius:28px;border:1px solid rgba(255,255,255,.16);background:linear-gradient(145deg,rgba(255,255,255,.12),rgba(255,255,255,.04));box-shadow:0 40px 80px -36px rgba(0,0,0,.85),inset 0 1px 0 rgba(255,255,255,.18);animation:pop .8s var(--ease) both}
        h1{font-family:"Fraunces Variable",Georgia,serif;font-weight:600;font-size:clamp(1.7rem,5vw,2.1rem);letter-spacing:-.02em;margin:0 0 .7rem;color:#fff}
        p{line-height:1.7;color:rgba(253,247,241,.7);margin:.5rem 0}
        a.btn{display:inline-block;margin-top:1.4rem;background:linear-gradient(135deg,var(--amber-2),var(--amber));color:#26140a;padding:.85rem 1.7rem;border-radius:999px;text-decoration:none;font-weight:650;box-shadow:0 14px 34px -14px rgba(255,170,90,.75);transition:transform .35s var(--ease),box-shadow .35s var(--ease)}
        a.btn:hover{transform:translateY(-2px);box-shadow:0 22px 44px -14px rgba(255,170,90,.9)}
        small{display:block;margin-top:1.5rem;color:rgba(253,247,241,.5);line-height:1.6}
        code{background:rgba(253,247,241,.1);border-radius:6px;padding:.1rem .4rem;color:var(--amber-2)}
        @keyframes pop{from{opacity:0;transform:translateY(14px) scale(.97)}to{opacity:1;transform:none}}
        @media (prefers-reduced-motion:reduce){main{animation:none}a.btn{transition:none}}
    </style>
</head>
<body>
<main>
    <h1>Server sedang gangguan</h1>
    <p>SYNLORA belum dapat terhubung ke database. Data Anda aman dan tidak hilang.</p>
    <p>Timer sesi belajar mengikuti waktu server, sehingga akan tetap benar begitu layanan pulih. Halaman ini mencoba lagi otomatis tiap 15 detik.</p>
    <a class="btn" href="">Coba lagi sekarang</a>
    <small>Jika Anda pengelola: pastikan MySQL di Laragon sudah Start, lalu periksa <code>storage/logs</code>.</small>
</main>
</body>
</html>
