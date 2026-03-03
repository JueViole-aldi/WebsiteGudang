<?php
// page_laporan_masuk.php
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
            // Cek stok sebelum mengurangi
            $stock_check_stmt = $mysqli->prepare("SELECT stok FROM hadiah WHERE id = ?");
            $stock_check_stmt->bind_param("i", $id_hadiah);
            $stock_check_stmt->execute();
            $current_stock = $stock_check_stmt->get_result()->fetch_assoc()['stok'];

            if ($current_stock < $jumlah) {
                throw new Exception("Stok tidak akan cukup jika transaksi ini dihapus.");
            }

            // 1. Kurangi stok dari tabel hadiah
            $stmt_update = $mysqli->prepare("UPDATE hadiah SET stok = stok - ? WHERE id = ?");
            $stmt_update->bind_param("ii", $jumlah, $id_hadiah);
            $stmt_update->execute();

            // 2. Hapus record transaksi
            $stmt_delete = $mysqli->prepare("DELETE FROM transaksi_hadiah WHERE id = ?");
            $stmt_delete->bind_param("i", $id_transaksi);
            $stmt_delete->execute();
            
            $mysqli->commit();
            log_activity($mysqli, $_SESSION['id'], "Membatalkan transaksi masuk ID: {$id_transaksi} dan mengurangi {$jumlah} stok.");
            $success_message = "Transaksi berhasil dihapus dan stok telah disesuaikan.";

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
            while ($row = $result_data->fetch_assoc()) {
                $items_to_process[] = $row;
            }

            if (count($items_to_process) > 0) {
                 $stmt_update = $mysqli->prepare("UPDATE hadiah SET stok = stok - ? WHERE id = ?");
                foreach ($items_to_process as $item) {
                    $stmt_update->bind_param("ii", $item['jumlah'], $item['id_hadiah']);
                    $stmt_update->execute();
                }

                $stmt_delete = $mysqli->prepare("DELETE FROM transaksi_hadiah WHERE id IN ($ids_placeholder)");
                $stmt_delete->bind_param(str_repeat('i', count($sanitized_ids)), ...$sanitized_ids);
                $stmt_delete->execute();
                
                $mysqli->commit();
                $deleted_count = count($sanitized_ids);
                log_activity($mysqli, $_SESSION['id'], "Membatalkan {$deleted_count} transaksi masuk dan mengurangi stok.");
                $success_message = "{$deleted_count} transaksi berhasil dihapus dan stok telah disesuaikan.";
            } else {
                 throw new Exception("Tidak ada transaksi valid yang ditemukan.");
            }
        } catch (Exception $e) {
            $mysqli->rollback();
            $error_message = "Gagal menghapus transaksi massal: " . $e->getMessage();
        }
    } else {
        $error_message = "Tidak ada transaksi yang dipilih untuk dihapus.";
    }
}

// --- LOGIKA FILTER TANGGAL ---
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$sql = "
    SELECT t.id, t.tanggal_transaksi, h.nama_hadiah, h.id as id_hadiah, u.name as nama_user, t.jumlah, t.keterangan
    FROM transaksi_hadiah t
    JOIN hadiah h ON t.id_hadiah = h.id
    JOIN users u ON t.id_user = u.id
    WHERE t.tipe_transaksi = 'masuk'
