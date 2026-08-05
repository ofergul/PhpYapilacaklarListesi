<?php
/**
 * Görev Yöneticisi — JSON API
 *
 * Tüm istemci işlemleri bu uç noktadan yapılır.
 * Tüm sorgular PDO prepared statement ile çalışır.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/* ------------------------------------------------------------------ *
 *  İstek Ön İşleme
 * ------------------------------------------------------------------ */

$body = json_body();
$_POST = array_merge($_POST, $body);

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
$isWrite = !in_array($action, ['tasks', 'task_get', 'folders', 'tags', 'stats', 'reports', 'search', 'check_reminders', 'activity', 'backup_list', 'recurrence_preview', 'attachment_download', 'export', 'settings'], true);

if ($isWrite) {
    csrf_verify();
}

if ($action === '') {
    json_out(['ok' => false, 'error' => 'Aksiyon belirtilmedi.']);
}

/* ------------------------------------------------------------------ *
 *  Sabitler / Yardımcılar
 * ------------------------------------------------------------------ */

const PRIORITY_LABELS = ['Düşük', 'Normal', 'Yüksek', 'Kritik'];
const STATUS_LABELS = ['Bekliyor', 'Devam Ediyor', 'Askıda', 'Tamamlandı', 'İptal'];

/**
 * Görev kaydını ilişkileriyle birlikte zenginleştirilmiş olarak döndürür.
 */
function enrich_task(array $t, bool $withDetails = false): array
{
    $pdo = db();
    $id = (int)$t['id'];

    $t['priority_label'] = PRIORITY_LABELS[(int)$t['priority']] ?? 'Normal';
    $t['status_label']   = STATUS_LABELS[(int)$t['status']] ?? 'Bekliyor';
    $t['is_completed']   = (int)$t['status'] === 3;
    $t['is_trashed']     = $t['deleted_at'] !== null;
    $t['is_archived']    = $t['archived_at'] !== null;

    if (!empty($t['folder_id'])) {
        $stmt = $pdo->prepare('SELECT name, icon, color FROM folders WHERE id = :i');
        $stmt->execute([':i' => (int)$t['folder_id']]);
        $folder = $stmt->fetch();
        $t['folder_name'] = $folder['name'] ?? '';
        $t['folder_icon'] = $folder['icon'] ?? 'folder';
        $t['folder_color'] = $folder['color'] ?? '#0A84FF';
    } else {
        $t['folder_name'] = '';
        $t['folder_icon'] = '';
        $t['folder_color'] = '';
    }

    $stmt = $pdo->prepare(
        'SELECT tg.* FROM tags tg JOIN task_tags tt ON tt.tag_id = tg.id WHERE tt.task_id = :i ORDER BY tg.name'
    );
    $stmt->execute([':i' => $id]);
    $t['tags'] = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT * FROM subtasks WHERE task_id = :i AND deleted_at IS NULL ORDER BY sort_order, id');
    $stmt->execute([':i' => $id]);
    $t['subtasks'] = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT * FROM checklists WHERE task_id = :i AND deleted_at IS NULL ORDER BY sort_order, id');
    $stmt->execute([':i' => $id]);
    $t['checklists'] = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT * FROM attachments WHERE task_id = :i ORDER BY id');
    $stmt->execute([':i' => $id]);
    $t['attachments'] = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT * FROM reminders WHERE task_id = :i ORDER BY remind_at');
    $stmt->execute([':i' => $id]);
    $t['reminders'] = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT * FROM recurrence_rules WHERE task_id = :i');
    $stmt->execute([':i' => $id]);
    $t['recurrence'] = $stmt->fetch() ?: null;

    if (!$withDetails) {
        $t['has_details'] = (
            $t['description'] !== '' || $t['notes'] !== '' || $t['subtasks'] || $t['checklists']
            || $t['attachments'] || $t['reminders'] || $t['recurrence']
        );
        unset($t['description'], $t['notes'], $t['subtasks'], $t['checklists'], $t['attachments'], $t['reminders']);
    }

    return $t;
}

/**
 * Görev listesi sorgusunu görünüme göre kurar (filtre + sayfalama).
 */
function task_query(array $f): array
{
    $pdo  = db();
    $view = (string)($f['view'] ?? 'all');
    $sql  = [];
    $args = [];
    $where = ["t.deleted_at IS NULL"];

    switch ($view) {
        case 'inbox':
            $where[] = 't.folder_id IS NULL AND t.status <> 3';
            break;
        case 'today':
            $where[] = "date(t.due_date) = date('now','localtime') AND t.status <> 3";
            break;
        case 'tomorrow':
            $where[] = "date(t.due_date) = date('now','localtime','+1 day') AND t.status <> 3";
            break;
        case 'planned':
            $where[] = 't.due_date IS NOT NULL AND t.status <> 3';
            break;
        case 'week':
            $where[] = "t.status <> 3 AND date(t.due_date) >= date('now','localtime') AND date(t.due_date) <= date('now','localtime','+6 day')";
            break;
        case 'month':
            $where[] = "t.status <> 3 AND (date(t.due_date) BETWEEN date('now','start of month') AND date('now','start of month','+1 month','-1 day'))";
            break;
        case 'overdue':
            $where[] = "t.status <> 3 AND t.due_date IS NOT NULL AND date(t.due_date) < date('now','localtime')";
            break;
        case 'completed':
            $where[] = 't.status = 3';
            break;
        case 'archive':
            $where[] = 't.archived_at IS NOT NULL';
            break;
        case 'trash':
            return ['sql' => 'SELECT t.* FROM tasks t WHERE t.deleted_at IS NOT NULL', 'args' => []];
        case 'all':
        default:
            break;
    }

    if (isset($f['folder_id']) && $f['folder_id'] !== '' && $f['folder_id'] !== null) {
        if ((string)$f['folder_id'] === 'none') {
            $where[] = 't.folder_id IS NULL';
        } else {
            $where[] = 't.folder_id = :folder_id';
            $args[':folder_id'] = (int)$f['folder_id'];
        }
    }
    if (isset($f['tag_id']) && $f['tag_id'] !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM task_tags x WHERE x.task_id = t.id AND x.tag_id = :tag_id)';
        $args[':tag_id'] = (int)$f['tag_id'];
    }
    if (isset($f['priority']) && $f['priority'] !== '') {
        $where[] = 't.priority = :priority';
        $args[':priority'] = (int)$f['priority'];
    }
    if (isset($f['status']) && $f['status'] !== '') {
        $where[] = 't.status = :status';
        $args[':status'] = (int)$f['status'];
    }
    if (!empty($f['recurring'])) {
        $where[] = 'EXISTS (SELECT 1 FROM recurrence_rules r WHERE r.task_id = t.id)';
    }
    if (isset($f['date_from']) && $f['date_from'] !== '') {
        $where[] = 'date(t.due_date) >= :df';
        $args[':df'] = $f['date_from'];
    }
    if (isset($f['date_to']) && $f['date_to'] !== '') {
        $where[] = 'date(t.due_date) <= :dt';
        $args[':dt'] = $f['date_to'];
    }
    if (isset($f['search']) && $f['search'] !== '') {
        $like = '%' . $f['search'] . '%';
        $where[] = '(t.title LIKE :s1 OR t.description LIKE :s2 OR t.notes LIKE :s3 OR t.location LIKE :s4
            OR EXISTS (SELECT 1 FROM tags g JOIN task_tags tg2 ON tg2.tag_id = g.id WHERE tg2.task_id = t.id AND g.name LIKE :s5)
            OR EXISTS (SELECT 1 FROM attachments a WHERE a.task_id = t.id AND a.filename LIKE :s6))';
        $args[':s1'] = $like; $args[':s2'] = $like; $args[':s3'] = $like;
        $args[':s4'] = $like; $args[':s5'] = $like; $args[':s6'] = $like;
    }

    $order = 't.sort_order ASC, t.due_date ASC, t.id DESC';
    switch ($f['sort'] ?? 'default') {
        case 'title':     $order = 't.title COLLATE NOCASE ASC, t.id'; break;
        case 'created':   $order = 't.created_at DESC, t.id'; break;
        case 'due':       $order = 't.due_date ASC, t.id'; break;
        case 'priority':  $order = 't.priority DESC, t.id'; break;
    }

    $sqlStr = 'SELECT t.* FROM tasks t WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $order;
    return ['sql' => $sqlStr, 'args' => $args];
}

/* ------------------------------------------------------------------ *
 *  Yönlendirici
 * ------------------------------------------------------------------ */

