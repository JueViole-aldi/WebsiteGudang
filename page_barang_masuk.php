<?php
// page_barang_masuk.php

defined('APP_LOADED') or die('Akses langsung ke file ini tidak diizinkan.');

$user_id = $_SESSION['id'];
$user_role = $_SESSION['role'];
$error_message = '';
$success_message = '';

// Akses halaman ini dibatasi untuk peran tertentu
if (!has_access(['supplier', 'staff', 'admin'])) {
    echo "<div class='bg-red-100 p-4 rounded-md border border-red-200 text-red-700 font-medium'>Anda tidak memiliki hak akses untuk halaman ini.</div>";
    return;
}

// === LOGIKA HAPUS MASSAL (KHUSUS ADMIN) ===
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['bulk_delete_barang_masuk']) && has_access(['admin'])) {
    $ids_to_delete = isset($_POST['selected_ids']) ? array_map('intval', $_POST['selected_ids']) : [];
    if (empty($ids_to_delete)) {
        $error_message = "Tidak ada data yang dipilih untuk dihapus.";
    } else {
        $placeholders = implode(',', array_fill(0, count($ids_to_delete), '?'));
        $types = str_repeat('i', count($ids_to_delete));
        $sql = "DELETE FROM barang_masuk WHERE id IN ($placeholders)";
        if ($stmt = $mysqli->prepare($sql)) {
            $stmt->bind_param($types, ...$ids_to_delete);
            if ($stmt->execute()) {
                $deleted_count = $stmt->affected_rows;
                if (function_exists('log_activity')) { log_activity($mysqli, $user_id, "Menghapus $deleted_count data barang masuk."); }
                $success_message = "$deleted_count data barang masuk berhasil dihapus.";
            } else {
                $error_message = "Gagal menghapus data.";
            }
            $stmt->close();
        }
    }
}

// === LOGIKA UNTUK SUPPLIER: Mengajukan barang masuk ===
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_barang_masuk']) && $user_role == 'supplier') {
    $id_hadiah = (int)$_POST['id_hadiah'];
    $jumlah = (int)$_POST['jumlah'];
    $keterangan = trim($_POST['keterangan']);

    if (empty($id_hadiah) || $jumlah <= 0) {
        $error_message = "Harap pilih hadiah dan masukkan jumlah yang valid.";
    } else {
        $sql = "INSERT INTO barang_masuk (id_hadiah, jumlah, keterangan, id_supplier) VALUES (?, ?, ?, ?)";
        if ($stmt = $mysqli->prepare($sql)) {
            $stmt->bind_param("iisi", $id_hadiah, $jumlah, $keterangan, $user_id);
            if ($stmt->execute()) {
                $new_id = $stmt->insert_id;
                if (function_exists('log_activity')) { log_activity($mysqli, $user_id, "Mengajukan barang masuk #{$new_id} sejumlah {$jumlah} pcs."); }
                $success_message = "Pengajuan barang masuk berhasil dikirim dan menunggu konfirmasi.";
                
                if (function_exists('get_user_ids_by_role') && function_exists('create_notification')) {
                    $staff_ids = get_user_ids_by_role($mysqli, 'staff');
                    $admin_ids = get_user_ids_by_role($mysqli, 'admin');
                    $notify_users = array_unique(array_merge($staff_ids, $admin_ids));
                    create_notification($mysqli, $notify_users, "Ada pengajuan barang masuk baru #{$new_id} yang perlu dikonfirmasi.", "dashboard.php?page=barang_masuk");
                }
            } else {
                $error_message = "Gagal mengajukan barang masuk.";
            }
            $stmt->close();
        }
    }
}

