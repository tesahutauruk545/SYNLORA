<!DOCTYPE html>
<html lang="id" class="no-js">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SYNLORA — Belajar bersama, tetap terarah</title>
    <meta name="description" content="SYNLORA adalah ruang belajar bersama untuk mahasiswa dan dosen: study room, Pomodoro tersinkron server, indikator fokus, dan statistik belajar.">
    <meta name="theme-color" content="#0b0a1c">
    <link rel="icon" href="{{ asset('images/logo.png') }}">
    <link rel="preload" as="image" href="{{ asset('images/hero-coding-mode.jpg') }}">
    <script>
        document.documentElement.classList.remove('no-js');
        document.documentElement.classList.add('js-lp');
        // Bila skrip landing gagal dimuat, tampilkan konten apa adanya.
        setTimeout(function () { if (!window.__lpOk) document.documentElement.classList.remove('js-lp'); }, 4000);
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/css/landing.css', 'resources/js/landing.js'])
</head>
<body class="lp" style="--hero-img: url('{{ asset('images/hero-coding-mode.jpg') }}')">
@php
    $home = auth()->check() ? (auth()->user()->isDosen() ? route('dosen.monitor') : route('dashboard')) : null;
    $login = route('login');
    $register = route('register');
    // Papan ilustrasi 8 x 5 (g hijau, y kuning, r merah, x abu).
    $rows = explode(' ', 'ggggyggg gggggxgg ggrgggyg gggggggg xggyggrg');
    $tiles = [];
    foreach ($rows as $r => $row) { foreach (str_split($row) as $c => $s) { $tiles[] = ['s' => $s, 'i' => $r * 8 + $c]; } }
    $shift = [4 => 'a', 11 => 'b', 17 => 'c', 22 => 'a', 29 => 'b', 33 => 'c', 36 => 'a'];
    $arrow = '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 10h12M11 5l5 5-5 5"/></svg>';
    $facts = ['Timer Pomodoro tersinkron server', 'Indikator fokus empat warna', 'Kamera opsional, diproses di perangkat', 'Study room dengan kode enam karakter', 'Materi dan tugas dalam room', 'Dashboard dosen untuk satu kelas', 'Statistik, streak, dan target harian'];
@endphp

<a href="#konten" class="lp-skip">Lewati ke konten</a>

{{-- ===================== NAVBAR ===================== --}}
<header class="lp-nav" x-data="{ open: false }">
    <div class="lp-wrap lp-nav-in">
        <a href="{{ url('/') }}" class="lp-brand" aria-label="SYNLORA, beranda">
            <img src="{{ asset('images/logo-mark.png') }}" alt="">
            <span>Synlora</span>
        </a>

        <nav class="lp-links" aria-label="Navigasi utama">
            <a href="#fitur">Fitur</a>
            <a href="#cara-kerja">Cara Kerja</a>
            <a href="#manfaat">Manfaat</a>
        </nav>

        <div class="lp-actions">
            @if ($home)
                <a href="{{ $home }}" class="lp-btn lp-btn--warm lp-btn--sm" data-magnetic>Buka Dashboard</a>
            @else
                <a href="{{ $login }}" class="lp-btn lp-btn--glass lp-btn--sm">Masuk</a>
                <a href="{{ $register }}" class="lp-btn lp-btn--warm lp-btn--sm" data-magnetic>Mulai Belajar</a>
            @endif
        </div>

        <button type="button" class="lp-burger" @click="open = !open" :aria-expanded="open" aria-controls="menu-mobile" aria-label="Buka menu">
            <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path x-show="!open" stroke-linecap="round" d="M4 8h16M4 16h16"/><path x-show="open" x-cloak stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
    </div>

    <div id="menu-mobile" class="lp-drawer" x-show="open" x-cloak x-transition.opacity.duration.200ms>
        <nav aria-label="Navigasi seluler">
            <a class="l" @click="open=false" href="#fitur">Fitur</a>
            <a class="l" @click="open=false" href="#cara-kerja">Cara Kerja</a>
            <a class="l" @click="open=false" href="#manfaat">Manfaat</a>
        </nav>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-top:1.2rem">
            @if ($home)
                <a href="{{ $home }}" class="lp-btn lp-btn--warm" style="grid-column:span 2">Buka Dashboard</a>
            @else
                <a href="{{ $login }}" class="lp-btn lp-btn--glass">Masuk</a>
                <a href="{{ $register }}" class="lp-btn lp-btn--warm">Mulai Belajar</a>
            @endif
        </div>
    </div>
