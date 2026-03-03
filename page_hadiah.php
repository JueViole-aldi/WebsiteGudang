<?php
// page_hadiah.php

defined('APP_LOADED') or die('Akses langsung ke file ini tidak diizinkan.');

$user_id = $_SESSION['id'];
$user_role = $_SESSION['role'];
$error_message = '';
$success_message = '';

if (!has_access(['staff', 'admin'])) {
    echo "<div class='bg-red-100 p-4 rounded-md'>Anda tidak memiliki hak akses untuk halaman ini.</div>";
    return;
}

// === LOGIKA UNTUK ADMIN: Tambah Hadiah ===
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_hadiah']) && has_access(['admin'])) {
    $nama_hadiah = trim($_POST['nama_hadiah']);
    $id_kategori = (int)$_POST['id_kategori'];
    $stok = (int)$_POST['stok'];
    $stok_minimum = (int)$_POST['stok_minimum'];

    if (!empty($nama_hadiah) && $id_kategori > 0) {
        $sql = "INSERT INTO hadiah (nama_hadiah, id_kategori, stok, stok_minimum) VALUES (?, ?, ?, ?)";
        if ($stmt = $mysqli->prepare($sql)) {
            $stmt->bind_param("siii", $nama_hadiah, $id_kategori, $stok, $stok_minimum);
            if ($stmt->execute()) {
                log_activity($mysqli, $user_id, "Menambah hadiah baru: {$nama_hadiah}");
                $success_message = "Hadiah '{$nama_hadiah}' berhasil ditambahkan.";
            } else {
                $error_message = "Gagal menambahkan hadiah.";
            }
            $stmt->close();
        }
    } else {
        $error_message = "Nama hadiah dan kategori harus diisi.";
    }
}

// === LOGIKA UNTUK ADMIN: Edit Hadiah ===
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_hadiah']) && has_access(['admin'])) {
    $id_hadiah = (int)$_POST['id_hadiah_edit'];
    $nama_hadiah = trim($_POST['nama_hadiah_edit']);
    $id_kategori = (int)$_POST['id_kategori_edit'];
    $stok = (int)$_POST['stok_edit'];
    $stok_minimum = (int)$_POST['stok_minimum_edit'];

    if (!empty($nama_hadiah) && $id_kategori > 0 && $id_hadiah > 0) {
        $sql = "UPDATE hadiah SET nama_hadiah = ?, id_kategori = ?, stok = ?, stok_minimum = ? WHERE id = ?";
        if ($stmt = $mysqli->prepare($sql)) {
            $stmt->bind_param("siiii", $nama_hadiah, $id_kategori, $stok, $stok_minimum, $id_hadiah);
            if ($stmt->execute()) {
                log_activity($mysqli, $user_id, "Mengubah data hadiah: {$nama_hadiah}");
                $success_message = "Data hadiah berhasil diperbarui.";
            } else {
                $error_message = "Gagal memperbarui data hadiah.";
            }
            $stmt->close();
        }
    } else {
        $error_message = "Semua field harus diisi dengan benar.";
    }
}


// === LOGIKA HAPUS MASSAL (KHUSUS ADMIN) ===
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['bulk_delete_hadiah']) && has_access(['admin'])) {
    $ids_to_delete = isset($_POST['selected_ids']) ? array_map('intval', $_POST['selected_ids']) : [];

    if (empty($ids_to_delete)) {
        $error_message = "Tidak ada hadiah yang dipilih untuk dihapus.";
    } else {
        $mysqli->begin_transaction();
        try {
            $placeholders = implode(',', array_fill(0, count($ids_to_delete), '?'));
            $types = str_repeat('i', count($ids_to_delete));

            // Hapus data terkait dari tabel lain terlebih dahulu
            $tables_to_clear = [
                'transaksi_hadiah' => 'id_hadiah',
                'permintaan_stok' => 'id_hadiah',
                'barang_masuk' => 'id_hadiah',
            ];

            foreach ($tables_to_clear as $table => $column) {
                $sql_delete_related = "DELETE FROM {$table} WHERE {$column} IN ($placeholders)";
                $stmt_related = $mysqli->prepare($sql_delete_related);
                $stmt_related->bind_param($types, ...$ids_to_delete);
                $stmt_related->execute();
                $stmt_related->close();
            }
            
            // Atur id_hadiah_diberikan menjadi NULL di tabel donasi
            $sql_update_donasi = "UPDATE donasi SET id_hadiah_diberikan = NULL WHERE id_hadiah_diberikan IN ($placeholders)";
            $stmt_donasi = $mysqli->prepare($sql_update_donasi);
            $stmt_donasi->bind_param($types, ...$ids_to_delete);
            $stmt_donasi->execute();
            $stmt_donasi->close();

            // Hapus data hadiah utama
            $sql_delete_hadiah = "DELETE FROM hadiah WHERE id IN ($placeholders)";
            $stmt_hadiah = $mysqli->prepare($sql_delete_hadiah);
            $stmt_hadiah->bind_param($types, ...$ids_to_delete);
            $stmt_hadiah->execute();
            $deleted_count = $stmt_hadiah->affected_rows;
            $stmt_hadiah->close();
            
            $mysqli->commit();

            log_activity($mysqli, $user_id, "Menghapus $deleted_count data hadiah beserta riwayat terkait.");
            $success_message = "$deleted_count data hadiah dan semua riwayat terkait berhasil dihapus.";

        } catch (Exception $e) {
            $mysqli->rollback();
            $error_message = "Gagal menghapus hadiah: " . $e->getMessage();
        }
    }
}


