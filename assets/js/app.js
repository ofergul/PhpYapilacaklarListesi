/**
 * Görev Yöneticisi — Alpine.js Uygulama Çekirdeği
 * Tüm görünümler, işlemler, grafikler ve kısayollar burada tanımlıdır.
 */

/* ------------------------------------------------------------------ *
 *  Çekirdek Yardımcılar
 * ------------------------------------------------------------------ */

const BOOT = JSON.parse(document.getElementById('boot-data').textContent);

/**
 * API isteği gönderir; CSRF başlığını otomatik ekler.
 * @param {string} url
 * @param {object} [opts]
 */
async function api(url, opts = {}, retries = 1) {
    const headers = new Headers(opts.headers || {});
    if (!(opts.body instanceof FormData)) {
        headers.set('Content-Type', 'application/json');
    }
    headers.set('X-CSRF-Token', BOOT.csrf);
    const res = await fetch(url, { ...opts, headers, credentials: 'same-origin' });
    const ct = res.headers.get('content-type') || '';
    if (ct.includes('application/json')) {
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'İşlem başarısız');
        return data;
    }
    if (retries > 0 && res.status !== 401 && res.status !== 403) {
        await new Promise((r) => setTimeout(r, 350));
        return api(url, opts, retries - 1);
    }
    throw new Error('Sunucudan geçersiz yanıt (HTTP ' + res.status + ')');
}

/** HTML'e güvenli kaçırma (XSS koruması). */
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
}[c]));