// === LOGIKA UNTUK ADMIN/STAFF: Menambah Stok Langsung ===
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_stock_internal']) && has_access(['staff', 'admin'])) {
    $id_hadiah = (int)$_POST['id_hadiah_internal'];
    $jumlah = (int)$_POST['jumlah_internal'];
    $id_supplier = (int)$_POST['id_supplier_internal'];
    $keterangan = trim($_POST['keterangan_internal']);

    if ($id_hadiah > 0 && $jumlah > 0 && $id_supplier > 0) {
        $mysqli->begin_transaction();
        try {
            $stmt1 = $mysqli->prepare("INSERT INTO barang_masuk (id_hadiah, jumlah, keterangan, id_supplier, status, id_staff, tanggal_konfirmasi) VALUES (?, ?, ?, ?, 'divalidasi', ?, NOW())");
            $stmt1->bind_param("iisii", $id_hadiah, $jumlah, $keterangan, $id_supplier, $user_id);
            $stmt1->execute();
            $id_barang_masuk = $stmt1->insert_id;

            $stmt2 = $mysqli->prepare("UPDATE hadiah SET stok = stok + ? WHERE id = ?");
            $stmt2->bind_param("ii", $jumlah, $id_hadiah);
            $stmt2->execute();

            $keterangan_transaksi = "Pencatatan internal dari barang masuk #{$id_barang_masuk}";
            $stmt3 = $mysqli->prepare("INSERT INTO transaksi_hadiah (id_hadiah, id_user, tipe_transaksi, jumlah, keterangan) VALUES (?, ?, 'masuk', ?, ?)");
            $stmt3->bind_param("iiis", $id_hadiah, $user_id, $jumlah, $keterangan_transaksi);
            $stmt3->execute();

            $mysqli->commit();
            if (function_exists('log_activity')) { log_activity($mysqli, $user_id, "Mencatat & menambah stok untuk hadiah ID {$id_hadiah} sejumlah {$jumlah}."); }
            $success_message = "Stok berhasil dicatat dan ditambahkan secara internal.";
        } catch (Exception $e) {
            $mysqli->rollback();
            $error_message = "Gagal menambah stok: " . $e->getMessage();
        }
    } else {
        $error_message = "Semua field wajib diisi dengan benar.";
    }
}

