# PROJECT_SPEC.md — Görev Yöneticisi Proje Spesifikasyonu

> Bu dosya, projeyi sıfırdan birebir yeniden üretmek için gereken tüm teknik
> ve tasarım detaylarını içerir. Bir yapay zekaya "bu projeyi aynen yap" demek
> için bu dosya yeterlidir.

---

## 1. GENEL AMAÇ

Modern, hızlı, sade ve **Apple Human Interface Guidelines** yaklaşımına benzeyen,
tamamen **lokal** çalışan (localhost), **kullanıcı hesabı gerektirmeyen** bir görev
yönetim sistemi. Kullanıcı Unix-beğeni bir tasarım, glassmorphism (cam) efekti,
koyu/gündüz tema ve akıcı animasyonlarla görevlerini yönetir.

Amaç: Notion, Things 3, Apple Reminders ve Todoist'in sade kullanım mantığını
taklit etmek. Sistem derleme aşaması olmadan (no build tool) doğrudan çalışır.

### Temel ilkeler
- **Lokal & hesapsız:** Veri tek bir SQLite dosyasında (`database.sqlite`) cihazda saklanır.
- **Derlemesiz:** Node.js, Composer, paket yöneticisi, framework gerektirmez. CDN ile kütüphaneler yüklenir.
- **Apple estetiği:** Minimal, bol boşluk, yuvarlatılmış kartlar, blur/şeffaflık (backdrop-filter), yumuşak animasyonlar.
- **Tema:** Sistem temasını algılar; kullanıcı manually gündüz/koyu/sistem seçebilir.
- **Mobil öncelikli:** iPhone'da native uygulama hissi; masaüstünde içerik 1400px ile sınırlı.

---

## 2. TEKNOLOJİLER (Özet)

- **Backend:** PHP 8.2+, PDO (SQLite), seskey PDO prepared statements, transaction.
- **Frontend:** HTML5, TailwindCSS (CDN), Alpine.js (3.x), Chart.js (4.x), Flatpickr (4.x), SortableJS (1.x), Google Material Symbols Rounded, Inter font.
- **Veritabanı:** SQLite (WAL modu), 12 normalize tablo.
- **Güvenlik:** PDO prepared statements, XSS çıkış kaçırma, CSRF token (oturum bazlı), input validation.

---

## 3. DOSYA YAPISI

```
/
├── index.php          → SPA kabuğu (tüm görünümler, modallar, Alpine şablonları)
├── api.php            → JSON API (tüm BE işlemleri, yönlendirici)
├── config.php         → DB bağlantısı, şema boot, yardımcılar, CSRF, yedek
├── demo.php           → Demo veri üretici (seed_demo_data fonksiyonu + CLİ/HTTP girişi)
├── database.sqlite    → otomatik oluşturulur
├── README.md
├── assets/
│   ├── css/app.css    → Apple tarzı tasarım sistemi (değişkenler, glass, animasyonlar)
│   ├── js/app.js      → Alpine.js uygulaması (tüm mantık, grafikler, doğal dil)
│   └── icons/icon.svg → Uygulama ikonu
├── uploads/           → dosya ekleri (otomatik oluşur)
└── backup/            → yedekler (otomatik oluşur)
```

---

## 4. VERİTABANI ŞEMASI

Tüm tablolar `config.php` içindeki `DB_SCHEMA` sabitinde tanımlı, ilk açılışta
`init_schema()` ile otomatik kurulur. Ayrıntılı blok için TECH_STACK.md'ye bakın.

12 tablo: `folders`, `tags`, `tasks`, `subtasks`, `checklists`, `task_tags`,
`attachments`, `reminders`, `recurrence_rules`, `settings`, `activity_logs`, `backups`.

### Kilit tanımlar ve kurallar
- **tasks.priority:** 0=Düşük, 1=Normal, 2=Yüksek, 3=Kritik (INT).
- **tasks.status:** 0=Bekliyor, 1=Devam Ediyor, 2=Askıda, 3=Tamamlandı, 4=İptal (INT).
- **tasks.progress:** 0–100 INT. Alt görev + checklist tamamlanma oranından otomatik hesaplanır (`refresh_task_progress`).
- **Soft delete:** Tüm tablolarda `deleted_at` alanı. Görev çöp kutusu soft-delete; "kalıcı sil" (hard delete) çöp kutusundan yapılır.
- **Arşiv:** tasks.`archived_at` NULL değilse arşivlenmiştir.
- **Completed_at:** status=3 olunca `datetime('now','localtime')` yazılır; geri alınınca NULL.
- **Tekrar:** tasks tamamlanınca `handle_recurrence()` bir sonraki kaydı türetir; eski görev geçmişte kalır. `recurrence_rules` kuralı yeni göreve taşınır.