try {
    switch ($action) {

        /* ================= GÖREV LİSTELEME ================= */

        case 'tasks':
            $f = $_GET;
            $page = max(1, (int)($f['page'] ?? 1));
            $limit = min(500, max(10, (int)($f['limit'] ?? 100)));
            $q = task_query($f);

            $countStmt = db()->prepare(str_replace('SELECT t.* FROM', 'SELECT COUNT(*) AS c FROM', $q['sql']));
            $countStmt->execute($q['args']);
            $total = (int)$countStmt->fetch()['c'];

            $stmt = db()->prepare($q['sql'] . ' LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit));
            $stmt->execute($q['args']);
            $rows = array_map(fn($r) => enrich_task($r, false), $stmt->fetchAll());

            json_out(['ok' => true, 'tasks' => $rows, 'total' => $total, 'page' => $page, 'limit' => $limit]);

        case 'task_get':
            $stmt = db()->prepare('SELECT * FROM tasks WHERE id = :i');
            $stmt->execute([':i' => (int)($_GET['id'] ?? 0)]);
            $task = $stmt->fetch();
            if (!$task) {
                json_out(['ok' => false, 'error' => 'Görev bulunamadı.']);
            }
            json_out(['ok' => true, 'task' => enrich_task($task, true)]);

        case 'search':
            $q = trim((string)($_GET['q'] ?? ''));
            if ($q === '') {
                json_out(['ok' => true, 'tasks' => []]);
            }
            $f = ['view' => 'all', 'search' => $q, 'sort' => 'due', 'limit' => 30, 'page' => 1];
            $res = task_query($f);
            $stmt = db()->prepare($res['sql'] . ' LIMIT 30');
            $stmt->execute($res['args']);
            json_out(['ok' => true, 'tasks' => array_map(fn($r) => enrich_task($r, false), $stmt->fetchAll())]);

        /* ================= GÖREV KAYDET ================= */

        case 'task_save':
            $pdo = db();
            $id = (int)($_POST['id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            if ($title === '') {
                json_out(['ok' => false, 'error' => 'Görev başlığı boş olamaz.']);
            }

            $fields = [
                'title', 'description', 'notes', 'start_date', 'due_date', 'due_time',
                'color', 'emoji', 'icon', 'location',
            ];
            $data = [];
            foreach ($fields as $k) {
                $v = $_POST[$k] ?? '';
                $data[$k] = is_array($v) ? '' : trim((string)$v);
            }
            $data['priority'] = max(0, min(3, (int)($_POST['priority'] ?? 1)));
            $data['status']   = max(0, min(4, (int)($_POST['status'] ?? 0)));
            $data['progress'] = max(0, min(100, (int)($_POST['progress'] ?? 0)));
            $data['estimated_time'] = ((string)($_POST['estimated_time'] ?? '') !== '') ? max(0, (int)$_POST['estimated_time']) : null;
            $data['actual_time'] = max(0, (int)($_POST['actual_time'] ?? 0));
            $data['is_favorite'] = (int)($_POST['is_favorite'] ?? 0);
            $data['is_pinned']   = (int)($_POST['is_pinned'] ?? 0);
            $data['folder_id'] = (($_POST['folder_id'] ?? '') !== '') ? ((int)$_POST['folder_id'] ?: null) : null;

            $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($data)));
            $params = [];
            foreach ($data as $k => $v) {
                $params[':' . $k] = $v;
            }
            $params[':updated'] = date('Y-m-d H:i:s');

            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE tasks SET ' . $set . ", updated_at = :updated WHERE id = :id");
                $params[':id'] = $id;
                $stmt->execute($params);
            } else {
                $cols = implode(', ', array_keys($data));
                $vals = implode(', ', array_map(fn($k) => ':' . $k, array_keys($data)));
                $stmt = $pdo->prepare(
                    "INSERT INTO tasks ($cols, created_at, updated_at) VALUES ($vals, :updated, :updated)"
                );
                $stmt->execute($params);
                $id = (int)$pdo->lastInsertId();
            }

            // Etiketler
            if (isset($_POST['tags']) && is_array($_POST['tags'])) {
                $pdo->prepare('DELETE FROM task_tags WHERE task_id = :i')->execute([':i' => $id]);
                $ins = $pdo->prepare('INSERT OR IGNORE INTO task_tags (task_id, tag_id) VALUES (:t,:g)');
                foreach ($_POST['tags'] as $tagId) {
                    $ins->execute([':t' => $id, ':g' => (int)$tagId]);
                }
            }

            // Tekrarlama kuralı
            $recur = $_POST['recurrence'] ?? null;
            $pdo->prepare('DELETE FROM recurrence_rules WHERE task_id = :i')->execute([':i' => $id]);
            if (is_array($recur) && !empty($recur['freq'])) {
                $stmt = $pdo->prepare(
                    'INSERT INTO recurrence_rules (task_id, freq, `interval`, by_day, by_month_day, by_month, custom_cron, ends_on)
                     VALUES (:t,:f,:i,:d,:md,:m,:c,:e)'
                );
                $stmt->execute([
                    ':t' => $id, ':f' => (string)$recur['freq'],
                    ':i' => max(1, (int)($recur['interval'] ?? 1)),
                    ':d' => (string)($recur['by_day'] ?? ''),
                    ':md' => (string)($recur['by_month_day'] ?? ''),
                    ':m' => (string)($recur['by_month'] ?? ''),
                    ':c' => (string)($recur['custom_cron'] ?? ''),
                    ':e' => ((string)($recur['ends_on'] ?? '') !== '') ? (string)$recur['ends_on'] : null,
                ]);
            }

            // Hatırlatıcılar
            if (isset($_POST['reminders']) && is_array($_POST['reminders'])) {
                $pdo->prepare('DELETE FROM reminders WHERE task_id = :i')->execute([':i' => $id]);
                $ins = $pdo->prepare('INSERT INTO reminders (task_id, remind_at, remind_type, sound) VALUES (:t,:r,:ty,:s)');
                foreach ($_POST['reminders'] as $r) {
                    if (empty($r['remind_at'])) {
                        continue;
                    }
                    $ins->execute([
                        ':t' => $id, ':r' => (string)$r['remind_at'],
                        ':ty' => (string)($r['remind_type'] ?? 'notification'),
                        ':s' => (int)($r['sound'] ?? 0),
                    ]);
                }
            }

            if ((int)$data['status'] === 3 && empty($data['completed_at'])) {
                $pdo->prepare('UPDATE tasks SET completed_at = :c, updated_at = :u WHERE id = :i')
                    ->execute([':c' => date('Y-m-d H:i:s'), ':u' => date('Y-m-d H:i:s'), ':i' => $id]);
                handle_recurrence($id, $data);
            }
            if ((int)$data['status'] !== 3) {
                $pdo->prepare('UPDATE tasks SET completed_at = NULL WHERE id = :i')->execute([':i' => $id]);
            }

            activity_log($id, $id > 0 ? 'task_updated' : 'task_created', $title);
            $stmt = $pdo->prepare('SELECT * FROM tasks WHERE id = :i');
            $stmt->execute([':i' => $id]);
            json_out(['ok' => true, 'task' => enrich_task($stmt->fetch(), true)]);

        case 'task_toggle':
            $pdo = db();
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare('SELECT * FROM tasks WHERE id = :i AND deleted_at IS NULL');
            $stmt->execute([':i' => $id]);
            $task = $stmt->fetch();
            if (!$task) {
                json_out(['ok' => false, 'error' => 'Görev bulunamadı.']);
            }
            $now = date('Y-m-d H:i:s');
            if ((int)$task['status'] === 3) {
                $pdo->prepare('UPDATE tasks SET status = 0, progress = progress, completed_at = NULL, updated_at = :u WHERE id = :i')
                    ->execute([':u' => $now, ':i' => $id]);
                activity_log($id, 'task_uncompleted', $task['title']);
            } else {
                $pdo->prepare('UPDATE tasks SET status = 3, progress = 100, completed_at = :c, updated_at = :u WHERE id = :i')
                    ->execute([':c' => $now, ':u' => $now, ':i' => $id]);
                activity_log($id, 'task_completed', $task['title']);
                handle_recurrence($id, $task);
            }
            $stmt = $pdo->prepare('SELECT * FROM tasks WHERE id = :i');
            $stmt->execute([':i' => $id]);
            json_out(['ok' => true, 'task' => enrich_task($stmt->fetch(), true)]);

        case 'task_quick':
            $title = trim((string)($_POST['title'] ?? ''));
            if ($title === '') {
                json_out(['ok' => false, 'error' => 'Başlık boş olamaz.']);
            }
            $pdo = db();
            $stmt = $pdo->prepare(
                'INSERT INTO tasks (title, due_date, due_time, priority, folder_id, status, created_at, updated_at)
                 VALUES (:t,:d,:ti,:p,:f,0, :c, :c)'
            );
            $stmt->execute([
                ':t' => $title,
                ':d' => ((string)($_POST['due_date'] ?? '') !== '') ? (string)$_POST['due_date'] : null,
                ':ti' => ((string)($_POST['due_time'] ?? '') !== '') ? (string)$_POST['due_time'] : null,
                ':p' => max(0, min(3, (int)($_POST['priority'] ?? 1))),
                ':f' => (($_POST['folder_id'] ?? '') !== '') ? ((int)$_POST['folder_id'] ?: null) : null,
                ':c' => date('Y-m-d H:i:s'),
            ]);
            $id = (int)$pdo->lastInsertId();
            activity_log($id, 'task_created', $title);
            json_out(['ok' => true, 'id' => $id]);

        case 'task_delete':
            $id = (int)($_POST['id'] ?? 0);
            db()->prepare('UPDATE tasks SET deleted_at = :d, updated_at = :u WHERE id = :i')
                ->execute([':d' => date('Y-m-d H:i:s'), ':u' => date('Y-m-d H:i:s'), ':i' => $id]);
            activity_log($id, 'task_trashed');
            json_out(['ok' => true]);

        case 'task_restore':
            $id = (int)($_POST['id'] ?? 0);
            db()->prepare('UPDATE tasks SET deleted_at = NULL, updated_at = :u WHERE id = :i')
                ->execute([':u' => date('Y-m-d H:i:s'), ':i' => $id]);
            json_out(['ok' => true]);

        case 'task_destroy':
            $id = (int)($_POST['id'] ?? 0);
            $stmt = db()->prepare('SELECT path FROM attachments WHERE task_id = :i');
            $stmt->execute([':i' => $id]);
            foreach ($stmt->fetchAll() as $a) {
                $p = UPLOAD_DIR . DIRECTORY_SEPARATOR . basename($a['path']);
                if (is_file($p)) {
                    @unlink($p);
                }
            }
            db()->prepare('DELETE FROM tasks WHERE id = :i')->execute([':i' => $id]);
            activity_log(null, 'task_destroyed');
            json_out(['ok' => true]);

        case 'task_archive':
            db()->prepare('UPDATE tasks SET archived_at = :d, updated_at = :u WHERE id = :i')
                ->execute([':d' => date('Y-m-d H:i:s'), ':u' => date('Y-m-d H:i:s'), ':i' => (int)($_POST['id'] ?? 0)]);
            json_out(['ok' => true]);

        case 'task_unarchive':
            db()->prepare('UPDATE tasks SET archived_at = NULL, updated_at = :u WHERE id = :i')
                ->execute([':u' => date('Y-m-d H:i:s'), ':i' => (int)($_POST['id'] ?? 0)]);
            json_out(['ok' => true]);

        case 'task_flag':
            $col = (string)($_POST['col'] ?? 'is_favorite');
            if (!in_array($col, ['is_favorite', 'is_pinned'], true)) {
                json_out(['ok' => false, 'error' => 'Geçersiz bayrak.']);
            }
            db()->prepare("UPDATE tasks SET $col = :v, updated_at = :u WHERE id = :i")
                ->execute([':v' => (int)($_POST['value'] ?? 0), ':u' => date('Y-m-d H:i:s'), ':i' => (int)($_POST['id'] ?? 0)]);
            json_out(['ok' => true]);

        case 'task_move_folder':
            $fid = (($_POST['folder_id'] ?? '') !== '') ? ((int)$_POST['folder_id'] ?: null) : null;
            db()->prepare('UPDATE tasks SET folder_id = :f, updated_at = :u WHERE id = :i')
                ->execute([':f' => $fid, ':u' => date('Y-m-d H:i:s'), ':i' => (int)($_POST['id'] ?? 0)]);
            json_out(['ok' => true]);

        case 'task_reorder':
            $order = $_POST['order'] ?? [];
            if (is_array($order)) {
                $stmt = db()->prepare('UPDATE tasks SET sort_order = :s WHERE id = :i');
                foreach ($order as $i => $tid) {
                    $stmt->execute([':s' => (int)$i, ':i' => (int)$tid]);
                }
            }
            json_out(['ok' => true]);

        /* ================= TEKRARLAMA ================= */

        case 'recurrence_preview':
            $rule = $_POST['rule'] ?? [];
            $from = (string)($_POST['from'] ?? date('Y-m-d'));
            $freq = (string)($rule['freq'] ?? 'daily');
            $days = array_map('strtoupper', explode(',', (string)($rule['by_day'] ?? '')));
            $mDays = explode(',', (string)($rule['by_month_day'] ?? ''));
            $months = array_map('intval', explode(',', (string)($rule['by_month'] ?? '')));
            $interval = max(1, (int)($rule['interval'] ?? 1));
            $out = [];
            $d = new DateTime($from);
            for ($i = 0; $i < 8 && count($out) < 5; $i++) {
                $d = next_occurrence($d, $freq, $interval, $days, $mDays, $months);
                if (!$d) {
                    break;
                }
                $out[] = $d->format('Y-m-d');
            }
            json_out(['ok' => true, 'dates' => $out]);

        /* ================= KLASÖRLER ================= */

        case 'folders':
            $stmt = db()->query(
                'SELECT f.*, (SELECT COUNT(*) FROM tasks t WHERE t.folder_id = f.id AND t.deleted_at IS NULL AND t.status <> 3) AS task_count
                 FROM folders f WHERE f.deleted_at IS NULL ORDER BY f.sort_order, f.name'
            );
            json_out(['ok' => true, 'folders' => $stmt->fetchAll()]);

        case 'folder_save':
            $pdo = db();
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                json_out(['ok' => false, 'error' => 'Klasör adı boş olamaz.']);
            }
            $icon = trim((string)($_POST['icon'] ?? 'folder'));
            $color = trim((string)($_POST['color'] ?? '#0A84FF'));
            $desc = trim((string)($_POST['description'] ?? ''));
            if ($id > 0) {
                db()->prepare('UPDATE folders SET name=:n, icon=:i, color=:c, description=:d, updated_at=:u WHERE id=:id')
                    ->execute([':n' => $name, ':i' => $icon, ':c' => $color, ':d' => $desc, ':u' => date('Y-m-d H:i:s'), ':id' => $id]);
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO folders (name, icon, color, description, sort_order) VALUES (:n,:i,:c,:d, (SELECT COALESCE(MAX(sort_order),0)+1 FROM folders))'
                );
                $stmt->execute([':n' => $name, ':i' => $icon, ':c' => $color, ':d' => $desc]);
                $id = (int)$pdo->lastInsertId();
            }
            activity_log(null, 'folder_saved', $name);
            json_out(['ok' => true, 'id' => $id]);

        case 'folder_delete':
            $id = (int)($_POST['id'] ?? 0);
            db()->prepare('UPDATE folders SET deleted_at = :d WHERE id = :i')->execute([':d' => date('Y-m-d H:i:s'), ':i' => $id]);
            db()->prepare('UPDATE tasks SET folder_id = NULL WHERE folder_id = :i')->execute([':i' => $id]);
            json_out(['ok' => true]);

        /* ================= ETİKETLER ================= */

        case 'tags':
            $stmt = db()->query(
                'SELECT tg.*, (SELECT COUNT(*) FROM task_tags tt WHERE tt.tag_id = tg.id) AS task_count
                 FROM tags tg WHERE tg.deleted_at IS NULL ORDER BY tg.name'
            );
            json_out(['ok' => true, 'tags' => $stmt->fetchAll()]);

        case 'tag_save':
            $pdo = db();
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                json_out(['ok' => false, 'error' => 'Etiket adı boş olamaz.']);
            }
            $color = trim((string)($_POST['color'] ?? '#FF9F0A'));
            $emoji = trim((string)($_POST['emoji'] ?? ''));
            if ($id > 0) {
                db()->prepare('UPDATE tags SET name=:n, color=:c, emoji=:e WHERE id=:id')
                    ->execute([':n' => $name, ':c' => $color, ':e' => $emoji, ':id' => $id]);
            } else {
                $stmt = db()->prepare('INSERT INTO tags (name, color, emoji) VALUES (:n,:c,:e)');
                try {
                    $stmt->execute([':n' => $name, ':c' => $color, ':e' => $emoji]);
                } catch (PDOException) {
                    json_out(['ok' => false, 'error' => 'Bu etiket zaten var.']);
                }
                $id = (int)$pdo->lastInsertId();
            }
            json_out(['ok' => true, 'id' => $id]);

        case 'tag_delete':
            db()->prepare('DELETE FROM tags WHERE id = :i')->execute([':i' => (int)($_POST['id'] ?? 0)]);
            json_out(['ok' => true]);

        case 'task_tag_toggle':
            $tid = (int)($_POST['task_id'] ?? 0);
            $gid = (int)($_POST['tag_id'] ?? 0);
            $stmt = db()->prepare('SELECT COUNT(*) AS c FROM task_tags WHERE task_id = :t AND tag_id = :g');
            $stmt->execute([':t' => $tid, ':g' => $gid]);
            if ((int)$stmt->fetch()['c'] > 0) {
                db()->prepare('DELETE FROM task_tags WHERE task_id = :t AND tag_id = :g')->execute([':t' => $tid, ':g' => $gid]);
                $added = false;
            } else {
                db()->prepare('INSERT OR IGNORE INTO task_tags (task_id, tag_id) VALUES (:t,:g)')->execute([':t' => $tid, ':g' => $gid]);
                $added = true;
            }
            json_out(['ok' => true, 'added' => $added]);

        /* ================= ALT GÖREVLER ================= */

        case 'subtask_save':
            $pdo = db();
            $id = (int)($_POST['id'] ?? 0);
            $taskId = (int)($_POST['task_id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            if ($title === '' || $taskId <= 0) {
                json_out(['ok' => false, 'error' => 'Geçersiz alt görev.']);
            }
            $parentId = (($_POST['parent_id'] ?? '') !== '') ? ((int)$_POST['parent_id'] ?: null) : null;
            if ($id > 0) {
                db()->prepare('UPDATE subtasks SET title=:t, updated_at=:u WHERE id=:i')
                    ->execute([':t' => $title, ':u' => date('Y-m-d H:i:s'), ':i' => $id]);
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO subtasks (task_id, parent_id, title, sort_order) VALUES (:t,:p,:tt, (SELECT COALESCE(MAX(sort_order),0)+1 FROM subtasks WHERE task_id = :t2))'
                );
                $stmt->execute([':t' => $taskId, ':p' => $parentId, ':tt' => $title, ':t2' => $taskId]);
                $id = (int)$pdo->lastInsertId();
            }
            refresh_task_progress($taskId);
            json_out(['ok' => true, 'id' => $id]);

        case 'subtask_toggle':
            $id = (int)($_POST['id'] ?? 0);
            $stmt = db()->prepare('SELECT * FROM subtasks WHERE id = :i');
            $stmt->execute([':i' => $id]);
            $sub = $stmt->fetch();
            if (!$sub) {
                json_out(['ok' => false, 'error' => 'Alt görev bulunamadı.']);
            }
            $new = (int)$sub['completed'] ? 0 : 1;
            db()->prepare('UPDATE subtasks SET completed = :c, updated_at = :u WHERE id = :i')
                ->execute([':c' => $new, ':u' => date('Y-m-d H:i:s'), ':i' => $id]);
            refresh_task_progress((int)$sub['task_id']);
            json_out(['ok' => true, 'completed' => $new]);

        case 'subtask_delete':
            db()->prepare('DELETE FROM subtasks WHERE id = :i')->execute([':i' => (int)($_POST['id'] ?? 0)]);
            if ((int)($_POST['task_id'] ?? 0) > 0) {
                refresh_task_progress((int)$_POST['task_id']);
            }
            json_out(['ok' => true]);

        /* ================= CHECKLIST ================= */

        case 'checklist_save':
            $pdo = db();
            $id = (int)($_POST['id'] ?? 0);
            $taskId = (int)($_POST['task_id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            if ($title === '' || $taskId <= 0) {
                json_out(['ok' => false, 'error' => 'Geçersiz kontrol listesi.']);
            }
            if ($id > 0) {
                db()->prepare('UPDATE checklists SET title=:t, updated_at=:u WHERE id=:i')
                    ->execute([':t' => $title, ':u' => date('Y-m-d H:i:s'), ':i' => $id]);
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO checklists (task_id, title, sort_order) VALUES (:t,:tt, (SELECT COALESCE(MAX(sort_order),0)+1 FROM checklists WHERE task_id = :t2))'
                );
                $stmt->execute([':t' => $taskId, ':tt' => $title, ':t2' => $taskId]);
                $id = (int)$pdo->lastInsertId();
            }
            refresh_task_progress($taskId);
            json_out(['ok' => true, 'id' => $id]);

        case 'checklist_toggle':
            $id = (int)($_POST['id'] ?? 0);
            $stmt = db()->prepare('SELECT * FROM checklists WHERE id = :i');
            $stmt->execute([':i' => $id]);
            $item = $stmt->fetch();
            if (!$item) {
                json_out(['ok' => false, 'error' => 'Öğe bulunamadı.']);
            }
            $new = (int)$item['completed'] ? 0 : 1;
            db()->prepare('UPDATE checklists SET completed = :c, updated_at = :u WHERE id = :i')
                ->execute([':c' => $new, ':u' => date('Y-m-d H:i:s'), ':i' => $id]);
            refresh_task_progress((int)$item['task_id']);
            json_out(['ok' => true, 'completed' => $new]);

        case 'checklist_delete':
            db()->prepare('DELETE FROM checklists WHERE id = :i')->execute([':i' => (int)($_POST['id'] ?? 0)]);
            if ((int)($_POST['task_id'] ?? 0) > 0) {
                refresh_task_progress((int)$_POST['task_id']);
            }
            json_out(['ok' => true]);

        /* ================= DOSYA EKLERİ ================= */

        case 'attachment_upload':
            $taskId = (int)($_POST['task_id'] ?? 0);
            if ($taskId <= 0 || empty($_FILES['file']) || ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
                json_out(['ok' => false, 'error' => 'Dosya yüklenemedi.']);
            }
            if (!is_dir(UPLOAD_DIR)) {
                @mkdir(UPLOAD_DIR, 0777, true);
            }
            $file = $_FILES['file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $safeExt = preg_replace('/[^a-z0-9]/', '', $ext);
            $safeName = substr(bin2hex(random_bytes(8)), 0, 16) . ($safeExt !== '' ? '.' . $safeExt : '');
            $dest = UPLOAD_DIR . DIRECTORY_SEPARATOR . $safeName;
            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                json_out(['ok' => false, 'error' => 'Dosya kaydedilemedi.']);
            }
            $stmt = db()->prepare(
                'INSERT INTO attachments (task_id, filename, filesize, mime_type, path) VALUES (:t,:f,:s,:m,:p)'
            );
            $stmt->execute([
                ':t' => $taskId,
                ':f' => mb_convert_encoding($file['name'], 'UTF-8', 'auto') ?: $file['name'],
                ':s' => (int)$file['size'],
                ':m' => (string)($file['type'] ?? ''),
                ':p' => $safeName,
            ]);
            activity_log($taskId, 'attachment_added', $file['name']);
            json_out(['ok' => true, 'id' => (int)db()->lastInsertId()]);

        case 'attachment_delete':
            $id = (int)($_POST['id'] ?? 0);
            $stmt = db()->prepare('SELECT * FROM attachments WHERE id = :i');
            $stmt->execute([':i' => $id]);
            $a = $stmt->fetch();
            if ($a) {
                $p = UPLOAD_DIR . DIRECTORY_SEPARATOR . basename($a['path']);
                if (is_file($p)) {
                    @unlink($p);
                }
                db()->prepare('DELETE FROM attachments WHERE id = :i')->execute([':i' => $id]);
            }
            json_out(['ok' => true]);

        case 'attachment_download':
            $id = (int)($_GET['id'] ?? 0);
            $stmt = db()->prepare('SELECT * FROM attachments WHERE id = :i');
            $stmt->execute([':i' => $id]);
            $a = $stmt->fetch();
            if (!$a) {
                http_response_code(404);
                exit('Bulunamadı');
            }
            $path = UPLOAD_DIR . DIRECTORY_SEPARATOR . basename($a['path']);
            if (!is_file($path)) {
                http_response_code(404);
                exit('Dosya yok');
            }
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . addcslashes($a['filename'], '"') . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;

        /* ================= HATIRLATICI ================= */

        case 'check_reminders':
            $now = date('Y-m-d H:i:s');
            $stmt = db()->prepare(
                'SELECT r.*, t.title FROM reminders r JOIN tasks t ON t.id = r.task_id
                 WHERE r.triggered = 0 AND r.remind_at <= :n AND t.deleted_at IS NULL AND t.status <> 3
                 ORDER BY r.remind_at LIMIT 50'
            );
            $stmt->execute([':n' => $now]);
            $due = $stmt->fetchAll();
            if ($due) {
                $ids = array_column($due, 'id');
                $in = implode(',', array_fill(0, count($ids), '?'));
                db()->prepare("UPDATE reminders SET triggered = 1 WHERE id IN ($in)")->execute($ids);
            }
            json_out(['ok' => true, 'reminders' => $due]);

        /* ================= TOPLU İŞLEMLER ================= */

        case 'bulk':
            $ids = $_POST['ids'] ?? [];
            if (!is_array($ids) || !$ids) {
                json_out(['ok' => false, 'error' => 'Görev seçilmedi.']);
            }
            $ids = array_map('intval', array_values(array_filter($ids)));
            $in = implode(',', array_fill(0, count($ids), '?'));
            $op = (string)($_POST['op'] ?? '');
            $pdo = db();
            switch ($op) {
                case 'delete':
                    $d = date('Y-m-d H:i:s');
                    $pdo->prepare("UPDATE tasks SET deleted_at = ? WHERE id IN ($in)")->execute(array_merge([$d], $ids));
                    break;
                case 'restore':
                    $d = date('Y-m-d H:i:s');
                    $pdo->prepare("UPDATE tasks SET deleted_at = NULL, updated_at = ? WHERE id IN ($in)")->execute(array_merge([$d], $ids));
                    break;
                case 'archive':
                    $d = date('Y-m-d H:i:s');
                    $pdo->prepare("UPDATE tasks SET archived_at = ? WHERE id IN ($in)")->execute(array_merge([$d], $ids));
                    break;
                case 'destroy':
                    $pdo->prepare("DELETE FROM tasks WHERE id IN ($in)")->execute($ids);
                    break;
                case 'move_folder':
                    $fid = (($_POST['folder_id'] ?? '') !== '') ? ((int)$_POST['folder_id'] ?: null) : null;
                    $d = date('Y-m-d H:i:s');
                    $pdo->prepare("UPDATE tasks SET folder_id = ?, updated_at = ? WHERE id IN ($in)")->execute(array_merge([$fid, $d], $ids));
                    break;
                case 'add_tags':
                    $stmt = $pdo->prepare('INSERT OR IGNORE INTO task_tags (task_id, tag_id) VALUES (?, ?)');
                    foreach ($ids as $tid) {
                        foreach ((array)($_POST['tag_ids'] ?? []) as $gid) {
                            $stmt->execute([$tid, (int)$gid]);
                        }
                    }
                    break;
                case 'status':
                    $st = max(0, min(4, (int)($_POST['status'] ?? 0)));
                    $d = date('Y-m-d H:i:s');
                    $pdo->prepare("UPDATE tasks SET status = ?, updated_at = ? WHERE id IN ($in)")->execute(array_merge([$st, $d], $ids));
                    if ($st === 3) {
                        $pdo->prepare("UPDATE tasks SET completed_at = ? WHERE id IN ($in)")->execute(array_merge([$d], $ids));
                    } else {
                        $pdo->prepare("UPDATE tasks SET completed_at = NULL WHERE id IN ($in)")->execute($ids);
                    }
                    break;
                case 'priority':
                    $p = max(0, min(3, (int)($_POST['priority'] ?? 1)));
                    $d = date('Y-m-d H:i:s');
                    $pdo->prepare("UPDATE tasks SET priority = ?, updated_at = ? WHERE id IN ($in)")->execute(array_merge([$p, $d], $ids));
                    break;
                case 'flag':
                    $col = (string)($_POST['col'] ?? 'is_favorite');
                    if (!in_array($col, ['is_favorite', 'is_pinned'], true)) {
                        json_out(['ok' => false, 'error' => 'Geçersiz bayrak.']);
                    }
                    $v = (int)($_POST['value'] ?? 0);
                    $d = date('Y-m-d H:i:s');
                    $pdo->prepare("UPDATE tasks SET $col = ?, updated_at = ? WHERE id IN ($in)")->execute(array_merge([$v, $d], $ids));
                    break;
                default:
                    json_out(['ok' => false, 'error' => 'Geçersiz toplu işlem.']);
            }
            activity_log(null, 'bulk_' . $op, count($ids) . ' görev');
            json_out(['ok' => true]);

        /* ================= İSTATİSTİK ================= */

        case 'stats':
            $pdo = db();
            $q = function (string $sql, array $p = []) use ($pdo): int {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($p);
                return (int)$stmt->fetch()['c'];
            };
            $stats = [
                'total'      => $q("SELECT COUNT(*) c FROM tasks WHERE deleted_at IS NULL"),
                'completed'  => $q("SELECT COUNT(*) c FROM tasks WHERE deleted_at IS NULL AND status = 3"),
                'active'     => $q("SELECT COUNT(*) c FROM tasks WHERE deleted_at IS NULL AND status IN (0,1,2)"),
                'overdue'    => $q("SELECT COUNT(*) c FROM tasks WHERE deleted_at IS NULL AND status <> 3 AND due_date IS NOT NULL AND date(due_date) < date('now','localtime')"),
                'today'      => $q("SELECT COUNT(*) c FROM tasks WHERE deleted_at IS NULL AND status <> 3 AND date(due_date) = date('now','localtime')"),
                'tomorrow'   => $q("SELECT COUNT(*) c FROM tasks WHERE deleted_at IS NULL AND status <> 3 AND date(due_date) = date('now','localtime','+1 day')"),
                'week'       => $q("SELECT COUNT(*) c FROM tasks WHERE deleted_at IS NULL AND status <> 3 AND date(due_date) >= date('now','localtime') AND date(due_date) <= date('now','localtime','+6 day')"),
                'month'      => $q("SELECT COUNT(*) c FROM tasks WHERE deleted_at IS NULL AND status <> 3 AND date(due_date) BETWEEN date('now','start of month') AND date('now','start of month','+1 month','-1 day')"),
                'inbox'      => $q("SELECT COUNT(*) c FROM tasks WHERE deleted_at IS NULL AND status <> 3 AND folder_id IS NULL"),
                'pinned'     => $q("SELECT COUNT(*) c FROM tasks WHERE deleted_at IS NULL AND is_pinned = 1 AND status <> 3"),
                'favorites'  => $q("SELECT COUNT(*) c FROM tasks WHERE deleted_at IS NULL AND is_favorite = 1"),
            ];
            $stats['completion_rate'] = $stats['total'] > 0
                ? (int)round($stats['completed'] * 100 / $stats['total'])
                : 0;

            // En yoğun gün (tamamlananlara göre)
            $stmt = $pdo->query(
                "SELECT date(completed_at) AS d, COUNT(*) c FROM tasks
                 WHERE status = 3 AND completed_at IS NOT NULL AND date(completed_at) >= date('now','localtime','-90 day')
                 GROUP BY date(completed_at) ORDER BY c DESC LIMIT 1"
            );
            $busy = $stmt->fetch();
            $stats['busiest_day'] = $busy ? ['date' => $busy['d'], 'count' => (int)$busy['c']] : null;

            $stmt = $pdo->query(
                'SELECT g.name, COUNT(*) c FROM task_tags tt JOIN tags g ON g.id = tt.tag_id
                 GROUP BY g.id ORDER BY c DESC LIMIT 1'
            );
            $top = $stmt->fetch();
            $stats['top_tag'] = $top ? ['name' => $top['name'], 'count' => (int)$top['c']] : null;

            $stmt = $pdo->query(
                'SELECT f.name, COUNT(*) c FROM tasks t JOIN folders f ON f.id = t.folder_id
                 WHERE t.deleted_at IS NULL GROUP BY f.id ORDER BY c DESC LIMIT 1'
            );
            $topF = $stmt->fetch();
            $stats['top_folder'] = $topF ? ['name' => $topF['name'], 'count' => (int)$topF['c']] : null;

            // Yaklaşan 7 gün
            $stmt = $pdo->query(
                "SELECT date(due_date) d, COUNT(*) c FROM tasks
                 WHERE deleted_at IS NULL AND status <> 3 AND due_date IS NOT NULL
                 AND date(due_date) >= date('now','localtime') AND date(due_date) <= date('now','localtime','+7 day')
                 GROUP BY date(due_date)"
            );
            $stats['upcoming'] = $stmt->fetchAll();

            // Son 14 gün tamamlama
            $stmt = $pdo->query(
                "SELECT date(completed_at) d, COUNT(*) c FROM tasks
                 WHERE status = 3 AND completed_at IS NOT NULL
                 AND date(completed_at) >= date('now','localtime','-13 day')
                 GROUP BY date(completed_at)"
            );
            $stats['recent_completions'] = $stmt->fetchAll();

            json_out(['ok' => true, 'stats' => $stats]);

        /* ================= RAPORLAR ================= */

        case 'reports':
            $pdo = db();
            $weeks = max(4, min(52, (int)($_GET['weeks'] ?? 12)));

            $stmt = $pdo->query(
                "SELECT date(completed_at) d, COUNT(*) c FROM tasks
                 WHERE status = 3 AND completed_at IS NOT NULL
                 GROUP BY date(completed_at) ORDER BY d"
            );
            $byDay = $stmt->fetchAll();

            $stmt = $pdo->query(
                "SELECT strftime('%Y-%W', completed_at) w, COUNT(*) c FROM tasks
                 WHERE status = 3 AND completed_at IS NOT NULL AND date(completed_at) >= date('now','localtime', :wk)
                 GROUP BY w ORDER BY w"
            );
            $stmt->execute([':wk' => (-7 * $weeks) . ' day']);
            $weekly = $stmt->fetchAll();

            $stmt = $pdo->query(
                "SELECT strftime('%Y-%m', completed_at) m, COUNT(*) c FROM tasks
                 WHERE status = 3 AND completed_at IS NOT NULL AND date(completed_at) >= date('now','localtime','-11 month')
                 GROUP BY m ORDER BY m"
            );
            $monthly = $stmt->fetchAll();

            $stmt = $pdo->query(
                'SELECT f.name, COUNT(*) c FROM tasks t JOIN folders f ON f.id = t.folder_id
                 WHERE t.deleted_at IS NULL GROUP BY f.id ORDER BY c DESC LIMIT 12'
            );
            $byFolder = $stmt->fetchAll();

            $stmt = $pdo->query(
                'SELECT g.name, COUNT(*) c FROM task_tags tt JOIN tags g ON g.id = tt.tag_id
                 GROUP BY g.id ORDER BY c DESC LIMIT 12'
            );
            $byTag = $stmt->fetchAll();

            $stmt = $pdo->query(
                'SELECT priority, COUNT(*) c FROM tasks WHERE deleted_at IS NULL GROUP BY priority'
            );
            $byPriority = $stmt->fetchAll();

            $stmt = $pdo->query(
                'SELECT status, COUNT(*) c FROM tasks WHERE deleted_at IS NULL GROUP BY status'
            );
            $byStatus = $stmt->fetchAll();

            json_out(['ok' => true, 'reports' => compact('byDay', 'weekly', 'monthly', 'byFolder', 'byTag', 'byPriority', 'byStatus')]);

        /* ================= AYARLAR ================= */

        case 'settings':
            json_out(['ok' => true, 'settings' => [
                'theme'        => setting_get('theme', 'system'),
                'accent'       => setting_get('accent', '#0A84FF'),
                'sound'        => setting_get('sound', '1'),
                'notifications'=> setting_get('notifications', '1'),
                'default_folder'=> setting_get('default_folder', ''),
                'pomodoro_focus' => setting_get('pomodoro_focus', '25'),
                'pomodoro_break' => setting_get('pomodoro_break', '5'),
            ]]);

        case 'settings_save':
            $allowed = ['theme', 'accent', 'sound', 'notifications', 'default_folder', 'pomodoro_focus', 'pomodoro_break'];
            foreach ($allowed as $k) {
                if (array_key_exists($k, $_POST)) {
                    setting_set($k, trim((string)$_POST[$k]));
                }
            }
            activity_log(null, 'settings_updated');
            json_out(['ok' => true]);

        /* ================= YEDEKLEME ================= */

        case 'backup_create':
            if (!is_dir(BACKUP_DIR)) {
                @mkdir(BACKUP_DIR, 0777, true);
            }
            $name = 'backup-' . date('Ymd-His') . '.sqlite';
            $dest = BACKUP_DIR . DIRECTORY_SEPARATOR . $name;
            if (!copy(DB_FILE, $dest)) {
                json_out(['ok' => false, 'error' => 'Yedek alınamadı.']);
            }
            $size = filesize($dest) ?: 0;
            if (class_exists('ZipArchive')) {
                $zipName = 'backup-' . date('Ymd-His') . '.zip';
                $zipPath = BACKUP_DIR . DIRECTORY_SEPARATOR . $zipName;
                $zip = new ZipArchive();
                if ($zip->open($zipPath, ZipArchive::CREATE) === true) {
                    $zip->addFile($dest, 'database.sqlite');
                    $zip->close();
                    @unlink($dest);
                    $name = $zipName;
                    $dest = $zipPath;
                    $size = filesize($dest) ?: 0;
                }
            }
            $stmt = db()->prepare('INSERT INTO backups (filename, filesize, notes) VALUES (:f,:s,:n)');
            $stmt->execute([':f' => $name, ':s' => $size, ':n' => 'Manuel yedek']);
            json_out(['ok' => true, 'file' => $name]);

        case 'backup_list':
            $stmt = db()->query('SELECT * FROM backups ORDER BY created_at DESC LIMIT 30');
            json_out(['ok' => true, 'backups' => $stmt->fetchAll()]);

        case 'backup_download':
            $stmt = db()->prepare('SELECT * FROM backups WHERE id = :i');
            $stmt->execute([':i' => (int)($_GET['id'] ?? 0)]);
            $b = $stmt->fetch();
            if (!$b) {
                http_response_code(404);
                exit('Yok');
            }
            $path = BACKUP_DIR . DIRECTORY_SEPARATOR . basename($b['filename']);
            if (!is_file($path)) {
                http_response_code(404);
                exit('Dosya yok');
            }
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $b['filename'] . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;

        case 'backup_restore':
            $stmt = db()->prepare('SELECT * FROM backups WHERE id = :i');
            $stmt->execute([':i' => (int)($_POST['id'] ?? 0)]);
            $b = $stmt->fetch();
            if (!$b) {
                json_out(['ok' => false, 'error' => 'Yedek bulunamadı.']);
            }
            $path = BACKUP_DIR . DIRECTORY_SEPARATOR . basename($b['filename']);
            if (!is_file($path)) {
                json_out(['ok' => false, 'error' => 'Yedek dosyası yok.']);
            }
            $tmp = BACKUP_DIR . DIRECTORY_SEPARATOR . 'restore-tmp-' . bin2hex(random_bytes(4));
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'zip') {
                $zip = new ZipArchive();
                if ($zip->open($path) !== true || $zip->extractTo($tmp) !== true) {
                    json_out(['ok' => false, 'error' => 'ZIP açılamadı.']);
                }
                $zip->close();
                $sqlite = glob($tmp . DIRECTORY_SEPARATOR . '*.sqlite');
                if (!$sqlite) {
                    @array_map('unlink', glob($tmp . '/*'));
                    @rmdir($tmp);
                    json_out(['ok' => false, 'error' => 'ZIP içinde veritabanı yok.']);
                }
                $src = $sqlite[0];
            } else {
                $src = $path;
            }
            db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            if (!@copy($src, DB_FILE)) {
                json_out(['ok' => false, 'error' => 'Geri yükleme başarısız.']);
            }
            if (isset($zip)) {
                @array_map('unlink', glob($tmp . '/*'));
                @rmdir($tmp);
            }
            activity_log(null, 'backup_restored', $b['filename']);
            json_out(['ok' => true]);

        case 'backup_delete':
            $stmt = db()->prepare('SELECT * FROM backups WHERE id = :i');
            $stmt->execute([':i' => (int)($_POST['id'] ?? 0)]);
            $b = $stmt->fetch();
            if ($b) {
                $p = BACKUP_DIR . DIRECTORY_SEPARATOR . basename($b['filename']);
                if (is_file($p)) {
                    @unlink($p);
                }
                db()->prepare('DELETE FROM backups WHERE id = :i')->execute([':i' => (int)$b['id']]);
            }
            json_out(['ok' => true]);

        /* ================= DIŞA / İÇE AKTARIM ================= */

        case 'export':
            $type = (string)($_GET['type'] ?? 'json');
            $tasks = db()->query('SELECT * FROM tasks WHERE deleted_at IS NULL')->fetchAll();
            $folders = db()->query('SELECT * FROM folders WHERE deleted_at IS NULL')->fetchAll();
            $tags = db()->query('SELECT * FROM tags WHERE deleted_at IS NULL')->fetchAll();
            $taskTags = db()->query('SELECT * FROM task_tags')->fetchAll();
            $subtasks = db()->query('SELECT * FROM subtasks')->fetchAll();
            $checklists = db()->query('SELECT * FROM checklists')->fetchAll();

            $filename = 'gorevler-' . date('Ymd-His');
            switch ($type) {
                case 'json':
                    $data = json_encode([
                        'app' => APP_NAME, 'exported_at' => date('c'),
                        'folders' => $folders, 'tags' => $tags, 'tasks' => $tasks,
                        'task_tags' => $taskTags, 'subtasks' => $subtasks, 'checklists' => $checklists,
                    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                    header('Content-Type: application/json; charset=utf-8');
                    header('Content-Disposition: attachment; filename="' . $filename . '.json"');
                    echo $data;
                    exit;

                case 'csv':
                    $handle = fopen('php://output', 'w');
                    fwrite($handle, "\xEF\xBB\xBF");
                    fputcsv($handle, ['ID', 'Başlık', 'Açıklama', 'Notlar', 'Başlangıç', 'Bitiş', 'Saat', 'Öncelik', 'Durum', 'İlerleme %', 'Klasör', 'Etiketler', 'Oluşturma', 'Tamamlanma']);
                    foreach ($tasks as $t) {
                        $folder = $folders[array_search($t['folder_id'], array_column($folders, 'id'), true)] ?? null;
                        $tt = $tags[array_search($t['id'], array_column($taskTags, 'task_id'), true)] ?? null;
                        $tagNames = [];
                        foreach ($taskTags as $tt2) {
                            if ((int)$tt2['task_id'] === (int)$t['id']) {
                                foreach ($tags as $g) {
                                    if ((int)$g['id'] === (int)$tt2['tag_id']) {
                                        $tagNames[] = $g['name'];
                                    }
                                }
                            }
                        }
                        fputcsv($handle, [
                            $t['id'], $t['title'], $t['description'], $t['notes'], $t['start_date'], $t['due_date'], $t['due_time'],
                            PRIORITY_LABELS[(int)$t['priority']], STATUS_LABELS[(int)$t['status']], $t['progress'],
                            $folder['name'] ?? '', implode(', ', $tagNames), $t['created_at'], $t['completed_at'],
                        ]);
                    }
                    fclose($handle);
                    header('Content-Type: text/csv; charset=utf-8');
                    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
                    exit;

                case 'excel':
                    $handle = fopen('php://output', 'w');
                    fwrite($handle, "\xEF\xBB\xBF");
                    fputcsv($handle, ['ID', 'Başlık', 'Açıklama', 'Bitiş', 'Öncelik', 'Durum'], ';');
                    foreach ($tasks as $t) {
                        fputcsv($handle, [
                            $t['id'], $t['title'], $t['description'], $t['due_date'],
                            PRIORITY_LABELS[(int)$t['priority']], STATUS_LABELS[(int)$t['status']],
                        ], ';');
                    }
                    fclose($handle);
                    header('Content-Type: text/csv; charset=utf-8');
                    header('Content-Disposition: attachment; filename="' . $filename . '-excel.csv"');
                    exit;

                case 'md':
                    $lines = ["# " . APP_NAME . " Dışa Aktarımı\n", '**Tarih:** ' . date('d.m.Y H:i') . "\n\n"];
                    foreach ($folders as $f) {
                        $lines[] = "## 📁 {$f['name']}\n";
                        foreach ($tasks as $t) {
                            if ((int)$t['folder_id'] === (int)$f['id']) {
                                $lines[] = "- [ ] **{$t['title']}** " . ($t['due_date'] ? "`{$t['due_date']}`" : '') . "\n";
                                if ($t['description'] !== '') {
                                    $lines[] = "    - {$t['description']}\n";
                                }
                            }
                        }
                        $lines[] = "\n";
                    }
                    header('Content-Type: text/markdown; charset=utf-8');
                    header('Content-Disposition: attachment; filename="' . $filename . '.md"');
                    echo implode('', $lines);
                    exit;
            }
            json_out(['ok' => false, 'error' => 'Geçersiz format.']);

        case 'import':
            $type = (string)($_POST['type'] ?? 'json');
            $content = (string)($_POST['content'] ?? '');
            if ($content === '' || !empty($_FILES['file'])) {
                if (!empty($_FILES['file']) && ($_FILES['file']['error'] ?? 1) === UPLOAD_ERR_OK) {
                    $content = (string)file_get_contents($_FILES['file']['tmp_name']);
                }
            }
            if ($content === '') {
                json_out(['ok' => false, 'error' => 'İçerik boş.']);
            }
            $pdo = db();
            $count = 0;
            $pdo->beginTransaction();
            try {
                if ($type === 'json') {
                    $data = json_decode($content, true);
                    if (!is_array($data) || empty($data['tasks'])) {
                        throw new RuntimeException('Geçersiz JSON. Eksik görev verisi.');
                    }
                    $folderMap = [];
                    foreach ($data['folders'] ?? [] as $f) {
                        $stmt = $pdo->prepare('SELECT id FROM folders WHERE name = :n');
                        $stmt->execute([':n' => $f['name']]);
                        $ex = $stmt->fetch();
                        if ($ex) {
                            $folderMap[$f['id']] = $ex['id'];
                        } else {
                            $stmt = $pdo->prepare('INSERT INTO folders (name, icon, color, description) VALUES (:n,:i,:c,:d)');
                            $stmt->execute([':n' => $f['name'], ':i' => $f['icon'] ?? 'folder', ':c' => $f['color'] ?? '#0A84FF', ':d' => $f['description'] ?? '']);
                            $folderMap[$f['id']] = (int)$pdo->lastInsertId();
                        }
                    }
                    $tagMap = [];
                    foreach ($data['tags'] ?? [] as $g) {
                        $stmt = $pdo->prepare('SELECT id FROM tags WHERE name = :n');
                        $stmt->execute([':n' => $g['name']]);
                        $ex = $stmt->fetch();
                        if ($ex) {
                            $tagMap[$g['id']] = $ex['id'];
                        } else {
                            $stmt = $pdo->prepare('INSERT INTO tags (name, color, emoji) VALUES (:n,:c,:e)');
                            $stmt->execute([':n' => $g['name'], ':c' => $g['color'] ?? '#FF9F0A', ':e' => $g['emoji'] ?? '']);
                            $tagMap[$g['id']] = (int)$pdo->lastInsertId();
                        }
                    }
                    $taskMap = [];
                    $fields = ['title', 'description', 'notes', 'start_date', 'due_date', 'due_time', 'priority', 'status', 'progress', 'color', 'emoji', 'icon', 'location', 'estimated_time', 'actual_time', 'is_favorite', 'is_pinned', 'created_at', 'completed_at'];
                    $cols = implode(', ', array_filter($fields));
                    $vals = implode(', ', array_map(fn($x) => ':' . $x, array_filter($fields)));
                    $ins = $pdo->prepare("INSERT INTO tasks ($cols, updated_at) VALUES ($vals, :u)");
                    foreach ($data['tasks'] as $t) {
                        $params = [];
                        foreach ($fields as $k) {
                            $params[':' . $k] = $t[$k] ?? null;
                        }
                        $params[':u'] = date('Y-m-d H:i:s');
                        $ins->execute($params);
                        $newId = (int)$pdo->lastInsertId();
                        $taskMap[$t['id']] = $newId;
                        if (!empty($t['folder_id']) && isset($folderMap[$t['folder_id']])) {
                            $pdo->prepare('UPDATE tasks SET folder_id = :f WHERE id = :i')
                                ->execute([':f' => $folderMap[$t['folder_id']], ':i' => $newId]);
                        }
                        foreach ($data['task_tags'] ?? [] as $tt) {
                            if ((int)$tt['task_id'] === (int)$t['id'] && isset($tagMap[$tt['tag_id']])) {
                                $pdo->prepare('INSERT OR IGNORE INTO task_tags (task_id, tag_id) VALUES (:t,:g)')
                                    ->execute([':t' => $newId, ':g' => $tagMap[$tt['tag_id']]]);
                            }
                        }
                        foreach ($data['subtasks'] ?? [] as $s) {
                            if ((int)$s['task_id'] === (int)$t['id']) {
                                $pdo->prepare('INSERT INTO subtasks (task_id, title, completed, sort_order) VALUES (:t,:tt,:c,:s)')
                                    ->execute([':t' => $newId, ':tt' => $s['title'], ':c' => (int)($s['completed'] ?? 0), ':s' => (int)($s['sort_order'] ?? 0)]);
                            }
                        }
                        foreach ($data['checklists'] ?? [] as $ch) {
                            if ((int)$ch['task_id'] === (int)$t['id']) {
                                $pdo->prepare('INSERT INTO checklists (task_id, title, completed, sort_order) VALUES (:t,:tt,:c,:s)')
                                    ->execute([':t' => $newId, ':tt' => $ch['title'], ':c' => (int)($ch['completed'] ?? 0), ':s' => (int)($ch['sort_order'] ?? 0)]);
                            }
                        }
                        $count++;
                    }
                } elseif ($type === 'csv') {
                    $rows = array_map('str_getcsv', explode("\n", $content));
                    array_shift($rows);
                    $ins = $pdo->prepare('INSERT INTO tasks (title, description, due_date, priority, status) VALUES (:t,:d,:dd,:p,:s)');
                    foreach ($rows as $r) {
                        if (!isset($r[1]) || trim((string)$r[1]) === '') {
                            continue;
                        }
                        $ins->execute([
                            ':t' => trim((string)$r[1]),
                            ':d' => trim((string)($r[2] ?? '')),
                            ':dd' => trim((string)($r[4] ?? '')),
                            ':p' => max(0, min(3, (int)($r[6] ?? 1) - 1)),
                            ':s' => max(0, min(4, (int)($r[7] ?? 0) - 1)),
                        ]);
                        $count++;
                    }
                } else {
                    throw new RuntimeException('Geçersiz içe aktarma türü.');
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                json_out(['ok' => false, 'error' => 'İçe aktarma başarısız: ' . $e->getMessage()]);
            }
            activity_log(null, 'import', $count . ' görev');
            json_out(['ok' => true, 'imported' => $count]);

        /* ================= ETKİNLİK ================= */

        case 'demo_seed':
            require_once __DIR__ . '/demo.php';
            $result = seed_demo_data(!empty($_POST['replace']));
            activity_log(null, 'import', 'Demo verisi yüklendi (' . $result['tasks'] . ' görev)');
            json_out(['ok' => true, 'tasks' => $result['tasks']]);

        case 'activity':
            $limit = min(100, max(10, (int)($_GET['limit'] ?? 50)));
            $stmt = db()->prepare(
                'SELECT a.*, t.title AS task_title FROM activity_logs a LEFT JOIN tasks t ON t.id = a.task_id ORDER BY a.id DESC LIMIT :l'
            );
            $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
            $stmt->execute();
            json_out(['ok' => true, 'activity' => $stmt->fetchAll()]);

        default:
            json_out(['ok' => false, 'error' => 'Bilinmeyen aksiyon: ' . e($action)]);
    }
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'Sunucu hatası: ' . $e->getMessage()], 500);
}

