<?php
// page_donatur.php

defined('APP_LOADED') or die('Akses langsung ke file ini tidak diizinkan.');

$is_admin = has_access(['admin']);
$error_message = '';
$success_message = '';
$user_id = $_SESSION['id'];

// === LOGIKA HAPUS PAKSA (CASCADE DELETE) UNTUK ADMIN ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete_donatur']) && $is_admin) {
    $selected_ids = isset($_POST['selected_ids']) && is_array($_POST['selected_ids']) ? array_map('intval', $_POST['selected_ids']) : [];

    if (empty($selected_ids)) {
        $error_message = "Tidak ada donatur yang dipilih untuk dihapus.";
    } else {
        // Memulai transaksi database untuk memastikan semua operasi berhasil atau tidak sama sekali
        $mysqli->begin_transaction();
        try {
            $ids_placeholder = implode(',', array_fill(0, count($selected_ids), '?'));
            $types = str_repeat('i', count($selected_ids));

            // PERBAIKAN ERROR FOREIGN KEY: 
            // Langkah 1: Lepaskan relasi donatur dari tabel transaksi_hadiah (Ubah ke NULL)
            // Agar histori stok/barang keluar tetap aman meskipun data donaturnya dihapus
            $sql_unlink_transaksi = "UPDATE transaksi_hadiah SET id_donatur = NULL WHERE id_donatur IN ($ids_placeholder)";
            $stmt_unlink = $mysqli->prepare($sql_unlink_transaksi);
            $stmt_unlink->bind_param($types, ...$selected_ids);
            $stmt_unlink->execute();
            $stmt_unlink->close();

            // Langkah 2: Hapus semua catatan tagihan/donasi keuangan yang terkait dengan donatur ini
            $sql_delete_donasi = "DELETE FROM donasi WHERE id_donatur IN ($ids_placeholder)";
            $stmt_donasi = $mysqli->prepare($sql_delete_donasi);
            $stmt_donasi->bind_param($types, ...$selected_ids);
            $stmt_donasi->execute();
            $stmt_donasi->close();

            // Langkah 3: Setelah riwayat bersih, hapus data master donatur itu sendiri
            $sql_delete_donatur = "DELETE FROM donatur WHERE id IN ($ids_placeholder)";
            $stmt_donatur = $mysqli->prepare($sql_delete_donatur);
            $stmt_donatur->bind_param($types, ...$selected_ids);
            $stmt_donatur->execute();
            $deleted_count = $stmt_donatur->affected_rows;
            $stmt_donatur->close();

            // Jika semua query berhasil tanpa error, simpan perubahan permanen
            $mysqli->commit();

            if ($deleted_count > 0) {
                if (function_exists('log_activity')) { log_activity($mysqli, $user_id, "Menghapus {$deleted_count} data donatur beserta riwayat donasinya secara paksa."); }
                $success_message = "Berhasil menghapus {$deleted_count} data donatur beserta seluruh riwayat donasinya.";
            } else {
                $error_message = "Tidak ada donatur yang dihapus. Mungkin data sudah tidak ada.";
            }

        } catch (Exception $e) {
            // Jika terjadi error di salah satu langkah, batalkan semua perubahan
            $mysqli->rollback();
            $error_message = "Terjadi kesalahan fatal saat menghapus data: " . $e->getMessage();
        }
    }
}


// Handle Tambah Donatur
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_donatur']) && $is_admin) {
    $nama_donatur = trim($_POST['nama_donatur']);
    $level_donasi = trim($_POST['level_donasi']);
    $kontak = trim($_POST['kontak']);
    $alamat = trim($_POST['alamat']);

    if (!empty($nama_donatur) && !empty($level_donasi)) {
        $sql = "INSERT INTO donatur (nama_donatur, level_donasi, kontak, alamat) VALUES (?, ?, ?, ?)";
        if ($stmt = $mysqli->prepare($sql)) {
            $stmt->bind_param("ssss", $nama_donatur, $level_donasi, $kontak, $alamat);
            if ($stmt->execute()) {
                if (function_exists('log_activity')) { log_activity($mysqli, $_SESSION['id'], "Menambah donatur baru: {$nama_donatur}"); }
                $success_message = "Donatur '{$nama_donatur}' berhasil ditambahkan ke database.";
            } else {
                $error_message = "Gagal menambahkan donatur.";
            }
            $stmt->close();
        }
    } else {
        $error_message = "Nama donatur dan level donasi wajib diisi.";
    }
}