</header>

<main id="konten">
{{-- ===================== HERO ===================== --}}
<section class="lp-hero" aria-label="Pengantar">
    <div class="lp-bg" aria-hidden="true">
        <div class="lp-bg-inner">
            <img src="{{ asset('images/hero-coding-mode.jpg') }}" alt="" fetchpriority="high" decoding="async">
            <span class="lp-glow" style="left:19%;top:57%;width:17%;aspect-ratio:1"></span>
            <span class="lp-glow" style="left:87%;top:46%;width:14%;aspect-ratio:1;animation-delay:-2.6s"></span>
            <span class="lp-win" style="left:4.4%;top:23.5%;--d:-1s"></span>
            <span class="lp-win" style="left:10.2%;top:35.4%;--d:-3s"></span>
            <span class="lp-win" style="left:14.4%;top:43.2%;--d:-2s"></span>
            <span class="lp-win" style="left:31.8%;top:35.2%;--d:-4s"></span>
            <span class="lp-win" style="left:39.2%;top:38.8%;--d:-.5s"></span>
            <span class="lp-win" style="left:52.4%;top:52%;--d:-3.5s"></span>
            <span class="lp-win" style="left:76.3%;top:29.4%;--d:-1.5s"></span>
            <span class="lp-win" style="left:60.4%;top:34%;--d:-2.4s"></span>
        </div>
    </div>
    <div class="lp-shade" aria-hidden="true"></div>
    <div class="lp-grain" aria-hidden="true"></div>

    <div class="lp-hero-main">
        <div class="lp-wrap" style="position:relative;width:min(1240px,100% - 2.5rem)">
            <div style="max-width:62rem">
                <p class="lp-eyebrow"><i></i> Ruang belajar bersama</p>
                <h1 class="lp-h1" aria-label="Belajar bersama, tetap terarah.">
                    <span class="ln" aria-hidden="true"><span class="w"><span>Belajar</span></span> <span class="w"><span>bersama,</span></span></span>
                    <span class="ln" aria-hidden="true"><span class="w"><span>tetap</span></span> <span class="w"><span><em>terarah.</em></span></span></span>
                </h1>
                <p class="lp-lead">SYNLORA mempertemukan mahasiswa dan dosen dalam satu study room: timer Pomodoro yang sama untuk semua, indikator fokus yang jujur, dan catatan belajar yang rapi setiap hari.</p>
                <div class="lp-cta-row">
                    @if ($home)
                        <a href="{{ $home }}" class="lp-btn lp-btn--warm" data-magnetic>Buka Dashboard {!! $arrow !!}</a>
                    @else
                        <a href="{{ $register }}" class="lp-btn lp-btn--warm" data-magnetic>Mulai Belajar {!! $arrow !!}</a>
                        <a href="{{ $login }}" class="lp-btn lp-btn--glass">Masuk</a>
                    @endif
                </div>
                <p class="lp-note">
                    <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 1.5l6 2.2v4.6c0 4-2.5 7.4-6 8.7-3.5-1.3-6-4.7-6-8.7V3.7l6-2.2zm-1.2 9.6l-1.9-1.9-1.1 1.1 3 3 5-5-1.1-1.1-3.9 3.9z" clip-rule="evenodd"/></svg>
                    Deteksi wajah berjalan di perangkat Anda. Video tidak ditampilkan, dikirim, atau disimpan.
                </p>
            </div>

            {{-- Elemen melayang (ilustrasi) --}}
            <div class="lp-float lp-f-timer" data-speed="-0.06" data-depth="14" style="--pop:1.5s">
                <div class="in lp-glass lp-timer-card" style="--dur:8s">
                    <svg viewBox="0 0 150 150" class="lp-ring" style="--off:120" aria-hidden="true">
                        <defs><linearGradient id="g-hero" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#c4a1ff"/><stop offset="1" stop-color="#ffb870"/></linearGradient></defs>
                        <circle class="t" cx="75" cy="75" r="65"/><circle class="p" cx="75" cy="75" r="65" stroke="url(#g-hero)"/>
                    </svg>
                    <div>
                        <div class="lp-time" data-timer>17:42</div>
                        <div class="lp-sub">Fokus, sesi ke-2</div>
                        <span class="lp-tag" style="margin-top:.5rem">Ilustrasi</span>
                    </div>
                </div>
            </div>
            <div class="lp-float lp-f-status" data-speed="-0.1" data-depth="-22" style="--pop:1.75s">
                <div class="in lp-glass lp-pill" style="--dur:10s;--dl:-3s;--by:-12px"><span class="lp-dot g pulse"></span> Fokus terjaga</div>
            </div>
            <div class="lp-float lp-f-room" data-speed="-0.14" data-depth="26" style="--pop:2s">
                <div class="in lp-glass" style="--dur:11s;--dl:-5s;padding:.9rem 1.1rem;border-radius:22px">
                    <div style="font-size:.72rem;letter-spacing:.14em;text-transform:uppercase;color:rgba(253,247,241,.6)">Study room</div>
                    <div style="display:flex;align-items:center;gap:.8rem;margin-top:.5rem">
                        <div class="lp-seats" aria-hidden="true">
                            <i class="lp-seat" style="--c1:#ffd9a3;--c2:#ffb870"></i><i class="lp-seat"></i><i class="lp-seat" style="--c1:#7dd3fc;--c2:#6366f1"></i><i class="lp-seat" style="--c1:#f9a8d4;--c2:#c084fc"></i>
                        </div>
                        <span style="font-size:.82rem;font-weight:600">Bergabung dengan kode</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="lp-marquee" aria-hidden="true">
        <div class="lp-marquee-track">
            @for ($k = 0; $k < 2; $k++)
                <div style="display:flex">@foreach ($facts as $f)<span>{{ $f }}</span>@endforeach</div>
            @endfor
        </div>
    </div>
