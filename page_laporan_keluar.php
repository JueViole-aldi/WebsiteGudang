<?php
// page_laporan_keluar.php
defined('APP_LOADED') or die('Akses langsung ke file ini tidak diizinkan.');

$is_accessible = has_access(['admin', 'manajer']);
$is_admin = has_access(['admin']); 
$success_message = '';
$error_message = '';

// --- LOGIKA HAPUS TRANSAKSI TUNGGAL (HANYA ADMIN) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_transaksi']) && $is_admin) {
    $id_transaksi = (int)$_POST['id_transaksi'];
    $id_hadiah = (int)$_POST['id_hadiah'];
    $jumlah = (int)$_POST['jumlah'];

    if ($id_transaksi > 0 && $id_hadiah > 0 && $jumlah > 0) {
        $mysqli->begin_transaction();
        try {
            // 1. Kembalikan stok ke tabel hadiah
            $stmt_update = $mysqli->prepare("UPDATE hadiah SET stok = stok + ? WHERE id = ?");
            $stmt_update->bind_param("ii", $jumlah, $id_hadiah);
            $stmt_update->execute();

            // 2. Hapus record transaksi
            $stmt_delete = $mysqli->prepare("DELETE FROM transaksi_hadiah WHERE id = ?");
            $stmt_delete->bind_param("i", $id_transaksi);
            $stmt_delete->execute();
            
            $mysqli->commit();
            if (function_exists('log_activity')) { log_activity($mysqli, $_SESSION['id'], "Menghapus transaksi keluar ID: {$id_transaksi} dan mengembalikan {$jumlah} stok."); }
            $success_message = "Transaksi berhasil dihapus dan stok telah dikembalikan.";

        } catch (Exception $e) {
            $mysqli->rollback();
            $error_message = "Gagal menghapus transaksi: " . $e->getMessage();
        }
    } else {
        $error_message = "Data tidak valid untuk penghapusan.";
    }
}

// --- LOGIKA HAPUS BANYAK TRANSAKSI (HANYA ADMIN) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_multiple']) && $is_admin) {
    if (!empty($_POST['transaksi_ids'])) {
        $transaksi_ids = $_POST['transaksi_ids'];
        $sanitized_ids = array_map('intval', $transaksi_ids);
        $ids_placeholder = implode(',', array_fill(0, count($sanitized_ids), '?'));
        
        $mysqli->begin_transaction();
        try {
            $sql_get_data = "SELECT id, id_hadiah, jumlah FROM transaksi_hadiah WHERE id IN ($ids_placeholder)";
            $stmt_get_data = $mysqli->prepare($sql_get_data);
            $stmt_get_data->bind_param(str_repeat('i', count($sanitized_ids)), ...$sanitized_ids);
            $stmt_get_data->execute();
            $result_data = $stmt_get_data->get_result();
            
            $items_to_process = [];
            while ($row = $result_data->fetch_assoc()) { $items_to_process[] = $row; }

            if (count($items_to_process) > 0) {
                $stmt_update = $mysqli->prepare("UPDATE hadiah SET stok = stok + ? WHERE id = ?");
                foreach ($items_to_process as $item) {
                    $stmt_update->bind_param("ii", $item['jumlah'], $item['id_hadiah']);
                    $stmt_update->execute();
                }
                $stmt_delete = $mysqli->prepare("DELETE FROM transaksi_hadiah WHERE id IN ($ids_placeholder)");
                $stmt_delete->bind_param(str_repeat('i', count($sanitized_ids)), ...$sanitized_ids);
                $stmt_delete->execute();
                
                $mysqli->commit();
                $deleted_count = count($sanitized_ids);
                if (function_exists('log_activity')) { log_activity($mysqli, $_SESSION['id'], "Menghapus {$deleted_count} transaksi keluar dan mengembalikan stok."); }
                $success_message = "{$deleted_count} transaksi berhasil dihapus dan stok telah dikembalikan.";
            } else { throw new Exception("Tidak ada transaksi valid."); }
        } catch (Exception $e) {
            $mysqli->rollback();
            $error_message = "Gagal hapus massal: " . $e->getMessage();
        }
    } else {
        $error_message = "Tidak ada transaksi yang dipilih.";
    }
}

// --- FILTER & QUERY ---
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$sql = "
    SELECT t.id, t.tanggal_transaksi, h.nama_hadiah, h.id as id_hadiah, d.nama_donatur, u.name as nama_user, t.jumlah, t.keterangan
    FROM transaksi_hadiah t
    JOIN hadiah h ON t.id_hadiah = h.id
    JOIN users u ON t.id_user = u.id
    LEFT JOIN donatur d ON t.id_donatur = d.id
    WHERE t.tipe_transaksi = 'keluar'
";
$params = [];
$types = '';