// === LOGIKA UNTUK STAFF & ADMIN: Konfirmasi atau Tolak pengajuan ===
if ($_SERVER["REQUEST_METHOD"] == "POST" && has_access(['staff', 'admin'])) {
    if (isset($_POST['confirm_barang'])) {
        $id_barang_masuk = (int)$_POST['id_barang_masuk'];
        
        $mysqli->begin_transaction();
        try {
            $data_query = $mysqli->query("SELECT id_hadiah, jumlah, id_supplier FROM barang_masuk WHERE id = $id_barang_masuk AND status = 'diajukan'");
            $data = $data_query->fetch_assoc();
            if (!$data) throw new Exception("Data tidak ditemukan atau sudah diproses.");
            
            $id_hadiah = $data['id_hadiah'];
            $jumlah = $data['jumlah'];
            $id_supplier = $data['id_supplier'];

            $stmt1 = $mysqli->prepare("UPDATE barang_masuk SET status = 'divalidasi', id_staff = ?, tanggal_konfirmasi = NOW() WHERE id = ?");
            $stmt1->bind_param("ii", $user_id, $id_barang_masuk);
            $stmt1->execute();

            $stmt2 = $mysqli->prepare("UPDATE hadiah SET stok = stok + ? WHERE id = ?");
            $stmt2->bind_param("ii", $jumlah, $id_hadiah);
            $stmt2->execute();

            $keterangan_transaksi = "Validasi dari pengajuan barang masuk ID: {$id_barang_masuk}";
            $stmt3 = $mysqli->prepare("INSERT INTO transaksi_hadiah (id_hadiah, id_user, tipe_transaksi, jumlah, keterangan) VALUES (?, ?, 'masuk', ?, ?)");
            $stmt3->bind_param("iiis", $id_hadiah, $user_id, $jumlah, $keterangan_transaksi);
            $stmt3->execute();

            $mysqli->commit();
            if (function_exists('log_activity')) { log_activity($mysqli, $user_id, "Mengkonfirmasi barang masuk ID: {$id_barang_masuk} dan menambah stok."); }
            $success_message = "Barang berhasil dikonfirmasi dan stok telah diperbarui.";
            
            if (function_exists('delete_notification_by_link')) { delete_notification_by_link($mysqli, "dashboard.php?page=barang_masuk"); }
            if (function_exists('create_notification')) { create_notification($mysqli, $id_supplier, "Pengajuan barang masuk #{$id_barang_masuk} Anda telah disetujui.", "dashboard.php?page=barang_masuk"); }
        } catch (Exception $e) {
            $mysqli->rollback();
            $error_message = "Gagal memproses barang: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['reject_barang'])) {
        $id_barang_masuk = (int)$_POST['id_barang_masuk_reject'];
        $alasan_ditolak = trim($_POST['alasan_ditolak']);

        $supplier_id_query = $mysqli->query("SELECT id_supplier FROM barang_masuk WHERE id = $id_barang_masuk");
        $id_supplier = $supplier_id_query->fetch_assoc()['id_supplier'] ?? 0;

        if (empty($alasan_ditolak)) {
            $error_message = "Alasan penolakan harus diisi.";
        } else {
            $sql = "UPDATE barang_masuk SET status = 'ditolak', id_staff = ?, tanggal_konfirmasi = NOW(), alasan_ditolak = ? WHERE id = ? AND status = 'diajukan'";
            if ($stmt = $mysqli->prepare($sql)) {
                $stmt->bind_param("isi", $user_id, $alasan_ditolak, $id_barang_masuk);
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    if (function_exists('log_activity')) { log_activity($mysqli, $user_id, "Menolak barang masuk ID: {$id_barang_masuk}."); }
                    $success_message = "Pengajuan barang berhasil ditolak.";
                    
                    if (function_exists('delete_notification_by_link')) { delete_notification_by_link($mysqli, "dashboard.php?page=barang_masuk"); }
                    if (function_exists('create_notification')) { create_notification($mysqli, $id_supplier, "Pengajuan barang masuk #{$id_barang_masuk} Anda ditolak. Alasan: " . $alasan_ditolak, "dashboard.php?page=barang_masuk"); }
                } else {
                    $error_message = "Gagal menolak barang atau barang sudah diproses.";
                }
                $stmt->close();
            }
        }
    }
}

// Ambil data untuk dropdown & tabel
$hadiah_list = $mysqli->query("SELECT id, nama_hadiah, stok FROM hadiah ORDER BY nama_hadiah ASC");
$supplier_list = $mysqli->query("SELECT id, name FROM users WHERE role = 'supplier' ORDER BY name ASC");

$query_barang_masuk = "
    SELECT 
        bm.id, bm.jumlah, bm.keterangan, bm.status, bm.tanggal_input, bm.alasan_ditolak,
        h.nama_hadiah,
        u_supp.name as supplier_name,
        u_staff.name as staff_name
    FROM barang_masuk bm
    JOIN hadiah h ON bm.id_hadiah = h.id
    JOIN users u_supp ON bm.id_supplier = u_supp.id
    LEFT JOIN users u_staff ON bm.id_staff = u_staff.id
";

if ($user_role == 'supplier') {
    $query_barang_masuk .= " WHERE bm.id_supplier = " . $user_id;
}
$query_barang_masuk .= " ORDER BY bm.tanggal_input DESC";
$result_barang_masuk = $mysqli->query($query_barang_masuk);
?>

