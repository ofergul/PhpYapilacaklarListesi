# CURRENT_STATE.md — Projenin Güncel Durumu

> Son güncelleme: Ağustos 2026. Bu belge, **tamamlanan özellikler**, **bilinen
> eksikler** ve **bilinen sorunlar**ı açıklar. "Bu projeyi aynen yap" denirse,
> öncelikle aşağıdaki "tamamlanan" blok hedef davranışı tanımlar.

---

## 1. GENEL DURUM

| Alan | Durum |
|---|---|
| Backend (PHP + SQLite) | ✅ Tamamen çalışıyor; E2E HTTP testi ile doğrulandı |
| Frontend (Alpine.js SPA) | ✅ Tamamen yazıldı; JS sözdizimi kontrol edildi |
| Veritabanı şeması | ✅ 12 tablo, otomatik kurulum |
| Demo veri | ✅ Yüklendi (27 görev, 9 klasör, 8 etiket) |
| Dosyalar | ✅ Tüm proje dosyaları + 4 dokümantasyon dosyası hazır |
| Tarayıcı görsel testi | ⚠️ Yapılmadı (yalnız API/CLI ile doğrulandı) |

**Çalıştırma:** `http://localhost/gorev2/` (XAMPP Apache) veya `php -S localhost:8000`.

---

## 2. TAMAMLANAN ÖZELLİKLER

### Veri & İş Mantığı
- [x] Otomatik SQLite oluşturma + şema + varsayılan klasör/etiket seed'i
- [x] Görev CRUD (oluşturma, düzenleme, tamamlama, geri alma, silme, geri yükleme, kalıcı silme)
- [x] Öncelik (0-3), durum (0-4), % ilerleme, tahmini/gerçek süre, renk/emoji/ikon, konum, favori, sabitlik
- [x] Klasör & etiket CRUD + **yeniden adlandırma + silme** (sidebar hover, hero buton, sağ tık menü)
- [x] Alt görevler + checklist; **otomatik ilerleme hesabı**
- [x] Tekrarlayan görevler (daily…custom) + **tamamlanınca otomatik sonraki kayıt**
- [x] Hatırlatıcılar + 30 sn'lik tarayıcı poll + Notification API + WebAudio sesi
- [x] Canlı arama (title/desc/notes/location/etiket adı/dosya adı)
- [x] Filtreler (öncelik, durum, klasör, etiket, tekrarlı, tarih aralığı) + sıralama
- [x] Toplu işlemler (sil, arşivle, taşı, etiket ekle, durum/öncelik/flag)
- [x] Dosya ekleri (multipart upload ≤25MB, indir, sil)
- [x] Arşiv + Çöp Kutusu
- [x] Etkinlik günlüğü (`activity_logs`)
- [x] CSRF koruması, XSS çıkış kaçırma, prepared statements
- [x] Sayfalama + lazy detay yükleme (`hasDetails`/`task_get`), performans index'leri

### Ekranlar (index.php)
- [x] **Dashboard:** istatistik kartları, ilerleme halkası, yaklaşan görevler, son 14 gün grafiği, bugün listesi, son etkinlikler
- [x] **Inbox / Bugün / Planlanan / Bu Hafta / Tamamlandı / Tüm Görevler / Arşiv**
- [x] **Klasör görünümü + Etiket görünümü** (hero + Düzenle/Sil)
- [x] **Takvim:** ay / hafta / gün; drag-drop ile tarih taşıma
- [x] **Kanban:** 4 sütun, SortableJS drag-drop, durum değiştirme
- [x] **Raporlar:** 6 grafik (çizgi/çubuk/donut), 3/6/12 ay aralığı
- [x] **Ayarlar:** tema, vurgu rengi, bildirim/ses, pomodoro süresi, yedekleme, içe/dışa aktarım, demo veri
- [x] **Çöp Kutusu:** geri al / kalıcı sil / boşalt

### Modallar & Etkileşim
- [x] Görev modal (5 sekme: Detaylar/Alt Görev/Checklist/Dosya/Tekrar)
- [x] Hızlı ekle (Ctrl+N) + **Türkçe doğal dil ayrıştırma**
- [x] Klasör / Etiket modalı
- [x] İçe aktar modalı, Pomodoro modalı, Kısayollar modalı, genel onay modalı
- [x] Sağ tık bağlam menüsü (görev/klasör/etiket)
- [x] Toast bildirim sistemi

### Tasarım & Hareket
- [x] Apple estetiği, glassmorphism, bol boşluk, yuvarlak kartlar
- [x] Sistem/Gündüz/Koyu tema + vurgu rengi seçimi
- [x] Responsive (mobil çekmece sidebar, 1400px masaüstü genişlik)
- [x] Kapsamlı hover/press/enter animasyonları ve @keyframes seti
- [x] Inter font + Material Symbols ikonları
- [x] WebAudio bildirim sesi (dosyasız)

### Demo & Dışa Aktarım
- [x] `demo.php` (27 görev; `replace` ile sıfırla-yükle) + Settings butonu
- [x] Dışa aktarım: JSON / CSV / Excel-CSV / Markdown
- [x] İçe aktarım: JSON / CSV (transaction)
- [x] Yedekleme: manuel ZIP (veya .sqlite), geri yükle, indir, sil, otomatik günlük yedek

---

## 3. TEST SONUÇLARI (gerçekleştirildi)