</section>

{{-- ===================== FITUR ===================== --}}
<section id="fitur" class="lp-sheet s-fitur" aria-labelledby="h-fitur">
    <div class="lp-wrap">
        <div class="lp-feat-head">
            <div data-rv="up">
                <span class="lp-kicker">Fitur</span>
                <h2 id="h-fitur" class="lp-h2">Semua yang dibutuhkan satu sesi belajar, <em>di satu tempat.</em></h2>
            </div>
            <p class="lp-p" data-rv="up" data-d="120">Dari membuka room sampai melihat rekap, tidak ada langkah yang perlu pindah aplikasi.</p>
        </div>

        {{-- 01 Virtual room --}}
        <article class="lp-feat">
            <div class="tx" data-rv="left">
                <span class="lp-num" aria-hidden="true">01</span>
                <h3>Virtual room yang terasa seperti satu meja.</h3>
                <p class="lp-p">Buat room, bagikan kode enam karakter, dan teman atau satu kelas langsung bergabung. Owner mengelola anggota, materi, dan tugas.</p>
                <div class="lp-chips"><span class="lp-chip">Kode enam karakter</span><span class="lp-chip">Kelola anggota</span><span class="lp-chip">Materi &amp; tugas</span></div>
            </div>
            <div class="vz" data-rv="right" data-d="100">
                <div class="lp-panel lp-glass" data-tilt>
                    <div class="lp-panel-bar">
                        <span class="lp-panel-title">Study room <span class="lp-tag">Ilustrasi</span></span>
                        <span class="lp-badge lp-badge--inline warm">Bergabung dengan kode</span>
                    </div>
                    <div class="lp-code" data-code="A7K2QX" aria-label="Contoh kode room"><b>A</b><b>7</b><b>K</b><b>2</b><b>Q</b><b>X</b></div>
                    <div class="lp-seat-grid" aria-hidden="true">
                        @foreach ([['#ffd9a3','#ffb870','g','Owner'],['#c4a1ff','#8b5cf6','g',''],['#7dd3fc','#6366f1','y',''],['#f9a8d4','#c084fc','g',''],['#86efac','#14b8a6','g',''],['#fde68a','#f59e0b','x',''],['#a5b4fc','#7c3aed','g',''],['#fda4af','#e11d48','g','']] as $i => $m)
                            <div class="s" style="--dl:-{{ $i * 0.8 }}s"><span class="av" style="--c1:{{ $m[0] }};--c2:{{ $m[1] }}"><i class="lp-dot {{ $m[2] }}"></i></span><small>{{ $m[3] ?: 'Anggota' }}</small></div>
                        @endforeach
                    </div>
                    <div class="lp-panel-foot">
                        <span class="lp-badge lp-badge--inline">
                            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="7.5" cy="7" r="2.6"/><path d="M2.5 16c.4-2.6 2.3-4 5-4s4.6 1.4 5 4"/><circle cx="14" cy="7.8" r="2"/><path d="M14.5 12.2c1.6.2 2.6 1.3 3 3.3"/></svg>
                            Owner mengelola room
                        </span>
                    </div>
                </div>
            </div>
        </article>

        {{-- 02 Pomodoro --}}
        <article class="lp-feat rev">
            <div class="tx" data-rv="right">
                <span class="lp-num" aria-hidden="true">02</span>
                <h3>Pomodoro yang sama untuk semua.</h3>
                <p class="lp-p">Waktu dihitung server, bukan browser. Muat ulang halaman atau pindah perangkat, hitung mundurnya tetap benar.</p>
                <div class="lp-chips"><span class="lp-chip">Dihitung server</span><span class="lp-chip">Fokus &amp; istirahat</span><span class="lp-chip">Tetap benar saat dimuat ulang</span></div>
            </div>
            <div class="vz" data-rv="left" data-d="100">
                <div class="lp-panel lp-glass" data-tilt>
                    <div class="lp-panel-bar"><span>Sesi fokus</span><span class="lp-tag">Ilustrasi</span></div>
                    <div class="lp-big-ring">
                        <svg viewBox="0 0 150 150" aria-hidden="true">
                            <defs><linearGradient id="grad-ring" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#c4a1ff"/><stop offset="1" stop-color="#ffb870"/></linearGradient></defs>
                            <circle class="t" cx="75" cy="75" r="65"/><circle class="p" cx="75" cy="75" r="65"/>
                        </svg>
                        <div class="c"><div class="lp-time" data-timer>17:42</div><div class="lp-sub">Fokus, sesi ke-2</div></div>
                    </div>
                    <div class="lp-phase" aria-hidden="true"><span>Fokus</span><i class="on"></i><span>Istirahat</span><i></i><span>Fokus</span><i></i></div>
                </div>
                <span class="lp-badge warm" data-speed="-0.06" style="left:-.8rem;top:1rem">Waktu dari server</span>
                <span class="lp-badge" data-speed="-0.1" style="right:-.6rem;bottom:2rem"><span class="lp-dot g pulse" style="margin-right:.5rem"></span>Tersinkron</span>
            </div>
        </article>

        {{-- 03 Fokus --}}
        <article id="indikator" class="lp-feat">
            <div class="tx" data-rv="left">
                <span class="lp-num" aria-hidden="true">03</span>
                <h3>Focus monitoring: empat warna, aturan yang bisa dijelaskan.</h3>
                <p class="lp-p">Status hanyalah indikator, bukan nilai konsentrasi. Wajah yang tidak terlihat sendirian tidak pernah dihitung sebagai gangguan.</p>
                <ul class="lp-status-list">
                    <li><i class="lp-dot g"></i><span><b>Hijau.</b> Tab SYNLORA aktif. Jika kamera menyala, wajah juga terdeteksi.</span></li>
                    <li><i class="lp-dot y"></i><span><b>Kuning.</b> Wajah tidak terlihat 5 detik atau lebih, atau tab baru saja ditinggalkan.</span></li>
                    <li><i class="lp-dot r"></i><span><b>Merah.</b> Tab atau aplikasi ditinggalkan lebih dari 10 detik. Posisi HP tidak memengaruhi status.</span></li>
                    <li><i class="lp-dot x"></i><span><b>Abu.</b> Tidak ada pemantauan: sesi dijeda, istirahat, atau belum mulai. Koneksi terputus ditandai terpisah.</span></li>
                </ul>
            </div>
            <div class="vz" data-rv="right" data-d="100">
                <div class="lp-panel lp-glass" data-tilt>
                    <div class="lp-panel-bar"><span>Ruang kelas, tampilan dosen</span><span class="lp-tag">Ilustrasi</span></div>
                    <div class="lp-legend"><span><i class="lp-dot g"></i>Fokus</span><span><i class="lp-dot y"></i>Perlu perhatian</span><span><i class="lp-dot r"></i>Terganggu</span><span><i class="lp-dot x"></i>Tidak aktif</span></div>
                    <div class="lp-board" role="img" aria-label="Ilustrasi 40 mahasiswa dengan status fokus hijau, kuning, merah, dan abu">
                        @foreach ($tiles as $t)
                            <span class="board-tile {{ isset($shift[$t['i']]) ? 'shift-'.$shift[$t['i']] : '' }}" data-s="{{ $t['s'] }}" style="--d: -{{ fmod($t['i'] * 0.37, 9) }}s"></span>
                        @endforeach
                    </div>
                </div>
                <span class="lp-badge warm" data-speed="-0.07" style="right:-.6rem;top:-1.2rem">Kamera opsional</span>
            </div>
        </article>

        <div class="lp-privacy" data-rv="up">
            <div>
                <span class="lp-kicker">Privasi</span>
                <h3 class="lp-h2" style="font-size:clamp(1.9rem,3.6vw,3rem)">Privasi dijaga sejak desainnya.</h3>
            </div>
            <ul>
                <li>Deteksi wajah memakai MediaPipe yang berjalan di browser Anda sendiri.</li>
                <li>Gambar kamera tidak ditampilkan, tidak dikirim, dan tidak disimpan. Server hanya menerima jenis kejadian dan warna status.</li>
                <li>Kamera bersifat opsional dan otomatis berhenti saat sesi dijeda atau istirahat.</li>
                <li>Mahasiswa hanya melihat statusnya sendiri; yang berhak melihat status kelas hanya pemilik room.</li>
            </ul>
        </div>

        <div class="lp-also">
            <div data-rv="up"><h4>Dashboard dosen untuk satu kelas penuh</h4><p>Pantau puluhan mahasiswa sekaligus: status, durasi belajar, Pomodoro, dan riwayat aktivitas, bisa disaring per kelas.</p></div>
            <div data-rv="up" data-d="120"><h4>Statistik, streak, dan target harian</h4><p>Lihat waktu fokus hari ini terhadap target, rangkaian hari belajar, dan rekap 30 hari terakhir.</p></div>
            <div data-rv="up" data-d="240"><h4>Materi dan tugas dalam room</h4><p>Dosen mengunggah materi dan membuat tugas; mahasiswa mengumpulkan dan menerima nilai di tempat yang sama.</p></div>
        </div>
    </div>
