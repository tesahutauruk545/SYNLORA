const ICONS = {
    SESSION_START: ['🚀', 'text-emerald-700', 'bg-emerald-50'],
    SESSION_PAUSE: ['⏸️', 'text-amber-700', 'bg-amber-50'],
    SESSION_RESUME: ['▶️', 'text-emerald-700', 'bg-emerald-50'],
    SESSION_END: ['🏁', 'text-synlora-700', 'bg-synlora-50'],
    PHASE_BREAK: ['☕', 'text-synlora-700', 'bg-synlora-50'],
    PHASE_FOCUS: ['📚', 'text-synlora-700', 'bg-synlora-50'],
    MONITOR_START: ['🛰️', 'text-slate-700', 'bg-white'],
    CAMERA_ON: ['🎥', 'text-slate-700', 'bg-white'],
    CAMERA_OFF: ['🚫', 'text-slate-700', 'bg-white'],
    FACE_DETECTED: ['🟢', 'text-emerald-700', 'bg-emerald-50'],
    FACE_LOST_SHORT: ['🟡', 'text-amber-700', 'bg-amber-50'],
    FACE_LOST_LONG: ['🟡', 'text-amber-700', 'bg-amber-50'],
    TAB_SWITCH_OUT: ['🔄', 'text-rose-700', 'bg-rose-50'],
    TAB_SWITCH_IN: ['↩️', 'text-amber-700', 'bg-amber-50'],
    HEARTBEAT_LOST: ['📡', 'text-slate-700', 'bg-slate-50'],
    CAMERA_DENIED: ['🚫', 'text-slate-700', 'bg-slate-50'],
    CAMERA_UNAVAILABLE: ['🚫', 'text-slate-700', 'bg-slate-50'],
    CAMERA_ERROR: ['⚠️', 'text-slate-700', 'bg-slate-50'],
    MODEL_LOAD_FAILED: ['⚠️', 'text-slate-700', 'bg-slate-50'],
    TAB_AWAY_LONG: ['🔴', 'text-rose-700', 'bg-rose-50'],
    WINDOW_BLUR: ['🪟', 'text-amber-700', 'bg-amber-50'],
    WINDOW_FOCUS: ['↩️', 'text-emerald-700', 'bg-emerald-50'],
    ORIENTATION_CHANGE: ['📱', 'text-rose-700', 'bg-rose-50'],
    MONITOR_ERROR: ['⚠️', 'text-rose-700', 'bg-rose-50'],
};

const FOCUS_BADGE = {
    HIJAU: ['🟢 Fokus terindikasi', 'text-emerald-600 font-semibold'],
    KUNING: ['🟡 Perhatian', 'text-amber-600 font-semibold'],
    MERAH: ['🔴 Terindikasi terganggu', 'text-rose-600 font-bold'],
    ABU: ['', ''],
};

const PRESENCE_CLASS = {
    belajar: 'text-emerald-600 font-semibold',
    istirahat: 'text-amber-600 font-semibold',
    jeda: 'text-slate-500 font-semibold',
    offline: 'text-slate-400',
};

/** Semua teks dari server dimasukkan lewat textContent (bukan innerHTML) agar aman dari XSS. */
function node(tag, className, text) {
    const el = document.createElement(tag);
    if (className) el.className = className;
    if (text !== undefined) el.textContent = text;
    return el;
}

export class RoomFeed {
    constructor({ url, feedEl, scopeEl, intervalMs = 4000 }) {
        this.url = url;
        this.feedEl = feedEl;
        this.scopeEl = scopeEl;
        this.intervalMs = intervalMs;
        this.timer = null;
    }

    start() {
        this.load();
        this.timer = setInterval(() => { if (!document.hidden) this.load(); }, this.intervalMs);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) this.load(); });
    }

    async load() {
        try {
            const res = await fetch(this.url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            if (!res.ok) return;
            const data = await res.json();
            this.renderFeed(data);
            this.renderMembers(data.members);
        } catch (err) {
            console.error('Gagal memuat aktivitas:', err);
        }
    }

    renderFeed(data) {
        if (!this.feedEl) return;
        if (this.scopeEl) {
            this.scopeEl.textContent = data.scope === 'room'
                ? 'Sebagai dosen di room ini, Anda melihat aktivitas mahasiswa yang bergabung.'
                : 'Anda hanya melihat aktivitas Anda sendiri. Data fokus anggota lain bersifat privat.';
        }

        this.feedEl.replaceChildren();
        if (!data.activities.length) {
            this.feedEl.append(node('div', 'text-slate-500 text-sm text-center', 'Belum ada aktivitas.'));
            return;
        }

        data.activities.forEach((a) => {
            const [icon, color, bg] = ICONS[a.event_type] || ['📝', 'text-slate-700', 'bg-white'];
            const row = node('div', `${bg} p-2 rounded text-sm ${color}`);
            row.append(
                node('span', 'font-mono text-xs text-slate-500', `[${a.occurred_at}] `),
                node('strong', '', a.is_me ? 'Anda' : a.user_name),
                document.createTextNode(` ${icon} ${a.details || a.event_type}`)
            );
            this.feedEl.append(row);
        });
    }

    renderMembers(members) {
        members.forEach((m) => {
            const card = document.querySelector(`[data-member-id="${m.id}"]`);
            if (!card) return;

            const presence = card.querySelector('[data-role="presence"]');
            if (presence) {
                presence.className = PRESENCE_CLASS[m.presence] || '';
                presence.textContent = m.presence_label;
            }

            const focus = card.querySelector('[data-role="focus"]');
            if (focus) {
                const [label, cls] = FOCUS_BADGE[m.focus_status] || ['', ''];
                focus.className = `text-xs mt-1 ${cls}`;
                focus.textContent = m.focus_status && label ? label : '';
            }
        });
    }
}