<div id="barang-masuk">
    <!-- Header Halaman -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-3xl font-extrabold text-gray-800 tracking-tight">Barang Masuk</h1>
            <p class="text-sm text-gray-500 mt-1">Kelola stok hadiah yang masuk dari rekanan supplier atau internal.</p>
        </div>
        
        <div class="flex gap-3">
            <?php if ($user_role == 'supplier'): ?>
            <button onclick="openModal('supplier-modal')" class="inline-flex items-center px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold rounded-xl transition-all shadow-lg shadow-emerald-200 transform hover:-translate-y-0.5">
                <i class="fas fa-truck-loading mr-2 text-lg"></i> Ajukan Pengiriman
            </button>
            <?php elseif (has_access(['staff', 'admin'])): ?>
            <button onclick="openModal('internal-modal')" class="inline-flex items-center px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold rounded-xl transition-all shadow-lg shadow-emerald-200 transform hover:-translate-y-0.5">
                <i class="fas fa-boxes mr-2 text-lg"></i> Catat Stok Internal
            </button>
            <?php endif; ?>
        </div>
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
                    <i class="fas fa-list-ul text-sm"></i>
                </span>
                <?php echo ($user_role == 'supplier' ? 'Riwayat Pengajuan Anda' : 'Daftar Proses Barang Masuk'); ?>
            </h2>
            <?php if (has_access(['admin'])): ?>
            <button type="submit" name="bulk_delete_barang_masuk" form="bulkDeleteFormBarangMasuk" id="btnBulkDeleteBarangMasuk" disabled class="inline-flex items-center px-4 py-2 bg-rose-50 text-rose-600 border border-rose-100 rounded-lg text-sm font-bold disabled:opacity-40 disabled:cursor-not-allowed transition-all hover:bg-rose-100">
                <i class="fas fa-trash-alt mr-2"></i> Hapus Terpilih
            </button>
            <?php endif; ?>
        </div>

        <form method="POST" id="bulkDeleteFormBarangMasuk" onsubmit="return confirm('Anda yakin ingin menghapus data yang dipilih?');">
            <input type="hidden" name="bulk_delete_barang_masuk" value="1">
            <div class="overflow-x-auto rounded-xl border border-gray-100">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-gray-50 border-b border-gray-100">
                            <?php if(has_access(['admin'])): ?>
                            <th class="py-4 px-4 w-12 text-center">
                                <input type="checkbox" id="selectAllBarangMasuk" class="h-4 w-4 text-emerald-600 focus:ring-emerald-500 border-gray-300 rounded cursor-pointer transition-colors">
                            </th>
                            <?php endif; ?>
                            <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Tanggal</th>
                            <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Hadiah</th>
                            <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Jumlah</th>
                            <?php if(has_access(['admin', 'staff'])): ?>
                            <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Supplier</th>
                            <?php endif; ?>
                            <!-- Keterangan kini ditampilkan untuk semua peran -->
                            <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Keterangan</th>
                            <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider text-center">Status</th>
                            <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if ($result_barang_masuk && $result_barang_masuk->num_rows > 0): ?>
                            <?php while ($row = $result_barang_masuk->fetch_assoc()): ?>
                            <tr class="hover:bg-emerald-50/50 transition-colors group">
                                <?php if(has_access(['admin'])): ?>
                                <td class="py-4 px-4 text-center">
                                    <input type="checkbox" name="selected_ids[]" value="<?php echo $row['id']; ?>" class="row-check-barang-masuk h-4 w-4 text-emerald-600 focus:ring-emerald-500 border-gray-300 rounded cursor-pointer transition-colors">
                                </td>
                                <?php endif; ?>
                                <td class="py-4 px-4 text-sm text-gray-600 whitespace-nowrap font-medium">
                                    <?php echo date('d M Y', strtotime($row['tanggal_input'])); ?>
                                </td>
                                <td class="py-4 px-4 font-bold text-gray-800"><?php echo htmlspecialchars($row['nama_hadiah']); ?></td>
                                <td class="py-4 px-4">
                                    <span class="inline-block px-2.5 py-1 bg-gray-100 text-gray-700 font-bold rounded text-xs border border-gray-200">
                                        <?php echo number_format($row['jumlah']); ?> unit
                                    </span>
                                </td>
                                
                                <?php if(has_access(['admin', 'staff'])): ?>
                                <td class="py-4 px-4 text-sm text-gray-700 font-medium"><?php echo htmlspecialchars($row['supplier_name']); ?></td>
                                <?php endif; ?>

                                <!-- Kolom Keterangan Terlihat Untuk Semua -->
                                <td class="py-4 px-4">
                                    <div class="text-[11px] text-gray-500 italic max-w-[150px] truncate" title="<?php echo htmlspecialchars($row['keterangan']); ?>">
                                        <?php echo htmlspecialchars($row['keterangan'] ?: '-'); ?>
                                    </div>
                                </td>

                                <td class="py-4 px-4 text-center">
                                    <?php
                                    $st = $row['status'];
                                    if ($st == 'diajukan') {
                                        echo "<span class='inline-flex px-3 py-1 text-[10px] font-bold rounded-full bg-blue-50 text-blue-600 border border-blue-100 uppercase tracking-wide'>Menunggu</span>";
                                    } elseif ($st == 'divalidasi') {
                                        echo "<span class='inline-flex px-3 py-1 text-[10px] font-bold rounded-full bg-emerald-50 text-emerald-600 border border-emerald-100 uppercase tracking-wide'><i class='fas fa-check mr-1 mt-0.5'></i> Diterima</span>";
                                    } elseif ($st == 'ditolak') {
                                        echo "<span class='inline-flex px-3 py-1 text-[10px] font-bold rounded-full bg-rose-50 text-rose-600 border border-rose-100 uppercase tracking-wide'>Ditolak</span>";
                                    }
                                    ?>
                                </td>
                                <td class="py-4 px-4 text-center">
                                    <?php if (has_access(['staff', 'admin']) && $row['status'] == 'diajukan'): ?>
                                    <!-- Aksi Admin/Staff -->
                                    <div class="flex items-center justify-center gap-2 opacity-90 group-hover:opacity-100 transition-opacity">
                                        <form method="POST" onsubmit="return confirm('Konfirmasi barang ini? Stok akan langsung bertambah ke gudang.');" class="m-0 p-0">
                                            <input type="hidden" name="id_barang_masuk" value="<?php echo $row['id']; ?>">
                                            <button type="submit" name="confirm_barang" class="inline-flex items-center justify-center px-3 py-1.5 bg-emerald-500 text-white text-xs font-bold rounded-lg hover:bg-emerald-600 shadow-sm transition-all transform hover:scale-105" title="Terima & Tambah Stok">
                                                <i class="fas fa-check mr-1.5"></i> Terima
                                            </button>
                                        </form>
                                        <button type="button" onclick="openRejectModal(<?php echo $row['id']; ?>)" class="inline-flex items-center justify-center px-3 py-1.5 bg-rose-500 text-white text-xs font-bold rounded-lg hover:bg-rose-600 shadow-sm transition-all transform hover:scale-105" title="Tolak Pengajuan">
                                            <i class="fas fa-times mr-1.5"></i> Tolak
                                        </button>
                                    </div>
                                    <?php elseif ($user_role == 'supplier' && $row['status'] == 'ditolak'): ?>
                                    <!-- Aksi Khusus Supplier Jika Ditolak -->
                                    <div class="flex items-center justify-center">
                                        <button type="button" onclick="showRejectReason('<?php echo htmlspecialchars($row['alasan_ditolak'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['staff_name'] ?? 'Admin/Staff', ENT_QUOTES); ?>')" class="inline-flex items-center justify-center px-3 py-1.5 bg-indigo-50 text-indigo-600 text-xs font-bold rounded-lg border border-indigo-100 hover:bg-indigo-100 transition-all" title="Lihat Alasan Penolakan">
                                            <i class="fas fa-info-circle mr-1.5"></i> Alasan
                                        </button>
                                    </div>
                                    <?php else: ?>
                                        <span class="text-xs text-gray-300 font-medium">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <?php
                                $colspan = 6; // Supplier
                                if(has_access(['staff'])) $colspan = 7;
                                if(has_access(['admin'])) $colspan = 8;
                                ?>
                                <td colspan="<?php echo $colspan; ?>" class="text-center py-16">
                                    <div class="flex flex-col items-center justify-center text-gray-400">
                                        <i class="fas fa-box-open text-4xl mb-3 text-gray-300"></i>
                                        <p class="font-medium text-sm">Belum ada riwayat barang masuk.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL DIALOGS -->