// Ambil daftar kategori untuk dropdown
$kategori_list = $mysqli->query("SELECT id, nama_kategori FROM kategori ORDER BY nama_kategori ASC");

// Ambil data hadiah
$hadiah_list_query = "
    SELECT h.*, k.nama_kategori 
    FROM hadiah h 
    LEFT JOIN kategori k ON h.id_kategori = k.id 
    ORDER BY h.nama_hadiah ASC
";
$hadiah_list = $mysqli->query($hadiah_list_query);

?>
<div id="daftar-hadiah">
    <h1 class="text-3xl font-bold text-gray-800 mb-6">Daftar Hadiah</h1>

    <?php if ($success_message): ?>
    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-md" role="alert">
        <p><?php echo $success_message; ?></p>
    </div>
    <?php endif; ?>
    <?php if ($error_message): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-md" role="alert">
        <p><?php echo $error_message; ?></p>
    </div>
    <?php endif; ?>

    <div class="bg-white p-6 rounded-xl shadow-md">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-semibold text-gray-800">Semua Hadiah di Gudang</h2>
            <?php if (has_access(['admin'])): ?>
            <div class="flex items-center gap-4">
                <form method="POST" id="bulkDeleteFormHadiah">
                    <button type="submit" name="bulk_delete_hadiah" id="btnBulkDeleteHadiah" class="px-4 py-2 rounded-lg bg-red-100 text-red-700 disabled:opacity-50 disabled:cursor-not-allowed" disabled onclick="return confirm('PERINGATAN: Aksi ini akan menghapus hadiah terpilih beserta SEMUA riwayat transaksinya (barang masuk, keluar, permintaan). Apakah Anda benar-benar yakin?');">
                        Hapus Terpilih
                    </button>
                </form>
                <button onclick="openAddModal()" type="button" class="btn bg-teal-600 text-white px-4 py-2 rounded-md hover:bg-teal-700">
                    Tambah Hadiah
                </button>
            </div>
            <?php endif; ?>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="border-b-2 bg-slate-50">
                        <?php if (has_access(['admin'])): ?><th class="py-3 px-4"><input type="checkbox" id="selectAllHadiah"></th><?php endif; ?>
                        <th class="py-3 px-4 font-semibold text-slate-600">Nama Hadiah</th>
                        <th class="py-3 px-4 font-semibold text-slate-600">Kategori</th>
                        <th class="py-3 px-4 font-semibold text-slate-600">Stok</th>
                        <th class="py-3 px-4 font-semibold text-slate-600">Stok Minimum</th>
                        <?php if (has_access(['admin'])): ?>
                        <th class="py-3 px-4 font-semibold text-slate-600 text-center">Aksi</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if ($hadiah_list && $hadiah_list->num_rows > 0): ?>
                    <?php while ($row = $hadiah_list->fetch_assoc()): ?>
                    <tr class="border-b hover:bg-slate-50">
                        <?php if (has_access(['admin'])): ?><td class="py-3 px-4"><input type="checkbox" class="row-check-hadiah" name="selected_ids[]" value="<?php echo $row['id']; ?>" form="bulkDeleteFormHadiah"></td><?php endif; ?>
                        <td class="py-3 px-4 font-medium text-gray-800"><?php echo htmlspecialchars($row['nama_hadiah']); ?></td>
                        <td class="py-3 px-4 text-gray-600"><?php echo htmlspecialchars($row['nama_kategori'] ?? 'N/A'); ?></td>
                        <td class="py-3 px-4 font-bold text-gray-800"><?php echo $row['stok']; ?></td>
                        <td class="py-3 px-4 text-gray-600"><?php echo $row['stok_minimum']; ?></td>
                        <?php if (has_access(['admin'])): ?>
                        <td class="py-3 px-4 text-center">
                            <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8'); ?>)" type="button" class="text-blue-500 hover:text-blue-700"><i data-lucide="edit" class="w-4 h-4"></i></button>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="6" class="text-center py-4 text-gray-500">Belum ada data hadiah.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Tambah Hadiah -->
