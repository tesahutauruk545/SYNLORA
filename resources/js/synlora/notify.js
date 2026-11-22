/** Toast notifikasi ringan (peringatan fokus). Teks selalu lewat textContent. */
const TONE = {
    warn: 'bg-amber-50 border-amber-200 text-amber-900',
    ok: 'bg-emerald-50 border-emerald-200 text-emerald-800',
    danger: 'bg-rose-50 border-rose-200 text-rose-800',
};

let stack = null;

export function notify(kind, title, message, ttlMs = 8000) {
    if (!stack) {
        stack = document.createElement('div');
        stack.setAttribute('aria-live', 'assertive');
        stack.className = 'fixed right-4 top-20 z-50 flex w-[92%] max-w-sm flex-col gap-2';
        document.body.append(stack);
    }
    const box = document.createElement('div');
    box.setAttribute('role', 'alert');
    box.className = `syn-toast rounded-2xl border px-4 py-3 text-sm shadow-lg ${TONE[kind] || TONE.warn}`;
    const t = document.createElement('div');
    t.className = 'font-semibold';
    t.textContent = title;
    box.append(t);
    if (message) {
        const m = document.createElement('div');
        m.className = 'mt-0.5 text-xs opacity-90';
        m.textContent = message;
        box.append(m);
    }
    stack.append(box);
    while (stack.children.length > 4) stack.firstChild.remove();
    setTimeout(() => box.remove(), ttlMs);
}