</section>

{{-- ===================== CARA KERJA ===================== --}}
<section id="cara-kerja" class="lp-sheet s-cara" aria-labelledby="h-cara">
    <div class="lp-wrap">
        <div style="max-width:48rem" data-rv="up">
            <span class="lp-kicker">Cara Kerja</span>
            <h2 id="h-cara" class="lp-h2">Dari daftar sampai rekap, <em>empat langkah.</em></h2>
        </div>
        <ol class="lp-steps">
            @foreach ([
                ['Daftar dengan NIM dan kelas', 'Mahasiswa mendaftar dengan NIM dan kelas. Akun dosen hanya dibuat dengan kode undangan dari institusi.'],
                ['Buka atau masuk ke room', 'Dosen atau teman membuat room. Anggota bergabung dengan kode enam karakter.'],
                ['Jalankan Pomodoro', 'Mulai sesi fokus bersama. Biarkan indikator berjalan; kamera boleh dimatikan.'],
                ['Lihat rekap', 'Tinjau rekap sesi, streak, dan target harian untuk melihat kapan Anda paling stabil.'],
            ] as $i => $st)
                <li class="lp-step" style="--d:{{ $i * 220 }}ms">
                    <span class="n">0{{ $i + 1 }}</span>
                    <h3>{{ $st[0] }}</h3>
                    <p>{{ $st[1] }}</p>
                </li>
            @endforeach
        </ol>
    </div>