// Handle Edit Donatur
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_donatur']) && $is_admin) {
    $id_donatur = (int)$_POST['id_donatur_edit'];
    $nama_donatur = trim($_POST['nama_donatur_edit']);
    $level_donasi = trim($_POST['level_donasi_edit']);
    $kontak = trim($_POST['kontak_edit']);
    $alamat = trim($_POST['alamat_edit']);

    if (!empty($nama_donatur) && !empty($level_donasi) && $id_donatur > 0) {
        $sql = "UPDATE donatur SET nama_donatur = ?, level_donasi = ?, kontak = ?, alamat = ? WHERE id = ?";
        if ($stmt = $mysqli->prepare($sql)) {
            $stmt->bind_param("ssssi", $nama_donatur, $level_donasi, $kontak, $alamat, $id_donatur);
            if ($stmt->execute()) {
                 if (function_exists('log_activity')) { log_activity($mysqli, $_SESSION['id'], "Mengubah data donatur: {$nama_donatur}"); }
                $success_message = "Data donatur berhasil diperbarui.";
            } else {
                $error_message = "Gagal memperbarui data donatur.";
            }
            $stmt->close();
        }
    } else {
        $error_message = "Semua field wajib harus diisi dengan benar.";
    }
}

// Ambil data donatur
$donatur_list = $mysqli->query("SELECT * FROM donatur ORDER BY nama_donatur ASC");
?>