### İlk veri (seed) — `init_schema()` içinde
- **9 varsayılan klasör:** İş, Kişisel, Ev, Yazılım, Okuma, Projeler, Finans, Sigorta, İnşaat. Her birinde ad, icon (Material Symbol adı), renk, açıklama, sort_order.
- **8 varsayılan etiket:** acil, telefon, mail, toplantı, bekliyor, müşteri, fatura, ödeme. Her birinde ad, renk, emoji.
- `settings.schema_version = 1` ve `installed_at` yazılır. Bu kayıt varsa tekrar seed yapılmaz.

---

## 5. İŞ MANTIĞI (BUSINESS LOGIC) — DETAY

### 5.1 Görev (task_save)
- Zorunlu: `title` boş olamaz.
- Alanlar: title, description, notes, start_date, due_date, due_time, priority, status, progress, estimated_time, actual_time, color, emoji, icon, location, folder_id, is_favorite, is_pinned.
- `folder_id` boş/null ise görev "Inbox" sayılır (klasörsüz).
- **Etiketler:** `tags` dizisi varsa `task_tags` yeniden yazılır (DELETE + INSERT).
- **Tekrar:** `recurrence` objesi varsa kural güncellenir/oluşturulur.
- **Hatırlatıcılar:** `reminders` dizisi varsa yeniden yazılır (`remind_at` `'Y-m-d H:i:s'`).
- status=3 olduğunda `completed_at` set edilir ve `handle_recurrence()` çalışır; status≠3 ise `completed_at=NULL`.
- Başarıda `activity_log` kaydı eklenir, zenginleştirilmiş görev döner.

### 5.2 Tamamlama / Geri Alma (task_toggle)
- status=3 → status=0, completed_at=NULL, progress değişmez (ama progress 100 bırakılır).
- status≠3 → status=3, progress=100, completed_at=now, `handle_recurrence()`.
- Her ikisinde activity_log + enrich edilmiş görev.

### 5.3 Hızlı Ekle (task_quick)
- Sadece title + opsiyonel due_date/due_time/priority/folder_id ile satır ekler.