<!-- ========================================== -->

<!-- Modal: Pengajuan Barang (Khusus Supplier) -->
<div id="supplier-modal" class="fixed inset-0 bg-gray-900/60 z-50 hidden items-center justify-center p-4 backdrop-blur-sm transition-opacity">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden transform transition-all animate-fade-in-up border border-emerald-100">
        <div class="bg-gradient-to-r from-emerald-600 to-teal-600 px-6 py-4 flex items-center justify-between">
            <h3 class="text-xl font-bold text-white flex items-center"><i class="fas fa-truck-loading mr-3 opacity-90"></i> Ajukan Barang</h3>
            <button onclick="closeModal('supplier-modal')" class="text-white/80 hover:text-white transition-colors"><i class="fas fa-times text-lg"></i></button>
        </div>
        <form method="POST" class="p-6 space-y-5 bg-gray-50/50">
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1.5">Pilih Hadiah yang Dikirim</label>
                <select name="id_hadiah" required class="w-full border-gray-300 rounded-xl px-4 py-3 bg-white focus:bg-emerald-50 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none transition-all shadow-sm appearance-none cursor-pointer">
                    <option value="">-- Pilih Barang --</option>
                    <?php mysqli_data_seek($hadiah_list, 0); while($h = $hadiah_list->fetch_assoc()): ?>
                        <option value="<?php echo $h['id']; ?>"><?php echo htmlspecialchars($h['nama_hadiah']); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1.5">Jumlah Unit</label>
                <input type="number" name="jumlah" min="1" required class="w-full border-gray-300 rounded-xl px-4 py-3 bg-white focus:bg-emerald-50 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none transition-all shadow-sm" placeholder="Masukkan angka">
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1.5">Keterangan / Catatan Resi</label>
                <textarea name="keterangan" rows="3" class="w-full border-gray-300 rounded-xl px-4 py-3 bg-white focus:bg-emerald-50 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none transition-all shadow-sm" placeholder="Contoh: Dikirim via JNE (Resi: 123456)"></textarea>
            </div>
            <div class="pt-4 flex space-x-3">
                <button type="button" onclick="closeModal('supplier-modal')" class="flex-1 py-3 bg-white border border-gray-300 text-gray-700 font-bold rounded-xl hover:bg-gray-50 transition-all shadow-sm">Batal</button>
                <button type="submit" name="submit_barang_masuk" class="flex-1 py-3 bg-emerald-600 text-white font-bold rounded-xl hover:bg-emerald-700 shadow-md shadow-emerald-200 transition-all transform hover:-translate-y-0.5">Kirim Pengajuan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Pencatatan Internal (Admin & Staff) -->