- **PHP sözdizimi:** `config.php`, `api.php`, `index.php`, `demo.php` → temiz.
- **JS sözdizimi:** `node --check assets/js/app.js` → temiz.
- **Veritabanı boot:** 12 tablo + 9 klasör + 8 etiket otomatik; tekrarlama önizleme doğru.
- **E2E (HTTP):** görev+etiket+tekrarlama kaydı ✅ · alt görev/checklist ilerleme ✅ ·
  tamamlama→otomatik tekrar kaydı ✅ · arama ✅ · istatistik ✅ · raporlar ✅ ·
  ZIP yedek ✅ · toplu işlem ✅ · JSON dışa ✅ · klasör/etiket rename+sil ✅ · demo_seed ✅.
- **Mevcut veri:** 27 görev / 19 alt görev / 7 checklist / 21 etiket bağı / 5 hatırlatıcı / 4 tekrar kuralı.

---

## 4. EKSİK / GELİŞTİRİLECEK

Aşağıdakiler "aynen üret" için gerekli **değildir** (proje bu haliyle çalışır), ancak
varsa spesifikasyonuna ek bilgi olarak not edilmiştir.

1. **Oyunlaştırma (gamification):** Spesifikasyonda "oyun mekanikleri" geçmesine
   rağmen puan/seri (streak)/seviye/başarı rozetleri **uygulanmadı**. Sadece
   istatistikler ve Pomodoro mevcut. (İstenirse eklenebilir.)
2. **Seçim modalı:** `bulkFolder`, `bulkTag`, `bulkPriority`, `ctxMoveFolder`
   şu an **native `prompt()`** kullanıyor (epeski tarz, Apple estetikle uyumsuz).
   Özel bir CSS "picker modalı" ile değiştirilmeli.
3. **Çoklu hatırlatıcı UI:** Görev modalında tek bir "hatırlat" switch'i var.
   Birden çok hatırlatıcı ekleme/düzenleme UI'ı yok (backend `reminders` array'ini
   destekliyor, sadece ön yüz tek değer üretiyor).
4. **Klasör geri yükleme:** Klasörler silinince soft-delete (`deleted_at`) yapılıyor
   ama "çöp kutusuna gitme + geri alma" UI'ı yok (görevler için var). Klasör silme kalıcı
   görünür. Etiket silme tamamen hard-delete.
5. **Cron/özel tekrar:** `custom_cron` alanı yalnız gösterim amaçlı; yorumlanıp
   çalıştırılmıyor. `by_day` içinde "son cuma" gibi karmaşık kurallar ancak çok basit
   özel durumla (`FRI` + son hafta) çalışır.
6. **Kanban veri boyutu:** Kanban yalnızca ilk 300 görevi yükler (performans koruması).
   Çok büyük listelerde tamamı görünmez.
7. **Mobil sağ tık:** Bağlam menüsü masaüstü sağ tıka bağlı; mobilde uzun basma
   (long-press) bağlam menüsü yok.
8. **Tema grafik yenileme:** Tema (koyu/gündüz) değişince **rapor** grafikleri renk
   paletini anında güncellemez; dashboard grafiği yenilenir, raporlar sayfa
   tazelenene kadar eski palette kalır. (küçük kozmetik)
9. **Dead code:** `config.php`'deki `task_link_sanity()` fonksiyonu tanımlı ancak
   hiçbir yerden çağrılmıyor. Kaldırılabilir.

---

## 5. BİLİNEN SORUNLAR (Bugs)

- **Öncelik: Düşük.** Windows konsolunda (PowerShell) Türkçe karakterler bazen `??`
  görünür. Bu sadece **test çıktısında** görüntülenme sorunudur; veritabanı ve web
  çıktısı UTF-8'e uygundur. (Kodlama değil, konsol kod sayfası.)
- **Düşük:** Görev modalında `Ctrl+S` sadece "Detaylar" sekmesi açıkken kaydeder
  (diğer sekmelerde öne çıkan "Kaydet" butonu kullanılmalıdır).
- **Düşük:** `x-model.number` kullanılan checkbox'lar (bildirim/ses) `true/false`
  boolean olur; sunucuda `(string)true="1"`, `(string)false=""` olarak saklanır —
  davranış doğru, fakat veri `""`/`"1"` şeklindedir.
- **Düşük:** Quick-add ile doğal dil ayrıştırma, `#etiket` kullanıcı tarafından
  mevcut etiket/klasör **eşleşmezse** sessizce başlıktan çıkarılmaz — sadece eşleşen
  değerler ayrıştırılır. (Beklenen davranış= mevcut öğelerle çalışır.)

> Bu üç "Düşük" bulgu davranışsal olarak kabul edilebilir; uygulamanın ana akışlarını
> bozmaz.

---

## 6. GELECEK ADIMLAR (önerilen)

1. Tarayıcıda manuel görsel onay (tema, grafikler, mobil) yapılması.
2. `prompt()` yerine **picker modalı**.
3. Çoklu hatırlatıcı yönetimi UI.
4. Klasör geri dönüşümü + mobil uzun basma.
5. İstenirse gamification (seri, puan, rozet).

---

## 7. DOSYALARIN DOĞRU ÇALIŞTIRILMASI İÇİN KONTROL

- `database.sqlite` yazılabilir olmalı (PHP kullanıcısı CREATE/INSERT/UPDATE yapabiliyor).
- `uploads/` ve `backup/` klasörleri yazılabilir.
- PHP 8.2+ ve `pdo_sqlite` açık.
- Sunucu `post_max_size`/`upload_max_filesize` en az 25MB (büyük ekler için).
- CDN erişimi internet gerektirir (Tailwind/Alpine/Chart/Flatpickr/Sortable/perf.fonts).