</section>

{{-- ===================== PENGALAMAN BELAJAR + MANFAAT ===================== --}}
<section id="manfaat" class="lp-sheet s-pengalaman" aria-labelledby="h-pengalaman">
    <div class="lp-wrap">
        <div class="lp-feat-head">
            <div data-rv="up">
                <span class="lp-kicker">Pengalaman Belajar</span>
                <h2 id="h-pengalaman" class="lp-h2">Satu meja belajar bersama, <em>di mana pun Anda duduk.</em></h2>
            </div>
            <p class="lp-p" data-rv="up" data-d="120">Semua orang dalam room melihat waktu yang sama, saling menemani, dan mendapat rekap setelah sesi selesai.</p>
        </div>

        <div class="lp-scene vz" data-rv="scale" aria-label="Ilustrasi pengalaman belajar bersama">
            <div class="lp-scene-bg" aria-hidden="true"></div>
            <span class="lp-tag tag">Ilustrasi</span>
            <div class="lp-orbit" aria-hidden="true">
                <div class="spin">
                    @foreach ([[50,0,'#ffd9a3','#ffb870','g'],[93,34,'#c4a1ff','#8b5cf6','g'],[76,88,'#7dd3fc','#6366f1','y'],[24,90,'#f9a8d4','#c084fc','g'],[4,36,'#86efac','#14b8a6','g']] as $b)
                        <span class="bub" style="--x:{{ $b[0] - 50 }};--y:{{ $b[1] - 50 }};--c1:{{ $b[2] }};--c2:{{ $b[3] }}"><i><span class="lp-dot {{ $b[4] }}"></span></i></span>
                    @endforeach
                </div>
            </div>
            <div class="lp-core lp-glass" data-speed="-0.04">
                <div class="lp-sub" style="margin:0 0 .4rem">Waktu bersama</div>
                <div class="lp-time" data-timer>17:42</div>
            </div>
            <div class="lp-float lp-f-b" data-speed="-0.12" style="position:absolute;opacity:1;scale:1">
                <div class="in lp-glass lp-pill" style="--dur:9s"><span class="lp-dot g pulse"></span> Semua fokus</div>
            </div>
            <div class="lp-recap lp-glass" data-speed="-0.08">
                <div class="lp-sub" style="margin:0">Rekap sesi</div>
                <div class="bars" aria-hidden="true">
                    @foreach ([40, 70, 55, 90, 65, 80, 50] as $k => $h)<i style="--h:{{ $h }}%;--k:{{ $k }}"></i>@endforeach
                </div>
            </div>
        </div>

        {{-- Carousel manfaat --}}
        <div class="lp-car-head" data-rv="up">
            <div>
                <span class="lp-kicker">Manfaat</span>
                <h3 class="lp-h2" style="font-size:clamp(1.9rem,3.6vw,3rem)">Untuk siapa pun yang belajar.</h3>
            </div>
            <div class="lp-arrows">
                <button type="button" class="lp-arrow" data-prev aria-label="Sebelumnya"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 10H4M9 5l-5 5 5 5"/></svg></button>
                <button type="button" class="lp-arrow" data-next aria-label="Berikutnya"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 10h12M11 5l5 5-5 5"/></svg></button>
            </div>
        </div>
        <div class="lp-car" data-carousel tabindex="0" role="region" aria-label="Manfaat SYNLORA">
            <article class="lp-slide" style="--t1:rgba(139,92,246,.35);--t2:rgba(23,19,58,.9);--gl:rgba(196,161,255,.5)">
                <span class="k">Untuk mahasiswa</span>
                <h3>Belajar berdampingan, tanpa harus bertemu.</h3>
                <ul>
                    <li>Belajar berdampingan dengan teman tanpa harus bertemu langsung.</li>
                    <li>Target harian dan streak membuat kebiasaan belajar terlihat, bukan sekadar niat.</li>
                    <li>Rekap tiap sesi menunjukkan kapan Anda paling stabil.</li>
                </ul>
            </article>
            <article class="lp-slide" style="--t1:rgba(255,184,112,.28);--t2:rgba(23,19,58,.9);--gl:rgba(255,184,112,.5)">
                <span class="k">Untuk dosen</span>
                <h3>Satu layar untuk satu kelas.</h3>
                <ul>
                    <li>Lihat siapa yang sedang belajar dan siapa yang butuh perhatian.</li>
                    <li>Riwayat aktivitas per mahasiswa untuk percakapan yang berdasar data, bukan dugaan.</li>
                    <li>Materi, tugas, dan nilai tetap berada di room yang sama.</li>
                </ul>
            </article>
            <article class="lp-slide" style="--t1:rgba(52,211,153,.2);--t2:rgba(23,19,58,.9);--gl:rgba(52,211,153,.4)">
                <span class="k">Privasi</span>
                <h3>Kamera opsional, data seperlunya.</h3>
                <ul>
                    <li>Deteksi wajah berjalan di perangkat Anda sendiri.</li>
                    <li>Server hanya menerima jenis kejadian dan warna status, tidak pernah gambar kamera.</li>
                    <li>Mahasiswa hanya melihat statusnya sendiri.</li>
                </ul>
            </article>
            <article class="lp-slide" style="--t1:rgba(125,211,252,.22);--t2:rgba(23,19,58,.9);--gl:rgba(125,211,252,.4)">
                <span class="k">Kebiasaan</span>
                <h3>Kemajuan yang bisa dilihat.</h3>
                <ul>
                    <li>Waktu fokus hari ini terhadap target harian.</li>
                    <li>Rangkaian hari belajar (streak).</li>
                    <li>Rekap 30 hari terakhir dan riwayat sesi.</li>
                </ul>
            </article>
        </div>
        <div class="lp-prog" data-prog aria-hidden="true"><i></i></div>
    </div>
