<?php
/**
 * Görev Yöneticisi — SPA Kabuğu
 * Tüm görünümler Alpine.js ile bu sayfada işlenir.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

auto_backup();

$csrf = csrf_token();
$appSettings = [
    'theme'         => setting_get('theme', 'system'),
    'accent'        => setting_get('accent', '#0A84FF'),
    'sound'         => setting_get('sound', '1'),
    'notifications' => setting_get('notifications', '1'),
    'default_folder'=> setting_get('default_folder', ''),
    'pomodoro_focus'=> setting_get('pomodoro_focus', '25'),
    'pomodoro_break'=> setting_get('pomodoro_break', '5'),
];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#0A84FF">
<title>Görev — Görev Yönetimi</title>

<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = {
  darkMode: 'class',
  theme: { extend: { fontFamily: { sans: ['Inter', 'system-ui', '-apple-system', 'sans-serif'] } } }
};
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link rel="stylesheet" href="assets/css/app.css">

<link rel="apple-touch-icon" href="assets/icons/icon.svg">
<link rel="icon" type="image/svg+xml" href="assets/icons/icon.svg">
</head>
<body x-data="app()" x-init="init()" :class="[themeClass, { 'sidebar-open': sidebarOpen, 'modal-open': (taskModal || quickOpen || folderModal.open || tagModal.open || shortcutHelp || importOpen || confirmMsg) }]">

<!-- Arka Plan Işıltıları -->
<div class="bg-orbs" aria-hidden="true">
    <div class="orb orb-a"></div>
    <div class="orb orb-b"></div>
    <div class="orb orb-c"></div>
</div>

<!-- Gizli Başlangıç Verileri -->
<script id="boot-data" type="application/json">
<?= json_encode([
    'csrf' => $csrf,
    'settings' => $appSettings,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
</script>

<div class="app-shell">

    <!-- ============ SOL MENÜ ============ -->
    <aside class="sidebar glass" :class="{ 'mobile-open': sidebarOpen }">
        <div class="flex flex-col h-full">

            <div class="px-5 pt-5 pb-2 flex items-center justify-between">
                <button @click="goView('dashboard')" class="flex items-center gap-3 group">
                    <span class="logo-mark">
                        <span class="material-symbols-rounded">check_circle</span>
                    </span>
                    <span class="text-lg font-bold tracking-tight">Görev</span>
                </button>
                <button class="btn-icon lg:hidden" @click="sidebarOpen = false">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>

            <nav class="flex-1 overflow-y-auto px-3 py-3 space-y-1 nav-scroll">
                <template x-for="item in navMain" :key="item.key">
                    <button @click="goView(item.key)"
                        class="nav-item" :class="{ 'active': view === item.key }">
                        <span class="material-symbols-rounded" :style="'font-variation-settings: ' + (view === item.key ? '\'FILL\' 1' : '\'FILL\' 0')"><span x-text="item.icon"></span></span>
                        <span class="flex-1 text-left truncate" x-text="item.label"></span>
                        <span x-show="item.badge !== undefined" class="badge" x-text="item.badge"></span>
                    </button>
                </template>

                <div class="nav-section-label">Klasörler</div>
                <template x-for="f in folders" :key="'f' + f.id">
                    <div @click="goFolder(f)" @contextmenu.prevent="openFolderContext($event, f)"
                        class="nav-item group cursor-pointer" :class="{ 'active': view === 'folders' && currentFolder === f.id }">
                        <span class="w-6 h-6 rounded-lg flex items-center justify-center text-white text-sm shrink-0" :style="'background:' + f.color">
                            <span class="material-symbols-rounded text-[14px]"><span x-text="f.icon || 'folder'"></span></span>
                        </span>
                        <span class="flex-1 text-left truncate" x-text="f.name"></span>
                        <span class="badge" x-text="f.task_count"></span>
                        <button class="row-action opacity-0 group-hover:opacity-100 !w-7 !h-7" @click.stop="folderModalOpen(f)" title="Düzenle">
                            <span class="material-symbols-rounded text-[15px]">edit</span>
                        </button>
                    </div>
                </template>
                <button @click="folderModalOpen()" class="nav-item subtle">
                    <span class="material-symbols-rounded">add</span>
                    <span class="text-left">Yeni Klasör</span>
                </button>

                <div class="nav-section-label">Etiketler</div>
                <template x-for="g in tags" :key="'t' + g.id">
                    <div @click="goTag(g)" @contextmenu.prevent="openTagContext($event, g)"
                        class="nav-item group cursor-pointer" :class="{ 'active': view === 'tags' && currentTag === g.id }">
                        <span class="w-6 h-6 rounded-full shrink-0 flex items-center justify-center text-xs" :style="'background:' + g.color + '22; color:' + g.color">
                            <span x-text="g.emoji || '#'"></span>
                        </span>
                        <span class="flex-1 text-left truncate" x-text="'#' + g.name"></span>
                        <span class="badge" x-text="g.task_count"></span>
                        <button class="row-action opacity-0 group-hover:opacity-100 !w-7 !h-7" @click.stop="tagModalOpen(g)" title="Düzenle">
                            <span class="material-symbols-rounded text-[15px]">edit</span>
                        </button>
                    </div>
                </template>
                <button @click="tagModalOpen()" class="nav-item subtle">
                    <span class="material-symbols-rounded">add</span>
                    <span class="text-left">Yeni Etiket</span>
                </button>

                <div class="nav-section-label">Araçlar</div>
                <template x-for="item in navTools" :key="item.key">
                    <button @click="goView(item.key)"
                        class="nav-item" :class="{ 'active': view === item.key }">
                        <span class="material-symbols-rounded" :style="'font-variation-settings: ' + (view === item.key ? '\'FILL\' 1' : '\'FILL\' 0')"><span x-text="item.icon"></span></span>
                        <span class="flex-1 text-left truncate" x-text="item.label"></span>
                    </button>
                </template>
            </nav>

            <div class="p-3 border-t" :style="'border-color: var(--border)'">
                <button @click="themeCycle" class="nav-item w-full">
                    <span class="material-symbols-rounded"><span x-text="themeIcon"></span></span>
                    <span class="flex-1 text-left" x-text="themeLabel"></span>
                </button>
                <button @click="shortcutHelp = true" class="nav-item subtle w-full">
                    <span class="material-symbols-rounded">keyboard</span>
                    <span class="flex-1 text-left">Kısayollar</span>
                </button>
            </div>
        </div>
    </aside>

    <!-- Mobil Karartma -->
    <div class="sidebar-backdrop" :class="{ 'show': sidebarOpen }" @click="sidebarOpen = false"></div>

    <!-- ============ ANA ALAN ============ -->
    <div class="main-area">

        <!-- Üst Çubuk -->
        <header class="topbar glass">
            <div class="flex items-center gap-2">
                <button class="btn-icon lg:hidden" @click="sidebarOpen = true">
                    <span class="material-symbols-rounded">menu</span>
                </button>
                <button class="btn-icon" @click="goView('dashboard')" :class="{ 'active-tool': view === 'dashboard' }">
                    <span class="material-symbols-rounded">grid_view</span>
                </button>
                <div class="ml-1">
                    <h1 class="text-lg font-bold leading-tight" x-text="viewTitle"></h1>
                    <p class="text-xs opacity-60" x-text="viewSubtitle"></p>
                </div>
            </div>

            <div class="flex items-center gap-1.5 flex-1 justify-end">
                <div class="search-box" :class="{ 'focused': searchFocused }">
                    <span class="material-symbols-rounded search-icon">search</span>
                    <input x-ref="searchInput" type="search" placeholder="Ara..." x-model="search"
                        @focus="searchFocused = true" @blur="searchFocused = false"
                        @keydown.escape="search = ''"
                        @input.debounce.400ms="reloadTasks()"
                        class="search-input">
                    <span x-show="search" @click="search=''" class="material-symbols-rounded search-clear cursor-pointer">close</span>
                </div>

                <button class="btn-icon" @click="pomodoroOpen" :class="{ 'active-tool': pomodoroOpen_ }" title="Pomodoro">
                    <span class="material-symbols-rounded">timer</span>
                </button>
                <button class="btn-primary" @click="quickOpen = true">
                    <span class="material-symbols-rounded">add</span>
                    <span class="hidden sm:inline">Yeni Görev</span>
                </button>
            </div>
        </header>

        <main class="content">

            <!-- ============ DASHBOARD ============ -->
            <section x-show="view === 'dashboard'" x-cloak x-transition.opacity class="view-wrap">
                <div class="greeting">
                    <h2 x-text="greeting"></h2>
                    <p class="text-sm opacity-60" x-text="fullDate"></p>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div class="stat-card" @click="goView('today')">
                        <div class="stat-icon" style="background:#0A84FF1a;color:#0A84FF"><span class="material-symbols-rounded">today</span></div>
                        <div class="stat-value" x-text="stats.today ?? 0"></div>
                        <div class="stat-label">Bugün</div>
                    </div>
                    <div class="stat-card" @click="goView('week')">
                        <div class="stat-icon" style="background:#30D1581a;color:#30D158"><span class="material-symbols-rounded">calendar_view_week</span></div>
                        <div class="stat-value" x-text="stats.week ?? 0"></div>
                        <div class="stat-label">Bu Hafta</div>
                    </div>
                    <div class="stat-card" @click="goView('all')">
                        <div class="stat-icon" style="background:#5E5CE61a;color:#5E5CE6"><span class="material-symbols-rounded">inventory_2</span></div>
                        <div class="stat-value" x-text="stats.active ?? 0"></div>
                        <div class="stat-label">Aktif Görev</div>
                    </div>
                    <div class="stat-card overdue-card" @click="goView('all')">
                        <div class="stat-icon" style="background:#FF453A1a;color:#FF453A"><span class="material-symbols-rounded">schedule</span></div>
                        <div class="stat-value" x-text="stats.overdue ?? 0"></div>
                        <div class="stat-label">Geciken</div>
                    </div>
                </div>

                <div class="grid lg:grid-cols-3 gap-4 mt-4">
                    <div class="glass-card p-5 flex flex-col items-center justify-center">
                        <div class="progress-ring" :style="'--pct:' + (stats.completion_rate ?? 0)">
                            <div class="progress-ring-inner">
                                <div class="text-2xl font-bold" x-text="(stats.completion_rate ?? 0) + '%'"></div>
                                <div class="text-xs opacity-60">Tamamlanma</div>
                            </div>
                        </div>
                        <div class="flex gap-4 mt-4 text-center text-xs">
                            <div><div class="font-semibold text-base" x-text="stats.completed ?? 0"></div><span class="opacity-60">Tamamlanan</span></div>
                            <div><div class="font-semibold text-base" x-text="stats.total ?? 0"></div><span class="opacity-60">Toplam</span></div>
                            <div><div class="font-semibold text-base" x-text="stats.inbox ?? 0"></div><span class="opacity-60">Inbox</span></div>
                        </div>
                    </div>

                    <div class="glass-card p-5">
                        <h3 class="card-title">Yaklaşan Görevler</h3>
                        <div class="space-y-2 mt-3 max-h-56 overflow-y-auto nav-scroll">
                            <template x-for="t in upcomingTasks()" :key="'up' + t.id">
                                <div class="flex items-center gap-3 p-2 rounded-xl hover:bg-black/5 dark:hover:bg-white/10 cursor-pointer transition" @click="openTask(t)">
                                    <span class="w-2 h-2 rounded-full shrink-0" :style="'background:' + (t.color || '#0A84FF')"></span>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-medium truncate" x-text="t.title"></div>
                                        <div class="text-[11px] opacity-60" x-text="humanDate(t.due_date)"></div>
                                    </div>
                                    <span class="prio-dot" :class="'prio-' + t.priority" x-show="t.priority > 0"></span>
                                </div>
                            </template>
                            <p x-show="upcomingTasks().length === 0" class="text-sm opacity-50 text-center py-4">Önümüzdeki 7 gün plan yok 🎉</p>
                        </div>
                    </div>

                    <div class="glass-card p-5">
                        <h3 class="card-title">Son 14 Gün Tamamlama</h3>
                        <div class="chart-wrap mt-2"><canvas id="chart-week"></canvas></div>
                        <div class="grid grid-cols-2 gap-2 mt-3 text-xs">
                            <div class="chip"><span class="material-symbols-rounded text-[14px]" style="color:#FF9F0A">local_fire_department</span><span x-text="'En yoğun gün: ' + (stats.busiest_day ? humanDate(stats.busiest_day.date) + ' (' + stats.busiest_day.count + ')' : '—')"></span></div>
                            <div class="chip"><span class="material-symbols-rounded text-[14px]" style="color:#0A84FF">sell</span><span x-text="'Top etiket: ' + (stats.top_tag ? '#' + stats.top_tag.name : '—')"></span></div>
                        </div>
                    </div>
                </div>

                <div class="grid lg:grid-cols-2 gap-4 mt-4">
                    <div class="glass-card p-5">
                        <div class="flex items-center justify-between">
                            <h3 class="card-title">Bugün</h3>
                            <button class="text-xs font-semibold link" @click="goView('today')">Tümünü Gör</button>
                        </div>
                        <div class="space-y-1.5 mt-3" x-show="viewTasks('today').length">
                            <template x-for="t in viewTasks('today')" :key="'dt' + t.id">
                                <div class="task-row">
                                    <button @click="toggleTask(t)" class="checkbox-apple" :class="{ 'checked': t.is_completed }">
                                        <span class="material-symbols-rounded">check</span>
                                    </button>
                                    <div class="flex-1 min-w-0 cursor-pointer" @click="openTask(t)">
                                        <span class="task-title" :class="{ 'done': t.is_completed }" x-text="t.title"></span>
                                    </div>
                                    <span x-show="t.due_time" class="text-[11px] opacity-60" x-text="t.due_time"></span>
                                    <button @click.stop="trashTask(t)" class="row-action"><span class="material-symbols-rounded">delete</span></button>
                                </div>
                            </template>
                        </div>
                        <p x-show="!viewTasks('today').length" class="empty-state">Bugün için planlanmış görev yok.</p>
                    </div>

                    <div class="glass-card p-5">
                        <div class="flex items-center justify-between">
                            <h3 class="card-title">Son Etkinlikler</h3>
                            <button class="text-xs font-semibold link" @click="goView('reports')">Raporlar</button>
                        </div>
                        <div class="space-y-2 mt-3 max-h-64 overflow-y-auto nav-scroll">
                            <template x-for="a in activity" :key="'act' + a.id">
                                <div class="flex items-start gap-2.5 text-sm">
                                    <span class="material-symbols-rounded text-[16px] mt-0.5 opacity-50"><span x-text="actIcon(a.action)"></span></span>
                                    <div class="flex-1 min-w-0">
                                        <div class="truncate"><span class="font-medium capitalize" x-text="actLabel(a.action)"></span> <span x-show="a.task_title" class="opacity-60" x-text="'— ' + a.task_title"></span></div>
                                        <div class="text-[11px] opacity-40" x-text="timeAgo(a.created_at)"></div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============ GÖREV LİSTESİ (Ortak) ============ -->
            <section x-show="['inbox','today','planned','week','completed','all','archive','folders','tags'].includes(view)" x-cloak x-transition.opacity class="view-wrap">
                <template x-if="view === 'folders' || view === 'tags'">
                    <div class="list-hero">
                        <template x-if="view === 'folders'">
                            <div class="flex items-center gap-3 flex-1 min-w-0">
                                <span class="w-12 h-12 rounded-2xl flex items-center justify-center text-white shrink-0" :style="'background:' + currentFolderColor">
                                    <span class="material-symbols-rounded"><span x-text="currentFolderIcon"></span></span>
                                </span>
                                <div class="min-w-0">
                                    <h2 class="text-xl font-bold truncate" x-text="currentFolderName"></h2>
                                    <p class="text-xs opacity-60 truncate" x-text="currentFolderDesc"></p>
                                </div>
                                <div class="flex items-center gap-2 ml-auto shrink-0">
                                    <button class="btn-secondary" @click="folderModalOpen(currentFolderObj)">
                                        <span class="material-symbols-rounded text-[16px]">edit</span> Düzenle
                                    </button>
                                    <button class="btn-secondary" @click="confirmDeleteFolderById">
                                        <span class="material-symbols-rounded text-[16px]" style="color:#FF453A">delete</span>
                                    </button>
                                </div>
                            </div>
                        </template>
                        <template x-if="view === 'tags'">
                            <div class="flex items-center gap-3 flex-1 min-w-0">
                                <span class="w-12 h-12 rounded-full flex items-center justify-center text-2xl shrink-0" :style="'background:' + currentTagColor + '22'">
                                    <span x-text="currentTagEmoji"></span>
                                </span>
                                <div class="min-w-0">
                                    <h2 class="text-xl font-bold truncate" x-text="'#' + currentTagName"></h2>
                                    <p class="text-xs opacity-60" x-text="viewTasks().length + ' görev'"></p>
                                </div>
                                <div class="flex items-center gap-2 ml-auto shrink-0">
                                    <button class="btn-secondary" @click="tagModalOpen(currentTagObj)">
                                        <span class="material-symbols-rounded text-[16px]">edit</span> Düzenle
                                    </button>
                                    <button class="btn-secondary" @click="confirmDeleteTagById">
                                        <span class="material-symbols-rounded text-[16px]" style="color:#FF453A">delete</span>
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>

                <div class="list-toolbar glass">
                    <div class="flex items-center gap-1.5 flex-wrap">
                        <template x-if="!selectMode">
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <button class="chip" :class="{ 'chip-active': filters.priority === '' }" @click="setFilter('priority','')">Tüm Öncelik</button>
                                <button class="chip" :class="{ 'chip-active': filters.priority === 3 }" @click="setFilter('priority', 3)">🔴 Kritik</button>
                                <button class="chip" :class="{ 'chip-active': filters.priority === 2 }" @click="setFilter('priority', 2)">🟠 Yüksek</button>
                                <button class="chip" :class="{ 'chip-active': filters.priority === 1 }" @click="setFilter('priority', 1)">🔵 Normal</button>
                                <button class="chip" :class="{ 'chip-active': filters.priority === 0 }" @click="setFilter('priority', 0)">⚪ Düşük</button>
                            </div>
                        </template>
                        <template x-if="selectMode">
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <button class="chip chip-primary" @click="bulk('status',3)">Tamamla</button>
                                <button class="chip" @click="bulk('delete')">Sil</button>
                                <button class="chip" @click="bulk('archive')">Arşivle</button>
                                <button class="chip" @click="bulkFolder">Klasöre Taşı</button>
                                <button class="chip" @click="bulkTag">Etiket Ekle</button>
                                <button class="chip" @click="bulkPriority">Öncelik</button>
                                <button class="chip chip-danger" @click="clearSelection">İptal</button>
                                <span class="text-xs opacity-60 ml-1" x-text="selection.length + ' seçili'"></span>
                            </div>
                        </template>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <select class="select-mini" x-model="filters.sort" @change="reloadTasks">
                            <option value="default">Varsayılan</option>
                            <option value="title">Başlık</option>
                            <option value="created">Oluşturma</option>
                            <option value="due">Bitiş Tarihi</option>
                            <option value="priority">Öncelik</option>
                        </select>
                        <select class="select-mini" x-model="filters.status" @change="reloadTasks">
                            <option value="">Tüm Durum</option>
                            <option value="0">Bekliyor</option>
                            <option value="1">Devam Ediyor</option>
                            <option value="2">Askıda</option>
                            <option value="3">Tamamlandı</option>
                            <option value="4">İptal</option>
                        </select>
                    </div>
                </div>

                <div class="list-head" x-show="!selectMode">
                    <span class="text-xs font-semibold uppercase tracking-wider opacity-40" x-text="totalTasks + ' görev'"></span>
                    <button class="btn-icon !w-8 !h-8" @click="toggleSelectAll" :title="selection.length ? 'Seçimi temizle' : 'Tümünü seç'">
                        <span class="material-symbols-rounded" x-text="selection.length ? 'check_box' : 'check_box_outline_blank'"></span>
                    </button>
                </div>

                <div class="space-y-1.5" x-show="!loading" id="task-list">
                    <template x-for="t in viewTasks()" :key="'tl' + t.id">
                        <div class="task-row glass-row" :class="{ 'selected': selection.includes(t.id), 'pinned-row': t.is_pinned }"
                            :data-id="t.id"
                            @click="!selectMode ? openTask(t) : toggleSelect(t.id)"
                            @contextmenu.prevent="openContext($event, t)">
                            <button @click.stop="toggleTask(t)" class="checkbox-apple" :class="{ 'checked': t.is_completed }">
                                <span class="material-symbols-rounded">check</span>
                            </button>

                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <span x-show="t.emoji" x-text="t.emoji" class="text-base"></span>
                                    <span class="task-title" :class="{ 'done': t.is_completed }" x-text="t.title"></span>
                                    <span x-show="t.is_favorite" class="material-symbols-rounded text-[14px]" style="color:#FFD60A">star</span>
                                    <span x-show="t.is_pinned" class="material-symbols-rounded text-[14px]" style="color:#64D2FF">push_pin</span>
                                    <span x-show="t.recurrence" class="material-symbols-rounded text-[14px] opacity-40" title="Tekrarlayan">repeat</span>
                                    <span x-show="t.has_details" class="material-symbols-rounded text-[14px] opacity-40">notes</span>
                                </div>
                                <div class="flex items-center gap-1.5 mt-0.5 flex-wrap">
                                    <span class="prio-dot" :class="'prio-' + t.priority" x-show="t.priority > 0" :title="t.priority_label"></span>
                                    <span x-show="t.due_date" class="text-[11px] due-chip" :class="{ 'overdue': isOverdue(t) }" x-text="dueLabel(t)"></span>
                                    <template x-for="g in (t.tags || []).slice(0,3)" :key="'tg' + t.id + g.id">
                                        <span class="tag-chip" :style="'background:' + g.color + '1f;color:' + g.color" x-text="'#' + g.name"></span>
                                    </template>
                                    <span x-show="t.folder_name" class="text-[11px] opacity-50 flex items-center gap-0.5">
                                        <span class="material-symbols-rounded text-[12px]"><span x-text="t.folder_icon || 'folder'"></span></span><span x-text="t.folder_name"></span>
                                    </span>
                                    <span x-show="t.progress > 0 && t.progress < 100" class="mini-progress"><span :style="'width:' + t.progress + '%'"></span></span>
                                    <span x-show="t.progress >= 100" class="text-[11px] font-semibold" style="color:#30D158">%100</span>
                                </div>
                            </div>

                            <div class="flex items-center gap-0.5" @click.stop>
                                <button class="row-action" @click="setFlag(t,'is_favorite', t.is_favorite ? 0 : 1)" :title="t.is_favorite ? 'Favoriden çıkar' : 'Favorile'">
                                    <span class="material-symbols-rounded" :class="{ 'filled-gold': t.is_favorite }">star</span>
                                </button>
                                <button class="row-action" @click="setFlag(t,'is_pinned', t.is_pinned ? 0 : 1)" :title="t.is_pinned ? 'Sabitlemeyi kaldır' : 'Sabitle'">
                                    <span class="material-symbols-rounded" :class="{ 'filled-blue': t.is_pinned }">push_pin</span>
                                </button>
                                <button class="row-action" @click="trashTask(t)" title="Sil">
                                    <span class="material-symbols-rounded">delete</span>
                                </button>
                            </div>
                        </div>
                    </template>

                    <button x-show="hasMore" @click="loadMore" class="load-more" :disabled="loadingMore">
                        <span x-text="loadingMore ? 'Yükleniyor...' : 'Daha fazla göster'"></span>
                    </button>

                    <div x-show="!viewTasks().length" class="empty-state py-14">
                        <span class="material-symbols-rounded text-4xl opacity-30 mb-3">inbox</span>
                        <p class="font-medium">Görev bulunamadı</p>
                        <p class="text-xs opacity-50 mt-1">Yeni görev eklemek için <b>Ctrl+N</b> kullanabilirsin</p>
                    </div>
                </div>

                <div x-show="loading" class="flex justify-center py-16">
                    <div class="spinner"></div>
                </div>
            </section>

            <!-- ============ TAKVİM ============ -->
            <section x-show="view === 'calendar'" x-cloak x-transition.opacity class="view-wrap">
                <div class="flex items-center justify-between flex-wrap gap-3 mb-4">
                    <div class="flex items-center gap-2">
                        <button class="btn-icon" @click="calNav(-1)"><span class="material-symbols-rounded">chevron_left</span></button>
                        <h2 class="text-xl font-bold min-w-40 text-center" x-text="calTitle"></h2>
                        <button class="btn-icon" @click="calNav(1)"><span class="material-symbols-rounded">chevron_right</span></button>
                        <button class="chip" @click="calToday">Bugün</button>
                    </div>
                    <div class="flex gap-1 p-1 rounded-xl glass">
                        <button class="chip" :class="{ 'chip-active': cal.mode === 'month' }" @click="cal.mode='month'">Ay</button>
                        <button class="chip" :class="{ 'chip-active': cal.mode === 'week' }" @click="cal.mode='week'">Hafta</button>
                        <button class="chip" :class="{ 'chip-active': cal.mode === 'day' }" @click="cal.mode='day'">Gün</button>
                    </div>
                </div>

                <!-- Ay Görünümü -->
                <div x-show="cal.mode === 'month'" class="glass-card p-4">
                    <div class="grid grid-cols-7 gap-1 mb-1 text-center">
                        <template x-for="d in ['Pzt','Sal','Çar','Per','Cum','Cmt','Paz']" :key="d">
                            <div class="text-[11px] font-semibold uppercase tracking-wide opacity-40 py-1" x-text="d"></div>
                        </template>
                    </div>
                    <div class="grid grid-cols-7 gap-1">
                        <template x-for="cell in calCells" :key="'c' + cell.key">
                            <div class="cal-cell" :class="{ 'other': !cell.current, 'today': cell.isToday, 'selected': cell.date === cal.selected }"
                                @click="cal.selected = cell.date; calSelectDate(cell.date)">
                                <div class="cal-day" x-text="cell.day"></div>
                                <template x-for="t in tasksOn(cell.date).slice(0,3)" :key="'cc' + cell.date + t.id">
                                    <div class="cal-task" :style="'background:' + (t.color || '#0A84FF') + '22;color:' + (t.color || '#0A84FF')"
                                        draggable="true" @dragstart="dragTask = t; dragFrom = 'cal'" @click.stop="openTask(t)"
                                        :title="t.title + (t.due_time ? ' • ' + t.due_time : '')">
                                        <span x-text="t.due_time ? t.due_time + ' ' : ''"></span><span class="truncate" x-text="t.title"></span>
                                    </div>
                                </template>
                                <div x-show="tasksOn(cell.date).length > 3" class="cal-more text-[10px]" x-text="'+' + (tasksOn(cell.date).length - 3) + ' daha'"></div>
                                <div x-show="tasksOn(cell.date).length" class="cal-task-slot" @dragover.prevent @drop="dropOnDate(cell.date)"></div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Hafta Görünümü -->
                <div x-show="cal.mode === 'week'" class="glass-card p-4">
                    <div class="grid grid-cols-1 md:grid-cols-7 gap-2">
                        <template x-for="day in calWeekDays" :key="'w' + day">
                            <div class="cal-day-col" :class="{ 'today': day === todayStr }">
                                <div class="text-xs font-semibold opacity-60 mb-2" x-text="humanDate(day)"></div>
                                <div class="space-y-1.5 min-h-24">
                                    <template x-for="t in tasksOn(day)" :key="'wc' + day + t.id">
                                        <div class="cal-task" :style="'background:' + (t.color || '#0A84FF') + '22;color:' + (t.color || '#0A84FF')"
                                            draggable="true" @dragstart="dragTask = t" @click="openTask(t)">
                                            <span class="truncate" x-text="t.title"></span>
                                        </div>
                                    </template>
                                    <div class="cal-task-slot" @dragover.prevent @drop="dropOnDate(day)"></div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Gün Görünümü -->
                <div x-show="cal.mode === 'day'" class="glass-card p-4">
                    <h3 class="font-semibold mb-3" x-text="humanDate(cal.selected)"></h3>
                    <div class="space-y-1.5">
                        <template x-for="t in tasksOn(cal.selected)" :key="'dd' + t.id">
                            <div class="task-row">
                                <button @click="toggleTask(t)" class="checkbox-apple" :class="{ 'checked': t.is_completed }"><span class="material-symbols-rounded">check</span></button>
                                <div class="flex-1 min-w-0 cursor-pointer" @click="openTask(t)">
                                    <span class="task-title" x-text="t.title"></span>
                                    <div class="text-[11px] opacity-50" x-text="t.due_time || 'Tüm gün'"></div>
                                </div>
                                <span class="prio-dot" :class="'prio-' + t.priority" x-show="t.priority > 1"></span>
                            </div>
                        </template>
                        <p x-show="!tasksOn(cal.selected).length" class="empty-state">Bu günde görev yok.</p>
                    </div>
                </div>
            </section>

            <!-- ============ KANBAN ============ -->
            <section x-show="view === 'kanban'" x-cloak x-transition.opacity class="view-wrap kanban-wrap">
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3" id="kanban-grid">
                    <template x-for="col in kanbanCols" :key="'kb' + col.status">
                        <div class="kanban-col glass-card p-3">
                            <div class="flex items-center gap-2 px-1 mb-3">
                                <span class="w-2.5 h-2.5 rounded-full" :style="'background:' + col.color"></span>
                                <h3 class="font-semibold text-sm flex-1" x-text="col.label"></h3>
                                <span class="badge" x-text="kanbanCount(col.status)"></span>
                            </div>
                            <div class="kanban-list space-y-1.5 min-h-16" :data-status="col.status">
                                <template x-for="t in kanbanTasks(col.status)" :key="'kb' + col.status + t.id">
                                    <div class="kanban-card" :data-id="t.id" @click="openTask(t)">
                                        <div class="flex items-start gap-2">
                                            <span class="material-symbols-rounded text-[18px] shrink-0" :style="'color:' + (t.color || '#0A84FF')"><span x-text="t.icon || 'check_circle'"></span></span>
                                            <div class="flex-1 min-w-0">
                                                <div class="text-sm font-medium leading-snug" x-text="t.title"></div>
                                                <div class="flex items-center gap-1 mt-1 flex-wrap">
                                                    <span x-show="t.due_date" class="text-[10px] due-chip" :class="{ 'overdue': isOverdue(t) }" x-text="dueLabel(t)"></span>
                                                    <span class="prio-dot" :class="'prio-' + t.priority" x-show="t.priority > 1"></span>
                                                </div>
                                            </div>
                                        </div>
                                        <div x-show="t.progress > 0" class="mini-progress mt-2"><span :style="'width:' + t.progress + '%'"></span></div>
                                    </div>
                                </template>
                                <p x-show="!kanbanTasks(col.status).length" class="text-xs opacity-40 text-center py-4">Görev yok</p>
                            </div>
                        </div>
                    </template>
                </div>
            </section>

            <!-- ============ RAPORLAR ============ -->
            <section x-show="view === 'reports'" x-cloak x-transition.opacity class="view-wrap">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xl font-bold">Raporlar</h2>
                    <div class="flex gap-1 p-1 rounded-xl glass">
                        <button class="chip" :class="{ 'chip-active': reportWeeks === 12 }" @click="reportWeeks=12; loadReports()">3 Ay</button>
                        <button class="chip" :class="{ 'chip-active': reportWeeks === 26 }" @click="reportWeeks=26; loadReports()">6 Ay</button>
                        <button class="chip" :class="{ 'chip-active': reportWeeks === 52 }" @click="reportWeeks=52; loadReports()">1 Yıl</button>
                    </div>
                </div>
                <div class="grid lg:grid-cols-2 gap-4">
                    <div class="glass-card p-5">
                        <h3 class="card-title">Tamamlanma Grafiği</h3>
                        <div class="chart-wrap mt-2"><canvas id="chart-completion"></canvas></div>
                    </div>
                    <div class="glass-card p-5">
                        <h3 class="card-title">Haftalık Üretkenlik</h3>
                        <div class="chart-wrap mt-2"><canvas id="chart-weekly"></canvas></div>
                    </div>
                    <div class="glass-card p-5">
                        <h3 class="card-title">Aylık Üretkenlik</h3>
                        <div class="chart-wrap mt-2"><canvas id="chart-monthly"></canvas></div>
                    </div>
                    <div class="glass-card p-5">
                        <h3 class="card-title">Kategori Dağılımı</h3>
                        <div class="chart-wrap mt-2"><canvas id="chart-folder"></canvas></div>
                    </div>
                    <div class="glass-card p-5">
                        <h3 class="card-title">Etiket Dağılımı</h3>
                        <div class="chart-wrap mt-2"><canvas id="chart-tag"></canvas></div>
                    </div>
                    <div class="glass-card p-5">
                        <h3 class="card-title">Öncelik & Durum</h3>
                        <div class="grid grid-cols-2 gap-3 mt-2">
                            <div class="chart-wrap"><canvas id="chart-priority"></canvas></div>
                            <div class="chart-wrap"><canvas id="chart-status"></canvas></div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============ AYARLAR ============ -->
            <section x-show="view === 'settings'" x-cloak x-transition.opacity class="view-wrap max-w-3xl">
                <h2 class="text-xl font-bold mb-4">Ayarlar</h2>
                <div class="space-y-4">
                    <div class="glass-card p-5">
                        <h3 class="card-title">Görünüm</h3>
                        <div class="mt-4 flex items-center justify-between">
                            <div>
                                <div class="font-medium text-sm">Tema</div>
                                <div class="text-xs opacity-50">Sistem temasını takip et veya kendin seç</div>
                            </div>
                            <div class="flex gap-1 p-1 rounded-xl glass">
                                <button class="chip" :class="{ 'chip-active': settingsForm.theme === 'system' }" @click="themeSet('system')">Sistem</button>
                                <button class="chip" :class="{ 'chip-active': settingsForm.theme === 'light' }" @click="themeSet('light')">Gündüz</button>
                                <button class="chip" :class="{ 'chip-active': settingsForm.theme === 'dark' }" @click="themeSet('dark')">Koyu</button>
                            </div>
                        </div>
                        <div class="mt-5">
                            <div class="font-medium text-sm mb-2">Vurgu Rengi</div>
                            <div class="flex flex-wrap gap-2">
                                <template x-for="c in accentColors" :key="c">
                                    <button class="accent-dot" :class="{ 'active': settingsForm.accent === c }" :style="'background:' + c" @click="settingsForm.accent = c; saveSettings()"></button>
                                </template>
                            </div>
                        </div>
                    </div>

                    <div class="glass-card p-5">
                        <h3 class="card-title">Bildirimler</h3>
                        <div class="mt-4 space-y-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="font-medium text-sm">Tarayıcı Bildirimleri</div>
                                    <div class="text-xs opacity-50">Hatırlatma zamanı gelince popup göster</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" x-model.number="settingsForm.notifications" @change="saveSettings()">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="font-medium text-sm">Bildirim Sesi</div>
                                    <div class="text-xs opacity-50">Hatırlatmada ses çal</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" x-model.number="settingsForm.sound" @change="saveSettings()">
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <button class="btn-secondary" @click="requestNotifyPerm">Bildirim İznini İste</button>
                        </div>
                    </div>

                    <div class="glass-card p-5">
                        <h3 class="card-title">Pomodoro</h3>
                        <div class="mt-4 grid grid-cols-2 gap-4">
                            <div>
                                <label class="text-xs opacity-60">Odak (dk)</label>
                                <input type="number" min="1" max="120" class="input" x-model.number="settingsForm.pomodoro_focus" @change="saveSettings()">
                            </div>
                            <div>
                                <label class="text-xs opacity-60">Mola (dk)</label>
                                <input type="number" min="1" max="60" class="input" x-model.number="settingsForm.pomodoro_break" @change="saveSettings()">
                            </div>
                        </div>
                    </div>

                    <div class="glass-card p-5">
                        <h3 class="card-title">Veri</h3>
                        <div class="mt-4 grid grid-cols-2 md:grid-cols-3 gap-2">
                            <button class="btn-secondary" @click="exportData('json')">JSON Dışa Aktar</button>
                            <button class="btn-secondary" @click="exportData('csv')">CSV Dışa Aktar</button>
                            <button class="btn-secondary" @click="exportData('excel')">Excel CSV</button>
                            <button class="btn-secondary" @click="exportData('md')">Markdown</button>
                            <button class="btn-secondary" @click="importOpen = true">İçe Aktar</button>
                            <button class="btn-secondary" @click="backupCreate">Yedek Oluştur</button>
                        </div>
                        <div class="mt-3 flex items-center justify-between gap-3 flex-wrap">
                            <div>
                                <div class="font-medium text-sm">Demo Verisi</div>
                                <div class="text-xs opacity-50">Örnek görevlerle başlamak isterseniz yükleyin</div>
                            </div>
                            <button class="btn-primary" @click="confirmDemoSeed">
                                <span class="material-symbols-rounded">auto_awesome</span> Demo Verisi Yükle
                            </button>
                        </div>
                        <div class="mt-4">
                            <h4 class="text-sm font-semibold mb-2">Yedekler</h4>
                            <div class="space-y-1.5 max-h-48 overflow-y-auto nav-scroll">
                                <template x-for="b in backups" :key="'b' + b.id">
                                    <div class="flex items-center gap-2 p-2 rounded-xl bg-black/5 dark:bg-white/10 text-sm">
                                        <span class="material-symbols-rounded text-[16px] opacity-50">archive</span>
                                        <div class="flex-1 min-w-0">
                                            <div class="truncate" x-text="b.filename"></div>
                                            <div class="text-[11px] opacity-50" x-text="timeAgo(b.created_at) + ' • ' + fmtSize(b.filesize)"></div>
                                        </div>
                                        <button class="row-action" title="Geri yükle" @click="confirmBackupRestore(b)"><span class="material-symbols-rounded">restore</span></button>
                                        <button class="row-action" title="İndir" @click="backupDownload(b.id)"><span class="material-symbols-rounded">download</span></button>
                                        <button class="row-action" title="Sil" @click="backupDelete(b.id)"><span class="material-symbols-rounded">delete</span></button>
                                    </div>
                                </template>
                                <p x-show="!backups.length" class="text-xs opacity-50 text-center py-3">Henüz yedek yok.</p>
                            </div>
                        </div>
                    </div>

                    <div class="glass-card p-5">
                        <h3 class="card-title">Uygulama</h3>
                        <div class="mt-3 text-xs opacity-60 leading-relaxed">
                            <b>Görev</b> — Tamamen lokal çalışan, hesap gerektirmeyen görev yönetim sistemi.<br>
                            Verileriniz <span class="font-mono">database.sqlite</span> içinde cihazınızda saklanır.
                            <div class="mt-3 flex gap-2 flex-wrap">
                                <button class="btn-secondary" @click="shortcutHelp = true">Kısayollar</button>
                                <button class="btn-secondary" @click="openFeedback()">Geri Bildirim</button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ============ ÇÖP KUTUSU ============ -->
            <section x-show="view === 'trash'" x-cloak x-transition.opacity class="view-wrap">
                <div class="list-toolbar glass justify-between">
                    <p class="text-sm opacity-70">Çöp kutusundaki görevler <b>30 gün sonra</b> kalıcı olarak silinir.</p>
                    <button class="btn-secondary" @click="emptyTrash">Çöp Kutusunu Boşalt</button>
                </div>
                <div class="space-y-1.5 mt-4">
                    <template x-for="t in trashTasks" :key="'tr' + t.id">
                        <div class="task-row glass-row opacity-80">
                            <span class="material-symbols-rounded opacity-40">delete</span>
                            <div class="flex-1 min-w-0">
                                <span class="task-title" x-text="t.title"></span>
                                <div class="text-[11px] opacity-50" x-text="'Silinme: ' + timeAgo(t.deleted_at)"></div>
                            </div>
                            <button class="row-action" title="Geri al" @click="restoreTask(t)"><span class="material-symbols-rounded">restore</span></button>
                            <button class="row-action danger" title="Kalıcı sil" @click="destroyTask(t)"><span class="material-symbols-rounded">delete_forever</span></button>
                        </div>
                    </template>
                    <p x-show="!trashTasks.length" class="empty-state py-14">Çöp kutusu boş.</p>
                </div>
            </section>

        </main>
    </div>
</div>

<!-- ============ GÖREV MODALI ============ -->
<template x-teleport="body">
<div x-show="taskModal" x-cloak class="modal-backdrop" @click.self="closeModal">
    <div class="modal glass-strong" @keydown.escape="closeModal">
        <div class="modal-header">
            <div class="flex items-center gap-2 flex-1 min-w-0">
                <button @click="toggleTask(taskModal)" class="checkbox-apple !w-9 !h-9" :class="{ 'checked': taskModal.is_completed }">
                    <span class="material-symbols-rounded">check</span>
                </button>
                <input x-show="modalTab === 'details'" type="text" class="modal-title-input" x-model="taskModal.title"
                    @keydown.enter="$refs.modalSave.click()">
                <h2 x-show="modalTab !== 'details'" class="text-lg font-bold truncate" x-text="taskModal.title"></h2>
                <span x-show="taskModal.is_favorite" class="material-symbols-rounded text-[18px]" style="color:#FFD60A">star</span>
            </div>
            <div class="flex items-center gap-1">
                <button class="btn-icon" :title="taskModal.is_pinned ? 'Sabitlemeyi kaldır' : 'Sabitle'" @click="setFlag(taskModal,'is_pinned', taskModal.is_pinned ? 0 : 1)">
                    <span class="material-symbols-rounded" :class="{ 'filled-blue': taskModal.is_pinned }">push_pin</span>
                </button>
                <button class="btn-icon" :title="taskModal.is_favorite ? 'Favoriden çıkar' : 'Favorile'" @click="setFlag(taskModal,'is_favorite', taskModal.is_favorite ? 0 : 1)">
                    <span class="material-symbols-rounded" :class="{ 'filled-gold': taskModal.is_favorite }">star</span>
                </button>
                <button class="btn-icon" @click="trashTask(taskModal); closeModal()"><span class="material-symbols-rounded">delete</span></button>
                <button class="btn-icon" @click="closeModal"><span class="material-symbols-rounded">close</span></button>
            </div>
        </div>

        <div class="modal-tabs">
            <button class="modal-tab" :class="{ 'active': modalTab === 'details' }" @click="modalTab='details'">Detaylar</button>
            <button class="modal-tab" :class="{ 'active': modalTab === 'subtasks' }" @click="modalTab='subtasks'">
                Alt Görevler <span x-show="(taskModal.subtasks || []).length" class="badge ml-1" x-text="subtaskStats.done + '/' + subtaskStats.total"></span>
            </button>
            <button class="modal-tab" :class="{ 'active': modalTab === 'checklist' }" @click="modalTab='checklist'">
                Checklist <span x-show="(taskModal.checklists || []).length" class="badge ml-1" x-text="checkStats.done + '/' + checkStats.total"></span>
            </button>
            <button class="modal-tab" :class="{ 'active': modalTab === 'files' }" @click="modalTab='files'">Dosyalar <span x-show="(taskModal.attachments || []).length" class="badge ml-1" x-text="taskModal.attachments.length"></span></button>
            <button class="modal-tab" :class="{ 'active': modalTab === 'recurrence' }" @click="modalTab='recurrence'">Tekrarlama</button>
        </div>

        <div class="modal-body">

            <!-- DETAYLAR -->
            <div x-show="modalTab === 'details'" class="space-y-3.5">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="field-label">Başlangıç</label>
                        <input type="text" class="input datepicker" x-model="taskModal.start_date" placeholder="—">
                    </div>
                    <div>
                        <label class="field-label">Bitiş</label>
                        <input type="text" class="input datepicker" x-model="taskModal.due_date" placeholder="—">
                    </div>
                    <div>
                        <label class="field-label">Saat</label>
                        <input type="text" class="input timepicker" x-model="taskModal.due_time" placeholder="—">
                    </div>
                    <div>
                        <label class="field-label">Konum</label>
                        <input type="text" class="input" x-model="taskModal.location" placeholder="Konum">
                    </div>
                </div>

                <div>
                    <label class="field-label">Öncelik</label>
                    <div class="grid grid-cols-4 gap-2">
                        <template x-for="p in 4" :key="'p' + p">
                            <button class="prio-btn" :class="{ 'active': taskModal.priority === (p - 1) }"
                                :style="taskModal.priority === (p - 1) ? 'border-color:' + prioColor(p - 1) + ';background:' + prioColor(p - 1) + '1a' : ''"
                                @click="taskModal.priority = p - 1">
                                <span class="w-2 h-2 rounded-full inline-block" :style="'background:' + prioColor(p - 1)"></span>
                                <span class="text-xs font-medium" x-text="['Düşük','Normal','Yüksek','Kritik'][p - 1]"></span>
                            </button>
                        </template>
                    </div>
                </div>

                <div>
                    <label class="field-label">Durum</label>
                    <div class="grid grid-cols-5 gap-2">
                        <template x-for="(s, i) in ['Bekliyor','Devam','Askıda','Tamamlandı','İptal']" :key="'s' + i">
                            <button class="status-btn" :class="{ 'active': taskModal.status === i }" @click="taskModal.status = i" x-text="s"></button>
                        </template>
                    </div>
                </div>

                <div>
                    <label class="field-label">İlerleme: <span x-text="taskModal.progress + '%'"></span></label>
                    <input type="range" min="0" max="100" step="5" class="slider-input w-full" x-model.number="taskModal.progress">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="field-label">Tahmini Süre (dk)</label>
                        <input type="number" min="0" class="input" x-model.number="taskModal.estimated_time" placeholder="—">
                    </div>
                    <div>
                        <label class="field-label">Gerçek Süre (dk)</label>
                        <input type="number" min="0" class="input" x-model.number="taskModal.actual_time" placeholder="—">
                    </div>
                </div>

                <div>
                    <label class="field-label">Klasör</label>
                <select class="input" x-model="taskModal.folder_id">
                    <option :value="null">Klasörsüz (Inbox)</option>
                        <template x-for="f in folders" :key="'fm' + f.id">
                            <option :value="f.id" x-text="f.name"></option>
                        </template>
                    </select>
                </div>

                <div>
                    <label class="field-label">Etiketler</label>
                    <div class="flex flex-wrap gap-1.5">
                        <template x-for="g in tags" :key="'tm' + g.id">
                            <button class="tag-chip selectable" :class="{ 'selected': taskTags.includes(g.id) }"
                                :style="taskTags.includes(g.id) ? 'background:' + g.color + '2b;color:' + g.color : ''"
                                @click="toggleTaskTag(g.id)">
                                <span x-text="g.emoji || '#'"></span> <span x-text="g.name"></span>
                            </button>
                        </template>
                    </div>
                </div>

                <div>
                    <label class="field-label">Görünüm</label>
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="material-symbols-rounded">face</span>
                        <input type="text" class="input !w-20 text-center" x-model="taskModal.emoji" placeholder="😀" maxlength="8">
                        <input type="color" class="color-input" x-model="taskModal.color" :value="taskModal.color || '#0A84FF'">
                        <input type="text" class="input flex-1" x-model="taskModal.icon" placeholder="İkon (örn. rocket_launch)">
                        <button class="chip" @click="taskModal.icon = pickRandomIcon()">🎲 Rastgele</button>
                    </div>
                </div>

                <div>
                    <label class="field-label">Açıklama</label>
                    <textarea class="input" rows="2" x-model="taskModal.description" placeholder="Açıklama ekle..."></textarea>
                </div>

                <div>
                    <label class="field-label">Notlar <span class="opacity-40 text-[10px]">(Markdown destekli)</span></label>
                    <textarea class="input font-mono" rows="5" x-model="taskModal.notes" placeholder="**Notlar**, - [ ] yapılacaklar, ```kod``` ..."></textarea>
                </div>

                <div class="flex items-center justify-between text-[11px] opacity-50 pt-1">
                    <span x-text="'Oluşturuldu: ' + timeAgo(taskModal.created_at)"></span>
                    <span x-show="taskModal.completed_at" x-text="'Tamamlandı: ' + timeAgo(taskModal.completed_at)"></span>
                </div>

                <div class="glass-inner p-3 rounded-xl prose-markdown" x-show="taskModal.notes" x-html="renderMarkdown(taskModal.notes)"></div>
            </div>

            <!-- ALT GÖREVLER -->
            <div x-show="modalTab === 'subtasks'" class="space-y-2">
                <div x-show="subtaskStats.total > 0" class="mini-progress !h-2 mb-3"><span :style="'width:' + subtaskStats.pct + '%'"></span></div>
                <div class="flex gap-2">
                    <input type="text" class="input flex-1" x-model="newSubtask" @keydown.enter="addSubtask" placeholder="Alt görev ekle...">
                    <button class="btn-primary !px-4" @click="addSubtask"><span class="material-symbols-rounded">add</span></button>
                </div>
                <div class="space-y-1">
                    <template x-for="s in taskModal.subtasks" :key="'sub' + s.id">
                        <div class="flex items-center gap-2.5 group p-2 rounded-xl hover:bg-black/5 dark:hover:bg-white/10" :class="{ 'opacity-60': s.completed }">
                            <button @click="toggleSubtask(s)" class="checkbox-apple" :class="{ 'checked': s.completed }"><span class="material-symbols-rounded">check</span></button>
                            <input type="text" class="flex-1 bg-transparent text-sm outline-none" :class="{ 'line-through': s.completed }"
                                :value="s.title" @change="renameSubtask(s, $event.target.value)">
                            <button class="row-action opacity-0 group-hover:opacity-100" @click="delSubtask(s)"><span class="material-symbols-rounded">close</span></button>
                        </div>
                    </template>
                    <p x-show="!taskModal.subtasks.length" class="text-xs opacity-50 text-center py-6">Alt görev yok. İlerleme otomatik hesaplanır.</p>
                </div>
            </div>

            <!-- CHECKLIST -->
            <div x-show="modalTab === 'checklist'" class="space-y-2">
                <div x-show="checkStats.total > 0" class="mini-progress !h-2 mb-3"><span :style="'width:' + checkStats.pct + '%'"></span></div>
                <div class="flex gap-2">
                    <input type="text" class="input flex-1" x-model="newChecklist" @keydown.enter="addChecklist" placeholder="Yeni madde...">
                    <button class="btn-primary !px-4" @click="addChecklist"><span class="material-symbols-rounded">add</span></button>
                </div>
                <div class="space-y-1">
                    <template x-for="c in taskModal.checklists" :key="'ch' + c.id">
                        <div class="flex items-center gap-2.5 group p-2 rounded-xl hover:bg-black/5 dark:hover:bg-white/10" :class="{ 'opacity-60': c.completed }">
                            <button @click="toggleChecklist(c)" class="checkbox-apple" :class="{ 'checked': c.completed }"><span class="material-symbols-rounded">check</span></button>
                            <span class="flex-1 text-sm" :class="{ 'line-through': c.completed }" x-text="c.title"></span>
                            <button class="row-action opacity-0 group-hover:opacity-100" @click="delChecklist(c)"><span class="material-symbols-rounded">close</span></button>
                        </div>
                    </template>
                    <p x-show="!taskModal.checklists.length" class="text-xs opacity-50 text-center py-6">Checklist boş.</p>
                </div>
            </div>

            <!-- DOSYALAR -->
            <div x-show="modalTab === 'files'" class="space-y-3">
                <div class="drop-zone" @click="$refs.fileInput.click()" @dragover.prevent="dropZoneOver = true" @dragleave="dropZoneOver = false" @drop.prevent="uploadFiles($event.dataTransfer.files)">
                    <span class="material-symbols-rounded text-3xl opacity-40">upload_file</span>
                    <p class="text-sm font-medium">Dosya sürükle veya tıkla</p>
                    <p class="text-xs opacity-50">En fazla 25MB</p>
                    <input type="file" x-ref="fileInput" class="hidden" multiple @change="uploadFiles($event.target.files)">
                </div>
                <div class="space-y-1.5">
                    <template x-for="a in taskModal.attachments" :key="'att' + a.id">
                        <div class="flex items-center gap-2.5 p-2.5 rounded-xl bg-black/5 dark:bg-white/10 group">
                            <span class="material-symbols-rounded opacity-60">description</span>
                            <div class="flex-1 min-w-0">
                                <div class="text-sm truncate" x-text="a.filename"></div>
                                <div class="text-[11px] opacity-50" x-text="fmtSize(a.filesize) + ' • ' + timeAgo(a.created_at)"></div>
                            </div>
                            <a :href="'api.php?action=attachment_download&id=' + a.id" class="row-action"><span class="material-symbols-rounded">download</span></a>
                            <button class="row-action" @click="delAttachment(a)"><span class="material-symbols-rounded">delete</span></button>
                        </div>
                    </template>
                    <p x-show="!taskModal.attachments.length" class="text-xs opacity-50 text-center py-6">Dosya eklenmemiş.</p>
                </div>
            </div>

            <!-- TEKRARLAMA -->
            <div x-show="modalTab === 'recurrence'" class="space-y-3.5">
                <div>
                    <label class="field-label">Tekrar Sıklığı</label>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-1.5">
                        <template x-for="r in recurFreqs" :key="r.value">
                            <button class="chip justify-center" :class="{ 'chip-active': recurForm.freq === r.value }"
                                @click="recurForm.freq = r.value; recurPreview()" x-text="r.label"></button>
                        </template>
                    </div>
                </div>

                <div x-show="recurForm.freq === 'custom'" class="space-y-3">
                    <div>
                        <label class="field-label">Haftanın günleri</label>
                        <div class="flex flex-wrap gap-1.5">
                            <template x-for="(d, i) in ['MON','TUE','WED','THU','FRI','SAT','SUN']" :key="d">
                                <button class="chip" :class="{ 'chip-active': recurForm.by_day.includes(d) }"
                                    @click="toggleCsv('by_day', d); recurPreview()" x-text="['Pzt','Sal','Çar','Per','Cum','Cmt','Paz'][i]"></button>
                            </template>
                        </div>
                    </div>
                    <div>
                        <label class="field-label">Ayın günleri (örn: 1,15)</label>
                        <input type="text" class="input" x-model="recurForm.by_month_day" @change="recurPreview" placeholder="1,15">
                    </div>
                    <div>
                        <label class="field-label">Aylar (örn: 3,9)</label>
                        <input type="text" class="input" x-model="recurForm.by_month" @change="recurPreview" placeholder="3,9">
                    </div>
                    <div>
                        <label class="field-label">Cron ifadesi</label>
                        <input type="text" class="input font-mono" x-model="recurForm.custom_cron" placeholder="0 9 * * MON">
                        <p class="text-[11px] opacity-40 mt-1">Örnek: <code>0 9 * * MON</code> = her Pazartesi 09:00</p>
                    </div>
                </div>

                <div x-show="recurForm.freq === 'custom' || recurForm.freq === 'weekly'" class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="field-label">Aralık</label>
                        <input type="number" min="1" max="30" class="input" x-model.number="recurForm.interval" @change="recurPreview">
                    </div>
                    <div>
                        <label class="field-label">Bitiş (ops.)</label>
                        <input type="text" class="input datepicker" x-model="recurForm.ends_on" placeholder="Sonsuz">
                    </div>
                </div>

                <div x-show="recurForm.freq !== 'none'">
                    <label class="field-label">Önizleme</label>
                    <div class="flex flex-wrap gap-1.5">
                        <template x-for="d in recurDates" :key="d">
                            <span class="tag-chip" x-text="d"></span>
                        </template>
                    </div>
                    <p class="text-[11px] opacity-40 mt-2">Görev tamamlandığında bir sonraki tekrar otomatik oluşturulur.</p>
                </div>
            </div>
        </div>

        <div class="modal-footer">
            <div class="flex items-center gap-2">
                <button class="btn-secondary" @click="closeModal">İptal</button>
                <label class="switch ml-1" title="Bittiğinde hatırlat">
                    <input type="checkbox" x-model="hasReminder" @change="syncReminderInput">
                    <span class="slider"></span>
                </label>
            </div>
            <button x-ref="modalSave" class="btn-primary" @click="saveTask">
                <span class="material-symbols-rounded">save</span> Kaydet
            </button>
        </div>
    </div>
</div>
</template>

<!-- ============ HIZLI EKLE ============ -->
<template x-teleport="body">
<div x-show="quickOpen" x-cloak class="modal-backdrop quick-backdrop" @click.self="quickOpen = false" @keydown.escape="quickOpen = false">
    <div class="quick-add glass-strong">
        <div class="flex items-center gap-3 p-4">
            <span class="material-symbols-rounded text-[#0A84FF]">bolt</span>
            <input x-ref="quickInput" type="text" class="flex-1 bg-transparent outline-none text-base"
                x-model="quickText" @keydown.enter="quickSubmit"
                placeholder="'Yarın saat 15'te Ahmet'i ara' gibi yazın...">
            <button class="btn-primary" @click="quickSubmit">Ekle</button>
        </div>
        <div class="px-4 pb-3 flex items-center gap-2 text-[11px] opacity-50 flex-wrap">
            <span class="chip">📅 Bugün / Yarın / 15 Mart</span>
            <span class="chip">🕐 Saat 15 / 15:30</span>
            <span class="chip">⚡ acil</span>
            <span class="chip">📁 #klasör</span>
            <span class="chip">❗ yüksek</span>
        </div>
        <div x-show="quickParsed" class="px-4 pb-4 text-sm">
            <div class="glass-inner rounded-xl p-3 flex items-center gap-2 flex-wrap">
                <span class="tag-chip">📅 <b x-text="quickParsed.date || '—'"></b></span>
                <span class="tag-chip">🕐 <b x-text="quickParsed.time || '—'"></b></span>
                <span class="tag-chip">⚡ <b x-text="quickParsed.priority || '—'"></b></span>
            </div>
        </div>
    </div>
</div>
</template>

<!-- ============ KLASÖR MODALI ============ -->
<template x-teleport="body">
<div x-show="folderModal.open" x-cloak class="modal-backdrop" @click.self="folderModal.open = false">
    <div class="modal glass-strong !max-w-sm">
        <h3 class="text-lg font-bold p-5 pb-2" x-text="folderModal.id ? 'Klasörü Düzenle' : 'Yeni Klasör'"></h3>
        <div class="p-5 space-y-3">
            <div>
                <label class="field-label">Ad</label>
                <input type="text" class="input" x-model="folderModal.name" placeholder="Klasör adı" @keydown.enter="saveFolder">
            </div>
            <div>
                <label class="field-label">Açıklama</label>
                <input type="text" class="input" x-model="folderModal.description" placeholder="Opsiyonel">
            </div>
            <div>
                <label class="field-label">İkon</label>
                <div class="flex flex-wrap gap-1.5">
                    <template x-for="i in folderIcons" :key="i">
                        <button class="icon-pick" :class="{ 'selected': folderModal.icon === i }" @click="folderModal.icon = i">
                            <span class="material-symbols-rounded"><span x-text="i"></span></span>
                        </button>
                    </template>
                </div>
            </div>
            <div>
                <label class="field-label">Renk</label>
                <div class="flex flex-wrap gap-2">
                    <template x-for="c in accentColors" :key="'fc' + c">
                        <button class="accent-dot" :class="{ 'active': folderModal.color === c }" :style="'background:' + c" @click="folderModal.color = c"></button>
                    </template>
                </div>
            </div>
            <div class="flex gap-2 pt-2" x-show="folderModal.id">
                <button class="btn-secondary flex-1" @click="confirmDeleteFolder">Sil</button>
            </div>
            <div class="flex gap-2 pt-2">
                <button class="btn-secondary flex-1" @click="folderModal.open = false">İptal</button>
                <button class="btn-primary flex-1" @click="saveFolder">Kaydet</button>
            </div>
        </div>
    </div>
</div>
</template>

<!-- ============ ETİKET MODALI ============ -->
<template x-teleport="body">
<div x-show="tagModal.open" x-cloak class="modal-backdrop" @click.self="tagModal.open = false">
    <div class="modal glass-strong !max-w-sm">
        <h3 class="text-lg font-bold p-5 pb-2" x-text="tagModal.id ? 'Etiketi Düzenle' : 'Yeni Etiket'"></h3>
        <div class="p-5 space-y-3">
            <div>
                <label class="field-label">Ad</label>
                <input type="text" class="input" x-model="tagModal.name" placeholder="örn. telefon" @keydown.enter="saveTag">
            </div>
            <div>
                <label class="field-label">Emoji</label>
                <input type="text" class="input" x-model="tagModal.emoji" placeholder="📞" maxlength="8">
            </div>
            <div>
                <label class="field-label">Renk</label>
                <div class="flex flex-wrap gap-2">
                    <template x-for="c in accentColors" :key="'tc' + c">
                        <button class="accent-dot" :class="{ 'active': tagModal.color === c }" :style="'background:' + c" @click="tagModal.color = c"></button>
                    </template>
                </div>
            </div>
            <div class="flex gap-2 pt-2" x-show="tagModal.id">
                <button class="btn-secondary flex-1" @click="confirmDeleteTag">Sil</button>
            </div>
            <div class="flex gap-2 pt-2">
                <button class="btn-secondary flex-1" @click="tagModal.open = false">İptal</button>
                <button class="btn-primary flex-1" @click="saveTag">Kaydet</button>
            </div>
        </div>
    </div>
</div>
</template>

<!-- ============ İÇE AKTAR ============ -->
<template x-teleport="body">
<div x-show="importOpen" x-cloak class="modal-backdrop" @click.self="importOpen = false">
    <div class="modal glass-strong !max-w-md">
        <h3 class="text-lg font-bold p-5 pb-2">İçe Aktar</h3>
        <div class="p-5 space-y-3">
            <div class="flex gap-2">
                <label class="chip" :class="{ 'chip-active': importType === 'json' }" @click="importType='json'">JSON</label>
                <label class="chip" :class="{ 'chip-active': importType === 'csv' }" @click="importType='csv'">CSV</label>
            </div>
            <textarea class="input font-mono" rows="8" x-model="importText" placeholder="JSON veya CSV içeriğini yapıştırın..."></textarea>
            <div class="flex items-center gap-2">
                <label class="btn-secondary flex-1 text-center cursor-pointer">
                    Dosya Seç
                    <input type="file" class="hidden" accept=".json,.csv" @change="importFile($event)">
                </label>
                <button class="btn-primary flex-1" @click="importSubmit" :disabled="!importText">Aktar</button>
            </div>
        </div>
    </div>
</div>
</template>

<!-- ============ POMODORO ============ -->
<template x-teleport="body">
<div x-show="pomodoroOpen_" x-cloak class="modal-backdrop" @click.self="pomodoroClose">
    <div class="modal glass-strong !max-w-sm text-center">
        <div class="p-8">
            <div class="relative w-44 h-44 mx-auto">
                <svg viewBox="0 0 120 120" class="w-full h-full -rotate-90">
                    <circle cx="60" cy="60" r="52" fill="none" stroke-width="8" class="pomo-track"/>
                    <circle cx="60" cy="60" r="52" fill="none" stroke-width="8" class="pomo-progress"
                        :stroke="pomodoro.mode === 'focus' ? '#FF453A' : '#30D158'"
                        :stroke-dasharray="pomodoroCircum"
                        :stroke-dashoffset="pomodoroOffset"/>
                </svg>
                <div class="absolute inset-0 flex flex-col items-center justify-center">
                    <div class="text-4xl font-bold tabular-nums" x-text="pomodoroDisplay"></div>
                    <div class="text-xs opacity-60 mt-1" x-text="pomodoro.mode === 'focus' ? 'Odak' : 'Mola'"></div>
                </div>
            </div>
            <div class="flex items-center justify-center gap-2 mt-6">
                <button class="btn-icon !w-12 !h-12 !text-xl" @click="pomodoroToggle">
                    <span class="material-symbols-rounded" x-text="pomodoro.running ? 'pause' : 'play_arrow'"></span>
                </button>
                <button class="btn-icon" @click="pomodoroReset"><span class="material-symbols-rounded">replay</span></button>
                <button class="btn-icon" @click="pomodoroSkip"><span class="material-symbols-rounded">skip_next</span></button>
                <button class="btn-icon" @click="pomodoroClose"><span class="material-symbols-rounded">close</span></button>
            </div>
        </div>
    </div>
</div>
</template>

<!-- ============ KISAYOLLAR ============ -->
<template x-teleport="body">
<div x-show="shortcutHelp" x-cloak class="modal-backdrop" @click.self="shortcutHelp = false">
    <div class="modal glass-strong !max-w-md">
        <h3 class="text-lg font-bold p-5 pb-3">Klavye Kısayolları</h3>
        <div class="p-5 pt-0 space-y-1.5 text-sm">
            <template x-for="k in shortcutsList" :key="k.keys">
                <div class="flex items-center justify-between py-1.5 border-b last:border-0" :style="'border-color: var(--border)'">
                    <span class="opacity-70" x-text="k.desc"></span>
                    <span class="kbd" x-text="k.keys"></span>
                </div>
            </template>
        </div>
    </div>
</div>
</template>

<!-- ============ ONAY ============ -->
<template x-teleport="body">
<div x-show="confirmMsg" x-cloak class="modal-backdrop" @click.self="confirmCancel">
    <div class="modal glass-strong !max-w-xs text-center">
        <div class="p-6">
            <div class="text-3xl mb-3">🤔</div>
            <h3 class="font-bold mb-1" x-text="confirmMsg.title"></h3>
            <p class="text-sm opacity-60 mb-5" x-text="confirmMsg.message"></p>
            <div class="flex gap-2">
                <button class="btn-secondary flex-1" @click="confirmCancel">Vazgeç</button>
                <button class="btn-primary flex-1 !bg-[#FF453A]" @click="confirmOk">Onayla</button>
            </div>
        </div>
    </div>
</div>
</template>

<!-- ============ BAĞLAM MENÜSÜ ============ -->
<div x-show="contextOpen" x-cloak class="context-menu glass-strong" :style="contextPos" @click.outside="contextOpen = false">
    <!-- Görev Bağlam Menüsü -->
    <template x-if="contextTask && !contextTask.kind">
        <div class="p-1.5 space-y-0.5 text-sm">
            <button class="ctx-item" @click="toggleTask(contextTask); contextOpen=false"><span class="material-symbols-rounded">check_circle</span>Tamamla / Geri Al</button>
            <button class="ctx-item" @click="setFlag(contextTask,'is_favorite', contextTask.is_favorite ? 0 : 1); contextOpen=false"><span class="material-symbols-rounded">star</span>Favori</button>
            <button class="ctx-item" @click="setFlag(contextTask,'is_pinned', contextTask.is_pinned ? 0 : 1); contextOpen=false"><span class="material-symbols-rounded">push_pin</span>Sabitle</button>
            <div class="ctx-divider"></div>
            <button class="ctx-item" @click="ctxMoveFolder"><span class="material-symbols-rounded">drive_file_move</span>Klasöre Taşı</button>
            <button class="ctx-item" @click="archiveTask(contextTask); contextOpen=false"><span class="material-symbols-rounded">archive</span>Arşivle</button>
            <button class="ctx-item danger" @click="trashTask(contextTask); contextOpen=false"><span class="material-symbols-rounded">delete</span>Sil</button>
        </div>
    </template>
    <!-- Klasör Bağlam Menüsü -->
    <template x-if="contextTask && contextTask.kind === 'folder'">
        <div class="p-1.5 space-y-0.5 text-sm">
            <button class="ctx-item" @click="ctxEdit"><span class="material-symbols-rounded">edit</span>Yeniden Adlandır / Düzenle</button>
            <button class="ctx-item" @click="ctxAddTask"><span class="material-symbols-rounded">add_task</span>Bu klasöre görev ekle</button>
            <div class="ctx-divider"></div>
            <button class="ctx-item danger" @click="ctxDelete"><span class="material-symbols-rounded">delete</span>Klasörü Sil</button>
        </div>
    </template>
    <!-- Etiket Bağlam Menüsü -->
    <template x-if="contextTask && contextTask.kind === 'tag'">
        <div class="p-1.5 space-y-0.5 text-sm">
            <button class="ctx-item" @click="ctxEdit"><span class="material-symbols-rounded">edit</span>Yeniden Adlandır / Düzenle</button>
            <button class="ctx-item" @click="ctxAddTask"><span class="material-symbols-rounded">add_task</span>Bu etiketle görev ekle</button>
            <div class="ctx-divider"></div>
            <button class="ctx-item danger" @click="ctxDelete"><span class="material-symbols-rounded">delete</span>Etiketi Sil</button>
        </div>
    </template>
</div>

<!-- Toast -->
<div class="toast-stack">
    <template x-for="t in toasts" :key="t.id">
        <div class="toast glass-strong" :class="'toast-' + t.type">
            <span class="material-symbols-rounded text-[18px]" x-text="t.icon"></span>
            <span class="flex-1 text-sm" x-text="t.message"></span>
            <button @click="removeToast(t.id)" class="opacity-60 text-sm">✕</button>
        </div>
    </template>
</div>

<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/tr.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
