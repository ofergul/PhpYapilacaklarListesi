<?php
/**
 * Demo Veri Üretici
 *
 * Veritabanını gerçekçi örnek görevlerle doldurur.
 * - Doğrudan erişim (http://localhost/gorev2/demo.php) ile çalışır
 * - api.php içinden "demo_seed" aksiyonu ile çağrılabilir
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Demo verisini doldurur. $replace = true ise mevcut görevler temizlenir.
 */
function seed_demo_data(bool $replace = false): array
{
    $pdo = db();

    if ($replace) {
        $pdo->exec('DELETE FROM task_tags');
        $pdo->exec('DELETE FROM subtasks');
        $pdo->exec('DELETE FROM checklists');
        $pdo->exec('DELETE FROM reminders');
        $pdo->exec('DELETE FROM recurrence_rules');
        $pdo->exec('DELETE FROM attachments');
        $pdo->exec('DELETE FROM tasks');
    }

    // Klasör ve etiket eşlemeleri
    $folderMap = [];
    foreach ($pdo->query('SELECT * FROM folders WHERE deleted_at IS NULL')->fetchAll() as $f) {
        $folderMap[$f['name']] = (int)$f['id'];
    }
    $tagMap = [];
    foreach ($pdo->query('SELECT * FROM tags WHERE deleted_at IS NULL')->fetchAll() as $t) {
        $tagMap[$t['name']] = (int)$t['id'];
    }

    $count = 0;
    $ins = $pdo->prepare(
        'INSERT INTO tasks
         (title, description, notes, start_date, due_date, due_time, priority, status, progress,
          estimated_time, actual_time, color, emoji, icon, location, folder_id,
          is_favorite, is_pinned, completed_at, created_at, updated_at)
         VALUES
         (:title, :description, :notes, :start_date, :due_date, :due_time, :priority, :status, :progress,
          :estimated_time, :actual_time, :color, :emoji, :icon, :location, :folder_id,
          :is_favorite, :is_pinned, :completed_at, :created_at, :updated_at)'
    );

    $insTag     = $pdo->prepare('INSERT OR IGNORE INTO task_tags (task_id, tag_id) VALUES (:t, :g)');
    $insSub     = $pdo->prepare('INSERT INTO subtasks (task_id, title, completed, sort_order) VALUES (:t, :tt, :c, :s)');
    $insChk     = $pdo->prepare('INSERT INTO checklists (task_id, title, completed, sort_order) VALUES (:t, :tt, :c, :s)');
    $insRem     = $pdo->prepare('INSERT INTO reminders (task_id, remind_at, remind_type, sound) VALUES (:t, :r, :ty, :s)');
    $insRec     = $pdo->prepare('INSERT INTO recurrence_rules (task_id, freq, `interval`, by_day, by_month_day, by_month, custom_cron, ends_on) VALUES (:t, :f, :i, :d, :md, :m, :c, :e)');

    $now = date('Y-m-d H:i:s');

    /**
     * Görev ekleme yardımcıcısı.
     * $spec: title, description, notes, due (gün farkı), due_time, priority, status, progress,
     *        folder, tags[], emoji, icon, color, location, start, est, act, fav, pin,
     *        completed_gün, created_gün, subtasks[[title, completed]], checklists[],
     *        reminder[days+time], recurrence[freq, interval, by_day...]
     */
    $task = function (array $spec) use ($pdo, $ins, $insTag, $insSub, $insChk, $insRem, $insRec, $folderMap, $tagMap, $now): int {
        $dueOffset = $spec['due'] ?? null;
        $due = $dueOffset !== null
            ? date('Y-m-d', strtotime(($dueOffset >= 0 ? '+' : '') . $dueOffset . ' days'))
            : null;

        $completed = $spec['completed_gün'] ?? null;
        $completedAt = $completed !== null
            ? date('Y-m-d 09:00:00', strtotime(($completed >= 0 ? '+' : '') . $completed . ' days'))
            : null;

        $createdOffset = $spec['created_gün'] ?? ($completed !== null ? $completed : -14);
        $createdAt = date('Y-m-d H:i:s', strtotime(($createdOffset >= 0 ? '+' : '') . $createdOffset . ' days'));

        $ins->execute([
            ':title'        => $spec['title'],
            ':description'  => $spec['description'] ?? '',
            ':notes'        => $spec['notes'] ?? '',
            ':start_date'   => isset($spec['start']) ? date('Y-m-d', strtotime(($spec['start'] >= 0 ? '+' : '') . $spec['start'] . ' days')) : null,
            ':due_date'     => $due,
            ':due_time'     => $spec['due_time'] ?? null,
            ':priority'     => $spec['priority'] ?? 1,
            ':status'       => $spec['status'] ?? ($completed !== null ? 3 : 0),
            ':progress'     => $spec['progress'] ?? 0,
            ':estimated_time' => $spec['est'] ?? null,
            ':actual_time'  => $spec['act'] ?? 0,
            ':color'        => $spec['color'] ?? '#0A84FF',
            ':emoji'        => $spec['emoji'] ?? '',
            ':icon'         => $spec['icon'] ?? 'check_circle',
            ':location'     => $spec['location'] ?? '',
            ':folder_id'    => isset($spec['folder'], $folderMap[$spec['folder']]) ? $folderMap[$spec['folder']] : null,
            ':is_favorite'  => (int)($spec['fav'] ?? 0),
            ':is_pinned'    => (int)($spec['pin'] ?? 0),
            ':completed_at' => $completedAt,
            ':created_at'   => $createdAt,
            ':updated_at'   => $now,
        ]);
        $id = (int)$pdo->lastInsertId();

        foreach ($spec['tags'] ?? [] as $tname) {
            if (isset($tagMap[$tname])) {
                $insTag->execute([':t' => $id, ':g' => $tagMap[$tname]]);
            }
        }
        foreach ($spec['subtasks'] ?? [] as $i => $sub) {
            $insSub->execute([':t' => $id, ':tt' => is_array($sub) ? $sub[0] : $sub, ':c' => (int)(is_array($sub) ? $sub[1] : 0), ':s' => $i]);
        }
        foreach ($spec['checklists'] ?? [] as $i => $chk) {
            $insChk->execute([':t' => $id, ':tt' => is_array($chk) ? $chk[0] : $chk, ':c' => (int)(is_array($chk) ? $chk[1] : 0), ':s' => $i]);
        }
        $GLOBALS['__seed_count'] = ($GLOBALS['__seed_count'] ?? 0) + 1;
        if (!empty($spec['reminder'])) {
            $r = $spec['reminder'];
            $remindAt = date('Y-m-d H:i:s', strtotime((isset($r['day']) && $r['day'] >= 0 ? '+' : '') . ($r['day'] ?? 0) . ' days' . (isset($r['time']) ? ' ' . $r['time'] : ' 09:00')));
            $insRem->execute([':t' => $id, ':r' => $remindAt, ':ty' => 'notification', ':s' => 1]);
        }
        if (!empty($spec['recurrence'])) {
            $rec = $spec['recurrence'];
            $insRec->execute([
                ':t' => $id, ':f' => $rec['freq'], ':i' => $rec['interval'] ?? 1,
                ':d' => $rec['by_day'] ?? '', ':md' => $rec['by_month_day'] ?? '',
                ':m' => $rec['by_month'] ?? '', ':c' => $rec['custom_cron'] ?? '',
                ':e' => $rec['ends_on'] ?? null,
            ]);
        }
        return $id;
    };

    /* ---------- Görevler ---------- */

    // Bugün
    $task([
        'title' => 'Mağazadan süt ve ekmek al', 'emoji' => '🛒', 'color' => '#30D158',
        'folder' => 'Ev', 'due' => 0, 'due_time' => '18:00',
        'checklists' => [['Süt', 0], ['Ekmek', 0], ['Yumurta', 0], ['Kahve', 1]],
        'reminder' => ['day' => 0, 'time' => '16:00'],
    ]);
    $task([
        'title' => 'Ekip toplantısı', 'emoji' => '📅', 'color' => '#0A84FF',
        'folder' => 'İş', 'due' => 0, 'due_time' => '14:00', 'priority' => 2, 'status' => 1, 'progress' => 40,
        'tags' => ['toplantı'], 'description' => 'Haftalık sprint planlama ve görev dağılımı.',
        'notes' => "## Gündem\n\n- [x] Sprint hedefleri\n- [ ] Engelleri tartış\n- [ ] Tahminler (estimation)",
        'subtasks' => [['Sunumu hazırla', 1], ['Gündemi paylaş', 1], ['Kararları not et', 0]],
        'est' => 60, 'reminder' => ['day' => 0, 'time' => '13:30'],
    ]);
    $task([
        'title' => 'Sabah yürüyüşü', 'emoji' => '🚶', 'color' => '#30D158',
        'folder' => 'Kişisel', 'due' => 0, 'due_time' => '07:30', 'completed_gün' => 0,
        'act' => 25,
    ]);

    // Yarın
    $task([
        'title' => 'Aylık raporu müşteriye gönder', 'emoji' => '📊', 'color' => '#FF453A',
        'folder' => 'İş', 'due' => 1, 'due_time' => '09:30', 'priority' => 3,
        'tags' => ['mail', 'acil'], 'description' => 'Q3 raporu, teslim tarihi yarın sabah.',
        'subtasks' => [['Raporu finalleştir', 0], ['PDF\'e dönüştür', 1], ['Müşteri listesini doğrula', 1]],
        'est' => 90, 'reminder' => ['day' => 1, 'time' => '08:00'],
    ]);
    $task([
        'title' => 'Dişçi randevusu', 'emoji' => '🦷', 'color' => '#64D2FF',
        'folder' => 'Kişisel', 'due' => 1, 'due_time' => '11:00', 'location' => 'Merkez Diş Kliniği',
        'tags' => ['telefon'],
    ]);
    $task([
        'title' => 'Maaş beklentisi görüşmesi', 'emoji' => '💬', 'color' => '#5E5CE6',
        'folder' => 'Kişisel', 'due' => 1, 'due_time' => '16:00', 'priority' => 2, 'status' => 1,
        'tags' => ['telefon'], 'description' => 'Yeni teklif hakkında görüşme yapılacak.',
    ]);

    // Bu hafta
    $task([
        'title' => 'Sunum taslaklarını hazırla', 'emoji' => '🎤', 'color' => '#BF5AF2',
        'folder' => 'Yazılım', 'due' => 3, 'priority' => 2, 'status' => 1, 'progress' => 55,
        'notes' => "Konu: **Mimari yenileme**. Şablonlar: `speakerdeck`.",
        'subtasks' => [['Ana hat', 1], ['Slayt taslağı', 1], ['Demo video', 0], ['Provası', 0]],
        'checklists' => [['Giriş slaytı', 1], ['Veri grafiği', 0], ['Kapanış', 0]],
        'est' => 180, 'act' => 100,
    ]);
    $task([
        'title' => 'Faturaları öde', 'emoji' => '🧾', 'color' => '#FFD60A',
        'folder' => 'Finans', 'due' => 4, 'priority' => 2,
        'tags' => ['fatura', 'ödeme'], 'description' => 'Elektrik, internet ve su faturaları.',
        'subtasks' => [['Elektrik', 0], ['İnternet', 1], ['Su', 0], ['Doğalgaz', 0]],
        'reminder' => ['day' => 4, 'time' => '12:00'],
    ]);
    $task([
        'title' => 'Kitabın son bölümünü bitir', 'emoji' => '📖', 'color' => '#FF6482',
        'folder' => 'Okuma', 'due' => 5, 'progress' => 60, 'tags' => ['bekliyor'],
        'notes' => '**Sistem Tasarımı** — bölüm 12. Sayfa 310.',
    ]);
    $task([
        'title' => 'Mobilya araştırması yap', 'emoji' => '🛋️', 'color' => '#A2845E',
        'folder' => 'İnşaat', 'due' => 7, 'status' => 2, 'priority' => 1,
        'description' => 'Salon için koltuk takımı fiyat karşılaştırması.',
    ]);
    $task([
        'title' => 'Kuru temizlemeciye takım elbise', 'emoji' => '👔', 'color' => '#0A84FF',
        'due' => 2, 'location' => 'Merkez', 'tags' => ['telefon'],
    ]);
    $task([
        'title' => 'Haftalık planlama', 'emoji' => '🗓️', 'color' => '#30D158',
        'folder' => 'İş', 'due' => 0, 'due_time' => '09:00', 'priority' => 1,
        'recurrence' => ['freq' => 'weekly', 'interval' => 1],
    ]);

    // Gelecek
    $task([
        'title' => 'Uygulama v1.0 lansman planı', 'emoji' => '🚀', 'color' => '#5E5CE6',
        'folder' => 'Projeler', 'due' => 20, 'priority' => 2, 'progress' => 25,
        'notes' => "Lansman checklist'i ve geri sayım takvimi oluşturulacak.",
        'subtasks' => [['Beta test', 0], ['Çıkış notları', 0], ['Basın bülteni', 0]],
        'recurrence' => ['freq' => 'monthly', 'interval' => 1],
    ]);
    $task([
        'title' => 'Pasaport başvurusu', 'emoji' => '🛂', 'color' => '#FF453A',
        'due' => 10, 'priority' => 3, 'fav' => 1, 'pin' => 1,
        'tags' => ['acil'], 'description' => 'Randevu alındı, evraklar hazırlanmalı.',
    ]);
    $task([
        'title' => 'Kiranın ödendiğini kontrol et', 'emoji' => '🏠', 'color' => '#FFD60A',
        'folder' => 'Finans', 'due' => 5, 'priority' => 2,
        'tags' => ['ödeme'], 'recurrence' => ['freq' => 'monthly', 'interval' => 1, 'by_month_day' => '1'],
    ]);
    $task([
        'title' => 'Diş fırçası setini yenile', 'emoji' => '🪥', 'color' => '#64D2FF',
        'folder' => 'Ev', 'due' => 60, 'recurrence' => ['freq' => 'quarterly', 'interval' => 1],
    ]);

    // Geciken
    $task([
        'title' => 'Sigorta poliçesini yenile', 'emoji' => '🛡️', 'color' => '#FF453A',
        'folder' => 'Sigorta', 'due' => -3, 'priority' => 2,
        'tags' => ['acil'], 'description' => 'Poliçe sona erdi, yenilenmedi!',
        'reminder' => ['day' => 0, 'time' => '10:00'],
    ]);
    $task([
        'title' => 'Elektrik faturası yatır', 'emoji' => '💡', 'color' => '#FF9F0A',
        'folder' => 'Finans', 'due' => -1, 'priority' => 2, 'tags' => ['fatura'],
    ]);
    $task([
        'title' => 'Kütüphaneye kitap iade et', 'emoji' => '🏛️', 'color' => '#FF6482',
        'folder' => 'Okuma', 'due' => -2, 'tags' => ['bekliyor'],
    ]);

    // Tamamlananlar
    $task([
        'title' => 'Haftalık rapor', 'emoji' => '📄', 'color' => '#0A84FF',
        'folder' => 'İş', 'due' => -1, 'completed_gün' => -1, 'tags' => ['mail'],
        'est' => 45, 'act' => 40,
    ]);
    $task([
        'title' => 'Ev temizliği', 'emoji' => '🧹', 'color' => '#30D158',
        'folder' => 'Ev', 'due' => -2, 'completed_gün' => -2, 'act' => 90,
    ]);
    $task([
        'title' => 'GitHub PR incele', 'emoji' => '🔀', 'color' => '#5E5CE6',
        'folder' => 'Yazılım', 'due' => -3, 'completed_gün' => -3, 'tags' => ['müşteri'],
    ]);
    $task([
        'title' => 'Gym üyeliğini yenile', 'emoji' => '💪', 'color' => '#BF5AF2',
        'folder' => 'Kişisel', 'due' => -5, 'completed_gün' => -5,
        'subtasks' => [['Fiyatları araştır', 1], ['Kayıt ol', 1]],
    ]);
    $task([
        'title' => 'Kartvizit tasarımını onayla', 'emoji' => '🪪', 'color' => '#FF9F0A',
        'folder' => 'İş', 'due' => -8, 'completed_gün' => -8, 'tags' => ['müşteri'],
    ]);

    // Inbox
    $task([
        'title' => 'Fikirleri not et', 'emoji' => '💡', 'color' => '#FFD60A',
        'tags' => ['bekliyor'], 'description' => 'Uygulama için yeni özellik fikirleri.',
        'notes' => "- Karanlık mod\n- Sesli notlar\n- Widget desteği",
    ]);
    $task([
        'title' => 'Müşteriden dönüş bekle', 'emoji' => '⏳', 'color' => '#98989D',
        'priority' => 1, 'status' => 2, 'tags' => ['bekliyor', 'müşteri'],
    ]);
    $task([
        'title' => 'Yıllık hedefleri gözden geçir', 'emoji' => '🎯', 'color' => '#BF5AF2',
        'priority' => 1, 'tags' => ['toplantı'], 'est' => 60,
    ]);

    $count += ($GLOBALS['__seed_count'] ?? 0);
    unset($GLOBALS['__seed_count']);
    activity_log(null, 'import', 'Demo verisi yüklendi (' . $count . ' görev)');
    return ['tasks' => $count];
}

/* Doğrudan erişimde çalıştır */
$isCli = (PHP_SAPI === 'cli');
$isHttp = (PHP_SAPI !== 'cli') && (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'demo.php');

if ($isCli || $isHttp) {
    $replace = isset($_GET['replace']) ? (bool)$_GET['replace'] : false;
    $result = seed_demo_data($replace);
    if ($isCli) {
        echo "Demo verisi yüklendi: " . $result['tasks'] . " görev" . PHP_EOL;
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Demo verisi yüklendi: " . $result['tasks'] . " görev";
    }
}