<div id="internal-modal" class="fixed inset-0 bg-gray-900/60 z-50 hidden items-center justify-center p-4 backdrop-blur-sm transition-opacity">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden transform transition-all animate-fade-in-up border border-emerald-100">
        <div class="bg-gradient-to-r from-emerald-600 to-teal-600 px-6 py-4 flex items-center justify-between">
            <h3 class="text-xl font-bold text-white flex items-center"><i class="fas fa-boxes mr-3 opacity-90"></i> Catat Stok Internal</h3>
            <button onclick="closeModal('internal-modal')" class="text-white/80 hover:text-white transition-colors"><i class="fas fa-times text-lg"></i></button>
        </div>
        <form method="POST" class="p-6 space-y-4 bg-gray-50/50">
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1.5">Pilih Hadiah Masuk</label>
                <select name="id_hadiah_internal" required class="w-full border-gray-300 rounded-xl px-4 py-3 bg-white focus:bg-emerald-50 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none transition-all shadow-sm appearance-none cursor-pointer">
                    <option value="">-- Pilih Barang --</option>
                    <?php mysqli_data_seek($hadiah_list, 0); while($h = $hadiah_list->fetch_assoc()): ?>
                        <option value="<?php echo $h['id']; ?>"><?php echo htmlspecialchars($h['nama_hadiah']); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1.5">Dari Supplier</label>
                    <select name="id_supplier_internal" required class="w-full border-gray-300 rounded-xl px-4 py-3 bg-white focus:bg-emerald-50 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none transition-all shadow-sm appearance-none cursor-pointer">
                        <option value="">-- Pilih --</option>
                        <?php mysqli_data_seek($supplier_list, 0); while($s = $supplier_list->fetch_assoc()): ?>
                            <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1.5">Jumlah</label>
                    <input type="number" name="jumlah_internal" min="1" required class="w-full border-gray-300 rounded-xl px-4 py-3 bg-white focus:bg-emerald-50 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none transition-all shadow-sm">
                </div>
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1.5">Catatan Admin/Staff</label>
                <textarea name="keterangan_internal" rows="2" class="w-full border-gray-300 rounded-xl px-4 py-3 bg-white focus:bg-emerald-50 focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none transition-all shadow-sm" placeholder="Misal: Pembelian tunai langsung"></textarea>
            </div>
            <div class="pt-4 flex space-x-3">
                <button type="button" onclick="closeModal('internal-modal')" class="flex-1 py-3 bg-white border border-gray-300 text-gray-700 font-bold rounded-xl hover:bg-gray-50 transition-all shadow-sm">Batal</button>
                <button type="submit" name="add_stock_internal" class="flex-1 py-3 bg-emerald-600 text-white font-bold rounded-xl shadow-md shadow-emerald-200 hover:bg-emerald-700 transition-all transform hover:-translate-y-0.5">Tambah ke Stok</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Tolak Pengajuan -->