### 5.4 Silme zinciri
- **task_delete:** soft-delete (`deleted_at`).
- **task_restore:** `deleted_at=NULL`.
- **task_destroy:** hard-delete; önce ilişkili dosyaları diskten siler, sonra satırı siler.
- Klasör silindiğinde (`folder_delete`) görevlerin `folder_id=NULL` olur (Inbox'a düşer).
- Etiket silindiğinde (`tag_delete`) `tags`+'task_tags' satırları silinir.

### 5.5 İlerleme Hesabı (refresh_task_progress)
`progress = round( (tamamlananSub + tamamlananChk) * 100 / (toplamSub + toplamChk) )`.
Alt görev/checklist ekleme, silme, toggle sonrası çağrılır. Liste yoksa 0.

### 5.6 Tekrar Kuralı (recurrence_rules)
`freq` değerleri: `none`, `daily`, `weekdays`, `weekly`, `biweekly`, `monthly`,
`quarterly`, `semiannual`, `yearly`, `custom`.
- `interval`: 1+ (haftalık/aylık gibi aralıklarda çarpan).
- `by_day`: virgülle ayrılmış `MON,TUE,...` (custom).
- `by_month_day`: virgülle ayrılmış `1,15` (custom).
- `by_month`: virgülle ayrılmış `3,9` (custom).
- `custom_cron`: serbest metin (belgesel, yürütülmez).
- `ends_on`: bitiş tarihi (geçilirse yeni kayıt oluşturulmaz).

**Sıradaki tarihi bulma (next_occurrence):**
- daily: +interval gün
- weekdays: gelecek hafta içi gününe atla (Cum/Çar gibi hafta sonu atlanır)
- weekly: +interval hafta
- biweekly: +interval*2 hafta
- monthly: +interval ay, ay sonu taşması güvenli (31→30/28)
- quarterly/semiannual/yearly: +3/6/12*interval ay
- custom: günde ilerleyerek by_month ∩ (by_day veya "son X") ∩ by_month_day eşleşme arar (400 deneme limiti)

**Oluşturma (handle_recurrence):**
- Kuralı kopyalamadan önce dayanak `due_date` (yoksa bugün) kullanılır.
- Yeni görev: status=0, progress=0, due_date=sıradaki, checklist/alt görevler (üst seviye) kopyalanır, etiketler kopyalanır, recurrence kuralı **eski→yeni** taşınır.

### 5.7 İstatistik (stats) — Dashboard
Hesaplananlar: total, completed, active(status 0/1/2), overdue, today, tomorrow,
week(+6 gün), month (ay içi), inbox (klasörsüz & aktif), pinned, favorites,
completion_rate (% tamamlanan / toplam), busiest_day (son 90 gün en çok tamamlanan gün),
top_tag, top_folder, upcoming (önümüzdeki 7 gün daily counts), recent_completions (son 14 gün).

### 5.8 Raporlar (reports)
- `byDay`: tüm tamamlama günleri (date,c) — tamamlama grafiği.
- `weekly` (son `weeks` hafta, `%Y-%W`), `monthly` (son 11 ay `%Y-%m`).
- `byFolder`, `byTag` (ilk 12), `byPriority` (counts), `byStatus` (counts).

### 5.9 Görev listesi (tasks + task_query)
Görünüme göre filtre: `view` param: `inbox`, `today`, `tomorrow`, `planned`
(due_date dolu), `week` (+6 gün), `month` (ay içi), `overdue`, `completed`,
`archive` (archived_at), `trash` (deleted_at≠NULL), `all` (varsayılan; deleted_at NULL).
Ek filtreler: `folder_id` ("none"=klasörsüz), `tag_id`, `priority`, `status`, `recurring`,
`date_from`, `date_to`, `search` (title/desc/notes/location/tag adı/dosya adı).
Sıralama (`sort`): `default` (sort_order, due_date, id), `title`, `created`, `due`, `priority`.
Sayfalama: `page`, `limit` (10–500, varsayılan 100). `total` döner, `total` karşılığı `hasMore` JS'de.

### 5.10 Toplu İşlem (bulk) — `ids[]` + `op`
- `delete`,`restore`,`archive`,`destroy`,`move_folder`,`add_tags`(+tag_ids),
  `status`(+status→3 ise completed_at set), `priority`, `flag`(+col/value).
- Arka planda `IN (...)` güvenli prepared.

### 5.11 Dışa / İçe Aktarım
- **Dışa:** JSON (tüm tablolar), CSV (düz görev düzleşik), Excel (semicolon+UTF-8 BOM), Markdown (klasör başlıkları + checkbox listeleri).
- **İçe:** JSON (klasör/tag eşleşmesi, görev+alt görev+checklist; `field_map` ile), CSV (basit düz). Transaction içinde çalışır, hata olursa rollback.

### 5.12 Yedekleme
- `backup_create`: `database.sqlite` kopyalanır; ZipArchive varsa ZIP'e konur, yoksa `.sqlite` olarak kaydedilir. `backups` kaydı yazılır.
- `backup_restore`: `.sqlite` veya ZIP içindeki `.sqlite` bulunur, `PRAGMA wal_checkpoint(TRUNCATE)` sonrası dosya değiştirilir. Sağlık için önce ZIP'i geçici klasöre açar.
- `backup_download`, `backup_delete`, `backup_list`.
- `auto_backup()`: index yüklemesinde günde bir kez otomatik kopya alır (`settings.last_auto_backup` 24s kontrol).

### 5.13 Hatırlatıcı Kontrolü (check_reminders)
- `reminders.triggered=0` ve `remind_at <= now` ve görev silinmemiş & tamamlanmamış olanlar döner; ardından `triggered=1` yapılır.

### 5.14 Etkinlik Günlüğü (activity_logs)
- `task_id` (opsiyonel), `action`, `detail`, `now`. Yeni görev/updated/completed/trashed/destroyed/recurrence/klasör/ayarlar/yedek/import kayıtları yazılır.

### 5.15 Demo Verisi (seed_demo_data)
- `replace=false` ise görev eklemeden önce mevcut görevler korunur; `true` ise önce `tasks`+ilişkileri silinir.
- 27 görev: bugün, yarın, hafta içi, gelecek, geciken, tamamlanan; 4 tekrarlı; alt görev, checklist, hatırlatıcı, etiket, klasör, emoji, markdown not, konum, tahmini/gerçek süre, favori, sabitlik karışımı.
- Tarihler bugünün görecelisi ile hesaplanır; tamamlananlar için `completed_at`/`created_at` de geçmiş zaman.
- Sonunda `activity_log('import')` yazılır.

### 5.16 Doğal Dil Ayrıştırıcı (JS: parseNatural) — Hızlı Ekle
- Öncelik anahtar kelimeleri: `kritik|acil|!!!`→3, `yüksek|önemli|!!`→2, `düşük|önemsiz|!`→0; `!` temizlenir.
- Özel günler: `öbür gün`(+2), `yarın`(+1), `bugün`(0), `hafta sonuna`(+5), `haftaya`(+7).
- Tarih: `15 mart` biçimi (Türkçe ay adlarını çözümler; geçtiyse yıl +1). Gün adları: `pazartesi..pazar` (gelecek tekrar; bugünse haftaya atlar).
- Saat: `15:30` / `15.30` ya da `saat 15` → `HH:MM`.
- `#ad`: önce klasör adına eşleşirse klasör, sonra etiket adına eşleşirse etiket; metinden çıkarılır.
- Sonuç: `{ title, date, time, priority, tags[], folder }`; `quickParsed` olarak saklanır.

---

## 6. EKRANLAR / GÖRÜNÜMLER (View'ler)

Tüm görünümler tek `index.php` içinde Alpine.js `x-show` ile açılıp kapanır; `view` state'i ile geçiş yapılır. `navMain`, `navTools` array'leri sidebar menüyü üretir.

### 6.1 Sidebar (sol menü)
- **Ana:** Genel Bakış (dashboard), Inbox, Bugün, Planlanan, Bu Hafta, Tamamlandı, Takvim, Tüm Görevler.
- **Klasörler bölümü:** klasör listesi (+görev sayısı), hover'da ✏️ Düzenle, sağ tık menüsü, altta "Yeni Klasör".
- **Etiketler bölümü:** etiket listesi (+görev sayısı), hover'da ✏️, sağ tık menüsü, altta "Yeni Etiket".
- **Araçlar:** Kanban, Raporlar, Arşiv, Çöp Kutusu, Ayarlar.
- Alt kısım: Tema döngüsü (sistem/gündüz/koyu) + Kısayollar butonu.
- Aktif öğe `nav-item.active` rengiyle vurgulanır; mobilde çekmece (slide-in) + karartma.

### 6.2 Üst Çubuk (topbar)
- Sol: mobil menü butonu, dashboard butonu, görünüm başlığı + alt başlık.
- Sağ: canlı arama kutusu (focus'ta genişler), Pomodoro butonu, "Yeni Görev" butonu (quick add açar).

### 6.3 Dashboard (Genel Bakış)
- Selamlama + tarih (saate göre "Günaydın/İyi günler/İyi akşamlar/İyi geceler").
- 4 istatistik kartı: Bugün, Bu Hafta, Aktif Görev, Geciken (tıklanınca ilgili görünüm).
- İlerleme halkası (completion_rate) + tamamlanan/toplam/inbox.
- "Yaklaşan Görevler" listesi (7 gün içi, renk noktası + öncelik).
- "Son 14 Gün Tamamlama" çizgi grafiği (Chart.js), en yoğun gün + top etiket çipi.
- "Bugün" alt listesi (checkbox + görev + sil) ve "Son Etkinlikler" listesi.

### 6.4 Görev Listesi (ortak; inbox/today/planned/week/completed/all/archive/folders/tags)
- `list-hero` (yalnız folders/tags): ikon, ad, açıklama + **Düzenle/Sil** butonları.
- `list-toolbar`: öncelik filtre çipleri (Tüm/Kritik/Yüksek/Normal/Düşük) — normal mod;
  seçim modunda toplu işlem çipleri (Tamamla/Sil/Arşivle/Klasöre Taşı/Etiket Ekle/Öncelik/İptal + seçim adedi).
- Sağ: sıralama (Varsayılan/Başlık/Oluşturma/Bitiş/Öncelik) ve durum filtresi dropdown.
- Liste başlığı: "N görev" + toplu seçim çipi.
- Satır: checkbox (tamamla), başlık + emoji/⭐/📌/tekrar/not ikonları, öncelik noktası,
  bitiş çipi (gecikmişse kırmızı), etiket çipleri (ilk 3), klasör adı, mini ilerleme, %100,
  satır eylemleri (favori/sabitle/sil).
- Click → görev modalı; seçim modunda click → seçim; sağ tık → bağlam menüsü.
- Infinite scroll: "Daha fazla göster" + `hasMore`.

### 6.5 Takvim
- Üst: gezinme (önceki/başlık/sonraki, Bugün) + görünüm seçici (Ay/Hafta/Gün).
- **Ay:** 42 hücrelik ızgara (7×6), diğer ay soluk, bugün vurgulu, seçili gün çerçeveli; hücrede görev çipleri (renkli), +N daha, hücre üzerine görev bırak → tarih atanır.
- **Hafta:** 7 sütun, gün adı + görev çipleri, bırakma alanı.
- **Gün:** seçili tarihin görev listesi (checkbox, saat).
- Görevler `calTasks` (ay aralığı için 500 limit Al ALL) içinden `tasksOn(date)` ile filtrelenir.

### 6.6 Kanban
- 4 sütun: Bekliyor, Devam Ediyor, Askıda, Tamamlandı (renk kodlu başlık + sayaç).
- Kartlar SortableJS ile sürüklenir; bırakınca `task_save` ile `status` güncellenir, teslimat listeyi günceller.
- Kart: ikon, başlık, bitiş çipi, öncelik noktası, mini ilerleme.

### 6.7 Raporlar
- Aralık seçici: 3 ay/6 ay/1 yıl (`reportWeeks` 12/26/52).
- 6 panel: Tamamlanma (çizgi), Haftalık Üretkenlik (çubuk), Aylık Üretkenlik (çubuk),
  Kategori Dağılımı (donut), Etiket Dağılımı (donut), Öncelik & Durum (çubuk + donut).

### 6.8 Ayarlar
- **Görünüm:** tema (Sistem/Gündüz/Koyu), vurgu rengi seçici (10 renk noktası, aktif ✓).
- **Bildirimler:** tarayıcı bildirim switch, ses switch, "Bildirim İznini İste" butonu.
- **Pomodoro:** odak ve mola süresi (dk).
- **Veri:** dışa aktarım (JSON/CSV/Excel/Markdown), içe aktar (modal), "Yedek Oluştur",
  demo veri bölümü ("Demo Verisi Yükle" butonu), yedek listesi (geri yükle/indir/sil).
- **Uygulama:** bilgi + Kısayollar + Geri Bildirim.

### 6.9 Çöp Kutusu
- Açıklama satırı + "Çöp Kutusunu Boşalt".
- Satır: başlık, silinme zamanı, "Geri al" (restore), "Kalıcı sil" (destroy).

### 6.10 Modallar (teleport to body)
1. **Görev modalı:** başlık (satır içi), sekme "checkbox" ile tamamla, kapat.
   - Sekmeler: Detaylar, Alt Görevler (`x/y`), Checklist (`x/y`), Dosyalar (N), Tekrarlama.
   - **Detaylar:** başlangıç/bitiş/bitiş saati (flatpickr), konum; öncelik (4 buton), durum (5 buton),
     ilerleme slider'ı; tahmini/gerçek süre; klasör dropdown; etiket çipleri (seçilebilir);
     görünüm (emoji, renk input, ikon adı, 🎲 rastgele); açıklama textarea; markdown not textarea + canlı önizleme; meta (oluşturma/tamamlama zamanı).
   - **Alt Görevler:** ilerleme barı, ekleme girheli + buton, satır (onay kutusu, satır içi yeniden ad, sil).
   - **Checklist:** ilerleme barı, ekleme, satır (onay kutusu, sil).
   - **Dosyalar:** drop-zone (tıkla/sürükle, 25MB, çoklu), dosya listesi (indir/sil).
   - **Tekrarlama:** frekans çipleri, özel günler (hafta/gün/ay), aralık & bitiş, önizleme çipleri.
   - Alt kısım: "İptal" + hatırlatma switch + "Kaydet" (Ctrl+S).
2. **Hızlı ekle (quick add):** doğal dil girdi + ipucu çipleri + parse önizlemesi, "Ekle".
3. **Klasör modalı:** ad, açıklama, ikon seçici, renk seçici, (id varsa) Sil, İptal, Kaydet.
4. **Etiket modalı:** ad, emoji, renk seçici, (id varsa) Sil, İptal, Kaydet.
5. **İçe aktar modalı:** tür seçimi (JSON/CSV), textarea, dosya seç, Aktar.
6. **Pomodoro modalı:** halkalı sayaç (SVG), başlat/duraklat, sıfırla, atla, kapat.
7. **Kısayollar modalı:** tuş+eylem listesi.
8. **Onay modalı:** başlık, mesaj, Vazgeç/Onayla (genel amaçlı `confirm`).

### 6.11 Ek Üst Katmanlar (overlay)
- **Bağlam menüsü (sağ tık):** görev → Tamamla/Favori/Sabitle/Klasöre Taşı/Arşivle/Sil;
  klasör → Yeniden Adlandır / Bu klasöre görev ekle / Sil; etiket → aynı mantık.
- **Toast yığını:** sağ alt-orta, tip ikonları (success/error/info), otomatik 4.2s.

---

## 7. KULLANICI AKIŞLARI

### 7.1 İlk Açılış
1. `index.php` → `config.php` → `init_schema()` (tablolar + seed) → `auto_backup()` (ilk gün).
2. `boot-data` JSON'dan csrf + ayarlar okunur; tema/accent uygulanır.
3. Alpine `init()`: klasör, etiket, istatistik, yedek, etkinlik yüklenir; görev listesi yüklenir; takvim/kam-board init; pomodoro ve bildirim poll başlar.
4. Dashboard görünür.

### 7.2 Görev Ekleme (3 yol)
- **Yeni Görev butonu / Ctrl+N** → quick add; doğal dil yaz → Ekle → `task_quick` → görev listesi güncellenir.
- Sağ tık klasör → "Bu klasöre görev ekle" → quick add + klasör bağlamı.
- **görev modalı** Detaylar sekmesinden doldur → Kaydet (Ctrl+S) → `task_save`.

### 7.3 Tamamlama
- Liste checkbox / kanban / takvim/satır → `task_toggle`; tekrarlıysa otomatik yeni kayıt.
- Toplu: seçim modu → "Tamamla".

### 7.4 Klasör/Etiket yönetimi
- Sidebar hover ✏️ veya hero "Düzenle" → modal → Kaydet → `folder_save`/`tag_save`.
- Sidebar sağ tık → Sil → onay → `folder_delete`/`tag_delete`.

### 7.5 Alarm
- Tarayıcı geçmişindeki zamanlayıcı 30sn'de bir `check_reminders` çalışır; due varsa bildirim + ses + toast.

### 7.6 Yedekleme
- Ayarlar → Yedek Oluştur → ZIP oluşur; liste + indir/geri yükle.

---

## 8. TASARIM SİSTEMİ (CSS — assets/css/app.css)

### 8.1 Değişkenler (light/dark)
- `--accent` (varsayılan #0A84FF, kullanıcı değiştirebilir), `--bg`, `--surface` (glass), `--surface-strong`, `--surface-inner`, `--text`, `--text-2`, `--border`, `--shadow`, `--shadow-lg`, `--ring`.
- Koyu tema `.dark` sınıfı ile `:root` değişkenleri ezilir.

### 8.2 Bileşen stilleri
- `.glass`, `.glass-strong`, `.glass-inner`, `.glass-card` — backdrop-filter blur + saturate; kartlarda hover shadow büyür.
- `.bg-orbs` + `.orb` animated radial blob'lar (arka plan).
- `.sidebar`, `.main-area` (margin-left:264px), `.content` (max-width:1400px centered).
- `.nav-item` (+ `.active`, `.subtle`, `.group`), `.badge`, `.nav-section-label`.
- `.btn-icon`, `.btn-primary`, `.btn-secondary`, `.chip` (+`.chip-active`), `.tag-chip`, `.prio-dot`, `.prio-btn`, `.status-btn`.
- `.checkbox-apple` (yuvarlak, pop + checkIn animasyonu), `.row-action`.
- `.stat-card`, `.progress-ring` (conic-gradient %), `.card-title`.
- `.task-row` (+`.glass-row`, `.selected`, `.pinned-row`), `.task-title.done` (üstü çizili).
- `.mini-progress`, `.due-chip.overdue`, `.search-box` (focus'ta genişler).
- Takvim: `.cal-cell`, `.cal-day`, `.cal-task`, `.cal-task-slot`, `.cal-day-col`.
- Kanban: `.kanban-wrap`, `.kanban-col`, `.kanban-list` (+drag-over), `.kanban-card` (+dragging).
- Modal: `.modal-backdrop` (blur), `.modal`, `.modal-header`, `.modal-tabs`, `.modal-tab` (+active), `.modal-body` (scroll), `.modal-footer`; giriş animasyonu.
- `.quick-add` (üstten düşüş), `.switch/.slider`, `.accent-dot`, `.drop-zone`, `.toast-stack/.toast`, `.context-menu/.ctx-item`, `.kbd`, `.spinner`, `.prose-markdown`.
- Scrollbar özelleştirme (`.nav-scroll`, `.modal-body`, `.content`).

### 8.3 Animasyonlar
- `@keyframes viewIn` (görünüm girişi: fade+translateY).
- `@keyframes orbFloat` (arka blob'lar, infinite alternate).
- `@keyframes pop`, `@keyframes checkIn` (checkbox).
- `@keyframes fadeIn`, `@keyframes modalIn` (ölçek + kaydırma), `@keyframes quickIn`.
- `@keyframes spin` (spinner), `@keyframes toastIn/toastOut`.
- Transition'lar: `.glass-card` transform/shadow, `.btn-*` scale, `.chip` color, `.modal-tab` etc.

### 8.4 Ses Efektleri (JS tarafında)
- Bildirim sesi WebAudio ile sentezlenir (`nativeBeep`): 880Hz osilatör, 0.15 genlik, 0.7s exponential decay.
- Pomodoro bittiğinde aynı `beep()` kullanılır. Diskte ses dosyası yoktur.

---

## 9. JAVASCRIPT MİMARİSİ (assets/js/app.js)

- `BOOT = JSON.parse(#boot-data)`: csrf + settings.
- `api(url, opts)`: JSON veya FormData; otomatik `X-CSRF-Token`; `content-type`; hata ise throw.
- `esc()`, `renderMarkdown(md)` (güvenli markdown → HTML), `ICON_POOL`, `ACCENTS`, `FOLDER_ICONS`, `TR_MONTHS`, `TR_DAYS`, `ACT_LABELS/ACT_ICONS`.
- `function app()` returns Alpine data objesi (tek kök state).

### State özellikleri
`view`, `sidebarOpen`, `search`, `filters{priority,status,sort}`, `selectMode`, `selection[]`,
`tasks`, `totalTasks`, `page`, `loading`, `loadingMore`, `dashToday`, `calTasks`, `trashTasks`,
`folders`, `tags`, `stats`, `activity`, `reports`, `reportWeeks`, `currentFolder`, `currentTag`,
`backups`, `taskModal`, `modalTab`, `newSubtask`, `newChecklist`, `hasReminder`,
`recurForm`, `recurDates`, `quickOpen`, `quickText`, `quickParsed`, `newQuickInFolder`, `newQuickTag`,
`folderModal`, `tagModal`, `shortcutHelp`, `importOpen`, `importType`, `importText`,
`pomodoroOpen_`, `pomodoro`, `confirmMsg`, `confirmCb`, `contextOpen`, `contextTask`, `contextPos`,
`toasts`, `toastSeq`, `cal{mode,year,month,selected}`, `dragTask`, `kanbanCols`, `settingsForm`,
`themeOverride`, `charts{}`, `flatpickrs[]`, `reminderTimer`, `pomoTimer`, `listSortable`.

### Yöntem (method) grupları
- **init / tema:** init, themeClass (getter), themeLabel/themeIcon (getter), themeSet, themeCycle, applyAccent.
- **navigasyon:** viewTitle/viewSubtitle/greeting/fullDate (getter), goView, goFolder, goTag.
- **veri yükleme:** viewParams, reloadTasks (infinite sayfalama + initListSortable), loadMore, hasMore (getter), loadDashToday, loadTrash, loadFolders, loadTags, loadStats (+dashboard chart), loadActivity, loadBackups, loadReports.
- **listeler:** viewTasks, upcomingTasks, currentFolderName/Desc/Color/Icon, currentTagName/Color/Emoji (getter'lar).
- **filtre/seçim:** setFilter, clearSelection, toggleSelect, toggleSelectAll, bulk (op+extra), bulkDoneMsg, bulkFolder, bulkTag, bulkPriority, askSelect (prompt tabanlı), emptyTrash.
- **görev:** openTask, closeModal, taskTags (getter), toggleTaskTag, saveTask, toggleTask, trashTask, restoreTask, destroyTask, archiveTask, unarchiveTask, setFlag, moveTaskFolder.
- **alt görev/checklist:** subtaskStats/checkStats (getter), addSubtask, toggleSubtask, renameSubtask, delSubtask, addChecklist, toggleChecklist, delChecklist.
- **dosya:** uploadFiles (25MB limit, çoklu), delAttachment.
- **tekrar:** toggleCsv, recurPreview.
- **hızlı ekle:** quickSubmit, quickSubmitParsed, parseNatural.
- **takvim:** todayStr (getter), loadCalendarTasks, calTitle, calCells, weekStart, calWeekDays, toISO, tasksOn, calNav, calToday, calSelectDate, dropOnDate.
- **kanban:** loadKanban, kanbanCount, kanbanTasks, initKanbanSortable, kanbanMove.
- **sıralama:** initListSortable.
- **rapor/adapter:** chartBase, ensureChart, renderDashboardChart, renderReportCharts.
- **içe/dışa:** exportData, importFile, importSubmit.
- **yedek:** backupCreate, backupDelete, backupDownload, confirmBackupRestore.
- **ayar:** saveSettings, requestNotifyPerm.
- **bildirim:** startReminderPolling, checkReminders, notify, beep.
- **pomodoro:** pomodoroOpen/Close/Toggle/Reset/Skip, initPomodoroTimer, pomodoroDisplay/circum/offset (getter).
- **onay/menü/toast:** openContext, ctxEdit, ctxDelete, ctxAddTask, ctxMoveFolder, confirm, confirmCancel, confirmOk, confirmDeleteFolder, confirmDeleteTag, toast, removeToast, openContext (task).
- **tarih/biçim:** humanDate, timeAgo, dueLabel, isOverdue, fmtSize, actLabel, actIcon, prioColor, pickRandomIcon, openFeedback.
- **klasör/etiket CRUD:** currentFolderObj, currentTagObj, openFolderContext, openTagContext, deleteFolderById, deleteTagById, confirmDeleteFolderById, confirmDeleteTagById, confirmDemoSeed, folderModalOpen, saveFolder, deleteFolder, tagModalOpen, saveTag, deleteTag.
- **flatpickr:** initModalDatepickers, destroyDatepickers.
- **kısayollar:** bindShortcuts.

### Klavye Kısayolları
| Tuş | Eylem |
|---|---|
| Ctrl+N | quick add aç |
| Ctrl+F | aramayı odakla |
| Ctrl+S | modal Detaylar açıkken kaydet |
| Esc | modal/quick/kısayol kapat |
| Delete | seçili görevleri sil (bulk) |
| Space | seçili görevleri tamamla (status 3) |
| T / W / A / K / C / R / I | Bugün / Hafta / Tümü / Kanban / Takvim / Raporlar / Inbox |

---

## 10. UI/UX DETAYLARI

- Tüm satırlarda pürüzsüz hover (arka plan aydınlanması) ve active (scale).
- Checkbox tamamlanınca yeşil+aqua pop animasyonu, üstü çizili başlık.
- Kartlar hover'da yükselir (`translateY(-3px)`) ve üzerinde `--shadow-lg` belirir.
- Seçili görevler mavi iç çerçeveli; pinned satırlar sol kenar çizgili.
- Boş durumlar için emoji'lı "empty-state" mesajları.
- Toast'lar hareket, hafifwalet, tip rengine göre sol çubuk ikonu.
- Mobil: sidebar çekmece (translateX), içerik 1rem padding; arama kutdu ekran küçükken ikona daralır.
- Tema geçişinde arka plan rengi `.5s` smooth.

---

## 11. PERFORMANS & GÜVENLİK

- **Performans:** sunucu tarafı sayfalama (limit/offset), `PRAGMA journal_mode=WAL`, `busy_timeout`, `synchronous=NORMAL`, index'ler (`idx_tasks_*`, `idx_subtasks_task`, vb.), sadece gerekli alanlar çekilir, `hasDetails` ile ilişkiler tembel yüklenir (listede detay yüklenmez, modalda yüklenir).
- **Güvenlik:** PDO prepared statements (emulate prepares kapalı), `htmlspecialchars` çıktı (`e()`), oturum tabanlı CSRF (`X-CSRF-Token` header), dosya yüklemede rastgele güvenli ad + uzantı filtreleme, ZIP geri yükleme geçici klasöre unpack (path traversal koruması), `mb_convert_encoding` dosya adları.

---

## 12. HATA / UÇ DURUM POLİTİKALARI

- Giriş doğrulama hataları JSON `{ok:false, error}` ile döner; frontend toast gösterir.
- Görev yoksa `empty-state`; takvimde "görev yok" mesajı; kanban'da "Görev yok".
- Bilinmeyen action → "Bilinmeyen aksiyon". Bilinmeyen api hatası → "Sunucu hatası".
- Dosya >25MB reddeder. Yeniden adlandırma/silme onayla korunur.
- Demo seed `replace=1` mevcut görevleri siler (kullanıcı onayı frontend'de).