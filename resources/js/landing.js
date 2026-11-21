// SYNLORA landing page: animasi & interaksi ringan (tanpa library tambahan).
// Hormati prefers-reduced-motion; semua efek berhenti bila tidak terlihat.
(() => {
    const root = document.documentElement;
    const body = document.body;
    if (!body.classList.contains('lp')) return;
    window.__lpOk = true;

    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const fine = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    const $ = (s, c = document) => c.querySelector(s);
    const $$ = (s, c = document) => Array.from(c.querySelectorAll(s));
    const clamp = (v, a, b) => Math.min(b, Math.max(a, v));

    // ---------- Hero entrance ----------
    const hero = $('.lp-hero');
    $$('.lp-h1 .w > span').forEach((el, i) => el.style.setProperty('--i', i));
    const start = () => {
        hero && hero.classList.add('is-ready');
        root.classList.add('nav-in');
    };
    const bgImg = $('.lp-bg-inner img');
    const go = () => requestAnimationFrame(() => requestAnimationFrame(start));
    if (reduce) start();
    else if (bgImg && !bgImg.complete) { bgImg.addEventListener('load', go, { once: true }); bgImg.addEventListener('error', go, { once: true }); setTimeout(go, 1800); }
    else go();

    // ---------- Scroll reveal ----------
    const rv = $$('[data-rv], .lp-step, .vz');
    if (reduce || !('IntersectionObserver' in window)) {
        rv.forEach((el) => el.classList.add('is-in'));
    } else {
        const io = new IntersectionObserver((es) => {
            es.forEach((e) => {
                if (!e.isIntersecting) return;
                e.target.classList.add('is-in');
                io.unobserve(e.target);
            });
        }, { threshold: 0.14, rootMargin: '0px 0px -7% 0px' });
        rv.forEach((el) => {
            const d = el.getAttribute('data-d');
            if (d) el.style.setProperty('--d', d + 'ms');
            io.observe(el);
        });
    }

    // ---------- Smooth anchor scrolling (easing, offset header) ----------
    const ease = (t) => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2);
    let anim = 0;
    const scrollToY = (y) => {
        cancelAnimationFrame(anim);
        if (reduce) { window.scrollTo(0, y); return; }
        const y0 = window.scrollY, dist = y - y0, dur = clamp(Math.abs(dist) * 0.55, 600, 1400);
        const t0 = performance.now();
        const stop = () => cancelAnimationFrame(anim);
        window.addEventListener('wheel', stop, { once: true, passive: true });
        window.addEventListener('touchstart', stop, { once: true, passive: true });
        const step = (now) => {
            const p = clamp((now - t0) / dur, 0, 1);
            window.scrollTo(0, y0 + dist * ease(p));
            if (p < 1) anim = requestAnimationFrame(step);
        };
        anim = requestAnimationFrame(step);
    };
    document.addEventListener('click', (ev) => {
        const a = ev.target.closest('a[href^="#"]');
        if (!a || ev.defaultPrevented || ev.metaKey || ev.ctrlKey) return;
        const id = a.getAttribute('href');
        if (id === '#' || id.length < 2) return;
        const t = document.getElementById(id.slice(1));
        if (!t) return;
        ev.preventDefault();
        const navH = (document.querySelector('.lp-nav')?.offsetHeight || 72);
        // Posisi dihitung dari kotak section (bukan dari anak yang masih ter-translate oleh animasi reveal),
        // lalu diarahkan ke awal konten (kicker/judul) dengan jarak napas di bawah header.
        let y = t.getBoundingClientRect().top + window.scrollY;
        if (t.classList.contains('lp-sheet')) {
            const wrap = t.querySelector(':scope > .lp-wrap');
            y += wrap ? parseFloat(getComputedStyle(wrap).paddingTop) || 0 : 0;
            y -= navH + 28;
        } else {
            y -= navH + 16;
        }
        scrollToY(Math.max(0, y));
        history.replaceState(null, '', id);
    });

    // ---------- Navbar & active link ----------
    const nav = $('.lp-nav');
    const links = $$('.lp-links a[href^="#"]');
    const spy = links.map((a) => ({ a, s: document.getElementById(a.getAttribute('href').slice(1)) })).filter((x) => x.s);

    // ---------- Parallax + mouse depth ----------
    const px = $$('[data-speed]');
    const bgInner = $('.lp-bg-inner');
    const sceneBg = $('.lp-scene-bg');
    let mx = 0, my = 0, tx = 0, ty = 0, ticking = false;

    const frame = () => {
        ticking = false;
        const y = window.scrollY, vh = window.innerHeight;
        nav && nav.classList.toggle('is-solid', y > 40);
        let cur = null;
        spy.forEach((x) => { if (x.s.getBoundingClientRect().top < vh * 0.4) cur = x.a; });
        links.forEach((a) => a.classList.toggle('is-active', a === cur));
        if (reduce) return;
        if (bgInner && y < vh * 1.2) {
            bgInner.style.setProperty('--px', (mx * -14).toFixed(1) + 'px');
            bgInner.style.setProperty('--py', (y * 0.18 + my * -10).toFixed(1) + 'px');
        }
        if (sceneBg) {
            const r = sceneBg.parentElement.getBoundingClientRect();
            if (r.bottom > 0 && r.top < vh) sceneBg.style.transform = `translate3d(0, ${((r.top + r.height / 2 - vh / 2) * -0.08).toFixed(1)}px, 0)`;
        }
        px.forEach((el) => {
            const r = el.getBoundingClientRect();
            if (r.bottom < -200 || r.top > vh + 200) return;
            const sp = parseFloat(el.dataset.speed) || 0;
            const off = (r.top + r.height / 2 - vh / 2) * sp;
            const d = parseFloat(el.dataset.depth) || 0;
            el.style.transform = `translate3d(${(mx * d).toFixed(1)}px, ${(off + my * d).toFixed(1)}px, 0)`;
        });
        if (Math.abs(tx - mx) > 0.001 || Math.abs(ty - my) > 0.001) {
            mx += (tx - mx) * 0.08; my += (ty - my) * 0.08;
            request();
        }
    };
    const request = () => { if (!ticking) { ticking = true; requestAnimationFrame(frame); } };
    window.addEventListener('scroll', request, { passive: true });
    window.addEventListener('resize', request, { passive: true });
    if (fine && !reduce && hero) {
        hero.addEventListener('pointermove', (e) => {
            tx = (e.clientX / window.innerWidth - 0.5) * 2;
            ty = (e.clientY / window.innerHeight - 0.5) * 2;
            request();
        });
        hero.addEventListener('pointerleave', () => { tx = 0; ty = 0; request(); });
    }
    request();

    // ---------- Magnetic buttons & tilt ----------
    if (fine && !reduce) {
        $$('[data-magnetic]').forEach((b) => {
            b.addEventListener('pointermove', (e) => {
                const r = b.getBoundingClientRect();
                b.style.transform = `translate(${((e.clientX - r.left - r.width / 2) * 0.22).toFixed(1)}px, ${((e.clientY - r.top - r.height / 2) * 0.32).toFixed(1)}px)`;
            });
            b.addEventListener('pointerleave', () => { b.style.transform = ''; });
        });
        $$('[data-tilt]').forEach((p) => {
            p.addEventListener('pointermove', (e) => {
                const r = p.getBoundingClientRect();
                const x = (e.clientX - r.left) / r.width - 0.5, y = (e.clientY - r.top) / r.height - 0.5;
                p.style.transform = `perspective(1000px) rotateY(${(x * 7).toFixed(2)}deg) rotateX(${(y * -7).toFixed(2)}deg)`;
            });
            p.addEventListener('pointerleave', () => { p.style.transform = ''; });
        });
    }

    // ---------- Timer demo (ilustrasi, bukan data nyata) ----------
    const timers = $$('[data-timer]');
    if (timers.length) {
        let left = 17 * 60 + 42;
        const fmt = () => String(Math.floor(left / 60)).padStart(2, '0') + ':' + String(left % 60).padStart(2, '0');
        const paint = () => timers.forEach((t) => { t.textContent = fmt(); });
        paint();
        if (!reduce) setInterval(() => { if (document.hidden) return; left = left <= 1 ? 25 * 60 : left - 1; paint(); }, 1000);
    }

    // ---------- Kode room (efek acak lalu terkunci) ----------
    const code = $('[data-code]');
    if (code) {
        const final = code.dataset.code, cells = $$('b', code), chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        let done = false;
        const run = () => {
            if (done) return; done = true;
            if (reduce) return;
            cells.forEach((c, i) => {
                let n = 0;
                const t = setInterval(() => {
                    c.textContent = chars[Math.floor(Math.random() * chars.length)];
                    if (++n > 8 + i * 5) { clearInterval(t); c.textContent = final[i]; }
                }, 55);
            });
        };
        if ('IntersectionObserver' in window) {
            const o = new IntersectionObserver((es) => { if (es[0].isIntersecting) { run(); o.disconnect(); } }, { threshold: 0.5 });
            o.observe(code);
        }
    }

    // ---------- Carousel ----------
    const car = $('[data-carousel]');
    if (car) {
        const prev = $('[data-prev]'), next = $('[data-next]'), bar = $('[data-prog] i');
        const slides = $$('.lp-slide', car);
        const step = () => (slides[1] ? slides[1].offsetLeft - slides[0].offsetLeft : car.clientWidth * 0.8);
        const upd = () => {
            const max = car.scrollWidth - car.clientWidth;
            const p = max > 0 ? car.scrollLeft / max : 1;
            if (prev) prev.disabled = car.scrollLeft < 8;
            if (next) next.disabled = car.scrollLeft > max - 8;
            if (bar) { const w = clamp(car.clientWidth / car.scrollWidth, 0.1, 1); bar.style.width = w * 100 + '%'; bar.style.transform = `translateX(${(p * (1 / w - 1) * 100).toFixed(1)}%)`; }
        };
        prev && prev.addEventListener('click', () => car.scrollBy({ left: -step(), behavior: reduce ? 'auto' : 'smooth' }));
        next && next.addEventListener('click', () => car.scrollBy({ left: step(), behavior: reduce ? 'auto' : 'smooth' }));
        car.addEventListener('scroll', () => requestAnimationFrame(upd), { passive: true });
        window.addEventListener('resize', upd, { passive: true });
        car.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowRight') { e.preventDefault(); next && next.click(); }
            if (e.key === 'ArrowLeft') { e.preventDefault(); prev && prev.click(); }
        });
        // seret dengan mouse
        let down = false, sx = 0, sl = 0, moved = false;
        car.addEventListener('pointerdown', (e) => { if (e.pointerType !== 'mouse') return; down = true; moved = false; sx = e.clientX; sl = car.scrollLeft; });
        window.addEventListener('pointermove', (e) => {
            if (!down) return;
            const dx = e.clientX - sx;
            if (Math.abs(dx) > 4) { moved = true; car.classList.add('is-drag'); }
            if (moved) car.scrollLeft = sl - dx;
        });
        window.addEventListener('pointerup', () => {
            if (!down) return;
            down = false;
            if (moved) { car.classList.remove('is-drag'); const s = step(); car.scrollTo({ left: Math.round(car.scrollLeft / s) * s, behavior: 'smooth' }); }
        });
        upd();
    }

    // ---------- FAQ accordion ----------
    const items = $$('.lp-acc-item');
    items.forEach((it) => {
        const q = $('.lp-acc-q', it);
        q.addEventListener('click', () => {
            const open = !it.classList.contains('is-open');
            items.forEach((o) => { o.classList.remove('is-open'); $('.lp-acc-q', o).setAttribute('aria-expanded', 'false'); });
            if (open) { it.classList.add('is-open'); q.setAttribute('aria-expanded', 'true'); }
        });
    });
})();