/* ------------------------------------------------------------------ *
 *  Yardımcı Mantık (Tekrarlama + İlerleme)
 * ------------------------------------------------------------------ */

/**
 * Bir sonraki tekrar tarihini hesaplar.
 */
function next_occurrence(DateTime $from, string $freq, int $interval, array $byDay, array $mDays, array $months): ?DateTime
{
    $d = clone $from;
    switch ($freq) {
        case 'daily':
            $d->modify("+{$interval} day");
            break;
        case 'weekdays':
            do {
                $d->modify('+1 day');
            } while ((int)$d->format('N') >= 6);
            break;
        case 'weekly':
            $d->modify("+{$interval} week");
            break;
        case 'biweekly':
            $d->modify("+$interval fortnight");
            break;
        case 'monthly':
            $d->modify("+{$interval} month");
            $last = (int)$d->format('t');
            if ((int)$d->format('j') > $last) {
                $d->setDate((int)$d->format('Y'), (int)$d->format('m'), $last);
            }
            break;
        case 'quarterly':
            $d->modify("+" . (3 * $interval) . " month");
            break;
        case 'semiannual':
            $d->modify("+" . (6 * $interval) . " month");
            break;
        case 'yearly':
            $d->modify("+{$interval} year");
            if ($d->format('m-d') === '02-29') {
                $d->setDate((int)$d->format('Y'), 2, 28);
            }
            break;
        case 'custom':
            $tries = 0;
            do {
                $d->modify('+1 day');
                $tries++;
                $ok = true;
                if ($months && !in_array((int)$d->format('n'), $months, true)) {
                    $ok = false;
                }
                if ($ok && $byDay && !in_array($d->format('D'), $byDay, true)) {
                    // "son cuma" özel durumu
                    if (!(in_array('FRI', $byDay, true) && $d->format('D') === 'Fri' && (int)$d->format('j') > (int)$d->format('t') - 7)) {
                        $ok = false;
                    } else {
                        // basit gün eşleşmesi yerine son-hafta mantığı kabul
                        $ok = true;
                    }
                }
                if ($ok && $mDays && !in_array((int)$d->format('j'), array_map('intval', $mDays), true)) {
                    $ok = false;
                }
                if (count($months) === 0 && count($byDay) === 0 && count($mDays) === 0) {
                    $ok = false;
                }
            } while (!$ok && $tries < 400);
            if ($tries >= 400) {
                return null;
            }
            break;
        default:
            return null;
    }
    return $d;
}

