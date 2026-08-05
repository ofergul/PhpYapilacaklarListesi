# FILE_STRUCTURE.md — Dosya ve Klasör Yapısı

```
gorev2/
├── README.md                    ← Proje tanıtımı, kurulum, özellikler, kısayollar
├── PROJECT_SPEC.md              ← Detaylı özellik/kullanıcı akışı/iş mantığı spesifikasyonu
├── TECH_STACK.md                ← Teknoloji, sürümler, şema
├── FILE_STRUCTURE.md            ← Bu dosya
├── CURRENT_STATE.md             ← Geliştirme durumu, eksikler, bilinen sorunlar
│
├── index.php                    ← SPA kabuğu. HTML iskeleti, tüm görünümler,
│                                   8 modal (teleport), bağlam menüsü, toast yığını,
│                                   CDN kütüphane yüklemeleri, inline boot-data JSON.
│                                   Tüm veri gösterimi Alpine.js şablonlarıyla yapılır.
│
├── api.php                      ← Backend JSON API. ~50+ action'ı tek yönlendiriciyle
│                                   sunar. CSRF (yazma işlemlerinde), CRUD, tekrarlama,
│                                   istatistik, rapor, yedekleme, içe/dışa aktarım,
│                                   doğal-dil girdisi dışında tüm iş mantığı burada.
│
├── config.php                   ← Merkezi yapılandırma. PDO tekil bağlantı (SQLite,
│                                   WAL/foreign_keys), DB_SCHEMA sabiti (12 tablo DDL),
│                                   init_schema() (otomatik kurulum + varsayılan seed),
│                                   yardımcılar (e, json_out, input, json_body, csrf,
│                                   setting_get/set, activity_log, auto_backup).
│
├── demo.php                     ← Demo veri üretici. seed_demo_data() fonksiyonunu ve
│                                   CLI/HTTP girişini içerir. api.php'deki 'demo_seed'
│                                   ve Settings butonuyla da çağrılabilir.
│
├── database.sqlite              ← SQLite veritabanı (ilk açılışta otomatik oluşur).
│                                   Şu an demo verisi içerir (27 görev).
│
├── assets/
│   ├── css/
│   │   └── app.css              ← Tüm özel tasarım sistemi. CSS değişkenleri
│   │                                (gündüz/koyu tema), glassmorphism (backdrop-filter),
│   │                                Apple tarzı bileşenler (buton, checkbox, chip,
│   │                                kart, modal, takvim, kanban, toast, switch,
│   │                                progress-ring, context-menu), animasyonlar,
│   │                                @keyframes ve responsive kurallar.
│   └── js/
│       └── app.js               ← Alpine.js uygulama çekirdeği. Tek app() root:
│                                    state, tüm method'lar (veri yükleme, CRUD, takvim,
│                                    kanban, rapor grafikleri (Chart.js), doğal dil
│                                    ayrıştırıcı, bildirim/ses (WebAudio), pomodoro,
│                                    net kullanıcı kısayolları, toast/onay/bağlam menüsü,
│                                    flatpickr kurulumu, tema sistemi).
│
│   └── icons/
│       └── icon.svg             ← Uygulama ikonu (gradan'lı çek + onay işareti).
│                                    Apple-touch-icon ve favicon.
│
├── uploads/                     ← Dosya ekleri (çalışma zamanında oluşur; boş bırakılır).
│
└── backup/                      ← Yedek dosyaları (.sqlite / .zip; çalışma zamanında oluşur).
```

## Önemli Bağımlılıklar/Çağrı grafiği

- `index.php` → `config.php` (require) → `init_schema()` + `auto_backup()`.
- `index.php` **inline** `#boot-data` JSON: `{ csrf, settings }` → `app.js` `BOOT` olarak okur.
- `app.js` → `api.php?action=...` (fetch, `X-CSRF-Token` header ile).
- `api.php` → `config.php` (require), `demo.php` (yalnız `demo_seed` action'ı için).
- `demo.php` → `config.php` (require).
- `app.js` üçüncü taraf global'lere dayanır: `Chart`, `flatpickr`, `Sortable` (CDN'den gelir).

## API Uç Nokta Listesi (api.php)

**GET (örnek `api.php?action=...&...`)**:
`tasks`, `task_get`, `search`, `folders`, `tags`, `stats`, `reports`, `settings`,
`activity`, `backup_list`, `attachment_download`, `backup_download`, `export`.

**POST (yazma; `X-CSRF-Token` zorunlu)**:
`task_save`, `task_toggle`, `task_quick`, `task_delete`, `task_restore`,
`task_destroy`, `task_archive`, `task_unarchive`, `task_flag`, `task_move_folder`,
`task_reorder`, `subtask_save`, `subtask_toggle`, `subtask_delete`, `checklist_save`,
`checklist_toggle`, `checklist_delete`, `folder_save`, `folder_delete`, `tag_save`,
`tag_delete`, `task_tag_toggle`, `attachment_upload` (multipart), `attachment_delete`,
`bulk`, `settings_save`, `backup_create`, `backup_restore`, `backup_delete`,
`import`, `demo_seed`, `recurrence_preview`, `check_reminders`.

> `recurrence_preview` ve `check_reminders` salt-okumsal olmasına rağmen POST kabul
> eder (CSRF atlanır). Frontend daima header gönderir.