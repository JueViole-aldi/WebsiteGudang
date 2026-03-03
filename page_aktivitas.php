<?php
// page_aktivitas.php
defined('APP_LOADED') or die('Akses langsung ke file ini tidak diizinkan.');

$is_accessible = has_access(['admin']);
$success_message = '';
$error_message = '';

// --- LOGIKA PENGHAPUSAN LOG UNTUK ADMIN ---
if ($is_accessible && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['id']; // Ambil user ID untuk logging

    // Aksi hapus log HARI INI
    if (isset($_POST['delete_today'])) {
        $sql = "DELETE FROM log_aktivitas WHERE DATE(timestamp) = CURDATE()";
        if ($mysqli->query($sql)) {
            $deleted_count = $mysqli->affected_rows;
            log_activity($mysqli, $user_id, "Menghapus {$deleted_count} log aktivitas hari ini.");
            $success_message = "Berhasil menghapus {$deleted_count} log aktivitas hari ini.";
        } else {
            $error_message = "Gagal menghapus log: " . $mysqli->error;
        }
    }

    // Aksi hapus log LAMA (lebih dari 30 hari)
    if (isset($_POST['delete_old'])) {
        $sql = "DELETE FROM log_aktivitas WHERE timestamp < NOW() - INTERVAL 30 DAY";
        if ($mysqli->query($sql)) {
            $deleted_count = $mysqli->affected_rows;
            log_activity($mysqli, $user_id, "Menghapus {$deleted_count} log aktivitas lama (lebih dari 30 hari).");
            $success_message = "Berhasil menghapus {$deleted_count} log aktivitas lama.";
        } else {
            $error_message = "Gagal menghapus log: " . $mysqli->error;
        }
    }

    // Aksi hapus SELURUH log
    if (isset($_POST['delete_all'])) {
        // TRUNCATE TABLE lebih efisien untuk mengosongkan seluruh tabel
        $sql = "TRUNCATE TABLE log_aktivitas";
        if ($mysqli->query($sql)) {
            $success_message = "Berhasil menghapus seluruh riwayat aktivitas.";
            // Tidak ada log_activity di sini karena tabelnya sudah kosong
        } else {
            $error_message = "Gagal menghapus seluruh log: " . $mysqli->error;
        }
    }
}
// --- AKHIR LOGIKA PENGHAPUSAN ---


// Ambil data log aktivitas (diambil ulang setelah kemungkinan penghapusan)
$result = $mysqli->query("
    SELECT l.timestamp, u.name, l.aktivitas
    FROM log_aktivitas l
    JOIN users u ON l.id_user = u.id
    ORDER BY l.timestamp DESC
");
?>
<div id="riwayat-aktivitas">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Riwayat Aktivitas</h1>
        
        <!-- Tombol Aksi hanya untuk Admin -->
        <?php if ($is_accessible): ?>
        <div class="relative" x-data="{ open: false }">
            <button @click="open = !open" class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500">
                <i data-lucide="settings-2" class="w-4 h-4 mr-2"></i>
                Aksi
                <i data-lucide="chevron-down" class="w-4 h-4 ml-2 -mr-1"></i>
            </button>

            <div x-show="open" @click.away="open = false" 
                 x-transition:enter="transition ease-out duration-100"
                 x-transition:enter-start="transform opacity-0 scale-95"
                 x-transition:enter-end="transform opacity-100 scale-100"
                 x-transition:leave="transition ease-in duration-75"
                 x-transition:leave-start="transform opacity-100 scale-100"
                 x-transition:leave-end="transform opacity-0 scale-95"
                 class="origin-top-right absolute right-0 mt-2 w-56 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-10"
                 style="display: none;">
                <div class="py-1">
                    <form method="POST" action="dashboard.php?page=aktivitas" class="w-full">
                        <button type="submit" name="delete_today" 
                                onclick="return confirm('Anda yakin ingin menghapus SEMUA log aktivitas untuk HARI INI?')"
                                class="w-full text-left flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-gray-900">
                            <i data-lucide="trash-2" class="w-4 h-4 mr-3"></i>
                            Hapus Log Hari Ini
                        </button>
                        <button type="submit" name="delete_old"
                                onclick="return confirm('Anda yakin ingin menghapus log yang lebih tua dari 30 HARI? Aksi ini tidak dapat dibatalkan.')"
                                class="w-full text-left flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 hover:text-gray-900">
                            <i data-lucide="archive" class="w-4 h-4 mr-3"></i>
                            Hapus Log Lama (> 30 hari)
                        </button>
                        <div class="border-t border-gray-100 my-1"></div>
                        <button type="submit" name="delete_all"
                                onclick="return confirm('PERINGATAN! Anda akan menghapus SELURUH riwayat aktivitas. Aksi ini tidak dapat dibatalkan. Lanjutkan?')"
                                class="w-full text-left flex items-center px-4 py-2 text-sm text-red-700 hover:bg-red-50 hover:text-red-900">
                            <i data-lucide="shield-alert" class="w-4 h-4 mr-3"></i>
                            Hapus Seluruh Log
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Notifikasi Sukses/Error -->
    <?php if ($success_message): ?><div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-md" role="alert"><p><?php echo $success_message; ?></p></div><?php endif; ?>
    <?php if ($error_message): ?><div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-md" role="alert"><p><?php echo $error_message; ?></p></div><?php endif; ?>


    <?php if ($is_accessible): ?>
    <div class="bg-white p-6 rounded-xl shadow-md">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Log Aktivitas Pengguna</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="bg-slate-50 border-b">
                        <th class="py-3 px-4 font-semibold text-slate-600">Waktu</th>
                        <th class="py-3 px-4 font-semibold text-slate-600">Pengguna</th>
                        <th class="py-3 px-4 font-semibold text-slate-600">Aktivitas</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                        <tr class="border-b hover:bg-slate-50">
                            <td class="py-3 px-4 text-gray-600 whitespace-nowrap"><?php echo date('d M Y, H:i:s', strtotime($row['timestamp'])); ?></td>
                            <td class="py-3 px-4 font-medium text-gray-800"><?php echo htmlspecialchars($row['name']); ?></td>
                            <td class="py-3 px-4 text-gray-600"><?php echo htmlspecialchars($row['aktivitas']); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="3" class="text-center py-4 text-gray-500">Belum ada aktivitas yang tercatat.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php else: ?>
        <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 rounded-md" role="alert">
            <p>Anda tidak memiliki hak akses untuk melihat halaman ini.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Menambahkan Alpine.js untuk dropdown interaktif -->
<script src="https://cdn.jsdelivr.net/gh/alpinejs/alpine@v2.x.x/dist/alpine.min.js" defer></script>