/**
 * Tamamlanan tekrarlanan görevin bir sonraki kopyasını oluşturur.
 */
function handle_recurrence(int $taskId, array $task): void
{
    $stmt = db()->prepare('SELECT * FROM recurrence_rules WHERE task_id = :i');
    $stmt->execute([':i' => $taskId]);
    $rule = $stmt->fetch();
    if (!$rule || $rule['freq'] === 'none') {
        return;
    }

    $byDay = array_values(array_filter(array_map('strtoupper', explode(',', (string)$rule['by_day']))));
    $mDays = array_values(array_filter(array_map('intval', explode(',', (string)$rule['by_month_day']))));
    $months = array_values(array_filter(array_map('intval', explode(',', (string)$rule['by_month']))));

    $base = new DateTime($task['due_date'] ?: date('Y-m-d'));
    $next = next_occurrence($base, (string)$rule['freq'], (int)$rule['interval'], $byDay, $mDays, $months);
    if (!$next) {
        return;
    }
    if ($rule['ends_on'] && $next > new DateTime($rule['ends_on'])) {
        return;
    }

    $fields = ['title', 'description', 'notes', 'start_date', 'due_time', 'priority', 'color', 'emoji', 'icon', 'location', 'folder_id', 'estimated_time', 'is_favorite', 'is_pinned', 'sort_order'];
    $cols = implode(', ', array_filter($fields));
    $vals = implode(', ', array_map(fn($x) => ':' . $x, array_filter($fields)));
    $stmt = db()->prepare("INSERT INTO tasks ($cols, status, progress, due_date, created_at, updated_at) VALUES ($vals, 0, 0, :due, :c, :c)");
    $params = [];
    foreach ($fields as $f) {
        $params[':' . $f] = $task[$f] ?? null;
    }
    $params[':due'] = $next->format('Y-m-d');
    $params[':c'] = date('Y-m-d H:i:s');
    $stmt->execute($params);
    $newId = (int)db()->lastInsertId();

    // Etiketleri kopyala
    db()->prepare('INSERT OR IGNORE INTO task_tags (task_id, tag_id) SELECT :n, tag_id FROM task_tags WHERE task_id = :o')
        ->execute([':n' => $newId, ':o' => $taskId]);
    // Checklist kopyala
    db()->prepare('INSERT INTO checklists (task_id, title, completed, sort_order) SELECT :n, title, 0, sort_order FROM checklists WHERE task_id = :o AND deleted_at IS NULL')
        ->execute([':n' => $newId, ':o' => $taskId]);
    // Alt görevleri kopyala (tek seviye)
    db()->prepare('INSERT INTO subtasks (task_id, title, completed, sort_order) SELECT :n, title, 0, sort_order FROM subtasks WHERE task_id = :o AND deleted_at IS NULL AND parent_id IS NULL')
        ->execute([':n' => $newId, ':o' => $taskId]);
    // Tekrar kuralını yeni göreve taşı
    db()->prepare('UPDATE recurrence_rules SET task_id = :n WHERE task_id = :o')
        ->execute([':n' => $newId, ':o' => $taskId]);

    activity_log($newId, 'task_recurrence', 'Bir sonraki tekrar oluşturuldu: ' . $next->format('Y-m-d'));
}