<div id="reject-modal" class="fixed inset-0 bg-gray-900/60 z-50 hidden items-center justify-center p-4 backdrop-blur-sm transition-opacity">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden transform transition-all animate-fade-in-up border border-rose-100">
        <div class="bg-gradient-to-r from-rose-500 to-red-600 px-6 py-4 flex items-center justify-between">
            <h3 class="text-xl font-bold text-white flex items-center"><i class="fas fa-exclamation-triangle mr-3 opacity-90"></i> Tolak Barang</h3>
            <button onclick="closeModal('reject-modal')" class="text-white/80 hover:text-white transition-colors"><i class="fas fa-times text-lg"></i></button>
        </div>
        <form method="POST" class="p-6 bg-gray-50/50">
            <input type="hidden" name="id_barang_masuk_reject" id="id_barang_masuk_reject">
            <div class="mb-6">
                <label class="block text-sm font-bold text-gray-700 mb-2">Alasan Penolakan (Wajib Diisi)</label>
                <textarea name="alasan_ditolak" id="alasan_ditolak" required rows="4" class="w-full border-gray-300 rounded-xl px-4 py-3 bg-white focus:bg-rose-50 focus:ring-2 focus:ring-rose-500 focus:border-rose-500 outline-none transition-all shadow-sm" placeholder="Jelaskan alasan pengajuan ini ditolak kepada supplier..."></textarea>
            </div>
            <div class="flex space-x-3">
                <button type="button" onclick="closeModal('reject-modal')" class="flex-1 py-3 bg-white border border-gray-300 text-gray-700 font-bold rounded-xl hover:bg-gray-50 transition-all shadow-sm">Batal</button>
                <button type="submit" name="reject_barang" class="flex-1 py-3 bg-rose-600 text-white font-bold rounded-xl shadow-md shadow-rose-200 hover:bg-rose-700 transition-all">Tolak Pengajuan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Detail Penolakan (Untuk Supplier) -->
