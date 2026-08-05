<?php
/**
 * Görev Yöneticisi — Merkezi Yapılandırma
 *
 * Veritabanı bağlantısı, şema kurulumu, yardımcı fonksiyonlar
 * ve otomatik günlük yedekleme bu dosyada toplanır.
 */

declare(strict_types=1);

/* ------------------------------------------------------------------ *
 *  Ortam Sabitleri
 * ------------------------------------------------------------------ */

define('BASE_PATH', __DIR__);
define('DB_FILE', BASE_PATH . DIRECTORY_SEPARATOR . 'database.sqlite');
define('UPLOAD_DIR', BASE_PATH . DIRECTORY_SEPARATOR . 'uploads');
define('BACKUP_DIR', BASE_PATH . DIRECTORY_SEPARATOR . 'backup');
define('APP_NAME', 'Görev');

/* ------------------------------------------------------------------ *
 *  Veritabanı Şeması (İlk açılışta otomatik oluşturulur)
 * ------------------------------------------------------------------ */

const DB_SCHEMA = <<<'SQL'
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
    priority        INTEGER NOT NULL DEFAULT 1,
    status          INTEGER NOT NULL DEFAULT 0,
    progress        INTEGER NOT NULL DEFAULT 0,
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
    remind_at   TEXT NOT NULL,
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
SQL;

/* ------------------------------------------------------------------ *
 *  PDO Bağlantısı
 * ------------------------------------------------------------------ */

/**
 * Tekil PDO örneği döndürür; bağlantı ömrü boyunca aynı kalır.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!file_exists(DB_FILE)) {
        if (!is_dir(BASE_PATH) || !is_writable(BASE_PATH)) {
            throw new RuntimeException('Veritabanı dizini yazılabilir değil.');
        }
        touch(DB_FILE);
    }

    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec('PRAGMA busy_timeout = 5000;');
    $pdo->exec('PRAGMA synchronous = NORMAL;');
    return $pdo;
}

/* ------------------------------------------------------------------ *
 *  Şema Kurulumu + İlk Veri
 * ------------------------------------------------------------------ */

/**
 * Şemayı kurar; ilk açılışta varsayılan klasör ve etiketleri ekler.
 */