<div id="data-donatur">
    <!-- Header Halaman -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-3xl font-extrabold text-gray-800 tracking-tight">Data Master Donatur</h1>
            <p class="text-sm text-gray-500 mt-1">Kelola direktori kontak dan identitas para donatur yayasan.</p>
        </div>
        
        <?php if ($is_admin): ?>
        <div class="flex gap-3">
            <button onclick="openAddModalDonatur()" class="inline-flex items-center px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold rounded-xl transition-all shadow-lg shadow-emerald-200 transform hover:-translate-y-0.5">
                <i class="fas fa-user-plus mr-2 text-lg"></i> Tambah Donatur Baru
            </button>
        </div>
        <?php endif; ?>
    </div>

    <!-- Alert Notifikasi -->
    <?php if ($success_message): ?>
        <div class="bg-emerald-50 border border-emerald-200 border-l-4 border-l-emerald-500 p-4 mb-6 rounded-r-xl shadow-sm animate-fade-in flex items-center">
            <i class="fas fa-check-circle text-emerald-500 mr-3 text-lg"></i>
            <p class="text-sm text-emerald-800 font-bold"><?php echo $success_message; ?></p>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="bg-rose-50 border border-rose-200 border-l-4 border-l-rose-500 p-4 mb-6 rounded-r-xl shadow-sm animate-fade-in flex items-center">
            <i class="fas fa-exclamation-circle text-rose-500 mr-3 text-lg"></i>
            <p class="text-sm text-rose-800 font-bold"><?php echo $error_message; ?></p>
        </div>
    <?php endif; ?>

    <!-- Tabel Data -->
    <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
            <h2 class="text-xl font-bold text-gray-800 flex items-center">
                <span class="w-8 h-8 rounded-lg bg-emerald-100 text-emerald-600 flex items-center justify-center mr-3">
                    <i class="fas fa-users text-sm"></i>
                </span>
                Direktori Seluruh Donatur
            </h2>
            <?php if ($is_admin): ?>
            <form method="POST" id="bulkDeleteFormDonatur">
                <button type="submit" name="bulk_delete_donatur" id="bulkDeleteButtonDonatur" disabled class="inline-flex items-center px-4 py-2 bg-rose-50 text-rose-600 border border-rose-100 rounded-lg text-sm font-bold disabled:opacity-40 disabled:cursor-not-allowed transition-all hover:bg-rose-100" onclick="return confirm('PERINGATAN: Aksi ini akan menghapus donatur yang dipilih BESERTA SELURUH RIWAYAT DONASINYA secara permanen. Apakah Anda benar-benar yakin?');">
                    <i class="fas fa-trash-alt mr-2"></i> Hapus Terpilih
                </button>
            </form>
            <?php endif; ?>
        </div>

        <div class="overflow-x-auto rounded-xl border border-gray-100">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-100">
                        <?php if ($is_admin): ?>
                        <th class="py-4 px-4 w-12 text-center">
                            <input type="checkbox" id="selectAllCheckboxDonatur" class="h-4 w-4 text-emerald-600 focus:ring-emerald-500 border-gray-300 rounded cursor-pointer transition-colors">
                        </th>
                        <?php endif; ?>
                        <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Info Donatur</th>
                        <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Level Apresiasi</th>
                        <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Kontak Penghubung</th>
                        <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Alamat Pengiriman</th>
                        <?php if ($is_admin): ?>
                        <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider text-center">Aksi</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                <?php if ($donatur_list && $donatur_list->num_rows > 0): ?>
                    <?php while ($row = $donatur_list->fetch_assoc()): ?>
                    <tr class="hover:bg-emerald-50/50 transition-colors group">
                        <?php if ($is_admin): ?>
                        <td class="py-4 px-4 text-center">
                            <input type="checkbox" name="selected_ids[]" value="<?php echo $row['id']; ?>" class="row-checkbox-donatur h-4 w-4 text-emerald-600 focus:ring-emerald-500 border-gray-300 rounded cursor-pointer transition-colors" form="bulkDeleteFormDonatur">
                        </td>
                        <?php endif; ?>
                        <td class="py-4 px-4">
                            <div class="flex items-center">
                                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-emerald-100 to-teal-200 text-teal-700 flex items-center justify-center font-bold text-lg mr-3 shadow-inner">
                                    <?php echo strtoupper(substr($row['nama_donatur'], 0, 1)); ?>
                                </div>
                                <span class="font-extrabold text-gray-800"><?php echo htmlspecialchars($row['nama_donatur'] ?? ''); ?></span>
                            </div>
                        </td>
                        <td class="py-4 px-4">
                            <span class="inline-flex px-3 py-1 text-[10px] font-bold rounded-full bg-indigo-50 text-indigo-700 border border-indigo-100 uppercase tracking-wider">
                                <?php echo htmlspecialchars($row['level_donasi'] ?? 'Standard'); ?>
                            </span>
                        </td>
                        <td class="py-4 px-4">
                            <div class="flex items-center text-sm font-medium text-gray-600">
                                <i class="fas fa-phone-alt text-gray-400 mr-2 text-xs"></i>
                                <?php echo htmlspecialchars($row['kontak'] ?? '-'); ?>
                            </div>
                        </td>
                        <td class="py-4 px-4 text-sm text-gray-500 max-w-[250px] truncate" title="<?php echo htmlspecialchars($row['alamat'] ?? '-'); ?>">
                            <i class="fas fa-map-marker-alt text-gray-400 mr-1.5"></i> <?php echo htmlspecialchars($row['alamat'] ?? '-'); ?>
                        </td>
                         <?php if ($is_admin): ?>
                        <td class="py-4 px-4 text-center">
                            <button type="button" onclick="openEditModalDonatur(<?php echo htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8'); ?>)" class="inline-flex items-center justify-center p-2 bg-blue-50 text-blue-600 rounded-lg hover:bg-blue-100 transition-colors shadow-sm opacity-80 group-hover:opacity-100" title="Edit Data">
                                <i class="fas fa-edit"></i>
                            </button>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="<?php echo $is_admin ? '6' : '5'; ?>" class="text-center py-16">
                            <div class="flex flex-col items-center justify-center text-gray-400">
                                <div class="w-16 h-16 bg-gray-50 rounded-full flex items-center justify-center mb-3 border border-gray-100">
                                    <i class="fas fa-users-slash text-3xl text-gray-300"></i>
                                </div>
                                <p class="font-medium text-sm">Belum ada data donatur yang tersimpan.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL DIALOGS -->
<!-- ========================================== -->

