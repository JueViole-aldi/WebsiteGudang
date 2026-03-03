<?php
// Ambil data sesi untuk ditampilkan
$user_role = $_SESSION['role'];
$user_name = $_SESSION['name'];
$user_avatar_char = $_SESSION['avatar_char'];
$current_page = $_GET['page'] ?? 'dashboard';

// Mengambil jumlah notifikasi yang belum dibaca (KOLOM DIPERBAIKI)
$unread_notif_query = $mysqli->prepare("SELECT COUNT(*) as total FROM notifikasi WHERE id_user = ? AND sudah_dibaca = 0");
$unread_notif_query->bind_param('i', $_SESSION['id']);
$unread_notif_query->execute();
$unread_count = $unread_notif_query->get_result()->fetch_assoc()['total'] ?? 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Gudang Hadiah</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <style>
        :root {
            --primary-color: #0d9488; /* Teal-600 */
            --primary-hover: #0f766e; /* Teal-700 */
            --light-bg: #f8fafc; /* Slate-50 */
            --border-color: #e2e8f0; /* Slate-200 */
        }
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--light-bg);
        }
        
        /* Desain Sidebar */
        .sidebar {
            transition: width 0.3s ease, transform 0.3s ease;
            border-right: 1px solid var(--border-color);
        }
        .sidebar-link {
            display: flex;
            align-items: center;
            padding: 0.75rem 1.25rem;
            border-radius: 0.5rem;
            font-weight: 500;
            color: #475569; /* Slate-600 */
            transition: all 0.2s ease-in-out;
            position: relative;
            overflow: hidden;
            white-space: nowrap;
        }
        .sidebar-link-icon {
            flex-shrink: 0;
            transition: transform 0.2s ease-in-out, margin 0.2s ease-in-out;
        }
        .sidebar-link-text {
            transition: opacity 0.2s ease;
        }
        .sidebar-link:hover .sidebar-link-icon {
             transform: scale(1.1) rotate(-5deg);
        }
        .sidebar-link:before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 4px;
            height: 100%;
            background-color: var(--primary-color);
            transform: scaleY(0);
            transition: transform 0.2s ease;
            border-radius: 0 4px 4px 0;
        }
        .sidebar-link:hover {
            background-color: #f1f5f9; /* Slate-100 */
            color: var(--primary-hover);
        }
        .sidebar-link.active {
            background-color: #ccfbf1; /* Teal-100 */
            color: var(--primary-color);
            font-weight: 600;
        }
        .sidebar-link.active:before {
            transform: scaleY(1);
        }
        
        /* Tooltip saat sidebar diciutkan */
        .sidebar-link .tooltip {
            visibility: hidden;
            opacity: 0;
            position: absolute;
            left: calc(100% + 10px); /* Jarak dari ikon */
            top: 50%;
            transform: translateY(-50%);
            padding: 0.5rem 0.75rem;
            border-radius: 0.375rem;
            background-color: #1e293b; /* Slate-800 */
            color: white;
            font-size: 0.875rem;
            white-space: nowrap;
            transition: opacity 0.2s ease, visibility 0.2s ease;
            z-index: 50;
        }
        
        .sidebar.collapsed .sidebar-link:hover .tooltip {
            visibility: visible;
            opacity: 1;
        }

        /* Perbaikan untuk Ikon & Teks saat Sidebar diciutkan */
        .sidebar.collapsed .sidebar-link {
            justify-content: center;
        }
        .sidebar.collapsed .sidebar-link-icon {
            margin-right: 0;
        }
        .sidebar.collapsed .sidebar-link-text,
        .sidebar.collapsed .px-4.pt-4.pb-2,
        .sidebar.collapsed .truncate {
            opacity: 0;
            width: 0;
            overflow: hidden;
        }
        /* PERBAIKAN: Tata letak profil saat diciutkan */
        .sidebar.collapsed .user-profile-container {
            justify-content: center;
        }
        .sidebar.collapsed .user-profile-container .ml-3 {
            margin-left: 0;
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background-color: #ef4444; /* Red-500 */
            color: white;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }
        #notification-dropdown {
            max-height: 400px;
            overflow-y: auto;
        }
        
        /* Menghilangkan Scrollbar Navigasi */
        .sidebar-nav {
            -ms-overflow-style: none;  /* IE and Edge */
            scrollbar-width: none;  /* Firefox */
        }
        .sidebar-nav::-webkit-scrollbar {
            display: none; /* Chrome, Safari, Opera */
        }

    </style>