";
$params = [];
$types = '';
if (!empty($start_date)) {
    $sql .= " AND DATE(t.tanggal_transaksi) >= ?";
    $params[] = $start_date;
    $types .= 's';
}
if (!empty($end_date)) {
    $sql .= " AND DATE(t.tanggal_transaksi) <= ?";
    $params[] = $end_date;
    $types .= 's';
}
$sql .= " ORDER BY t.tanggal_transaksi DESC";
$stmt = $mysqli->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// --- SETUP EXPORT URL DINAMIS ---
$export_url = "export_handler.php?report=laporan_masuk";
if (!empty($start_date)) $export_url .= "&start_date=" . urlencode($start_date);
if (!empty($end_date)) $export_url .= "&end_date=" . urlencode($end_date);
?>
<div id="laporan-masuk">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Laporan Hadiah Masuk</h1>
        <?php if ($is_accessible): ?>
        <a href="<?php echo htmlspecialchars($export_url); ?>" target="_blank" class="btn bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 flex items-center">
            <i data-lucide="file-spreadsheet" class="w-4 h-4 mr-2"></i>Export ke Excel
        </a>
        <?php endif; ?>
    </div>

    <?php if ($is_accessible): ?>
    <?php if ($success_message): ?><div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-md" role="alert"><p><?php echo $success_message; ?></p></div><?php endif; ?>
    <?php if ($error_message): ?><div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-md" role="alert"><p><?php echo $error_message; ?></p></div><?php endif; ?>
    <div class="bg-white p-6 rounded-xl shadow-md">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Riwayat Transaksi Masuk</h2>
        <form method="GET" action="dashboard.php" class="mb-6 border-b pb-6">
            <input type="hidden" name="page" value="laporan_masuk">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                <div>
                    <label for="start_date" class="block text-sm font-medium text-gray-700 mb-1">Dari Tanggal</label>
                    <input type="date" name="start_date" id="start_date" value="<?php echo htmlspecialchars($start_date); ?>" class="w-full border border-slate-300 rounded-md px-3 py-2">
                </div>
                <div>
                    <label for="end_date" class="block text-sm font-medium text-gray-700 mb-1">Sampai Tanggal</label>
                    <input type="date" name="end_date" id="end_date" value="<?php echo htmlspecialchars($end_date); ?>" class="w-full border border-slate-300 rounded-md px-3 py-2">
                </div>
                <div class="flex items-center space-x-2">
                    <button type="submit" class="btn w-full bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">Filter</button>
                    <a href="dashboard.php?page=laporan_masuk" class="btn w-full text-center bg-gray-200 px-4 py-2 rounded-md hover:bg-gray-300">Reset</a>
                </div>
            </div>
        </form>

        <form method="POST" id="form-delete-multiple" action="dashboard.php?page=laporan_masuk&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>">
            <?php if ($is_admin): ?>
            <div class="mb-4">
                <button type="submit" name="delete_multiple" class="btn bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700 hidden" id="btn-delete-selected" onclick="return confirm('Anda yakin ingin menghapus semua transaksi yang dipilih? Stok akan DIKURANGI dari gudang.');">
                    <i data-lucide="trash-2" class="w-4 h-4 inline-block mr-2"></i>Hapus yang Dipilih (<span id="selected-count">0</span>)
                </button>
            </div>
            <?php endif; ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-slate-50 border-b">
                            <?php if ($is_admin): ?>
                            <th class="py-3 px-4"><input type="checkbox" id="select-all"></th>
                            <?php endif; ?>
                            <th class="py-3 px-4 font-semibold text-slate-600">Tanggal</th>
                            <th class="py-3 px-4 font-semibold text-slate-600">Nama Hadiah</th>
                            <th class="py-3 px-4 font-semibold text-slate-600">Jumlah</th>
                            <th class="py-3 px-4 font-semibold text-slate-600">Dicatat oleh</th>
                            <th class="py-3 px-4 font-semibold text-slate-600">Keterangan</th>
                            <?php if ($is_admin): ?>
                            <th class="py-3 px-4 font-semibold text-slate-600 text-right">Aksi</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                            <tr class="border-b hover:bg-slate-50">
                                <?php if ($is_admin): ?>
                                <td class="py-3 px-4"><input type="checkbox" name="transaksi_ids[]" value="<?php echo $row['id']; ?>" class="row-checkbox"></td>
                                <?php endif; ?>
                                <td class="py-3 px-4 text-gray-600"><?php echo date('d M Y, H:i', strtotime($row['tanggal_transaksi'])); ?></td>
                                <td class="py-3 px-4 font-medium text-gray-800"><?php echo htmlspecialchars($row['nama_hadiah']); ?></td>
                                <td class="py-3 px-4 font-medium text-gray-800"><?php echo $row['jumlah']; ?></td>
                                <td class="py-3 px-4 text-gray-600"><?php echo htmlspecialchars($row['nama_user']); ?></td>
                                <td class="py-3 px-4 text-gray-600"><?php echo htmlspecialchars($row['keterangan']); ?></td>
                                <?php if ($is_admin): ?>
                                <td class="py-3 px-4 text-right">
                                    <form method="POST" action="dashboard.php?page=laporan_masuk&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" onsubmit="return confirm('Anda yakin ingin menghapus transaksi ini? Stok akan DIKURANGI dari gudang.');" class="inline-flex">
                                        <input type="hidden" name="id_transaksi" value="<?php echo $row['id']; ?>">
                                        <input type="hidden" name="id_hadiah" value="<?php echo $row['id_hadiah']; ?>">
                                        <input type="hidden" name="jumlah" value="<?php echo $row['jumlah']; ?>">
                                        <button type="submit" name="delete_transaksi" class="text-red-500 hover:text-red-700">
                                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                                        </button>
                                    </form>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="<?php echo $is_admin ? '7' : '5'; ?>" class="text-center py-4 text-gray-500">
                                <?php if (!empty($start_date) || !empty($end_date)): ?>
                                    Tidak ada data transaksi masuk pada rentang tanggal yang dipilih.
                                <?php else: ?>
                                    Belum ada data transaksi masuk.
                                <?php endif; ?>
                            </td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
    </div>
    <?php else: ?>
        <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 rounded-md" role="alert">
            <p>Anda tidak memiliki hak akses untuk melihat halaman ini.</p>
        </div>
    <?php endif; ?>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectAllCheckbox = document.getElementById('select-all');
    const rowCheckboxes = document.querySelectorAll('.row-checkbox');
    const deleteButton = document.getElementById('btn-delete-selected');
    const selectedCountSpan = document.getElementById('selected-count');

    if (!selectAllCheckbox) return;

    function updateDeleteButton() {
        const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
        selectedCountSpan.textContent = checkedCount;
        if (checkedCount > 0) {
            deleteButton.classList.remove('hidden');
        } else {
            deleteButton.classList.add('hidden');
        }
    }

    selectAllCheckbox.addEventListener('change', function() {
        rowCheckboxes.forEach(checkbox => {
            checkbox.checked = selectAllCheckbox.checked;
        });
        updateDeleteButton();
    });

    rowCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            if (!this.checked) {
                selectAllCheckbox.checked = false;
            } else {
                const allChecked = Array.from(rowCheckboxes).every(cb => cb.checked);
                selectAllCheckbox.checked = allChecked;
            }
            updateDeleteButton();
        });
    });
});
</script>