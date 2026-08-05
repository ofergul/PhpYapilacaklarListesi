# TECH_STACK.md — Teknoloji Yığını ve Gereksinimler

Bu proje **derlemesiz** (no build tool) çalışır. Tüm kütüphaneler CDN veya local
dosyalarla yüklenir.

---

## 1. DİL & RUNTIME

| Katman | Teknoloji | Min. Versiyon | Not |
|---|---|---|---|
| Backend | PHP | **8.2+** | `declare(strict_types=1)`, typed arrays, `str_contains` vb. kullanılır. 8.0 minimum çalışabilir ama önerilen 8.2+ |
| Veritabanı | SQLite | 3.31+ (PHP ile gelen pdo_sqlite) | WAL modu, foreign keys |
| Frontend | HTML5 + Vanilla JS | modern tarayıcı (Edge/Chrome/Safari/Firefox son 2 sürüm) | CSS `backdrop-filter`, `conic-gradient`, `color-mix()` |
| Web Server | Apache (XAMPP) veya PHP built-in | — | `php -S` ile de çalışır |

### Gerekli PHP eklentileri (XAMPP'de varsayılan)
- `pdo_sqlite` (zorunlu)
- `openssl` (session/güvenlik için önerilir)
- `zip` (önerilen — varsa yedekleme ZIP olur; yoksa `.sqlite` kopyası olur)
- `mbstring` (dosya adı UTF-8 kodlama)
- `fileinfo` (opsiyonel)

**Dağıtım zorunluluğu yoktur; Composer, npm, Node yok.**

---

## 2. FRONTEND KÜTÜPHANELERİ (CDN)

Yükleyen: `index.php` içindeki `<script>/<link>` etiketleri. Sürümler belirli çapa bağlıdır.

| Kütüphane | Versiyon | Amaç |
|---|---|---|
| TailwindCSS | CDN (latest play CDN) + `tailwind.config` inline | yardımcı sınıflar (`grid`, `flex`, `gap`, `mt-*`) |
| Alpine.js | `3.x.x` (cdn.min.js) | SPA reaktivite (`x-data`, `x-show`, `x-for`, `x-model`, `x-teleport`) |
| Chart.js | `4.4.1` (`chart.umd.min.js`) | dashboard + rapor grafikleri |
| Flatpickr | `4.6.13` + `l10n/tr.js` | tarih/saat girişleri (Türkçe locale) |
| SortableJS | `1.15.2` | kanban sürükle-bırak + liste sıralama |
| Google Material Symbols Rounded | fonts.googleapis + fonts.gstatic | ikon seti (ligatür) |
| Inter | Google Fonts | tipografi |

> **Not:** Alpine, Chart.js, Flatpickr, SortableJS'e ait JS dosyaları `assets/js/app.js`
> içinde `window.Sortable`, `window.flatpickr`, `window.Chart` global'lerine dayanır.
> Bu CDN'ler çalışmıyorsa ilgili özellik sessizce devre dışı kalır (ör. flatpickr,
> kanban sürükleme grafikler). Frontend uygulamanın kendisi o CDN gitmese de çalışır.

---

## 3. BACKEND YAPISI

- **PDO** SQLite bağlantısı `config.php` → `db()` tekil fonksiyonu.
- PDO seçenekleri: `ERRMODE_EXCEPTION`, `DEFAULT_FETCH_MODE=ASSOC`, `EMULATE_PREPARES=false`.
- `PRAGMA`: `journal_mode=WAL`, `foreign_keys=ON`, `busy_timeout=5000`, `synchronous=NORMAL`.
- Sorguların tamamı prepared statement; toplu işlemler `IN (...)` placeholder ile.

---

## 4. VERİTABANI ŞEMASI (SQLite DDL — config.php → DB_SCHEMA)