</section>

{{-- ===================== FAQ ===================== --}}
<section id="faq" class="lp-sheet s-faq" aria-labelledby="h-faq">
    <div class="lp-wrap">
        <div class="lp-faq">
            <div class="lp-faq-side" data-rv="up">
                <span class="lp-kicker">FAQ</span>
                <h2 id="h-faq" class="lp-h2">Ada pertanyaan? <em>Cek dulu di sini.</em></h2>
                <p class="lp-p" style="margin-top:1.2rem;max-width:26rem">Jawaban singkat tentang privasi, timer, dan akun.</p>
                <a href="{{ $home ?? $register }}" class="lp-btn lp-btn--warm" style="margin-top:1.8rem" data-magnetic>{{ $home ? 'Buka Dashboard' : 'Mulai Belajar' }} {!! $arrow !!}</a>
            </div>
            <div class="lp-acc" data-rv="up" data-d="120">
                @foreach ([
                    ['Apakah kamera wajib dinyalakan?', 'Tidak. Kamera bersifat opsional dan otomatis berhenti saat sesi dijeda atau istirahat.'],
                    ['Apakah video saya disimpan atau dikirim?', 'Tidak. Deteksi wajah memakai MediaPipe yang berjalan di browser Anda sendiri. Gambar kamera tidak ditampilkan, tidak dikirim, dan tidak disimpan; server hanya menerima jenis kejadian dan warna status.'],
                    ['Siapa yang bisa melihat status fokus saya?', 'Mahasiswa hanya melihat statusnya sendiri. Yang berhak melihat status kelas hanya pemilik room.'],
                    ['Apa arti warna hijau, kuning, merah, dan abu?', 'Hijau: tab SYNLORA aktif. Kuning: wajah tidak terlihat 5 detik atau lebih, atau tab baru saja ditinggalkan. Merah: tab atau aplikasi ditinggalkan lebih dari 10 detik. Abu: tidak ada pemantauan karena sesi dijeda, istirahat, atau belum mulai. Status hanyalah indikator, bukan nilai konsentrasi.'],
                    ['Mengapa timer tetap benar setelah halaman dimuat ulang?', 'Waktu dihitung oleh server, bukan browser. Memuat ulang halaman atau berpindah perangkat tidak mengubah hitung mundurnya.'],
                    ['Bagaimana cara mendaftar?', 'Mahasiswa mendaftar dengan NIM dan kelas. Akun dosen hanya dibuat dengan kode undangan dari institusi.'],
                    ['Bagaimana teman bergabung ke room saya?', 'Buat room, lalu bagikan kode enam karakter. Teman atau satu kelas dapat langsung bergabung dengan kode tersebut.'],
                ] as $i => $qa)
                    <div class="lp-acc-item {{ $i === 0 ? 'is-open' : '' }}">
                        <h3><button type="button" class="lp-acc-q" aria-expanded="{{ $i === 0 ? 'true' : 'false' }}" aria-controls="faq-{{ $i }}"><span>{{ $qa[0] }}</span><span class="lp-plus" aria-hidden="true"></span></button></h3>
                        <div class="lp-acc-a" id="faq-{{ $i }}" role="region"><div><p>{{ $qa[1] }}</p></div></div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</section>