<!-- Modal Tambah Donatur -->
<div id="add-donatur-modal" class="fixed inset-0 bg-gray-900/60 z-50 hidden items-center justify-center p-4 backdrop-blur-sm transition-opacity">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden transform transition-all animate-fade-in-up border border-emerald-100">
        <div class="bg-gradient-to-r from-emerald-600 to-teal-600 px-6 py-4 flex items-center justify-between">
            <h3 class="text-xl font-bold text-white flex items-center"><i class="fas fa-user-plus mr-3 opacity-90"></i> Tambah Donatur</h3>
            <button type="button" onclick="closeAddModalDonatur()" class="text-white/80 hover:text-white transition-colors"><i class="fas fa-times text-lg"></i></button>
        </div>
        <form method="POST" action="dashboard.php?page=donatur" class="p-6 space-y-4 bg-gray-50/50">
            <div>
                <label for="nama_donatur" class="block text-sm font-bold text-gray-700 mb-1.5">Nama Lengkap / Instansi</label>
                <input type="text" name="nama_donatur" id="nama_donatur" required class="w-full border-gray-300 rounded-xl px-4 py-3 bg-white focus:bg-emerald-50 focus:ring-2 focus:ring-emerald-500 outline-none transition-all shadow-sm" placeholder="Contoh: Hamba Allah">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="level_donasi" class="block text-sm font-bold text-gray-700 mb-1.5">Tipe/Level Donasi</label>
                    <input type="text" name="level_donasi" id="level_donasi" required class="w-full border-gray-300 rounded-xl px-4 py-3 bg-white focus:bg-emerald-50 focus:ring-2 focus:ring-emerald-500 outline-none transition-all shadow-sm" value="Standard" placeholder="Standard/VIP">
                </div>
                <div>
                    <label for="kontak" class="block text-sm font-bold text-gray-700 mb-1.5">Kontak (Email/WA)</label>
                    <input type="text" name="kontak" id="kontak" required class="w-full border-gray-300 rounded-xl px-4 py-3 bg-white focus:bg-emerald-50 focus:ring-2 focus:ring-emerald-500 outline-none transition-all shadow-sm" placeholder="0812...">
                </div>
            </div>
            <div>
                <label for="alamat" class="block text-sm font-bold text-gray-700 mb-1.5">Alamat Pengiriman Lengkap</label>
                <textarea name="alamat" id="alamat" rows="3" class="w-full border-gray-300 rounded-xl px-4 py-3 bg-white focus:bg-emerald-50 focus:ring-2 focus:ring-emerald-500 outline-none transition-all shadow-sm" placeholder="Jl. Sudirman No..."></textarea>
            </div>
            <div class="pt-4 flex space-x-3">
                <button type="button" onclick="closeAddModalDonatur()" class="flex-1 py-3 bg-white border border-gray-300 text-gray-700 font-bold rounded-xl hover:bg-gray-50 transition-all shadow-sm">Batal</button>
                <button type="submit" name="add_donatur" class="flex-1 py-3 bg-emerald-600 text-white font-bold rounded-xl hover:bg-emerald-700 shadow-md shadow-emerald-200 transition-all transform hover:-translate-y-0.5">Simpan Data</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Edit Donatur -->