/** Basit Markdown → HTML dönüştürücü (güvenli). */
function renderMarkdown(md) {
    if (!md) return '';
    let h = esc(md);
    h = h.replace(/```([\s\S]*?)```/g, '<pre><code>$1</code></pre>');
    h = h.replace(/`([^`]+)`/g, '<code>$1</code>');
    h = h.replace(/^###### (.*)$/gm, '<h3>$1</h3>');
    h = h.replace(/^##### (.*)$/gm, '<h3>$1</h3>');
    h = h.replace(/^#### (.*)$/gm, '<h3>$1</h3>');
    h = h.replace(/^### (.*)$/gm, '<h3>$1</h3>');
    h = h.replace(/^## (.*)$/gm, '<h2>$1</h2>');
    h = h.replace(/^# (.*)$/gm, '<h1>$1</h1>');
    h = h.replace(/^\s*- \[x\]\s+(.*)$/gim, '<div><input type="checkbox" checked disabled> $1</div>');
    h = h.replace(/^\s*- \[ \]\s+(.*)$/gim, '<div><input type="checkbox" disabled> $1</div>');
    h = h.replace(/^\s*[-*+]\s+(.*)$/gm, '<li>$1</li>');
    h = h.replace(/(<li>[\s\S]*?<\/li>)(?![\s\S]*?<\/li>)/g, (m) => m.replace(/<li>/g, '<ul><li>').replace(/<\/li>/g, '</li></ul>'));
    h = h.replace(/^\s*\d+\.\s+(.*)$/gm, '<li>$1</li>');
    h = h.replace(/^\s*&gt;\s*(.*)$/gm, '<blockquote>$1</blockquote>');
    h = h.replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>');
    h = h.replace(/(^|\s)_([^_]+)_(?=\s|$)/g, '$1<i>$2</i>');
    h = h.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
    h = h.replace(/https?:\/\/[^\s<]+/g, '<a href="$&" target="_blank" rel="noopener">$&</a>');
    h = h.replace(/\n{2,}/g, '</p><p>').replace(/\n/g, '<br>');
    return '<p>' + h + '</p>';
}

const ICON_POOL = ['check_circle', 'rocket_launch', 'lightbulb', 'flag', 'star', 'favorite',
    'work', 'home', 'shopping_bag', 'payments', 'schedule', 'event', 'bolt', 'code',
    'menu_book', 'exercise', 'eco', 'local_cafe', 'music_note', 'movie', 'restaurant',
    'directions_car', 'flight', 'pets', 'construction', 'health_and_safety', 'school'];

const ACCENTS = ['#0A84FF', '#30D158', '#FF9F0A', '#FF453A', '#5E5CE6', '#BF5AF2', '#FF6482', '#64D2FF', '#FFD60A', '#A2845E'];

const FOLDER_ICONS = ['folder', 'work', 'person', 'home', 'code', 'menu_book', 'rocket_launch',
    'payments', 'shield', 'construction', 'school', 'fitness_center', 'flight', 'local_cafe',
    'movie', 'music_note', 'shopping_bag', 'pets', 'eco'];

const TR_MONTHS = { ocak: 1, şubat: 2, mart: 3, nisan: 4, mayıs: 5, haziran: 6, temmuz: 7, ağustos: 8, eylül: 9, ekim: 10, kasım: 11, aralık: 12 };
const TR_DAYS = { pazar: 0, pazartesi: 1, salı: 2, çarşamba: 3, perşembe: 4, cuma: 5, cumartesi: 6 };

const ACT_LABELS = {
    task_created: 'Görev oluşturuldu', task_updated: 'Görev güncellendi', task_completed: 'Görev tamamlandı',
    task_uncompleted: 'Görev geri alındı', task_trashed: 'Görev çöp kutusuna taşındı', task_destroyed: 'Görev kalıcı silindi',
    task_recurrence: 'Tekrarlanan görev', folder_saved: 'Klasör kaydedildi', settings_updated: 'Ayarlar güncellendi',
    backup_restored: 'Yedek geri yüklendi', import: 'İçe aktarım yapıldı', attachment_added: 'Dosya eklendi',
};
const ACT_ICONS = {
    task_created: 'add_circle', task_updated: 'edit', task_completed: 'task_alt', task_uncompleted: 'undo',
    task_trashed: 'delete', task_destroyed: 'delete_forever', task_recurrence: 'repeat', folder_saved: 'create_new_folder',
    settings_updated: 'tune', backup_restored: 'restore', import: 'download', attachment_added: 'attach_file',
};

/* ------------------------------------------------------------------ *
 *  Ana Uygulama
 * ------------------------------------------------------------------ */

function app() {
    return {
        /* ---------- Durum ---------- */
        sidebarOpen: false,
        view: 'dashboard',
        search: '',
        searchFocused: false,
        filters: { priority: '', status: '', sort: 'default' },
        selectMode: false,
        selection: [],
        tasks: [],
        totalTasks: 0,
        page: 1,
        loading: false,
        loadingMore: false,
        dashToday: [],
        calTasks: [],
        trashTasks: [],
        folders: [],
        tags: [],
        stats: {},
        activity: [],
        reports: {},
        reportWeeks: 12,
        currentFolder: null,
        currentTag: null,
        backups: [],

        /* ---------- Modal / UI ---------- */
        taskModal: null,
        modalTab: 'details',
        newSubtask: '',
        newChecklist: '',
        hasReminder: false,
        recurForm: { freq: 'none', interval: 1, by_day: '', by_month_day: '', by_month: '', custom_cron: '', ends_on: '' },
        recurDates: [],
        quickOpen: false,
        quickText: '',
        quickParsed: null,
        newQuickInFolder: null,
        newQuickTag: null,
        folderModal: { open: false, id: null, name: '', icon: 'folder', color: '#0A84FF', description: '' },
        tagModal: { open: false, id: null, name: '', emoji: '', color: '#FF9F0A' },
        shortcutHelp: false,
        importOpen: false,
        importType: 'json',
        importText: '',
        pomodoroOpen_: false,
        pomodoro: { running: false, mode: 'focus', seconds: 25 * 60, total: 25 * 60 },
        confirmMsg: null,
        confirmCb: null,
        contextOpen: false,
        contextTask: null,
        contextPos: '',
        toasts: [],
        toastSeq: 0,
        cal: (() => { const _n = new Date(); return { mode: 'month', year: _n.getFullYear(), month: _n.getMonth(), selected: '' }; })(),
        dragTask: null,
        kanbanCols: [
            { status: 0, label: 'Bekliyor', color: '#98989D' },
            { status: 1, label: 'Devam Ediyor', color: '#0A84FF' },
            { status: 2, label: 'Askıda', color: '#FF9F0A' },
            { status: 3, label: 'Tamamlandı', color: '#30D158' },
        ],
        settingsForm: { theme: 'system', accent: '#0A84FF', sound: '1', notifications: '1', pomodoro_focus: 25, pomodoro_break: 5 },
        themeOverride: 'system',
        charts: {},
        flatpickrs: [],
        reminderTimer: null,
        pomoTimer: null,
        listSortable: null,
        accentColors: ACCENTS,
        folderIcons: FOLDER_ICONS,
        recurFreqs: [
            { value: 'none', label: 'Yok' },
            { value: 'daily', label: 'Her gün' },
            { value: 'weekdays', label: 'Hafta içi' },
            { value: 'weekly', label: 'Haftalık' },
            { value: 'biweekly', label: '2 haftada bir' },
            { value: 'monthly', label: 'Aylık' },
            { value: 'quarterly', label: '3 ayda bir' },
            { value: 'semiannual', label: '6 ayda bir' },
            { value: 'yearly', label: 'Yıllık' },
            { value: 'custom', label: 'Özel' },
        ],
        shortcutsList: [
            { keys: 'Ctrl+N', desc: 'Hızlı görev ekle' },
            { keys: 'Ctrl+F', desc: 'Arama' },
            { keys: 'Ctrl+S', desc: 'Görevi kaydet' },
            { keys: 'Delete', desc: 'Seçili görevi sil' },
            { keys: 'Space', desc: 'Seçili görevi tamamla' },
            { keys: 'Esc', desc: 'Modal kapat' },
            { keys: 'T', desc: 'Bugün görünümü' },
            { keys: 'W', desc: 'Bu hafta görünümü' },
            { keys: 'A', desc: 'Tüm görevler' },
            { keys: 'K', desc: 'Kanban' },
            { keys: 'C', desc: 'Takvim' },
            { keys: 'R', desc: 'Raporlar' },
        ],
        navMain: [
            { key: 'inbox', label: 'Inbox', icon: 'inbox' },
            { key: 'today', label: 'Bugün', icon: 'star' },
            { key: 'planned', label: 'Planlanan', icon: 'event' },
            { key: 'week', label: 'Bu Hafta', icon: 'calendar_view_week' },
            { key: 'completed', label: 'Tamamlandı', icon: 'task_alt' },
            { key: 'calendar', label: 'Takvim', icon: 'calendar_month' },
            { key: 'all', label: 'Tüm Görevler', icon: 'inventory_2' },
        ],
        navTools: [
            { key: 'kanban', label: 'Kanban', icon: 'view_kanban' },
            { key: 'reports', label: 'Raporlar', icon: 'monitoring' },
            { key: 'archive', label: 'Arşiv', icon: 'archive' },
            { key: 'trash', label: 'Çöp Kutusu', icon: 'delete' },
            { key: 'settings', label: 'Ayarlar', icon: 'settings' },
        ],
        navBadges: {},

        /* ---------- Başlatma ---------- */
        async init() {
            this.settingsForm = { ...BOOT.settings, ...this.settingsForm };
            this.themeOverride = this.settingsForm.theme || 'system';
            this.applyAccent(this.settingsForm.accent);
            document.documentElement.classList.add(this.themeClass);

            const t = new Date();
            this.cal.year = t.getFullYear();
            this.cal.month = t.getMonth();
            this.cal.selected = this.todayStr;
            this.pomodoro.seconds = this.pomodoro.total = (this.settingsForm.pomodoro_focus || 25) * 60;

            this.bindShortcuts();
            await Promise.allSettled([this.loadFolders(), this.loadTags(), this.loadStats(), this.loadBackups(), this.loadActivity()]);
            await this.reloadTasks();
            this.loadCalendarTasks();
            if (this.view === 'dashboard') this.loadDashToday();
            this.initKanbanSortable();
            this.initPomodoroTimer();
            this.startReminderPolling();
            if ('Notification' in window && Notification.permission === 'granted') this.checkReminders();
        },

        /* ---------- Tema ---------- */
        get themeClass() {
            const t = this.themeOverride === 'system'
                ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : '')
                : (this.themeOverride === 'dark' ? 'dark' : '');
            return t;
        },
        get themeLabel() { return this.themeOverride === 'dark' ? 'Koyu Tema' : this.themeOverride === 'light' ? 'Gündüz Tema' : 'Sistem Tema'; },
        get themeIcon() { return this.themeOverride === 'dark' ? 'dark_mode' : this.themeOverride === 'light' ? 'light_mode' : 'brightness_auto'; },
        themeSet(mode) {
            this.themeOverride = mode;
            this.settingsForm.theme = mode;
            this.saveSettings();
        },
        themeCycle() {
            const order = ['system', 'light', 'dark'];
            const next = order[(order.indexOf(this.themeOverride) + 1) % 3];
            this.themeSet(next);
        },
        applyAccent(color) {
            document.documentElement.style.setProperty('--accent', color);
            document.querySelector('meta[name="theme-color"]')?.setAttribute('content', color);
        },

        /* ---------- Navigasyon ---------- */
        get viewTitle() {
            const map = {
                dashboard: 'Genel Bakış', inbox: 'Inbox', today: 'Bugün', planned: 'Planlanan',
                week: 'Bu Hafta', completed: 'Tamamlandı', calendar: 'Takvim', all: 'Tüm Görevler',
                folders: 'Klasörler', tags: 'Etiketler', archive: 'Arşiv', trash: 'Çöp Kutusu',
                kanban: 'Kanban', reports: 'Raporlar', settings: 'Ayarlar',
            };
            return map[this.view] || 'Görevler';
        },
        get viewSubtitle() {
            const d = new Date();
            if (this.view === 'today') return this.fullDate;
            if (this.view === 'dashboard') return 'Bugün ' + d.toLocaleDateString('tr-TR', { weekday: 'long', day: 'numeric', month: 'long' });
            if (this.view === 'folders') return 'Görevlerinizi klasörler halinde düzenleyin';
            if (this.view === 'tags') return 'Etiketlerle renkli filtreleme';
            return '';
        },
        get greeting() {
            const h = new Date().getHours();
            const name = 'Hoş geldin';
            if (h < 6) return 'İyi geceler';
            if (h < 12) return 'Günaydın';
            if (h < 18) return 'İyi günler';
            return 'İyi akşamlar';
        },
        get fullDate() { return new Date().toLocaleDateString('tr-TR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }); },

        goView(v) {
            this.view = v;
            this.sidebarOpen = false;
            this.filters = { priority: '', status: '', sort: 'default' };
            this.selection = [];
            this.selectMode = false;
            if (v === 'calendar') this.loadCalendarTasks();
            if (v === 'kanban') { this.loadKanban(); this.initKanbanSortable(); }
            if (v === 'reports') this.loadReports();
            if (v === 'settings') { this.loadBackups(); }
            if (v === 'trash') this.loadTrash();
            if (['inbox', 'today', 'planned', 'week', 'completed', 'all', 'archive'].includes(v)) this.reloadTasks();
            if (v === 'dashboard') { this.loadStats(); this.loadActivity(); this.loadDashToday(); }
            this.$nextTick(() => window.scrollTo({ top: 0 }));
        },

        goFolder(f) { this.currentFolder = f.id; this.view = 'folders'; this.sidebarOpen = false; this.reloadTasks(); },
        goTag(g) { this.currentTag = g.id; this.view = 'tags'; this.sidebarOpen = false; this.reloadTasks(); },

        /* ---------- Veri Yükleme ---------- */
        viewParams(extra = {}) {
            const p = { page: 1, limit: 100 };
            switch (this.view) {
                case 'folders': p.view = 'all'; p.folder_id = this.currentFolder ?? ''; break;
                case 'tags': p.view = 'all'; p.tag_id = this.currentTag ?? ''; break;
                default: p.view = this.view;
            }
            if (this.search && this.search.trim()) p.search = this.search.trim();
            return { ...p, ...extra };
        },

        async reloadTasks() {
            this.page = 1;
            this.loading = true;
            try {
                const data = await api('api.php?action=tasks&' + new URLSearchParams(this.viewParams({ page: 1 })));
                this.tasks = data.tasks;
                this.totalTasks = data.total;
            } catch (e) { this.toast(e.message, 'error'); }
            this.loading = false;
            this.$nextTick(() => this.initListSortable());
        },

        async loadMore() {
            if (this.loadingMore) return;
            this.loadingMore = true;
            try {
                const data = await api('api.php?action=tasks&' + new URLSearchParams(this.viewParams({ page: this.page + 1 })));
                this.tasks = this.tasks.concat(data.tasks);
                this.page = data.page;
            } catch (e) { this.toast(e.message, 'error'); }
            this.loadingMore = false;
        },

        get hasMore() { return this.tasks.length < this.totalTasks; },

        async loadDashToday() {
            try {
                const data = await api('api.php?action=tasks&view=today&limit=10');
                this.dashToday = data.tasks;
            } catch (e) { /* sessiz */ }
        },

        async loadTrash() {
            try {
                const data = await api('api.php?action=tasks&view=trash&limit=500');
                this.trashTasks = data.tasks;
            } catch (e) { this.toast(e.message, 'error'); }
        },

        async loadFolders() {
            try {
                const data = await api('api.php?action=folders');
                this.folders = data.folders;
            } catch (e) { this.toast(e.message, 'error'); }
        },
        async loadTags() {
            try {
                const data = await api('api.php?action=tags');
                this.tags = data.tags;
            } catch (e) { this.toast(e.message, 'error'); }
        },

        async loadStats() {
            try {
                const data = await api('api.php?action=stats');
                this.stats = data.stats;
                this.$nextTick(() => { try { this.renderDashboardChart(); } catch (e) { /* sessiz */ } });
            } catch (e) { this.toast(e.message, 'error'); }
        },

        async loadActivity() {
            try {
                const data = await api('api.php?action=activity&limit=15');
                this.activity = data.activity;
            } catch (e) { this.toast(e.message, 'error'); }
        },

        async loadBackups() {
            try {
                const data = await api('api.php?action=backup_list');
                this.backups = data.backups;
            } catch (e) { this.toast(e.message, 'error'); }
        },

        /* ---------- Listeler ---------- */
        viewTasks(name) {
            if (name === 'today' && this.view === 'dashboard') return this.dashToday;
            return this.tasks;
        },

        upcomingTasks() {
            const up = this.stats.upcoming || [];
            const byDate = {};
            up.forEach((u) => { byDate[u.d] = u.c; });
            return this.tasks.filter((t) => t.due_date && byDate[t.due_date]).slice(0, 6);
        },

        currentFolderName() {
            const f = this.folders.find((x) => x.id === this.currentFolder);
            return f ? f.name : '';
        },
        currentFolderDesc() { const f = this.folders.find((x) => x.id === this.currentFolder); return f ? f.description : ''; },
        currentFolderColor() { const f = this.folders.find((x) => x.id === this.currentFolder); return f ? f.color : '#0A84FF'; },
        currentFolderIcon() { const f = this.folders.find((x) => x.id === this.currentFolder); return f ? f.icon : 'folder'; },
        currentTagName() { const g = this.tags.find((x) => x.id === this.currentTag); return g ? g.name : ''; },
        currentTagColor() { const g = this.tags.find((x) => x.id === this.currentTag); return g ? g.color : '#FF9F0A'; },
        currentTagEmoji() { const g = this.tags.find((x) => x.id === this.currentTag); return g ? g.emoji : '🏷️'; },

        /* ---------- Filtreler ---------- */
        setFilter(k, v) { this.filters[k] = v; this.reloadTasks(); },
        clearSelection() { this.selection = []; this.selectMode = false; },

        toggleSelect(id) {
            const i = this.selection.indexOf(id);
            if (i >= 0) this.selection.splice(i, 1); else this.selection.push(id);
            if (!this.selection.length) this.selectMode = false;
        },
        toggleSelectAll() {
            if (this.selection.length) { this.clearSelection(); return; }
            this.selection = this.tasks.map((t) => t.id);
            this.selectMode = true;
        },

        /* ---------- Toplu İşlemler ---------- */
        async bulk(op, extra = {}) {
            if (!this.selection.length) { this.toast('Görev seçin', 'info'); return; }
            try {
                await api('api.php?action=bulk', {
                    method: 'POST',
                    body: JSON.stringify({ ids: this.selection, op, ...extra }),
                });
                this.toast(this.bulkDoneMsg(op));
                this.clearSelection();
                await this.reloadTasks();
                this.loadStats();
            } catch (e) { this.toast(e.message, 'error'); }
        },
        bulkDoneMsg(op) {
            const m = { delete: 'Görevler silindi', restore: 'Görevler geri alındı', archive: 'Görevler arşivlendi', move_folder: 'Klasör değiştirildi', add_tags: 'Etiketler eklendi', status: 'Durum güncellendi', priority: 'Öncelik güncellendi' };
            return m[op] || 'İşlem tamamlandı';
        },
        bulkFolder() {
            this.askSelect('Klasöre Taşı', this.folders.map((f) => ({ id: f.id, label: f.name, icon: f.icon, color: f.color })))
                .then((fid) => fid !== null && this.bulk('move_folder', { folder_id: fid }));
        },
        bulkTag() {
            this.askSelect('Etiket Ekle', this.tags.map((g) => ({ id: g.id, label: '#' + g.name, icon: g.emoji, color: g.color })))
                .then((gid) => gid !== null && this.bulk('add_tags', { tag_ids: [gid] }));
        },
        bulkPriority() {
            this.askSelect('Öncelik Değiştir', [
                { id: 0, label: 'Düşük', color: '#98989D' },
                { id: 1, label: 'Normal', color: '#0A84FF' },
                { id: 2, label: 'Yüksek', color: '#FF9F0A' },
                { id: 3, label: 'Kritik', color: '#FF453A' },
            ]).then((p) => p !== null && this.bulk('priority', { priority: p }));
        },
        askSelect(title, items) {
            return new Promise((resolve) => {
                const labels = items.map((i) => i.label).join('|');
                const picked = prompt(title + ': ' + labels);
                if (picked === null) return resolve(null);
                const idx = items.findIndex((i) => String(i.label) === picked.trim());
                resolve(idx >= 0 ? items[idx].id : null);
            });
        },

        async emptyTrash() {
            this.confirm('Çöp Kutusunu Boşalt', 'Tüm görevler kalıcı olarak silinecek. Emin misiniz?', async () => {
                if (!this.trashTasks.length) { this.toast('Çöp kutusu zaten boş', 'info'); return; }
                try {
                    await api('api.php?action=bulk', { method: 'POST', body: JSON.stringify({ ids: this.trashTasks.map((t) => t.id), op: 'destroy' }) });
                    this.toast('Çöp kutusu boşaltıldı');
                    this.loadTrash();
                } catch (e) { this.toast(e.message, 'error'); }
            });
        },

        /* ---------- Görev İşlemleri ---------- */
        async openTask(t) {
            try {
                const data = await api('api.php?action=task_get&id=' + t.id);
                this.taskModal = data.task;
                this.modalTab = 'details';
                this.recurForm = { freq: 'none', interval: 1, by_day: '', by_month_day: '', by_month: '', custom_cron: '', ends_on: '' };
                if (this.taskModal.recurrence) {
                    this.recurForm = {
                        freq: this.taskModal.recurrence.freq || 'none',
                        interval: this.taskModal.recurrence.interval || 1,
                        by_day: this.taskModal.recurrence.by_day || '',
                        by_month_day: this.taskModal.recurrence.by_month_day || '',
                        by_month: this.taskModal.recurrence.by_month || '',
                        custom_cron: this.taskModal.recurrence.custom_cron || '',
                        ends_on: this.taskModal.recurrence.ends_on || '',
                    };
                }
                this.hasReminder = false;
                this.$nextTick(() => this.initModalDatepickers());
            } catch (e) { this.toast(e.message, 'error'); }
        },

        closeModal() {
            this.destroyDatepickers();
            this.taskModal = null;
        },

        get taskTags() {
            return (this.taskModal && this.taskModal.tags || []).map((g) => g.id);
        },

        toggleTaskTag(gid) {
            const g = this.tags.find((x) => x.id === gid);
            if (!g || !this.taskModal) return;
            const has = this.taskModal.tags.some((x) => x.id === gid);
            if (has) {
                this.taskModal.tags = this.taskModal.tags.filter((x) => x.id !== gid);
            } else {
                this.taskModal.tags = [...this.taskModal.tags, { ...g, task_count: 0 }];
            }
        },

        async saveTask() {
            const t = this.taskModal;
            if (!t) return;
            if (!t.title || !t.title.trim()) { this.toast('Başlık gerekli', 'error'); return; }
            const payload = {
                id: t.id,
                title: t.title.trim(),
                description: t.description || '',
                notes: t.notes || '',
                start_date: t.start_date || '',
                due_date: t.due_date || '',
                due_time: t.due_time || '',
                priority: Number(t.priority) || 0,
                status: Number(t.status) || 0,
                progress: Math.min(100, Math.max(0, Number(t.progress) || 0)),
                estimated_time: t.estimated_time ?? '',
                actual_time: Number(t.actual_time) || 0,
                color: t.color || '#0A84FF',
                emoji: t.emoji || '',
                icon: t.icon || 'check_circle',
                location: t.location || '',
                folder_id: t.folder_id || '',
                is_favorite: t.is_favorite ? 1 : 0,
                is_pinned: t.is_pinned ? 1 : 0,
                tags: this.taskTags,
                recurrence: this.recurForm.freq !== 'none' ? this.recurForm : null,
                reminders: [],
            };
            if (this.hasReminder) {
                const rd = t.due_date || new Date().toISOString().slice(0, 10);
                const rt = t.due_time || '09:00';
                payload.reminders.push({ remind_at: `${rd} ${rt}:00`, remind_type: 'notification', sound: Number(this.settingsForm.sound) });
            }
            try {
                const data = await api('api.php?action=task_save', { method: 'POST', body: JSON.stringify(payload) });
                const saved = data.task;
                const i = this.tasks.findIndex((x) => x.id === saved.id);
                if (i >= 0) this.tasks[i] = saved; else this.tasks.unshift(saved);
                this.totalTasks = Math.max(this.totalTasks, this.tasks.length);
                this.toast('Görev kaydedildi');
                this.closeModal();
                this.reloadTasks();
                this.loadStats();
            } catch (e) { this.toast(e.message, 'error'); }
        },

        async toggleTask(t) {
            try {
                const data = await api('api.php?action=task_toggle', { method: 'POST', body: JSON.stringify({ id: t.id }) });
                const updated = data.task;
                const i = this.tasks.findIndex((x) => x.id === t.id);
                if (i >= 0) this.tasks[i] = updated;
                if (this.taskModal && this.taskModal.id === t.id) {
                    this.taskModal = { ...this.taskModal, ...updated };
                }
                this.toast(updated.status === 3 ? 'Tamamlandı 🎉' : 'Geri alındı');
                this.loadStats();
                if (this.view === 'completed') this.reloadTasks();
            } catch (e) { this.toast(e.message, 'error'); }
        },

        async trashTask(t) {
            try {
                await api('api.php?action=task_delete', { method: 'POST', body: JSON.stringify({ id: t.id }) });
                this.tasks = this.tasks.filter((x) => x.id !== t.id);
                this.totalTasks = Math.max(0, this.totalTasks - 1);
                if (this.taskModal && this.taskModal.id === t.id) this.taskModal = null;
                this.toast('Çöp kutusuna taşındı');
                this.loadStats();
            } catch (e) { this.toast(e.message, 'error'); }
        },

        async restoreTask(t) {
            try {
                await api('api.php?action=task_restore', { method: 'POST', body: JSON.stringify({ id: t.id }) });
                this.trashTasks = this.trashTasks.filter((x) => x.id !== t.id);
                this.toast('Görev geri alındı');
            } catch (e) { this.toast(e.message, 'error'); }
        },

        destroyTask(t) {
            this.confirm('Görevi Kalıcı Sil', '"' + t.title + '" kalıcı olarak silinecek. Bu işlem geri alınamaz.', async () => {
                try {
                    await api('api.php?action=task_destroy', { method: 'POST', body: JSON.stringify({ id: t.id }) });
                    this.trashTasks = this.trashTasks.filter((x) => x.id !== t.id);
                    this.toast('Görev kalıcı silindi');
                } catch (e) { this.toast(e.message, 'error'); }
            });
        },

        async archiveTask(t) {
            try {
                await api('api.php?action=task_archive', { method: 'POST', body: JSON.stringify({ id: t.id }) });
                this.tasks = this.tasks.filter((x) => x.id !== t.id);
                this.toast('Arşivlendi');
            } catch (e) { this.toast(e.message, 'error'); }
        },
        async unarchiveTask(t) {
            try {
                await api('api.php?action=task_unarchive', { method: 'POST', body: JSON.stringify({ id: t.id }) });
                this.tasks = this.tasks.filter((x) => x.id !== t.id);
                this.toast('Arşivden çıkarıldı');
            } catch (e) { this.toast(e.message, 'error'); }
        },

        async setFlag(t, col, val) {
            t[col] = val;
            try {
                await api('api.php?action=task_flag', { method: 'POST', body: JSON.stringify({ id: t.id, col, value: val }) });
            } catch (e) { t[col] = val ? 0 : 1; this.toast(e.message, 'error'); }
        },

        async moveTaskFolder(t, folderId) {
            try {
                await api('api.php?action=task_move_folder', { method: 'POST', body: JSON.stringify({ id: t.id, folder_id: folderId }) });
                t.folder_id = folderId;
                this.toast('Klasör güncellendi');
            } catch (e) { this.toast(e.message, 'error'); }
        },

        /* ---------- Alt Görevler ---------- */
        get subtaskStats() {
            const list = (this.taskModal && this.taskModal.subtasks) || [];
            const done = list.filter((s) => s.completed).length;
            const total = list.length;
            return { done, total, pct: total ? Math.round(done * 100 / total) : 0 };
        },
        async addSubtask() {
            const title = this.newSubtask.trim();
            if (!title) return;
            try {
                const data = await api('api.php?action=subtask_save', {
                    method: 'POST', body: JSON.stringify({ task_id: this.taskModal.id, title }),
                });
                this.taskModal.subtasks.push({ id: data.id, title, completed: 0, sort_order: this.taskModal.subtasks.length });
                this.newSubtask = '';
            } catch (e) { this.toast(e.message, 'error'); }
        },
        async toggleSubtask(s) {
            try {
                const data = await api('api.php?action=subtask_toggle', { method: 'POST', body: JSON.stringify({ id: s.id }) });
                s.completed = data.completed;
            } catch (e) { this.toast(e.message, 'error'); }
        },
        async renameSubtask(s, title) {
            if (!title.trim()) return;
            try {
                await api('api.php?action=subtask_save', { method: 'POST', body: JSON.stringify({ id: s.id, task_id: s.task_id, title }) });
                s.title = title;
            } catch (e) { this.toast(e.message, 'error'); }
        },
        async delSubtask(s) {
            try {
                await api('api.php?action=subtask_delete', { method: 'POST', body: JSON.stringify({ id: s.id, task_id: s.task_id }) });
                this.taskModal.subtasks = this.taskModal.subtasks.filter((x) => x.id !== s.id);
            } catch (e) { this.toast(e.message, 'error'); }
        },

        /* ---------- Checklist ---------- */
        get checkStats() {
            const list = (this.taskModal && this.taskModal.checklists) || [];
            const done = list.filter((c) => c.completed).length;
            const total = list.length;
            return { done, total, pct: total ? Math.round(done * 100 / total) : 0 };
        },
        async addChecklist() {
            const title = this.newChecklist.trim();
            if (!title) return;
            try {
                const data = await api('api.php?action=checklist_save', {
                    method: 'POST', body: JSON.stringify({ task_id: this.taskModal.id, title }),
                });
                this.taskModal.checklists.push({ id: data.id, title, completed: 0, sort_order: this.taskModal.checklists.length });
                this.newChecklist = '';
            } catch (e) { this.toast(e.message, 'error'); }
        },
        async toggleChecklist(c) {
            try {
                const data = await api('api.php?action=checklist_toggle', { method: 'POST', body: JSON.stringify({ id: c.id }) });
                c.completed = data.completed;
            } catch (e) { this.toast(e.message, 'error'); }
        },
        async delChecklist(c) {
            try {
                await api('api.php?action=checklist_delete', { method: 'POST', body: JSON.stringify({ id: c.id, task_id: c.task_id }) });
                this.taskModal.checklists = this.taskModal.checklists.filter((x) => x.id !== c.id);
            } catch (e) { this.toast(e.message, 'error'); }
        },

        /* ---------- Dosyalar ---------- */
        async uploadFiles(files) {
            if (!files || !files.length) return;
            for (const file of Array.from(files)) {
                if (file.size > 25 * 1024 * 1024) { this.toast('Dosya 25MB üstü: ' + file.name, 'error'); continue; }
                const fd = new FormData();
                fd.append('file', file);
                fd.append('task_id', this.taskModal.id);
                try {
                    const data = await api('api.php?action=attachment_upload', { method: 'POST', body: fd });
                    this.taskModal.attachments.push({
                        id: data.id, filename: file.name, filesize: file.size,
                        mime_type: file.type, created_at: new Date().toLocaleString(),
                    });
                } catch (e) { this.toast(e.message, 'error'); }
            }
            this.toast('Dosyalar yüklendi');
        },
        async delAttachment(a) {
            try {
                await api('api.php?action=attachment_delete', { method: 'POST', body: JSON.stringify({ id: a.id }) });
                this.taskModal.attachments = this.taskModal.attachments.filter((x) => x.id !== a.id);
            } catch (e) { this.toast(e.message, 'error'); }
        },

        /* ---------- Tekrarlama ---------- */
        toggleCsv(key, val) {
            const cur = this.recurForm[key] ? this.recurForm[key].split(',') : [];
            const i = cur.indexOf(val);
            if (i >= 0) cur.splice(i, 1); else cur.push(val);
            this.recurForm[key] = cur.join(',');
            this.recurPreview();
        },
        async recurPreview() {
            try {
                const data = await api('api.php?action=recurrence_preview', {
                    method: 'POST', body: JSON.stringify({
                        rule: this.recurForm,
                        from: (this.taskModal && this.taskModal.due_date) || new Date().toISOString().slice(0, 10),
                    }),
                });
                this.recurDates = data.dates;
            } catch (e) { this.recurDates = []; }
        },

        /* ---------- Hızlı Ekle ---------- */
        quickSubmit() {
            const parsed = this.parseNatural(this.quickText);
            if (this.newQuickInFolder && !parsed.folder) parsed.folder = this.newQuickInFolder;
            if (this.newQuickTag && !parsed.tags.length) parsed.tags = [this.newQuickTag];
            this.quickSubmitParsed(parsed);
        },
        async quickSubmitParsed(p) {
            if (!p.title) { this.toast('Boş görev eklenemez', 'error'); return; }
            try {
                const data = await api('api.php?action=task_quick', {
                    method: 'POST', body: JSON.stringify({
                        title: p.title, due_date: p.date || '', due_time: p.time || '',
                        priority: p.priority || 1, folder_id: p.folder || '',
                    }),
                });
                for (const tagId of p.tags || []) {
                    await api('api.php?action=task_tag_toggle', {
                        method: 'POST', body: JSON.stringify({ task_id: data.id, tag_id: tagId }),
                    });
                }
                this.quickOpen = false;
                this.quickText = '';
                this.quickParsed = null;
                this.newQuickInFolder = null;
                this.newQuickTag = null;
                this.toast('Görev eklendi ⚡');
                this.reloadTasks();
                this.loadStats();
            } catch (e) { this.toast(e.message, 'error'); }
        },

        /**
         * Doğal dil ayrıştırıcısı (Türkçe).
         * "Yarın saat 15'te Ahmet'i ara" → { title, date, time, priority, tags, folder }
         */
        parseNatural(text) {
            let t = text.trim();
            const out = { title: t, date: '', time: '', priority: 1, tags: [], folder: '' };
            if (!t) return out;
            const now = new Date();
            const fmt = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');

            // Öncelik
            const prioMatch = t.match(/(?:kritik|acil|!!!)/i);
            if (prioMatch) { out.priority = 3; t = t.replace(prioMatch[0], ''); }
            else if (t.match(/(?:yüksek|önemli|!!)/i)) { out.priority = 2; t = t.replace(/(?:yüksek|önemli)/i, ''); }
            else if (t.match(/(?:düşük|önemsiz|!)/i)) { out.priority = 0; t = t.replace(/(?:düşük|önemsiz)/i, ''); }
            t = t.replace(/!{1,3}/g, ' ');

            // Tarih: özel günler
            let dateMatch = null;
            const special = [
                [/öbür gün/i, 2], [/yarın/i, 1], [/bugün|bugünün/i, 0],
                [/hafta sonuna/i, 5], [/haftaya/i, 7],
            ];
            for (const [re, add] of special) {
                if (re.test(t)) { dateMatch = new Date(now.getTime() + add * 86400000); t = t.replace(re, ' '); break; }
            }

            // Tarih: "15 mart" biçimi
            if (!dateMatch) {
                const m = t.match(/(\d{1,2})\s+(ocak|şubat|mart|nisan|mayıs|haziran|temmuz|ağustos|eylül|ekim|kasım|aralık)(?:'ta|'de|'da|'te)?/i);
                if (m) {
                    let y = now.getFullYear();
                    let mo = TR_MONTHS[m[2].toLowerCase()];
                    if (mo < now.getMonth() + 1 || (mo === now.getMonth() + 1 && Number(m[1]) < now.getDate())) y++;
                    const d = new Date(y, mo - 1, Number(m[1]));
                    if (d.getDate() === Number(m[1])) dateMatch = d;
                    t = t.replace(m[0], ' ');
                }
            }

            // Tarih: gün adları
            if (!dateMatch) {
                const m = t.match(/(pazartesi|salı|çarşamba|perşembe|cuma|cumartesi|pazar)(?:'ye|'ya|'e|'a|'de|'da|'te|'ta| günü)?/i);
                if (m) {
                    const target = TR_DAYS[m[1].toLowerCase()];
                    let d = new Date(now);
                    let diff = (target - d.getDay() + 7) % 7;
                    if (diff === 0) diff = 7;
                    d.setDate(d.getDate() + diff);
                    dateMatch = d;
                    t = t.replace(m[0], ' ');
                }
            }

            // Saat: "15:30" veya "saat 15"
            const timeMatch = t.match(/(\d{1,2})[.:](\d{2})/);
            if (timeMatch) {
                out.time = timeMatch[1].padStart(2, '0') + ':' + timeMatch[2];
                t = t.replace(timeMatch[0], ' ');
            } else {
                const sm = t.match(/saat\s+(\d{1,2})(?:'e|'a|'te|'ta|'de|'da|'sinde|'sında)?/i);
                if (sm) {
                    out.time = sm[1].padStart(2, '0') + ':00';
                    t = t.replace(sm[0], ' ');
                }
            }

            // Klasör & etiket: #ad
            const hashes = t.match(/#([\wğüşöçıĞÜŞÖÇİ\-]+)/g) || [];
            for (const h of hashes) {
                const name = h.slice(1).toLowerCase();
                const folder = this.folders.find((f) => f.name.toLowerCase() === name);
                if (folder) { out.folder = folder.id; t = t.replace(h, ' '); continue; }
                const tag = this.tags.find((g) => g.name.toLowerCase() === name);
                if (tag) { out.tags.push(tag.id); t = t.replace(h, ' '); }
            }

            if (dateMatch) out.date = fmt(dateMatch);
            out.title = t.replace(/\s+/g, ' ').trim();
            this.quickParsed = out;
            return out;
        },

        /* ---------- Takvim ---------- */
        get todayStr() {
            const d = new Date();
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        },

        async loadCalendarTasks() {
            try {
                const from = new Date(this.cal.year, this.cal.month, 1);
                const to = new Date(this.cal.year, this.cal.month + 1, 0);
                const fmt = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
                const data = await api('api.php?action=tasks&' + new URLSearchParams({ view: 'all', date_from: fmt(from), date_to: fmt(to), limit: 500 }));
                this.calTasks = data.tasks;
            } catch (e) { this.toast(e.message, 'error'); }
        },

        get calTitle() {
            const d = new Date(this.cal.year, this.cal.month, 1);
            let s = d.toLocaleDateString('tr-TR', { month: 'long', year: 'numeric' });
            if (this.cal.mode === 'week') s = this.weekStart.toLocaleDateString('tr-TR', { day: 'numeric', month: 'long' });
            if (this.cal.mode === 'day') s = new Date(this.cal.selected + 'T00:00').toLocaleDateString('tr-TR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
            return s.charAt(0).toUpperCase() + s.slice(1);
        },

        get calCells() {
            const cells = [];
            const first = new Date(this.cal.year, this.cal.month, 1);
            const startPad = (first.getDay() + 6) % 7;
            const daysInMonth = new Date(this.cal.year, this.cal.month + 1, 0).getDate();
            const start = new Date(this.cal.year, this.cal.month, 1 - startPad);
            for (let i = 0; i < 42; i++) {
                const d = new Date(start);
                d.setDate(d.getDate() + i);
                cells.push({
                    key: d.toISOString().slice(0, 10).replace('T', ''),
                    date: d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'),
                    day: d.getDate(),
                    current: d.getMonth() === this.cal.month,
                    isToday: this.toISO(d) === this.todayStr,
                });
            }
            return cells;
        },

        get weekStart() {
            const sel = new Date(this.cal.selected + 'T00:00');
            const diff = (sel.getDay() + 6) % 7;
            const ws = new Date(sel);
            ws.setDate(sel.getDate() - diff);
            return ws;
        },

        get calWeekDays() {
            const ws = this.weekStart;
            const days = [];
            for (let i = 0; i < 7; i++) {
                const d = new Date(ws);
                d.setDate(ws.getDate() + i);
                days.push(this.toISO(d));
            }
            return days;
        },

        toISO(d) {
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        },

        tasksOn(date) {
            return this.calTasks.filter((t) => t.due_date === date);
        },

        calNav(dir) {
            if (this.cal.mode === 'day') {
                const d = new Date(this.cal.selected + 'T00:00');
                d.setDate(d.getDate() + dir);
                this.cal.selected = this.toISO(d);
            } else {
                this.cal.month += dir;
                if (this.cal.month < 0) { this.cal.month = 11; this.cal.year--; }
                if (this.cal.month > 11) { this.cal.month = 0; this.cal.year++; }
            }
            this.loadCalendarTasks();
        },
        calToday() {
            const t = new Date();
            this.cal.year = t.getFullYear();
            this.cal.month = t.getMonth();
            this.cal.selected = this.todayStr;
            this.loadCalendarTasks();
        },
        calSelectDate(date) {
            const [y, m] = date.split('-').map(Number);
            this.cal.year = y;
            this.cal.month = m - 1;
            this.loadCalendarTasks();
        },
        async dropOnDate(date) {
            if (!this.dragTask) return;
            const t = this.dragTask;
            this.dragTask = null;
            try {
                await api('api.php?action=task_save', {
                    method: 'POST',
                    body: JSON.stringify({
                        id: t.id, title: t.title, due_date: date,
                        due_time: t.due_time || '', start_date: t.start_date || '',
                        description: t.description || '', notes: t.notes || '',
                        priority: t.priority, status: t.status, progress: t.progress,
                        color: t.color || '#0A84FF', emoji: t.emoji || '', icon: t.icon || '',
                        location: t.location || '', folder_id: t.folder_id || '',
                        estimated_time: t.estimated_time ?? '', actual_time: t.actual_time || 0,
                        is_favorite: t.is_favorite, is_pinned: t.is_pinned, tags: [],
                    }),
                });
                t.due_date = date;
                this.toast('Tarih güncellendi: ' + this.humanDate(date));
                this.loadCalendarTasks();
            } catch (e) { this.toast(e.message, 'error'); }
        },

        /* ---------- Kanban ---------- */
        async loadKanban() {
            try {
                const data = await api('api.php?action=tasks&view=all&limit=300');
                this.tasks = data.tasks;
                this.totalTasks = data.total;
                this.$nextTick(() => { this.initKanbanSortable(); this.initListSortable(); });
            } catch (e) { this.toast(e.message, 'error'); }
        },
        kanbanCount(status) { return this.tasks.filter((t) => t.status === status && t.deleted_at === null).length; },
        kanbanTasks(status) { return this.tasks.filter((t) => t.status === status && t.deleted_at === null); },
        initKanbanSortable() {
            if (!window.Sortable || this.view !== 'kanban') return;
            const grid = document.getElementById('kanban-grid');
            if (!grid || grid.dataset.sortableInit) return;
            grid.dataset.sortableInit = '1';
            grid.querySelectorAll('.kanban-list').forEach((col) => {
                Sortable.create(col, {
                    group: 'kanban',
                    animation: 200,
                    ghostClass: 'opacity-30',
                    onEnd: (evt) => this.kanbanMove(evt),
                });
            });
        },
        async kanbanMove(evt) {
            const id = Number(evt.item.dataset.id);
            const newStatus = Number(evt.to.dataset.status);
            const task = this.tasks.find((t) => t.id === id);
            if (!task || task.status === newStatus) return;
            task.status = newStatus;
            if (newStatus === 3) task.progress = 100;
            try {
                await api('api.php?action=task_save', {
                    method: 'POST', body: JSON.stringify({
                        id: task.id, title: task.title, description: task.description || '',
                        notes: task.notes || '', start_date: task.start_date || '',
                        due_date: task.due_date || '', due_time: task.due_time || '',
                        priority: task.priority, status: newStatus, progress: task.progress,
                        color: task.color || '#0A84FF', emoji: task.emoji || '', icon: task.icon || '',
                        location: task.location || '', folder_id: task.folder_id || '',
                        estimated_time: task.estimated_time ?? '', actual_time: task.actual_time || 0,
                        is_favorite: task.is_favorite, is_pinned: task.is_pinned, tags: [],
                    }),
                });
                this.toast(newStatus === 3 ? 'Tamamlandı 🎉' : 'Durum: ' + this.kanbanCols[newStatus].label);
            } catch (e) {
                task.status = 0;
                this.toast(e.message, 'error');
            }
        },

        /* ---------- Sürükle Sıralama (liste) ---------- */
        initListSortable() {
            if (!window.Sortable) return;
            const list = document.getElementById('task-list');
            if (!list) return;
            if (this.listSortable) { this.listSortable.destroy(); this.listSortable = null; }
            if (this.filters.sort !== 'default') return;
            this.listSortable = Sortable.create(list, {
                animation: 200,
                handle: false,
                ghostClass: 'opacity-30',
                onEnd: async (evt) => {
                    const ids = Array.from(list.children).map((el) => Number(el.dataset.id)).filter(Boolean);
                    try {
                        await api('api.php?action=task_reorder', { method: 'POST', body: JSON.stringify({ order: ids }) });
                    } catch (e) { this.toast(e.message, 'error'); }
                },
            });
        },

        /* ---------- Raporlar ---------- */
        async loadReports() {
            try {
                const data = await api('api.php?action=reports&weeks=' + this.reportWeeks);
                this.reports = data.reports;
                this.$nextTick(() => this.renderReportCharts());
            } catch (e) { this.toast(e.message, 'error'); }
        },

        chartBase(colors = ['#0A84FF']) {
            const dark = document.documentElement.classList.contains('dark');
            return {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        labels: { color: dark ? '#aaa' : '#666', font: { size: 11, family: 'Inter' }, usePointStyle: true, boxWidth: 8 },
                    },
                    tooltip: {
                        backgroundColor: dark ? '#1c1c1e' : '#fff',
                        titleColor: dark ? '#fff' : '#1D1D1F',
                        bodyColor: dark ? '#bbb' : '#444',
                        borderColor: dark ? '#333' : '#eee',
                        borderWidth: 1,
                    },
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: dark ? '#777' : '#999', font: { size: 10 } } },
                    y: { beginAtZero: true, grid: { color: dark ? '#ffffff0a' : '#0000000a' }, ticks: { color: dark ? '#777' : '#999', font: { size: 10 }, precision: 0 } },
                },
            };
        },

        ensureChart(id, config) {
            if (this.charts[id]) this.charts[id].destroy();
            const el = document.getElementById(id);
            if (!el) return;
            this.charts[id] = new Chart(el.getContext('2d'), config);
        },

        renderDashboardChart() {
            const rec = this.stats.recent_completions || [];
            const days = [];
            const vals = [];
            for (let i = 13; i >= 0; i--) {
                const d = new Date();
                d.setDate(d.getDate() - i);
                const iso = this.toISO(d);
                days.push(d.toLocaleDateString('tr-TR', { day: 'numeric', month: 'short' }));
                const r = rec.find((x) => x.d === iso);
                vals.push(r ? r.c : 0);
            }
            const base = this.chartBase();
            base.scales.y.display = false;
            this.ensureChart('chart-week', {
                type: 'line',
                data: {
                    labels: days,
                    datasets: [{
                        data: vals,
                        borderColor: '#0A84FF',
                        backgroundColor: 'rgba(10,132,255,.12)',
                        fill: true,
                        tension: .45,
                        pointRadius: 0,
                        pointHoverRadius: 4,
                        borderWidth: 2,
                    }],
                },
                options: base,
            });
        },

        renderReportCharts() {
            const r = this.reports;
            if (!r) return;
            const base = this.chartBase();
            const dark = document.documentElement.classList.contains('dark');

            // Tamamlama grafiği
            this.ensureChart('chart-completion', {
                type: 'line',
                data: {
                    labels: r.byDay.map((x) => x.d),
                    datasets: [{
                        label: 'Tamamlanan',
                        data: r.byDay.map((x) => x.c),
                        borderColor: '#30D158',
                        backgroundColor: 'rgba(48,209,88,.12)',
                        fill: true,
                        tension: .4,
                        pointRadius: 0,
                        borderWidth: 2,
                    }],
                },
                options: base,
            });

            // Haftalık
            this.ensureChart('chart-weekly', {
                type: 'bar',
                data: {
                    labels: r.weekly.map((x) => x.w),
                    datasets: [{
                        label: 'Görev',
                        data: r.weekly.map((x) => x.c),
                        backgroundColor: 'rgba(94,92,230,.7)',
                        borderRadius: 6,
                    }],
                },
                options: base,
            });

            // Aylık
            this.ensureChart('chart-monthly', {
                type: 'bar',
                data: {
                    labels: r.monthly.map((x) => x.m),
                    datasets: [{
                        label: 'Görev',
                        data: r.monthly.map((x) => x.c),
                        backgroundColor: 'rgba(10,132,255,.7)',
                        borderRadius: 6,
                    }],
                },
                options: base,
            });

            // Klasör dağılımı
            const fColors = ['#0A84FF', '#30D158', '#FF9F0A', '#FF453A', '#5E5CE6', '#BF5AF2', '#FF6482', '#64D2FF', '#FFD60A', '#A2845E', '#98989D', '#00C7BE'];
            this.ensureChart('chart-folder', {
                type: 'doughnut',
                data: {
                    labels: r.byFolder.map((x) => x.name),
                    datasets: [{
                        data: r.byFolder.map((x) => x.c),
                        backgroundColor: fColors,
                        borderWidth: 0,
                    }],
                },
                options: { ...this.chartBase(fColors), scales: {} },
            });

            // Tag dağılımı
            this.ensureChart('chart-tag', {
                type: 'doughnut',
                data: {
                    labels: r.byTag.map((x) => '#' + x.name),
                    datasets: [{
                        data: r.byTag.map((x) => x.c),
                        backgroundColor: fColors,
                        borderWidth: 0,
                    }],
                },
                options: { ...this.chartBase(fColors), scales: {} },
            });

            // Öncelik
            const plabels = ['Düşük', 'Normal', 'Yüksek', 'Kritik'];
            const pColors = ['#98989D', '#0A84FF', '#FF9F0A', '#FF453A'];
            const pdata = [0, 1, 2, 3].map((p) => { const r2 = r.byPriority.find((x) => Number(x.priority) === p); return r2 ? r2.c : 0; });
            this.ensureChart('chart-priority', {
                type: 'bar',
                data: {
                    labels: plabels,
                    datasets: [{ data: pdata, backgroundColor: pColors, borderRadius: 6 }],
                },
                options: { ...this.chartBase(pColors), scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: dark ? '#ffffff0a' : '#0000000a' }, display: false } } },
            });

            // Durum
            const slabels = ['Bekliyor', 'Devam', 'Askıda', 'Tamamlandı', 'İptal'];
            const sdata = [0, 1, 2, 3, 4].map((s) => { const r2 = r.byStatus.find((x) => Number(x.status) === s); return r2 ? r2.c : 0; });
            this.ensureChart('chart-status', {
                type: 'doughnut',
                data: {
                    labels: slabels,
                    datasets: [{ data: sdata, backgroundColor: ['#98989D', '#0A84FF', '#FF9F0A', '#30D158', '#FF453A'], borderWidth: 0 }],
                },
                options: { plugins: { legend: { position: 'bottom', labels: { color: dark ? '#aaa' : '#666', font: { size: 10 }, usePointStyle: true } } } },
            });
        },

        /* ---------- Dışa / İçe Aktarım ---------- */
        exportData(type) {
            window.location.href = 'api.php?action=export&type=' + type;
            this.toast('Dışa aktarım başladı');
        },
        importFile(evt) {
            const file = evt.target.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = (e) => { this.importText = e.target.result; };
            reader.readAsText(file, 'UTF-8');
        },
        async importSubmit() {
            if (!this.importText.trim()) { this.toast('İçerik boş', 'error'); return; }
            try {
                const data = await api('api.php?action=import', {
                    method: 'POST', body: JSON.stringify({ type: this.importType, content: this.importText }),
                });
                this.toast(data.imported + ' görev içe aktarıldı');
                this.importOpen = false;
                this.importText = '';
                this.reloadTasks();
                this.loadStats();
                this.loadFolders();
                this.loadTags();
            } catch (e) { this.toast(e.message, 'error'); }
        },

        /* ---------- Yedekleme ---------- */
        async backupCreate() {
            try {
                await api('api.php?action=backup_create', { method: 'POST' });
                this.toast('Yedek oluşturuldu');
                this.loadBackups();
            } catch (e) { this.toast(e.message, 'error'); }
        },
        async backupDelete(id) {
            try {
                await api('api.php?action=backup_delete', { method: 'POST', body: JSON.stringify({ id }) });
                this.loadBackups();
            } catch (e) { this.toast(e.message, 'error'); }
        },
        backupDownload(id) { window.location.href = 'api.php?action=backup_download&id=' + id; },
        confirmBackupRestore(b) {
            this.confirm('Yedek Geri Yükle', '"' + b.filename + '" geri yüklenecek. Mevcut veriler bu yedekle değiştirilecek.', async () => {
                try {
                    await api('api.php?action=backup_restore', { method: 'POST', body: JSON.stringify({ id: b.id }) });
                    this.toast('Yedek geri yüklendi, yeniden yükleniyor...');
                    setTimeout(() => window.location.reload(), 900);
                } catch (e) { this.toast(e.message, 'error'); }
            });
        },

        /* ---------- Ayarlar ---------- */
        async saveSettings() {
            try {
                await api('api.php?action=settings_save', {
                    method: 'POST', body: JSON.stringify({ ...this.settingsForm }),
                });
                this.applyAccent(this.settingsForm.accent);
                document.documentElement.classList.toggle('dark', this.themeClass === 'dark');
                if (this.pomodoro.mode === 'focus') {
                    this.pomodoro.total = (this.settingsForm.pomodoro_focus || 25) * 60;
                }
                this.toast('Ayarlar kaydedildi');
            } catch (e) { this.toast(e.message, 'error'); }
        },
        async requestNotifyPerm() {
            if (!('Notification' in window)) { this.toast('Tarayıcı bildirim desteklemiyor', 'error'); return; }
            const perm = await Notification.requestPermission();
            this.toast(perm === 'granted' ? 'Bildirimler açıldı' : 'Bildirim izni verilmedi', perm === 'granted' ? 'success' : 'info');
        },

        /* ---------- Bildirimler ---------- */
        startReminderPolling() {
            if (this.reminderTimer) clearInterval(this.reminderTimer);
            this.reminderTimer = setInterval(() => this.checkReminders(), 30000);
        },
        async checkReminders() {
            try {
                const data = await api('api.php?action=check_reminders');
                for (const r of data.reminders || []) {
                    this.notify('⏰ Hatırlatma', r.title, r.sound ? 1 : 0);
                }
            } catch (e) { /* sessiz */ }
        },
        notify(title, body, playSound = 1) {
            if (Number(this.settingsForm.notifications) === 1 && 'Notification' in window && Notification.permission === 'granted') {
                try {
                    const n = new Notification(title, { body, icon: 'assets/icons/icon.svg' });
                    setTimeout(() => n.close(), 8000);
                } catch (e) { /* sessiz */ }
            }
            if (Number(this.settingsForm.sound) === 1 && playSound) this.beep();
            this.toast(title + (body ? ': ' + body : ''), 'info', 'notifications');
        },
        beep() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const o = ctx.createOscillator();
                const g = ctx.createGain();
                o.connect(g); g.connect(ctx.destination);
                o.frequency.setValueAtTime(880, ctx.currentTime);
                g.gain.setValueAtTime(.15, ctx.currentTime);
                g.gain.exponentialRampToValueAtTime(.001, ctx.currentTime + .7);
                o.start(); o.stop(ctx.currentTime + .7);
                setTimeout(() => o.disconnect(), 800);
            } catch (e) { /* sessiz */ }
        },

        /* ---------- Pomodoro ---------- */
        pomodoroOpen() {
            this.pomodoroOpen_ = true;
            if (!this.pomodoro.running) this.pomodoroReset();
        },
        pomodoroClose() { this.pomodoroOpen_ = false; this.pomodoro.running = false; },
        pomodoroToggle() { this.pomodoro.running = !this.pomodoro.running; },
        pomodoroReset() {
            this.pomodoro.running = false;
            this.pomodoro.mode = 'focus';
            this.pomodoro.total = (this.settingsForm.pomodoro_focus || 25) * 60;
            this.pomodoro.seconds = this.pomodoro.total;
        },
        pomodoroSkip() {
            if (this.pomodoro.mode === 'focus') {
                this.pomodoro.mode = 'break';
                this.pomodoro.total = (this.settingsForm.pomodoro_break || 5) * 60;
            } else {
                this.pomodoro.mode = 'focus';
                this.pomodoro.total = (this.settingsForm.pomodoro_focus || 25) * 60;
            }
            this.pomodoro.seconds = this.pomodoro.total;
        },
        initPomodoroTimer() {
            this.pomoTimer = setInterval(() => {
                if (!this.pomodoro.running) return;
                this.pomodoro.seconds--;
                if (this.pomodoro.seconds <= 0) {
                    this.pomodoro.running = false;
                    if (this.pomodoro.mode === 'focus') {
                        this.beep(); this.notify('🍅 Pomodoro bitti', 'Mola zamanı!');
                        this.pomodoro.mode = 'break';
                        this.pomodoro.total = (this.settingsForm.pomodoro_break || 5) * 60;
                    } else {
                        this.beep(); this.notify('☕ Mola bitti', 'Tekrar odaklan!');
                        this.pomodoro.mode = 'focus';
                        this.pomodoro.total = (this.settingsForm.pomodoro_focus || 25) * 60;
                    }
                    this.pomodoro.seconds = this.pomodoro.total;
                }
            }, 1000);
        },
        get pomodoroDisplay() {
            const m = Math.floor(this.pomodoro.seconds / 60);
            const s = this.pomodoro.seconds % 60;
            return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
        },
        get pomodoroCircum() { return 2 * Math.PI * 52; },
        get pomodoroOffset() {
            const frac = this.pomodoro.total ? this.pomodoro.seconds / this.pomodoro.total : 0;
            return this.pomodoroCircum * (1 - frac);
        },

        /* ---------- Onay / Bağlam Menüsü / Toast ---------- */
        openContext(e, t) {
            this.contextTask = t;
            const x = Math.min(e.clientX, window.innerWidth - 230);
            const y = Math.min(e.clientY, window.innerHeight - 280);
            this.contextPos = 'left:' + x + 'px;top:' + y + 'px;';
            this.contextOpen = true;
        },
        confirm(title, message, cb) { this.confirmMsg = { title, message }; this.confirmCb = cb; },
        confirmCancel() { this.confirmMsg = null; this.confirmCb = null; },
        async confirmOk() {
            const cb = this.confirmCb;
            this.confirmMsg = null;
            this.confirmCb = null;
            if (cb) await cb();
        },
        confirmDeleteFolder() {
            this.folderModal.open = false;
            this.confirm('Klasörü Sil', '"' + this.folderModal.name + '" silinecek, içindeki görevler Inbox\'a taşınacak.', async () => {
                await this.deleteFolder();
            });
        },
        confirmDeleteTag() {
            this.tagModal.open = false;
            this.confirm('Etiketi Sil', '"' + this.tagModal.name + '" silinecek.', async () => {
                await this.deleteTag();
            });
        },
        toast(message, type = 'success', icon = null) {
            const id = ++this.toastSeq;
            const icons = { success: 'check_circle', error: 'error', info: 'info' };
            this.toasts.push({ id, message, type, icon: icon || icons[type] || 'check_circle' });
            setTimeout(() => this.removeToast(id), 4200);
        },
        removeToast(id) {
            const t = this.toasts.find((x) => x.id === id);
            if (!t) return;
            t.leaving = true;
            setTimeout(() => { this.toasts = this.toasts.filter((x) => x.id !== id); }, 300);
        },

        /* ---------- Tarih / Biçim Yardımcıları ---------- */
        humanDate(d) {
            if (!d) return '';
            const iso = d.slice(0, 10);
            const today = this.todayStr;
            const t2 = new Date(today + 'T00:00');
            t2.setDate(t2.getDate() + 1);
            const tomorrow = this.toISO(t2);
            t2.setDate(t2.getDate() - 2);
            const yesterday = this.toISO(t2);
            if (iso === today) return 'Bugün';
            if (iso === tomorrow) return 'Yarın';
            if (iso === yesterday) return 'Dün';
            return new Date(iso + 'T00:00').toLocaleDateString('tr-TR', { day: 'numeric', month: 'short' });
        },
        timeAgo(ts) {
            if (!ts) return '';
            const diff = (Date.now() - new Date(ts.replace(' ', 'T')).getTime()) / 1000;
            if (diff < 60) return 'az önce';
            if (diff < 3600) return Math.floor(diff / 60) + ' dk önce';
            if (diff < 86400) return Math.floor(diff / 3600) + ' sa önce';
            if (diff < 604800) return Math.floor(diff / 86400) + ' gün önce';
            return new Date(ts.replace(' ', 'T')).toLocaleDateString('tr-TR');
        },
        dueLabel(t) {
            const parts = [this.humanDate(t.due_date)];
            if (t.due_time) parts.push(t.due_time);
            return parts.join(' • ');
        },
        isOverdue(t) {
            return t.status !== 3 && t.due_date && t.due_date.slice(0, 10) < this.todayStr;
        },
        fmtSize(b) {
            if (!b) return '0 B';
            if (b < 1024) return b + ' B';
            if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
            return (b / 1048576).toFixed(1) + ' MB';
        },
        actLabel(a) { return ACT_LABELS[a.action] || a.action.replace(/_/g, ' '); },
        actIcon(a) { return ACT_ICONS[a.action] || 'history'; },
        prioColor(p) { return ['#98989D', '#0A84FF', '#FF9F0A', '#FF453A'][p] || '#0A84FF'; },
        pickRandomIcon() { return ICON_POOL[Math.floor(Math.random() * ICON_POOL.length)]; },
        openFeedback() { this.toast('Teşekkürler! 💙', 'info', 'favorite'); },

        /* ---------- Klasör / Etiket CRUD ---------- */
        currentFolderObj() { return this.folders.find((x) => x.id === this.currentFolder) || null; },
        currentTagObj() { return this.tags.find((x) => x.id === this.currentTag) || null; },

        openFolderContext(e, f) {
            this.contextTask = { kind: 'folder', id: f.id, name: f.name };
            const x = Math.min(e.clientX, window.innerWidth - 240);
            const y = Math.min(e.clientY, window.innerHeight - 200);
            this.contextPos = 'left:' + x + 'px;top:' + y + 'px;';
            this.contextOpen = true;
        },
        openTagContext(e, g) {
            this.contextTask = { kind: 'tag', id: g.id, name: g.name };
            const x = Math.min(e.clientX, window.innerWidth - 240);
            const y = Math.min(e.clientY, window.innerHeight - 200);
            this.contextPos = 'left:' + x + 'px;top:' + y + 'px;';
            this.contextOpen = true;
        },
        ctxEdit() {
            const c = this.contextTask;
            this.contextOpen = false;
            if (!c) return;
            if (c.kind === 'folder') this.folderModalOpen(this.folders.find((f) => f.id === c.id));
            if (c.kind === 'tag') this.tagModalOpen(this.tags.find((g) => g.id === c.id));
        },
        ctxAddTask() {
            const c = this.contextTask;
            this.contextOpen = false;
            if (!c) return;
            if (c.kind === 'folder') {
                this.goFolder(this.folders.find((f) => f.id === c.id));
                this.newQuickInFolder = c.id;
            }
            if (c.kind === 'tag') {
                this.goTag(this.tags.find((g) => g.id === c.id));
                this.newQuickTag = c.id;
            }
            this.$nextTick(() => { this.quickOpen = true; });
        },
        ctxMoveFolder() {
            this.contextOpen = false;
            this.askSelect('Klasöre Taşı', this.folders.map((f) => ({ id: f.id, label: f.name, icon: f.icon, color: f.color })))
                .then((fid) => fid !== null && this.moveTaskFolder(this.contextTask, fid));
        },
        ctxDelete() {
            const c = this.contextTask;
            this.contextOpen = false;
            if (!c) return;
            if (c.kind === 'folder') this.confirmDeleteFolderById(c.id, c.name);
            if (c.kind === 'tag') this.confirmDeleteTagById(c.id, c.name);
        },

        async deleteFolderById(id) {
            try {
                await api('api.php?action=folder_delete', { method: 'POST', body: JSON.stringify({ id }) });
                this.toast('Klasör silindi');
                await this.loadFolders();
                if (this.view === 'folders' && this.currentFolder === id) { this.currentFolder = null; this.view = 'all'; }
                this.reloadTasks();
            } catch (e) { this.toast(e.message, 'error'); }
        },
        async deleteTagById(id) {
            try {
                await api('api.php?action=tag_delete', { method: 'POST', body: JSON.stringify({ id }) });
                this.toast('Etiket silindi');
                await this.loadTags();
                if (this.view === 'tags' && this.currentTag === id) { this.currentTag = null; this.view = 'all'; }
                this.reloadTasks();
            } catch (e) { this.toast(e.message, 'error'); }
        },
        confirmDeleteFolderById(id, name) {
            const f = this.currentFolderObj();
            id = id || (f && f.id);
            name = name || (f && f.name) || 'bu klasör';
            this.confirm('Klasörü Sil', '"' + name + '" silinecek, içindeki görevler Inbox\'a taşınacak.', () => this.deleteFolderById(id));
        },
        confirmDeleteTagById(id, name) {
            const g = this.currentTagObj();
            id = id || (g && g.id);
            name = name || (g && g.name) || 'bu etiket';
            this.confirm('Etiketi Sil', '"' + name + '" silinecek.', () => this.deleteTagById(id));
        },

        confirmDemoSeed() {
            this.confirm('Demo Verisi Yükle', 'Örnek görevler eklenecek. (Mevcut görevler korunur.)', async () => {
                try {
                    const data = await api('api.php?action=demo_seed', { method: 'POST', body: JSON.stringify({ replace: 0 }) });
                    this.toast(data.tasks + ' demo görev yüklendi 🎉');
                    this.reloadTasks();
                    this.loadStats();
                    this.loadFolders();
                    this.loadTags();
                } catch (e) { this.toast(e.message, 'error'); }
            });
        },

        folderModalOpen(f) {
            this.folderModal = f
                ? { open: true, id: f.id, name: f.name, icon: f.icon || 'folder', color: f.color || '#0A84FF', description: f.description || '' }
                : { open: true, id: null, name: '', icon: 'folder', color: ACCENTS[Math.floor(Math.random() * ACCENTS.length)], description: '' };
        },
        async saveFolder() {
            if (!this.folderModal.name.trim()) { this.toast('Klasör adı gerekli', 'error'); return; }
            try {
                await api('api.php?action=folder_save', { method: 'POST', body: JSON.stringify(this.folderModal) });
                this.folderModal.open = false;
                this.toast('Klasör kaydedildi');
                this.loadFolders();
            } catch (e) { this.toast(e.message, 'error'); }
        },
        async deleteFolder() {
            try {
                await api('api.php?action=folder_delete', { method: 'POST', body: JSON.stringify({ id: this.folderModal.id }) });
                this.toast('Klasör silindi');
                this.loadFolders();
            } catch (e) { this.toast(e.message, 'error'); }
        },
        tagModalOpen(g) {
            this.tagModal = g
                ? { open: true, id: g.id, name: g.name, emoji: g.emoji || '', color: g.color || '#FF9F0A' }
                : { open: true, id: null, name: '', emoji: '🏷️', color: '#FF9F0A' };
        },
        async saveTag() {
            if (!this.tagModal.name.trim()) { this.toast('Etiket adı gerekli', 'error'); return; }
            try {
                await api('api.php?action=tag_save', { method: 'POST', body: JSON.stringify(this.tagModal) });
                this.tagModal.open = false;
                this.toast('Etiket kaydedildi');
                this.loadTags();
            } catch (e) { this.toast(e.message, 'error'); }
        },
        async deleteTag() {
            try {
                await api('api.php?action=tag_delete', { method: 'POST', body: JSON.stringify({ id: this.tagModal.id }) });
                this.toast('Etiket silindi');
                this.loadTags();
            } catch (e) { this.toast(e.message, 'error'); }
        },

        /* ---------- Flatpickr ---------- */
        initModalDatepickers() {
            this.destroyDatepickers();
            if (!window.flatpickr) return;
            const locale = { firstDayOfWeek: 1, weekdays: { shorthand: ['Paz', 'Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt'] }, months: { shorthand: ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'] } };
            document.querySelectorAll('.datepicker').forEach((el) => {
                this.flatpickrs.push(flatpickr(el, {
                    dateFormat: 'Y-m-d',
                    locale,
                    allowInput: true,
                    onClose: () => el.dispatchEvent(new Event('input')),
                }));
            });
            document.querySelectorAll('.timepicker').forEach((el) => {
                this.flatpickrs.push(flatpickr(el, {
                    enableTime: true, noCalendar: true, dateFormat: 'H:i', time_24hr: true,
                    allowInput: true,
                    onClose: () => el.dispatchEvent(new Event('input')),
                }));
            });
        },
        destroyDatepickers() {
            this.flatpickrs.forEach((f) => { try { f.destroy(); } catch (e) { /* */ } });
            this.flatpickrs = [];
        },

        /* ---------- Kısayollar ---------- */
        bindShortcuts() {
            document.addEventListener('keydown', (e) => {
                const tag = document.activeElement && document.activeElement.tagName;
                const typing = tag === 'INPUT' || tag === 'TEXTAREA';
                if (e.ctrlKey && e.key.toLowerCase() === 'n') {
                    e.preventDefault();
                    this.quickOpen = true;
                    this.quickParsed = null;
                    setTimeout(() => this.$refs.quickInput?.focus(), 50);
                    return;
                }
                if (e.ctrlKey && e.key.toLowerCase() === 'f') {
                    if (typing) return;
                    e.preventDefault();
                    this.$refs.searchInput?.focus();
                    return;
                }
                if (e.ctrlKey && e.key.toLowerCase() === 's') {
                    if (this.taskModal && this.modalTab === 'details') {
                        e.preventDefault();
                        this.saveTask();
                    }
                    return;
                }
                if (typing) return;
                if (e.key === 'Escape') {
                    if (this.taskModal) this.closeModal();
                    if (this.quickOpen) this.quickOpen = false;
                    if (this.shortcutHelp) this.shortcutHelp = false;
                    return;
                }
                if (e.key === 'Delete') {
                    if (this.selection.length) this.bulk('delete');
                    return;
                }
                if (e.key === ' ') {
                    if (this.selection.length) { e.preventDefault(); this.bulk('status', { status: 3 }); }
                    return;
                }
                const kmap = { t: 'today', w: 'week', a: 'all', k: 'kanban', c: 'calendar', r: 'reports', i: 'inbox' };
                if (kmap[e.key.toLowerCase()]) {
                    e.preventDefault();
                    this.goView(kmap[e.key.toLowerCase()]);
                }
            });
        },
    };
}

/* ------------------------------------------------------------------ *
 *  Tema Değişimini Dinle
 * ------------------------------------------------------------------ */

window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
    const root = document.querySelector('[x-data]');
    if (root && root._x_dataStack) {
        try {
            const data = root._x_dataStack[0];
            if (data.themeOverride === 'system') {
                document.documentElement.classList.toggle('dark', window.matchMedia('(prefers-color-scheme: dark)').matches);
                data.renderDashboardChart && data.renderDashboardChart();
            }
        } catch (e) { /* sessiz */ }
    }
});