{{-- ===================== CTA ===================== --}}
<section class="lp-sheet s-cta" aria-labelledby="h-cta">
    <div class="cta-bg" aria-hidden="true"></div>
    <div class="lp-wrap lp-cta-box" style="padding-bottom:3rem">
        <h2 id="h-cta" class="lp-h2" data-rv="up">Siap membuka sesi belajar <em>pertama?</em></h2>
        <p class="lp-p" data-rv="up" data-d="100">Daftar dengan NIM Anda, buka atau masuk ke room, dan mulai Pomodoro hari ini.</p>
        <div class="lp-cta-row" data-rv="up" data-d="200" style="opacity:1">
            @if ($home)
                <a href="{{ $home }}" class="lp-btn lp-btn--warm" data-magnetic>Buka Dashboard {!! $arrow !!}</a>
            @else
                <a href="{{ $register }}" class="lp-btn lp-btn--warm" data-magnetic>Mulai Belajar {!! $arrow !!}</a>
                <a href="{{ $login }}" class="lp-btn lp-btn--glass">Masuk</a>
            @endif
        </div>
    </div>
</section>
</main>

{{-- ===================== FOOTER ===================== --}}
<footer style="position:relative;z-index:7;background:var(--ink)">
    <div class="lp-wrap">
        <div class="lp-foot">
            <div>
                <a href="{{ url('/') }}" class="lp-brand" aria-label="SYNLORA, beranda"><img src="{{ asset('images/logo-mark.png') }}" alt=""><span>Synlora</span></a>
                <p class="tagline">Learn Together. Evolve Together.</p>
                <p class="acr">Synchronized Network for Learning, Organization, Reflection &amp; Achievement</p>
            </div>
            <div>
                <h5>Jelajahi</h5>
                <ul><li><a href="#fitur">Fitur</a></li><li><a href="#cara-kerja">Cara Kerja</a></li><li><a href="#manfaat">Manfaat</a></li><li><a href="#faq">FAQ</a></li></ul>
            </div>
            <div>
                <h5>Akun</h5>
                <ul>
                    @if ($home)
                        <li><a href="{{ $home }}">Buka Dashboard</a></li>
                    @else
                        <li><a href="{{ $login }}">Masuk</a></li><li><a href="{{ $register }}">Mulai Belajar</a></li>
                    @endif
                </ul>
            </div>
        </div>
        <div class="lp-copy"><span>&copy; {{ date('Y') }} SYNLORA</span><span>Belajar bersama, tetap terarah.</span></div>
    </div>
</footer>
</body>
</html>