// Perbaikan filter jam (00:00:00 sampai 23:59:59)
if (!empty($start_date)) { 
    $sql .= " AND t.tanggal_transaksi >= ?"; 
    $params[] = $start_date . " 00:00:00"; 
    $types .= 's'; 
}
if (!empty($end_date)) { 
    $sql .= " AND t.tanggal_transaksi <= ?"; 
    $params[] = $end_date . " 23:59:59"; 
    $types .= 's'; 
}
$sql .= " ORDER BY t.tanggal_transaksi DESC";

$stmt = $mysqli->prepare($sql);
if (!empty($params)) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$result = $stmt->get_result();

// Himpun data dan hitung total
$total_transaksi = 0;
$total_unit_keluar = 0;
$laporan_data = [];

if ($result && $result->num_rows > 0) {
    $total_transaksi = $result->num_rows;
    while ($row = $result->fetch_assoc()) {
        $total_unit_keluar += (int)$row['jumlah'];
        $laporan_data[] = $row;
    }
}

// --- PEMBUATAN URL EXPORT DINAMIS ---
$export_url = "export_handler.php?report=laporan_keluar";
if (!empty($start_date)) $export_url .= "&start_date=" . urlencode($start_date);
if (!empty($end_date)) $export_url .= "&end_date=" . urlencode($end_date);
?>