function init_schema(): void
{
    $pdo = db();
    $pdo->exec(DB_SCHEMA);

    $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM settings WHERE key = :k');
    $stmt->execute([':k' => 'schema_version']);

    if ((int)$stmt->fetch()['c'] === 0) {
        $pdo->beginTransaction();
        try {
            $defaultFolders = [
                ['İş',       'work',        '#0A84FF', 'İş ve kariyer görevleri'],
                ['Kişisel',  'person',      '#30D158', 'Kişisel yaşam görevleri'],
                ['Ev',       'home',        '#FF9F0A', 'Ev işleri ve düzen'],
                ['Yazılım',  'code',        '#5E5CE6', 'Yazılım ve geliştirme'],
                ['Okuma',    'menu_book',   '#FF6482', 'Okuma listesi ve notlar'],
                ['Projeler', 'rocket_launch','#64D2FF', 'Büyük çaplı projeler'],
                ['Finans',   'payments',    '#FFD60A', 'Finans ve ödemeler'],
                ['Sigorta',  'shield',      '#A2845E', 'Sigorta işlemleri'],
                ['İnşaat',   'construction','#BF5AF2', 'İnşaat ve tadilat'],
            ];
            $insFolder = $pdo->prepare('INSERT INTO folders (name, icon, color, description, sort_order) VALUES (:n,:i,:c,:d,:s)');
            foreach ($defaultFolders as $i => $f) {
                $insFolder->execute([':n' => $f[0], ':i' => $f[1], ':c' => $f[2], ':d' => $f[3], ':s' => $i]);
            }

            $defaultTags = [
                ['acil',     '#FF453A', '🚨'],
                ['telefon',  '#0A84FF', '📞'],
                ['mail',     '#5E5CE6', '✉️'],
                ['toplantı', '#FF9F0A', '📅'],
                ['bekliyor', '#98989D', '⏳'],
                ['müşteri',  '#30D158', '🤝'],
                ['fatura',   '#FF6482', '🧾'],
                ['ödeme',    '#FFD60A', '💳'],
            ];
            $insTag = $pdo->prepare('INSERT INTO tags (name, color, emoji) VALUES (:n,:c,:e)');
            foreach ($defaultTags as $t) {
                $insTag->execute([':n' => $t[0], ':c' => $t[1], ':e' => $t[2]]);
            }

            $pdo->exec("INSERT INTO settings (key, value) VALUES ('schema_version','1')");
            $pdo->exec("INSERT INTO settings (key, value) VALUES ('installed_at', datetime('now','localtime'))");
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

/* ------------------------------------------------------------------ *
 *  Yardımcı Fonksiyonlar
 * ------------------------------------------------------------------ */

/**
 * HTML çıktıya değer güvenle kaçırır (XSS koruması).
 */
function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * JSON yanıtı üretip çıktılar ve komut dosyasını sonlandırır.
 */
function json_out(mixed $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * İstekteki string değeri alır; yoksa null döner.
 */
function input(string $key, mixed $default = null): mixed
{
    $body = $_POST + ($_GET ?? []);
    return array_key_exists($key, $body) ? $body[$key] : $default;
}

/**
 * JSON istek gövdesini (Content-Type: application/json) okur.
 */
function json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * CSRF token üretir / döndürür (oturum tabanlı).
 */
function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * CSRF token'ı doğrular; geçersizse isteği reddeder.
 */
function csrf_verify(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? input('_csrf', '');
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        json_out(['ok' => false, 'error' => 'Güvenlik doğrulaması başarısız (CSRF).'], 403);
    }
}

/**
 * Ayar değerini okur.
 */
function setting_get(string $key, string $default = ''): string
{
    $stmt = db()->prepare('SELECT value FROM settings WHERE key = :k');
    $stmt->execute([':k' => $key]);
    $row = $stmt->fetch();
    return $row ? (string)$row['value'] : $default;
}

/**
 * Ayar değerini yazar.
 */
function setting_set(string $key, string $value): void
{
    $stmt = db()->prepare(
        "INSERT INTO settings (key, value, updated_at) VALUES (:k,:v, datetime('now','localtime'))
         ON CONFLICT(key) DO UPDATE SET value = :v2, updated_at = datetime('now','localtime')"
    );
    $stmt->execute([':k' => $key, ':v' => $value, ':v2' => $value]);
}

/**
 * Etkinlik günlüğüne kayıt ekler.
 */
function activity_log(?int $taskId, string $action, string $detail = ''): void
{
    $stmt = db()->prepare('INSERT INTO activity_logs (task_id, action, detail) VALUES (:t,:a,:d)');
    $stmt->execute([':t' => $taskId, ':a' => $action, ':d' => $detail]);
}

/**
 * Sayfa yüklemeleri sırasında otomatik günlük yedek alır (günde bir kez).
 */
function auto_backup(): void
{
    $last = setting_get('last_auto_backup', '');
    if ($last !== '' && (strtotime('now') - strtotime($last)) < 86400) {
        return;
    }
    if (!is_dir(BACKUP_DIR)) {
        @mkdir(BACKUP_DIR, 0777, true);
    }
    $name = 'auto-' . date('Y-m-d') . '.sqlite';
    if (copy(DB_FILE, BACKUP_DIR . DIRECTORY_SEPARATOR . $name)) {
        $size = filesize(BACKUP_DIR . DIRECTORY_SEPARATOR . $name) ?: 0;
        $stmt = db()->prepare("INSERT INTO backups (filename, filesize, notes) VALUES (:f,:s,'Otomatik günlük yedek')");
        $stmt->execute([':f' => $name, ':s' => $size]);
        setting_set('last_auto_backup', date('Y-m-d H:i:s'));
    }
}

/**
 * Görev silindiğinde ilişkili durum güncellemeleri için yardımcı.
 * (Kullanılmayan eski tasarım; uyumluluk için bırakıldı.)
 */
function task_link_sanity(): void
{
    $pdo = db();
    $pdo->exec("DELETE FROM task_tags WHERE task_id NOT IN (SELECT id FROM tasks)");
    $pdo->exec("DELETE FROM subtasks WHERE task_id NOT IN (SELECT id FROM tasks)");
    $pdo->exec("DELETE FROM checklists WHERE task_id NOT IN (SELECT id FROM tasks)");
    $pdo->exec("DELETE FROM reminders WHERE task_id NOT IN (SELECT id FROM tasks)");
    $pdo->exec("DELETE FROM recurrence_rules WHERE task_id NOT IN (SELECT id FROM tasks)");
    $pdo->exec("DELETE FROM attachments WHERE task_id NOT IN (SELECT id FROM tasks)");
}

/* ------------------------------------------------------------------ *
 *  Başlatma
 * ------------------------------------------------------------------ */

init_schema();
