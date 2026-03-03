<?php
// dashboard.php (Router Utama)
define('APP_LOADED', true); // Penanda keamanan bahwa aplikasi dimuat dengan benar

// Memulai session dan koneksi database
// Menggunakan __DIR__ untuk path yang lebih andal
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/helpers.php';

// Memeriksa apakah user sudah login, jika tidak, arahkan ke halaman login
if (!is_logged_in()) {
    header("location: index.php");
    exit;
}

// Memasukkan header (termasuk sidebar dan bagian atas halaman)
require_once __DIR__ . '/_header.php';

// --- Logika Routing ---
// Mengambil halaman yang diminta dari URL, default-nya adalah 'dashboard'
$page = $_GET['page'] ?? 'dashboard';

// Daftar halaman yang valid untuk mencegah error
$allowed_pages = [
    'dashboard', 'hadiah', 'kategori', 'transaksi_masuk', 'transaksi_keluar',
    'donatur', 'laporan_distribusi', 'laporan_masuk', 'laporan_keluar',
    'aktivitas', 'users', 'barang_masuk', 'permintaan_stok', 'supplier',
    'proses_apresiasi', 'ajax_handler'
];

// Membuat nama file dari parameter 'page'
$page_file = ($page === 'ajax_handler') ? "{$page}.php" : "page_{$page}.php";

// Memeriksa apakah file halaman yang diminta ada dan valid, lalu memuatnya
if (in_array($page, $allowed_pages) && file_exists(__DIR__ . '/' . $page_file)) {
    require_once __DIR__ . '/' . $page_file;
} else {
    // Jika halaman tidak ditemukan, tampilkan pesan error
    echo "<div class='bg-red-100 p-4 rounded-md'>Halaman tidak ditemukan.</div>";
}
// --- Akhir Logika Routing ---


// Memasukkan footer (bagian bawah halaman dan tag penutup)
require_once __DIR__ . '/_footer.php';

?>