<div id="add-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <form method="POST" action="dashboard.php?page=hadiah" class="p-6">
            <h3 class="text-xl font-semibold mb-4">Tambah Hadiah Baru</h3>
            <div class="space-y-4">
                <div>
                    <label for="nama_hadiah" class="block text-sm font-medium">Nama Hadiah</label>
                    <input type="text" name="nama_hadiah" id="nama_hadiah" required class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2">
                </div>
                <div>
                    <label for="id_kategori" class="block text-sm font-medium">Kategori</label>
                    <select name="id_kategori" id="id_kategori" required class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2">
                        <option value="">-- Pilih Kategori --</option>
                        <?php mysqli_data_seek($kategori_list, 0); while($kategori = $kategori_list->fetch_assoc()): ?>
                        <option value="<?php echo $kategori['id']; ?>"><?php echo htmlspecialchars($kategori['nama_kategori']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div>
                    <label for="stok" class="block text-sm font-medium">Stok Awal</label>
                    <input type="number" name="stok" id="stok" min="0" required class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2">
                </div>
                <div>
                    <label for="stok_minimum" class="block text-sm font-medium">Stok Minimum</label>
                    <input type="number" name="stok_minimum" id="stok_minimum" min="0" required class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2">
                </div>
            </div>
            <div class="mt-6 flex justify-end space-x-3">
                <button type="button" onclick="closeAddModal()" class="px-4 py-2 bg-gray-200 rounded-md">Batal</button>
                <button type="submit" name="add_hadiah" class="px-4 py-2 bg-teal-600 text-white rounded-md">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Edit Hadiah -->
<div id="edit-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <form method="POST" action="dashboard.php?page=hadiah" class="p-6">
            <h3 class="text-xl font-semibold mb-4">Edit Hadiah</h3>
            <input type="hidden" name="id_hadiah_edit" id="id_hadiah_edit">
            <div class="space-y-4">
                <div>
                    <label for="nama_hadiah_edit" class="block text-sm font-medium">Nama Hadiah</label>
                    <input type="text" name="nama_hadiah_edit" id="nama_hadiah_edit" required class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2">
                </div>
                <div>
                    <label for="id_kategori_edit" class="block text-sm font-medium">Kategori</label>
                    <select name="id_kategori_edit" id="id_kategori_edit" required class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2">
                        <option value="">-- Pilih Kategori --</option>
                        <?php mysqli_data_seek($kategori_list, 0); while($kategori = $kategori_list->fetch_assoc()): ?>
                        <option value="<?php echo $kategori['id']; ?>"><?php echo htmlspecialchars($kategori['nama_kategori']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div>
                    <label for="stok_edit" class="block text-sm font-medium">Stok</label>
                    <input type="number" name="stok_edit" id="stok_edit" min="0" required class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2">
                </div>
                <div>
                    <label for="stok_minimum_edit" class="block text-sm font-medium">Stok Minimum</label>
                    <input type="number" name="stok_minimum_edit" id="stok_minimum_edit" min="0" required class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2">
                </div>
            </div>
            <div class="mt-6 flex justify-end space-x-3">
                <button type="button" onclick="closeEditModal()" class="px-4 py-2 bg-gray-200 rounded-md">Batal</button>
                <button type="submit" name="edit_hadiah" class="px-4 py-2 bg-teal-600 text-white rounded-md">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
    const addModal = document.getElementById('add-modal');
    const editModal = document.getElementById('edit-modal');

    function openAddModal() {
        addModal.classList.remove('hidden');
        addModal.classList.add('flex');
    }
    function closeAddModal() {
        addModal.classList.add('hidden');
        addModal.classList.remove('flex');
    }

    function openEditModal(hadiah) {
        document.getElementById('id_hadiah_edit').value = hadiah.id;
        document.getElementById('nama_hadiah_edit').value = hadiah.nama_hadiah;
        document.getElementById('id_kategori_edit').value = hadiah.id_kategori;
        document.getElementById('stok_edit').value = hadiah.stok;
        document.getElementById('stok_minimum_edit').value = hadiah.stok_minimum;
        editModal.classList.remove('hidden');
        editModal.classList.add('flex');
    }
    function closeEditModal() {
        editModal.classList.add('hidden');
        editModal.classList.remove('flex');
    }


    if (document.getElementById('selectAllHadiah')) {
        const selectAll = document.getElementById('selectAllHadiah');
        const btn = document.getElementById('btnBulkDeleteHadiah');
        const checks = () => Array.from(document.querySelectorAll('input.row-check-hadiah'));
        
        const update = () => {
            const selectedCount = checks().filter(c => c.checked).length;
            if (btn) btn.disabled = selectedCount === 0;
            if (selectAll) {
                const total = checks().length;
                selectAll.indeterminate = selectedCount > 0 && selectedCount < total;
                selectAll.checked = selectedCount > 0 && selectedCount === total;
            }
        };

        selectAll.addEventListener('change', () => {
            checks().forEach(c => c.checked = selectAll.checked);
            update();
        });
        
        document.addEventListener('change', e => {
            if (e.target && e.target.classList.contains('row-check-hadiah')) {
                update();
            }
        });

        update();
    }
</script>