<div id="edit-donatur-modal" class="fixed inset-0 bg-gray-900/60 z-50 hidden items-center justify-center p-4 backdrop-blur-sm transition-opacity">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden transform transition-all animate-fade-in-up border border-blue-100">
        <div class="bg-gradient-to-r from-blue-500 to-indigo-600 px-6 py-4 flex items-center justify-between">
            <h3 class="text-xl font-bold text-white flex items-center"><i class="fas fa-user-edit mr-3 opacity-90"></i> Edit Data Donatur</h3>
            <button type="button" onclick="closeEditModalDonatur()" class="text-white/80 hover:text-white transition-colors"><i class="fas fa-times text-lg"></i></button>
        </div>
        <form method="POST" action="dashboard.php?page=donatur" class="p-6 space-y-4 bg-gray-50/50">
            <input type="hidden" name="id_donatur_edit" id="id_donatur_edit">
            <div>
                <label for="nama_donatur_edit" class="block text-sm font-bold text-gray-700 mb-1.5">Nama Lengkap / Instansi</label>
                <input type="text" name="nama_donatur_edit" id="nama_donatur_edit" required class="w-full border-gray-300 rounded-xl px-4 py-3 bg-white focus:bg-blue-50 focus:ring-2 focus:ring-blue-500 outline-none transition-all shadow-sm">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="level_donasi_edit" class="block text-sm font-bold text-gray-700 mb-1.5">Tipe/Level Donasi</label>
                    <input type="text" name="level_donasi_edit" id="level_donasi_edit" required class="w-full border-gray-300 rounded-xl px-4 py-3 bg-white focus:bg-blue-50 focus:ring-2 focus:ring-blue-500 outline-none transition-all shadow-sm">
                </div>
                <div>
                    <label for="kontak_edit" class="block text-sm font-bold text-gray-700 mb-1.5">Kontak (Email/WA)</label>
                    <input type="text" name="kontak_edit" id="kontak_edit" required class="w-full border-gray-300 rounded-xl px-4 py-3 bg-white focus:bg-blue-50 focus:ring-2 focus:ring-blue-500 outline-none transition-all shadow-sm">
                </div>
            </div>
            <div>
                <label for="alamat_edit" class="block text-sm font-bold text-gray-700 mb-1.5">Alamat Pengiriman Lengkap</label>
                <textarea name="alamat_edit" id="alamat_edit" rows="3" class="w-full border-gray-300 rounded-xl px-4 py-3 bg-white focus:bg-blue-50 focus:ring-2 focus:ring-blue-500 outline-none transition-all shadow-sm"></textarea>
            </div>
            <div class="pt-4 flex space-x-3">
                <button type="button" onclick="closeEditModalDonatur()" class="flex-1 py-3 bg-white border border-gray-300 text-gray-700 font-bold rounded-xl hover:bg-gray-50 transition-all shadow-sm">Batal</button>
                <button type="submit" name="edit_donatur" class="flex-1 py-3 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 shadow-md shadow-blue-200 transition-all transform hover:-translate-y-0.5">Update Data</button>
            </div>
        </form>
    </div>
</div>

<script>
    const addDonaturModal = document.getElementById('add-donatur-modal');
    const editDonaturModal = document.getElementById('edit-donatur-modal');

    function openAddModalDonatur() { 
        addDonaturModal.classList.remove('hidden'); 
        addDonaturModal.classList.add('flex'); 
    }
    function closeAddModalDonatur() { 
        addDonaturModal.classList.add('hidden'); 
        addDonaturModal.classList.remove('flex'); 
    }
    
    function openEditModalDonatur(donatur) {
        document.getElementById('id_donatur_edit').value = donatur.id;
        document.getElementById('nama_donatur_edit').value = donatur.nama_donatur;
        document.getElementById('level_donasi_edit').value = donatur.level_donasi;
        document.getElementById('kontak_edit').value = donatur.kontak || '';
        document.getElementById('alamat_edit').value = donatur.alamat || '';
        editDonaturModal.classList.remove('hidden');
        editDonaturModal.classList.add('flex');
    }
    function closeEditModalDonatur() { 
        editDonaturModal.classList.add('hidden'); 
        editDonaturModal.classList.remove('flex'); 
    }
    
    // Script untuk bulk delete Donatur yang pintar
    document.addEventListener('DOMContentLoaded', function() {
        const selectAll = document.getElementById('selectAllCheckboxDonatur');
        const rowCheckboxes = document.querySelectorAll('.row-checkbox-donatur');
        const deleteButton = document.getElementById('bulkDeleteButtonDonatur');

        function toggleDeleteButton() {
            const anyChecked = Array.from(rowCheckboxes).filter(cb => cb.checked).length;
            if (deleteButton) deleteButton.disabled = anyChecked === 0;
            
            if (selectAll) {
                selectAll.indeterminate = anyChecked > 0 && anyChecked < rowCheckboxes.length;
                selectAll.checked = anyChecked > 0 && anyChecked === rowCheckboxes.length;
            }
        }

        if (selectAll) {
            selectAll.addEventListener('change', function() {
                rowCheckboxes.forEach(checkbox => checkbox.checked = selectAll.checked);
                toggleDeleteButton();
            });
        }
        
        rowCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', () => toggleDeleteButton());
        });
        
        toggleDeleteButton();
    });
</script>

<style>
    @keyframes fadeInUp { 
        0% { opacity: 0; transform: translateY(15px) scale(0.98); } 
        100% { opacity: 1; transform: translateY(0) scale(1); } 
    }
    .animate-fade-in-up { animation: fadeInUp 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
</style>