<div id="laporan-keluar">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-800">Laporan Hadiah Keluar</h1>
            <p class="text-gray-500 mt-1">Pantau distribusi hadiah apresiasi untuk donatur.</p>
        </div>
        <?php if ($is_accessible): ?>
        <!-- Link diubah agar menggunakan $export_url yang membawa data tanggal -->
        <a href="<?php echo htmlspecialchars($export_url); ?>" target="_blank" class="inline-flex items-center px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold rounded-lg transition-all shadow-lg shadow-emerald-100">
            <i class="fas fa-file-excel mr-2"></i> Export ke Excel
        </a>
        <?php endif; ?>
    </div>

    <?php if ($is_accessible): ?>
    <?php if ($success_message): ?><div class="bg-emerald-100 border-l-4 border-emerald-500 text-emerald-700 p-4 mb-6 rounded-md shadow-sm animate-fade-in"><p><?php echo $success_message; ?></p></div><?php endif; ?>
    <?php if ($error_message): ?><div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-md shadow-sm animate-fade-in"><p><?php echo $error_message; ?></p></div><?php endif; ?>

    <div class="bg-white p-6 rounded-2xl shadow-md border border-gray-100">
        <!-- Filter Bar -->
        <form method="GET" action="dashboard.php" class="mb-8 grid grid-cols-1 md:grid-cols-4 gap-4 items-end bg-gray-50 p-4 rounded-xl border border-gray-100">
            <input type="hidden" name="page" value="laporan_keluar">
            <div>
                <label for="start_date" class="block text-xs font-bold text-gray-500 uppercase mb-1">Dari Tanggal</label>
                <input type="date" name="start_date" id="start_date" value="<?php echo htmlspecialchars($start_date); ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 bg-white focus:ring-2 focus:ring-blue-500 outline-none">
            </div>
            <div>
                <label for="end_date" class="block text-xs font-bold text-gray-500 uppercase mb-1">Sampai Tanggal</label>
                <input type="date" name="end_date" id="end_date" value="<?php echo htmlspecialchars($end_date); ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 bg-white focus:ring-2 focus:ring-blue-500 outline-none">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="flex-1 bg-blue-600 text-white font-bold py-2 rounded-lg hover:bg-blue-700 transition-colors shadow-sm">Filter</button>
                <a href="dashboard.php?page=laporan_keluar" class="px-4 py-2 bg-gray-200 text-gray-700 font-bold rounded-lg hover:bg-gray-300 transition-colors text-center">Reset</a>
            </div>
        </form>

        <!-- WIDGET RINGKASAN DATA -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
            <div class="bg-gradient-to-r from-blue-500 to-indigo-600 rounded-2xl p-6 text-white shadow-lg shadow-blue-200 flex items-center">
                <div class="bg-white/20 p-4 rounded-xl mr-5">
                    <i class="fas fa-file-invoice text-3xl"></i>
                </div>
                <div>
                    <p class="text-blue-100 text-sm font-bold uppercase tracking-wider mb-1">Total Transaksi Keluar</p>
                    <h3 class="text-3xl font-extrabold"><?php echo number_format($total_transaksi); ?> <span class="text-lg font-medium">kali</span></h3>
                </div>
            </div>
            <div class="bg-gradient-to-r from-emerald-500 to-teal-600 rounded-2xl p-6 text-white shadow-lg shadow-emerald-200 flex items-center">
                <div class="bg-white/20 p-4 rounded-xl mr-5">
                    <i class="fas fa-gift text-3xl"></i>
                </div>
                <div>
                    <p class="text-emerald-100 text-sm font-bold uppercase tracking-wider mb-1">Total Hadiah Terdistribusi</p>
                    <h3 class="text-3xl font-extrabold"><?php echo number_format($total_unit_keluar); ?> <span class="text-lg font-medium">unit</span></h3>
                </div>
            </div>
        </div>

        <form method="POST" id="form-delete-multiple">
            <div class="flex justify-between items-center mb-4 px-2">
                <h3 class="text-lg font-bold text-gray-800">Riwayat Transaksi</h3>
                <?php if ($is_admin): ?>
                <button type="submit" name="delete_multiple" id="btn-delete-selected" class="hidden px-4 py-2 bg-red-50 text-red-600 border border-red-100 rounded-lg text-xs font-bold hover:bg-red-100 transition-all" onclick="return confirm('Hapus transaksi terpilih? Stok akan dikembalikan.');">
                    <i class="fas fa-trash-alt mr-2"></i> Hapus Terpilih (<span id="selected-count">0</span>)
                </button>
                <?php endif; ?>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-gray-50 border-b">
                            <?php if ($is_admin): ?><th class="py-4 px-4 w-10 text-center"><input type="checkbox" id="select-all" class="rounded border-gray-300"></th><?php endif; ?>
                            <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Waktu</th>
                            <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Hadiah</th>
                            <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Donatur</th>
                            <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Jumlah</th>
                            <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Keterangan</th>
                            <?php if ($is_admin): ?><th class="py-4 px-4 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">Aksi</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (!empty($laporan_data)): ?>
                            <?php foreach ($laporan_data as $row): 
                                $is_cash = strpos($row['keterangan'], 'Cash') !== false;
                            ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <?php if ($is_admin): ?>
                                <td class="py-4 px-4 text-center">
                                    <input type="checkbox" name="transaksi_ids[]" value="<?php echo $row['id']; ?>" class="row-checkbox rounded border-gray-300">
                                </td>
                                <?php endif; ?>
                                <td class="py-4 px-4 text-xs text-gray-600 whitespace-nowrap">
                                    <?php echo date('d/m/Y H:i', strtotime($row['tanggal_transaksi'])); ?>
                                </td>
                                <td class="py-4 px-4 font-bold text-gray-800"><?php echo htmlspecialchars($row['nama_hadiah']); ?></td>
                                <td class="py-4 px-4">
                                    <div class="text-sm font-medium text-gray-800"><?php echo htmlspecialchars($row['nama_donatur'] ?? 'N/A'); ?></div>
                                    <div class="text-[10px] text-gray-400">Oleh: <?php echo htmlspecialchars($row['nama_user']); ?></div>
                                </td>
                                <td class="py-4 px-4"><span class="font-bold text-indigo-600"><?php echo $row['jumlah']; ?></span> unit</td>
                                <td class="py-4 px-4">
                                    <?php if($is_cash): ?>
                                        <span class="inline-block px-2 py-0.5 bg-emerald-100 text-emerald-700 text-[10px] font-bold rounded mr-1">CASH</span>
                                    <?php endif; ?>
                                    <span class="text-xs text-gray-500 italic"><?php echo htmlspecialchars($row['keterangan']); ?></span>
                                </td>
                                <?php if ($is_admin): ?>
                                <td class="py-4 px-4 text-right">
                                    <form method="POST" onsubmit="return confirm('Hapus transaksi ini? Stok akan dikembalikan.');" class="inline-block">
                                        <input type="hidden" name="id_transaksi" value="<?php echo $row['id']; ?>">
                                        <input type="hidden" name="id_hadiah" value="<?php echo $row['id_hadiah']; ?>">
                                        <input type="hidden" name="jumlah" value="<?php echo $row['jumlah']; ?>">
                                        <button type="submit" name="delete_transaksi" class="p-2 text-red-400 hover:text-red-600 transition-colors">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </form>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="<?php echo $is_admin ? '7' : '6'; ?>" class="text-center py-20 text-gray-400 font-medium italic">Data transaksi tidak ditemukan.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
    </div>
    <?php else: ?>
        <div class="bg-amber-50 border-l-4 border-amber-500 text-amber-700 p-4 rounded-md"><p>Hanya Admin dan Manajer yang memiliki akses ke halaman ini.</p></div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectAll = document.getElementById('select-all');
    const checkboxes = document.querySelectorAll('.row-checkbox');
    const deleteBtn = document.getElementById('btn-delete-selected');
    const countSpan = document.getElementById('selected-count');

    if (!selectAll) return;

    function updateUI() {
        const checked = document.querySelectorAll('.row-checkbox:checked');
        countSpan.textContent = checked.length;
        deleteBtn.classList.toggle('hidden', checked.length === 0);
    }

    selectAll.addEventListener('change', function() {
        checkboxes.forEach(cb => cb.checked = selectAll.checked);
        updateUI();
    });

    checkboxes.forEach(cb => {
        cb.addEventListener('change', () => {
            const allChecked = Array.from(checkboxes).every(c => c.checked);
            selectAll.checked = allChecked;
            selectAll.indeterminate = !allChecked && Array.from(checkboxes).some(c => c.checked);
            updateUI();
        });
    });
});
</script>