</head>
<body class="bg-slate-50">
    <div id="page-transition-overlay" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: white; z-index: 9999; opacity: 1; transition: opacity 0.25s ease-in-out; pointer-events: none;"></div>

    <div id="app-container" class="flex h-screen">
        <aside id="sidebar" class="w-64 bg-white text-slate-800 flex flex-col fixed h-full shadow-lg sidebar">
            <div class="px-6 py-5 border-b border-slate-200 flex items-center shrink-0">
                <div class="p-2 bg-teal-100 rounded-lg">
                    <i data-lucide="gift" class="w-7 h-7 text-teal-600"></i>
                </div>
                <h1 class="text-xl font-bold ml-3 text-slate-800 sidebar-link-text">Gudang QUPRO</h1>
            </div>
            <nav id="sidebar-nav" class="flex-1 px-4 py-4 space-y-1.5 overflow-y-auto sidebar-nav">
                <!-- Menu Umum -->
                <a href="dashboard.php?page=dashboard" class="sidebar-link <?php echo ($current_page == 'dashboard') ? 'active' : ''; ?>">
                    <i data-lucide="layout-dashboard" class="w-5 h-5 mr-3 sidebar-link-icon"></i><span class="sidebar-link-text">Dashboard</span><span class="tooltip">Dashboard</span>
                </a>

                <!-- Grup Menu untuk Supplier -->
                <?php if (has_access(['supplier'])): ?>
                <p class="px-4 pt-4 pb-2 text-xs font-semibold text-slate-400 uppercase tracking-wider sidebar-link-text">Aktivitas</p>
                <a href="dashboard.php?page=permintaan_stok" class="sidebar-link <?php echo ($current_page == 'permintaan_stok') ? 'active' : ''; ?>">
                    <i data-lucide="shopping-cart" class="w-5 h-5 mr-3 sidebar-link-icon"></i><span class="sidebar-link-text">Permintaan Stok</span><span class="tooltip">Permintaan Stok</span>
                </a>
                <a href="dashboard.php?page=barang_masuk" class="sidebar-link <?php echo ($current_page == 'barang_masuk') ? 'active' : ''; ?>">
                    <i data-lucide="truck" class="w-5 h-5 mr-3 sidebar-link-icon"></i><span class="sidebar-link-text">Barang Masuk</span><span class="tooltip">Barang Masuk</span>
                </a>
                <?php endif; ?>

                <!-- Grup Menu untuk Staff & Admin -->
                <?php if (has_access(['admin', 'staff'])): ?>
                <p class="px-4 pt-4 pb-2 text-xs font-semibold text-slate-400 uppercase tracking-wider sidebar-link-text">Manajemen Gudang</p>
                <a href="dashboard.php?page=proses_apresiasi" class="sidebar-link <?php echo ($current_page == 'proses_apresiasi') ? 'active' : ''; ?>">
                    <i data-lucide="send" class="w-5 h-5 mr-3 sidebar-link-icon"></i><span class="sidebar-link-text">Proses Apresiasi</span><span class="tooltip">Proses Apresiasi</span>
                </a>
                 <a href="dashboard.php?page=permintaan_stok" class="sidebar-link <?php echo ($current_page == 'permintaan_stok') ? 'active' : ''; ?>">
                    <i data-lucide="shopping-cart" class="w-5 h-5 mr-3 sidebar-link-icon"></i><span class="sidebar-link-text">Permintaan Stok</span><span class="tooltip">Permintaan Stok</span>
                </a>
                <a href="dashboard.php?page=barang_masuk" class="sidebar-link <?php echo ($current_page == 'barang_masuk') ? 'active' : ''; ?>">
                    <i data-lucide="truck" class="w-5 h-5 mr-3 sidebar-link-icon"></i><span class="sidebar-link-text">Barang Masuk</span><span class="tooltip">Barang Masuk</span>
                </a>
                <a href="dashboard.php?page=hadiah" class="sidebar-link <?php echo ($current_page == 'hadiah') ? 'active' : ''; ?>">
                    <i data-lucide="package-search" class="w-5 h-5 mr-3 sidebar-link-icon"></i><span class="sidebar-link-text">Daftar Hadiah</span><span class="tooltip">Daftar Hadiah</span>
                </a>
                <?php if (has_access(['admin'])): ?>
                <a href="dashboard.php?page=kategori" class="sidebar-link <?php echo ($current_page == 'kategori') ? 'active' : ''; ?>">
                    <i data-lucide="archive" class="w-5 h-5 mr-3 sidebar-link-icon"></i><span class="sidebar-link-text">Kategori Hadiah</span><span class="tooltip">Kategori Hadiah</span>
                </a>
                <?php endif; ?>
                <?php endif; ?>

                <!-- Grup Menu untuk Manajer & Admin -->
                <?php if (has_access(['admin', 'manajer'])): ?>
                <p class="px-4 pt-4 pb-2 text-xs font-semibold text-slate-400 uppercase tracking-wider sidebar-link-text">Data & Laporan</p>
                <a href="dashboard.php?page=donatur" class="sidebar-link <?php echo ($current_page == 'donatur') ? 'active' : ''; ?>">
                    <i data-lucide="users" class="w-5 h-5 mr-3 sidebar-link-icon"></i><span class="sidebar-link-text">Data Donatur</span><span class="tooltip">Data Donatur</span>
                </a>
                <a href="dashboard.php?page=laporan_distribusi" class="sidebar-link <?php echo ($current_page == 'laporan_distribusi') ? 'active' : ''; ?>">
                    <i data-lucide="user-check" class="w-5 h-5 mr-3 sidebar-link-icon"></i><span class="sidebar-link-text">Laporan per Donatur</span><span class="tooltip">Laporan per Donatur</span>
                </a>
                <a href="dashboard.php?page=laporan_masuk" class="sidebar-link <?php echo ($current_page == 'laporan_masuk') ? 'active' : ''; ?>">
                    <i data-lucide="file-input" class="w-5 h-5 mr-3 sidebar-link-icon"></i><span class="sidebar-link-text">Laporan Masuk</span><span class="tooltip">Laporan Masuk</span>
                </a>
                 <a href="dashboard.php?page=laporan_keluar" class="sidebar-link <?php echo ($current_page == 'laporan_keluar') ? 'active' : ''; ?>">
                    <i data-lucide="file-output" class="w-5 h-5 mr-3 sidebar-link-icon"></i><span class="sidebar-link-text">Laporan Keluar</span><span class="tooltip">Laporan Keluar</span>
                </a>
                <?php endif; ?>
                
                <!-- Grup Menu Khusus Admin -->
                <?php if (has_access(['admin'])): ?>
                <p class="px-4 pt-4 pb-2 text-xs font-semibold text-slate-400 uppercase tracking-wider sidebar-link-text">Administrasi</p>
                 <a href="dashboard.php?page=supplier" class="sidebar-link <?php echo ($current_page == 'supplier') ? 'active' : ''; ?>">
                    <i data-lucide="contact" class="w-5 h-5 mr-3 sidebar-link-icon"></i><span class="sidebar-link-text">Data Supplier</span><span class="tooltip">Data Supplier</span>
                </a>
                <a href="dashboard.php?page=users" class="sidebar-link <?php echo ($current_page == 'users') ? 'active' : ''; ?>">
                    <i data-lucide="user-cog" class="w-5 h-5 mr-3 sidebar-link-icon"></i><span class="sidebar-link-text">Manajemen User</span><span class="tooltip">Manajemen User</span>
                </a>
                <a href="dashboard.php?page=aktivitas" class="sidebar-link <?php echo ($current_page == 'aktivitas') ? 'active' : ''; ?>">
                    <i data-lucide="history" class="w-5 h-5 mr-3 sidebar-link-icon"></i><span class="sidebar-link-text">Riwayat Aktivitas</span><span class="tooltip">Riwayat Aktivitas</span>
                </a>
                <?php endif; ?>
            </nav>
            <div class="px-4 py-4 border-t border-slate-200 shrink-0">
                <div class="flex items-center p-2 bg-slate-50 rounded-lg user-profile-container">
                    <img src="https://placehold.co/40x40/0d9488/ffffff?text=<?php echo htmlspecialchars($user_avatar_char); ?>" alt="Avatar" class="w-10 h-10 rounded-full">
                    <div class="ml-3 flex-1 overflow-hidden">
                        <p class="font-semibold text-sm text-slate-800 truncate sidebar-link-text"><?php echo htmlspecialchars($user_name); ?></p>
                        <p class="text-xs text-slate-500 capitalize sidebar-link-text"><?php echo htmlspecialchars($user_role); ?></p>
                    </div>
                    <a href="logout.php" class="text-slate-500 hover:text-red-500 transition-colors" title="Logout">
                        <i data-lucide="log-out" class="w-5 h-5"></i>
                    </a>
                </div>
                 <button id="sidebar-toggle" class="w-full mt-4 flex items-center justify-center p-2 rounded-lg hover:bg-slate-100 text-slate-600 transition-colors">
                    <i data-lucide="chevrons-left" class="w-5 h-5 transition-transform duration-300"></i>
                </button>
            </div>
        </aside>

        <div id="main-content" class="flex-1 ml-64 flex flex-col transition-all duration-300">
            <header class="bg-white/80 backdrop-blur-lg border-b border-slate-200 p-4 sticky top-0 z-10 flex justify-end items-center">
                <div class="relative">
                    <button id="notification-button" class="p-2 rounded-full hover:bg-slate-100 text-slate-600">
                        <i data-lucide="bell" class="w-6 h-6"></i>
                        <?php if ($unread_count > 0): ?>
                        <span id="notification-badge" class="notification-badge"><?php echo $unread_count; ?></span>
                        <?php endif; ?>
                    </button>
                    <div id="notification-dropdown" class="absolute right-0 mt-2 w-80 bg-white rounded-lg shadow-xl border border-slate-200 z-20 hidden">
                        <div class="p-4 border-b">
                            <h3 class="font-semibold">Notifikasi</h3>
                        </div>
                        <div id="notification-list" class="divide-y divide-slate-100">
                            <!-- Notifikasi akan dimuat di sini oleh JavaScript -->
                            <div class="p-4 text-center text-slate-500">Memuat...</div>
                        </div>
                    </div>
                </div>
            </header>
            <main class="flex-1 p-8 overflow-y-auto">