```sql
CREATE TABLE IF NOT EXISTS folders (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL,
    icon        TEXT DEFAULT 'folder',
    color       TEXT DEFAULT '#0A84FF',
    description TEXT DEFAULT '',
    sort_order  INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at  TEXT,
    deleted_at  TEXT
);

CREATE TABLE IF NOT EXISTS tags (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL UNIQUE,
    color      TEXT DEFAULT '#FF9F0A',
    emoji      TEXT DEFAULT '🏷️',
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    deleted_at TEXT
);

CREATE TABLE IF NOT EXISTS tasks (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    title           TEXT NOT NULL,
    description     TEXT DEFAULT '',
    notes           TEXT DEFAULT '',
    start_date      TEXT,
    due_date        TEXT,
    due_time        TEXT,
    priority        INTEGER NOT NULL DEFAULT 1,   -- 0..3
    status          INTEGER NOT NULL DEFAULT 0,   -- 0..4
    progress        INTEGER NOT NULL DEFAULT 0,   -- 0..100
    estimated_time  INTEGER DEFAULT NULL,
    actual_time     INTEGER DEFAULT 0,
    color           TEXT DEFAULT '#0A84FF',
    emoji           TEXT DEFAULT '',
    icon            TEXT DEFAULT 'check_circle',
    location        TEXT DEFAULT '',
    folder_id       INTEGER,
    is_favorite     INTEGER NOT NULL DEFAULT 0,
    is_pinned       INTEGER NOT NULL DEFAULT 0,
    sort_order      INTEGER NOT NULL DEFAULT 0,
    completed_at    TEXT,
    archived_at     TEXT,
    created_at      TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at      TEXT,
    deleted_at      TEXT,
    FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_tasks_status   ON tasks(status);
CREATE INDEX IF NOT EXISTS idx_tasks_due_date ON tasks(due_date);
CREATE INDEX IF NOT EXISTS idx_tasks_folder   ON tasks(folder_id);
CREATE INDEX IF NOT EXISTS idx_tasks_deleted  ON tasks(deleted_at);

CREATE TABLE IF NOT EXISTS subtasks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id    INTEGER NOT NULL,
    parent_id  INTEGER DEFAULT NULL,
    title      TEXT NOT NULL,
    completed  INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at TEXT,
    deleted_at TEXT,
    FOREIGN KEY (task_id)   REFERENCES tasks(id)   ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES subtasks(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_subtasks_task ON subtasks(task_id);

CREATE TABLE IF NOT EXISTS checklists (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id    INTEGER NOT NULL,
    title      TEXT NOT NULL,
    completed  INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at TEXT,
    deleted_at TEXT,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_checklists_task ON checklists(task_id);

CREATE TABLE IF NOT EXISTS task_tags (
    task_id INTEGER NOT NULL,
    tag_id  INTEGER NOT NULL,
    PRIMARY KEY (task_id, tag_id),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id)  REFERENCES tags(id)  ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_task_tags_tag ON task_tags(tag_id);

CREATE TABLE IF NOT EXISTS attachments (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id    INTEGER NOT NULL,
    filename   TEXT NOT NULL,
    filesize   INTEGER NOT NULL DEFAULT 0,
    mime_type  TEXT DEFAULT '',
    path       TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_attachments_task ON attachments(task_id);

CREATE TABLE IF NOT EXISTS reminders (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id     INTEGER NOT NULL,
    remind_at   TEXT NOT NULL,                    -- 'Y-m-d H:i:s'
    remind_type TEXT NOT NULL DEFAULT 'notification',
    sound       INTEGER NOT NULL DEFAULT 0,
    triggered   INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_reminders_time ON reminders(remind_at);

CREATE TABLE IF NOT EXISTS recurrence_rules (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id      INTEGER NOT NULL UNIQUE,
    freq         TEXT NOT NULL DEFAULT 'daily',
    interval     INTEGER NOT NULL DEFAULT 1,
    by_day       TEXT DEFAULT '',
    by_month_day TEXT DEFAULT '',
    by_month     TEXT DEFAULT '',
    custom_cron  TEXT DEFAULT '',
    ends_on      TEXT DEFAULT NULL,
    created_at   TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS settings (
    key        TEXT PRIMARY KEY,
    value      TEXT DEFAULT '',
    updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

CREATE TABLE IF NOT EXISTS activity_logs (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id    INTEGER DEFAULT NULL,
    action     TEXT NOT NULL,
    detail     TEXT DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE INDEX IF NOT EXISTS idx_activity_task ON activity_logs(task_id);

CREATE TABLE IF NOT EXISTS backups (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    filename   TEXT NOT NULL,
    filesize   INTEGER NOT NULL DEFAULT 0,
    notes      TEXT DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
```

**settings anahtarları:** `theme` (system/light/dark), `accent` (#hex), `sound` (1/0),
`notifications` (1/0), `default_folder`, `pomodoro_focus` (dk), `pomodoro_break` (dk),
`schema_version`, `installed_at`, `last_auto_backup`.

---

## 5. ENE: ENV / BUILD AYARLARI

- **Build yok.** Hiçbir derleme adımı, konfig dosyası (webpack/vite) yok.
- **CDN dışındaki local dosyalar:**
  - `assets/css/app.css` (elle yazılmış tüm tasarım)
  - `assets/js/app.js` (elle yazılmış tüm uygulama)
  - `assets/icons/icon.svg`
- **Sunucu kökleri:** `uploads/`, `backup/` çalışma zamanında oluşturulur (`@mkdir`).
- **Disc'daki dosyalar yazılmaya hazır**: PHP 8.2.12 (XAMPP), pdo_sqlite+zip+mbstring açık.

### Kurulum (2 yol)
```bash
# 1) XAMPP Apache: klasörü htdocs altına koy, http://localhost/gorev2/
# 2) PHP built-in:
cd C:\xampp\htdocs\gorev2
php -S localhost:8000
# http://localhost:8000
```

### Test araçları
- `php -l <file>` — PHP sözdizimi.
- `node --check assets/js/app.js` — JS sözdizimi (node mevcut ise).
- Tarayıcı ile `http://localhost:<port>/` manuel doğrulama; DevTools konsol boş olmalı.
- API testleri: `php -S` başlat → `Invoke-WebRequest`/`curl` ile `api.php?action=...` (CSRF header gerekli).