/**
 * Alt görev + checklist tamamlanma oranına göre görevin ilerlemesini günceller.
 */
function refresh_task_progress(int $taskId): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT COUNT(*) c FROM subtasks WHERE task_id = :i AND deleted_at IS NULL');
    $stmt->execute([':i' => $taskId]);
    $subTotal = (int)$stmt->fetch()['c'];
    $stmt = $pdo->prepare('SELECT COUNT(*) c FROM subtasks WHERE task_id = :i AND deleted_at IS NULL AND completed = 1');
    $stmt->execute([':i' => $taskId]);
    $subDone = (int)$stmt->fetch()['c'];

    $stmt = $pdo->prepare('SELECT COUNT(*) c FROM checklists WHERE task_id = :i AND deleted_at IS NULL');
    $stmt->execute([':i' => $taskId]);
    $chkTotal = (int)$stmt->fetch()['c'];
    $stmt = $pdo->prepare('SELECT COUNT(*) c FROM checklists WHERE task_id = :i AND deleted_at IS NULL AND completed = 1');
    $stmt->execute([':i' => $taskId]);
    $chkDone = (int)$stmt->fetch()['c'];

    $total = $subTotal + $chkTotal;
    $done = $subDone + $chkDone;
    $progress = $total > 0 ? (int)round($done * 100 / $total) : 0;

    $pdo->prepare('UPDATE tasks SET progress = :p, updated_at = :u WHERE id = :i')
        ->execute([':p' => $progress, ':u' => date('Y-m-d H:i:s'), ':i' => $taskId]);
}