<div id="reason-modal" class="fixed inset-0 bg-gray-900/60 z-50 hidden items-center justify-center p-4 backdrop-blur-sm transition-opacity">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm p-6 transform transition-all animate-fade-in-up text-center border border-indigo-100">
        <div class="w-16 h-16 bg-indigo-100 text-indigo-600 rounded-full flex items-center justify-center mx-auto mb-4">
            <i class="fas fa-info-circle text-3xl"></i>
        </div>
        <h3 class="text-xl font-bold text-gray-800 mb-1">Informasi Penolakan</h3>
        <p class="text-xs text-gray-400 mb-6 uppercase font-bold tracking-wider">Ditolak oleh: <span id="rejected-by" class="text-gray-600"></span></p>
        
        <div class="bg-gray-50 p-4 rounded-xl border border-gray-200 mb-6 text-left">
            <p class="text-xs text-gray-500 font-bold uppercase mb-1">Alasan Penolakan:</p>
            <p id="rejection-reason" class="text-gray-800 text-sm font-medium"></p>
        </div>
        
        <button type="button" onclick="closeModal('reason-modal')" class="w-full py-3 bg-indigo-600 text-white font-bold rounded-xl shadow-md hover:bg-indigo-700 transition-all">Mengerti & Tutup</button>
    </div>
</div>

<script>
    // Global Modal Helpers
    function openModal(id) {
        const m = document.getElementById(id);
        m.classList.remove('hidden');
        m.classList.add('flex');
    }
    function closeModal(id) {
        const m = document.getElementById(id);
        m.classList.add('hidden');
        m.classList.remove('flex');
    }

    // Context Specific
    function openRejectModal(id) {
        document.getElementById('id_barang_masuk_reject').value = id;
        openModal('reject-modal');
    }

    function showRejectReason(reason, rejectedBy) {
        document.getElementById('rejection-reason').innerText = reason || 'Tidak ada alasan spesifik yang diberikan.';
        document.getElementById('rejected-by').innerText = rejectedBy || 'Petugas Gudang';
        openModal('reason-modal');
    }

    // Bulk Delete Logic yang aman
    document.addEventListener('DOMContentLoaded', function() {
        const selectAll = document.getElementById('selectAllBarangMasuk');
        const btnDel = document.getElementById('btnBulkDeleteBarangMasuk');
        const getChecks = () => Array.from(document.querySelectorAll('.row-check-barang-masuk'));
        
        function updateBtn() {
            const checks = getChecks();
            const selCount = checks.filter(c => c.checked).length;
            if(btnDel) btnDel.disabled = selCount === 0;
            if(selectAll) {
                selectAll.indeterminate = selCount > 0 && selCount < checks.length;
                selectAll.checked = selCount > 0 && selCount === checks.length;
            }
        }

        if(selectAll){
            selectAll.addEventListener('change', () => {
                getChecks().forEach(c => c.checked = selectAll.checked);
                updateBtn();
            });
        }
        
        document.addEventListener('change', (e) => {
            if(e.target && e.target.classList.contains('row-check-barang-masuk')) {
                updateBtn();
            }
        });
        
        updateBtn(); // Init on load
    });
</script>

<style>
    @keyframes fadeInUp { 
        0% { opacity: 0; transform: translateY(15px) scale(0.98); } 
        100% { opacity: 1; transform: translateY(0) scale(1); } 
    }
    .animate-fade-in-up { animation: fadeInUp 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
</style>