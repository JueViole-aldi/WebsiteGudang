<?php
// page_permintaan_stok.php

defined('APP_LOADED') or die('Akses langsung ke file ini tidak diizinkan.');

$user_id = $_SESSION['id'];
$user_role = $_SESSION['role'];
$error_message = '';
$success_message = '';

if (!has_access(['staff', 'admin', 'supplier'])) {
    echo "<div class='bg-red-100 p-4 rounded-md border border-red-200 text-red-700 font-medium'>Anda tidak memiliki hak akses untuk halaman ini.</div>";
    return;
}

// LOGIKA HAPUS MASSAL (KHUSUS ADMIN)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['bulk_delete_permintaan']) && has_access(['admin'])) {
    $ids_to_delete = isset($_POST['selected_ids']) ? array_map('intval', $_POST['selected_ids']) : [];
    if (empty($ids_to_delete)) {
        $error_message = "Tidak ada data yang dipilih untuk dihapus.";
    } else {
        $placeholders = implode(',', array_fill(0, count($ids_to_delete), '?'));
        $types = str_repeat('i', count($ids_to_delete));
        $sql = "DELETE FROM permintaan_stok WHERE id IN ($placeholders)";
        if ($stmt = $mysqli->prepare($sql)) {
            $stmt->bind_param($types, ...$ids_to_delete);
            if ($stmt->execute()) {
                $deleted_count = $stmt->affected_rows;
                if (function_exists('log_activity')) { log_activity($mysqli, $user_id, "Menghapus $deleted_count data permintaan stok."); }
                $success_message = "$deleted_count data permintaan stok berhasil dihapus.";
            } else {
                $error_message = "Gagal menghapus data.";
            }
            $stmt->close();
        }
    }
}


// LOGIKA STAFF & ADMIN: Membuat Permintaan
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_permintaan']) && has_access(['staff', 'admin'])) {
    $id_hadiah = (int)$_POST['id_hadiah'];
    $jumlah_diminta = (int)$_POST['jumlah_diminta'];
    $catatan_staff = trim($_POST['catatan_staff']);
    if ($id_hadiah > 0 && $jumlah_diminta > 0) {
        $sql = "INSERT INTO permintaan_stok (id_hadiah, jumlah_diminta, catatan_staff, id_staff) VALUES (?, ?, ?, ?)";
        if ($stmt = $mysqli->prepare($sql)) {
            $stmt->bind_param("iisi", $id_hadiah, $jumlah_diminta, $catatan_staff, $user_id);
            if ($stmt->execute()) {
                $permintaan_id = $stmt->insert_id;
                if (function_exists('log_activity')) { log_activity($mysqli, $user_id, "Membuat permintaan stok #{$permintaan_id} untuk hadiah ID {$id_hadiah}."); }
                
                if (function_exists('get_user_ids_by_role') && function_exists('create_notification')) {
                    $notify_users = array_merge(get_user_ids_by_role($mysqli, 'admin'), get_user_ids_by_role($mysqli, 'staff'));
                    foreach ($notify_users as $notify_user_id) {
                        if ($notify_user_id != $user_id) {
                             create_notification($mysqli, $notify_user_id, "Permintaan stok baru #{$permintaan_id} perlu disetujui.", "dashboard.php?page=permintaan_stok");
                        }
                    }
                }
                $success_message = "Permintaan stok berhasil dibuat dan menunggu persetujuan.";
            } else { $error_message = "Gagal membuat permintaan stok."; }
            $stmt->close();
        }
    } else { $error_message = "Harap pilih hadiah dan isi jumlah dengan benar."; }
}


// LOGIKA STAFF & ADMIN: Menyetujui atau Menolak
if ($_SERVER["REQUEST_METHOD"] == "POST" && has_access(['staff', 'admin'])) {
    if (isset($_POST['approve_permintaan'])) {
        $id_permintaan = (int)$_POST['id_permintaan_approve'];
        $id_supplier = (int)$_POST['id_supplier_ditugaskan'];
        
        // Ambil catatan asli dari staff untuk diteruskan ke supplier
        $stmt_get_note = $mysqli->prepare("SELECT catatan_staff FROM permintaan_stok WHERE id = ?");
        $stmt_get_note->bind_param("i", $id_permintaan);
        $stmt_get_note->execute();
        $catatan_asli = $stmt_get_note->get_result()->fetch_assoc()['catatan_staff'] ?? '';
        $stmt_get_note->close();

        if (function_exists('delete_notification_by_link_and_user')) {
            delete_notification_by_link_and_user($mysqli, "dashboard.php?page=permintaan_stok", $user_id);
        }

        if ($id_supplier > 0) {
            $sql = "UPDATE permintaan_stok SET status = 'disetujui', id_admin = ?, tanggal_keputusan = NOW(), id_supplier_ditugaskan = ?, catatan_admin = ? WHERE id = ? AND status = 'diajukan'";
            if ($stmt = $mysqli->prepare($sql)) {
                $stmt->bind_param("iisi", $user_id, $id_supplier, $catatan_asli, $id_permintaan);
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    if (function_exists('log_activity')) { log_activity($mysqli, $user_id, "Menyetujui permintaan stok ID: {$id_permintaan}."); }
                    
                    if (function_exists('create_notification')) {
                        create_notification($mysqli, $id_supplier, "Anda memiliki permintaan stok baru #{$id_permintaan} untuk dipenuhi.", "dashboard.php?page=permintaan_stok");
                    }
                    $success_message = "Permintaan disetujui dan telah diteruskan ke supplier.";
                } else { $error_message = "Gagal menyetujui permintaan atau permintaan sudah diproses."; }
                $stmt->close();
            }
        } else { $error_message = "Harap pilih supplier yang akan ditugaskan."; }
    }
    
    if (isset($_POST['reject_permintaan'])) {
        $id_permintaan = (int)$_POST['id_permintaan_reject'];
        $catatan_admin = trim($_POST['catatan_admin_reject']);
        
        if (function_exists('delete_notification_by_link_and_user')) {
            delete_notification_by_link_and_user($mysqli, "dashboard.php?page=permintaan_stok", $user_id);
        }

        if (empty($catatan_admin)) {
             $error_message = "Catatan atau alasan penolakan wajib diisi.";
        } else {
             $sql = "UPDATE permintaan_stok SET status = 'ditolak', id_admin = ?, tanggal_keputusan = NOW(), catatan_admin = ? WHERE id = ? AND status = 'diajukan'";
            if ($stmt = $mysqli->prepare($sql)) {
                $stmt->bind_param("isi", $user_id, $catatan_admin, $id_permintaan);
                 if ($stmt->execute() && $stmt->affected_rows > 0) {
                    if (function_exists('log_activity')) { log_activity($mysqli, $user_id, "Menolak permintaan stok ID: {$id_permintaan}."); }
                    $success_message = "Permintaan stok telah ditolak.";
                } else { $error_message = "Gagal menolak permintaan atau permintaan sudah diproses."; }
                $stmt->close();
            }
        }
    }
}


// LOGIKA SUPPLIER: Menyelesaikan Permintaan & STOK OTOMATIS BERTAMBAH
if ($_SERVER["REQUEST_METHOD"] == "POST" && $user_role == 'supplier' && isset($_POST['complete_request_supplier'])) {
    $id_permintaan = (int)$_POST['id_permintaan'];
    
    $mysqli->begin_transaction();
    try {
        $permintaan_query = $mysqli->prepare("SELECT id_hadiah, jumlah_diminta, id_admin FROM permintaan_stok WHERE id = ? AND status = 'disetujui' AND id_supplier_ditugaskan = ?");
        $permintaan_query->bind_param("ii", $id_permintaan, $user_id);
        $permintaan_query->execute();
        $permintaan_data = $permintaan_query->get_result()->fetch_assoc();

        if (!$permintaan_data) {
            throw new Exception("Permintaan tidak ditemukan, sudah diproses, atau bukan tugas Anda.");
        }

        $id_hadiah = $permintaan_data['id_hadiah'];
        $jumlah = $permintaan_data['jumlah_diminta'];
        $id_admin_approver = $permintaan_data['id_admin'];

        // Update status permintaan
        $stmt_update_permintaan = $mysqli->prepare("UPDATE permintaan_stok SET status = 'selesai', tanggal_respon_supplier = NOW() WHERE id = ?");
        $stmt_update_permintaan->bind_param("i", $id_permintaan);
        $stmt_update_permintaan->execute();
        
        // Buat entri baru di 'barang_masuk'
        $keterangan_masuk = "Otomatis dari penyelesaian permintaan stok #{$id_permintaan}";
        $stmt_barang_masuk = $mysqli->prepare("INSERT INTO barang_masuk (id_hadiah, jumlah, keterangan, id_supplier, status, id_staff, tanggal_konfirmasi) VALUES (?, ?, ?, ?, 'divalidasi', ?, NOW())");
        $stmt_barang_masuk->bind_param("iisii", $id_hadiah, $jumlah, $keterangan_masuk, $user_id, $id_admin_approver);
        $stmt_barang_masuk->execute();
        $id_barang_masuk = $stmt_barang_masuk->insert_id;

        // Update stok
        $stmt_update_stok = $mysqli->prepare("UPDATE hadiah SET stok = stok + ? WHERE id = ?");
        $stmt_update_stok->bind_param("ii", $jumlah, $id_hadiah);
        $stmt_update_stok->execute();

        // Catat di 'transaksi_hadiah'
        $keterangan_transaksi = "Otomatis dari barang masuk #{$id_barang_masuk}";
        $stmt_transaksi = $mysqli->prepare("INSERT INTO transaksi_hadiah (id_hadiah, id_user, tipe_transaksi, jumlah, keterangan) VALUES (?, ?, 'masuk', ?, ?)");
        $stmt_transaksi->bind_param("iiis", $id_hadiah, $id_admin_approver, $jumlah, $keterangan_transaksi);
        $stmt_transaksi->execute();

        $mysqli->commit();

        if (function_exists('log_activity')) { log_activity($mysqli, $user_id, "Menyelesaikan permintaan stok #{$id_permintaan}, stok bertambah {$jumlah}."); }
        $success_message = "Permintaan telah ditandai selesai dan stok barang telah bertambah secara otomatis.";
        
        if (function_exists('delete_notification_by_link_and_user')) { delete_notification_by_link_and_user($mysqli, "dashboard.php?page=permintaan_stok", $user_id); }
        if (function_exists('get_user_ids_by_role') && function_exists('create_notification')) {
            $admin_ids = get_user_ids_by_role($mysqli, 'admin');
            create_notification($mysqli, $admin_ids, "Supplier telah menyelesaikan permintaan stok #{$id_permintaan}. Stok telah diperbarui.", "dashboard.php?page=barang_masuk");
        }
    } catch (Exception $e) {
        $mysqli->rollback();
        $error_message = "Gagal menyelesaikan permintaan: " . $e->getMessage();
    }
}


$hadiah_list = $mysqli->query("SELECT id, nama_hadiah, stok FROM hadiah ORDER BY nama_hadiah ASC");
$supplier_list = $mysqli->query("SELECT id, name FROM users WHERE role = 'supplier' ORDER BY name ASC");

$base_query = "
    SELECT ps.*, h.nama_hadiah, u_peminta.name as peminta_name, u_admin.name as admin_name, u_supp.name as supplier_name
    FROM permintaan_stok ps
    JOIN hadiah h ON ps.id_hadiah = h.id
    JOIN users u_peminta ON ps.id_staff = u_peminta.id
    LEFT JOIN users u_admin ON ps.id_admin = u_admin.id
    LEFT JOIN users u_supp ON ps.id_supplier_ditugaskan = u_supp.id
";

$result_permintaan = null;
$result_tugas_baru = null;

if ($user_role == 'supplier') {
    $query_tugas_baru = $base_query . " WHERE ps.id_supplier_ditugaskan = ? AND ps.status = 'disetujui' ORDER BY ps.tanggal_permintaan DESC";
    $stmt_tugas = $mysqli->prepare($query_tugas_baru);
    $stmt_tugas->bind_param("i", $user_id);
    $stmt_tugas->execute();
    $result_tugas_baru = $stmt_tugas->get_result();
} else {
    $query_permintaan = $base_query . " ORDER BY ps.tanggal_permintaan DESC";
    $stmt_permintaan = $mysqli->prepare($query_permintaan);
    $stmt_permintaan->execute();
    $result_permintaan = $stmt_permintaan->get_result();
}
?>

<div id="permintaan-stok">
    <!-- Header Halaman -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-3xl font-extrabold text-gray-800 tracking-tight">Permintaan Stok</h1>
            <p class="text-sm text-gray-500 mt-1">Kelola pengajuan penambahan stok hadiah ke supplier.</p>
        </div>
        
        <?php if (has_access(['staff', 'admin'])): ?>
        <div class="flex gap-3">
            <button onclick="openRequestModal()" class="inline-flex items-center px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold rounded-xl transition-all shadow-lg shadow-emerald-200 transform hover:-translate-y-0.5">
                <i class="fas fa-plus-circle mr-2 text-lg"></i> Buat Permintaan Baru
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


    <?php if ($user_role == 'supplier'): ?>
    <!-- ============================================== -->
    <!-- TAMPILAN KHUSUS SUPPLIER (TUGAS BARU)          -->
    <!-- ============================================== -->
    <div class="space-y-8">
        <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
                <h2 class="text-xl font-bold text-gray-800 flex items-center">
                    <span class="w-8 h-8 rounded-lg bg-amber-100 text-amber-600 flex items-center justify-center mr-3">
                        <i class="fas fa-box-open text-sm"></i>
                    </span>
                    Tugas Permintaan Pengiriman Baru
                </h2>
            </div>
            
            <div class="overflow-x-auto rounded-xl border border-gray-100">
                <table class="w-full text-left border-collapse">
                     <thead>
                        <tr class="bg-gray-50 border-b border-gray-100">
                            <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Tanggal</th>
                            <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Item Hadiah</th>
                            <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Jumlah Diminta</th>
                            <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Pesan / Instruksi</th>
                            <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider text-center">Status</th>
                            <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if ($result_tugas_baru && $result_tugas_baru->num_rows > 0): ?>
                            <?php while ($row = $result_tugas_baru->fetch_assoc()): ?>
                            <tr class="hover:bg-amber-50/30 transition-colors group">
                                <td class="py-4 px-4 text-sm text-gray-600 whitespace-nowrap font-medium">
                                    <?php echo date('d M Y, H:i', strtotime($row['tanggal_permintaan'])); ?>
                                </td>
                                <td class="py-4 px-4 font-bold text-gray-800 text-base">
                                    <?php echo htmlspecialchars($row['nama_hadiah']); ?>
                                </td>
                                <td class="py-4 px-4">
                                    <span class="inline-block px-3 py-1.5 bg-gray-100 text-gray-800 font-bold rounded text-sm border border-gray-200">
                                        <?php echo number_format($row['jumlah_diminta']); ?> unit
                                    </span>
                                </td>
                                <!-- Kolom Catatan yang Diperjelas -->
                                <td class="py-4 px-4">
                                    <div class="text-sm text-gray-700 bg-gray-50 p-2.5 rounded-lg border border-gray-100 max-w-[250px] min-w-[150px]">
                                        <?php if (!empty($row['catatan_admin'])): ?>
                                            <i class="fas fa-comment-dots text-gray-400 mr-1.5"></i>
                                            <span class="font-medium italic"><?php echo htmlspecialchars($row['catatan_admin']); ?></span>
                                        <?php else: ?>
                                            <span class="text-gray-400 italic">Tidak ada pesan khusus.</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="py-4 px-4 text-center">
                                    <span class="inline-flex items-center px-3 py-1 text-[10px] font-bold rounded-full bg-amber-50 text-amber-600 border border-amber-200 uppercase tracking-wide">
                                        <i class="fas fa-hourglass-half mr-1.5"></i> Menunggu
                                    </span>
                                </td>
                                <td class="py-4 px-4 text-center">
                                    <!-- Tombol Aksi yang Diperbesar dan Premium -->
                                    <form method="POST" class="inline-block m-0 p-0" onsubmit="return confirm('Konfirmasi bahwa barang ini sudah Anda persiapkan/kirim? Sistem akan otomatis mencatatnya sebagai selesai dan menambah stok.')">
                                        <input type="hidden" name="id_permintaan" value="<?php echo $row['id']; ?>">
                                        <button type="submit" name="complete_request_supplier" class="inline-flex items-center justify-center px-4 py-2 bg-emerald-500 text-white text-xs font-bold rounded-lg hover:bg-emerald-600 shadow-md transition-all transform hover:scale-105" title="Selesaikan Permintaan Ini">
                                            <i class="fas fa-check-circle mr-1.5 text-sm"></i> Selesaikan Tugas
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-16">
                                    <div class="flex flex-col items-center justify-center text-gray-400">
                                        <i class="fas fa-check-double text-5xl mb-3 text-gray-300"></i>
                                        <p class="font-medium text-base text-gray-500">Hebat! Belum ada tugas pengiriman baru untuk saat ini.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php else: ?>
    <!-- ============================================== -->
    <!-- TAMPILAN KHUSUS ADMIN & STAFF                  -->
    <!-- ============================================== -->
    <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
            <h2 class="text-xl font-bold text-gray-800 flex items-center">
                <span class="w-8 h-8 rounded-lg bg-emerald-100 text-emerald-600 flex items-center justify-center mr-3">
                    <i class="fas fa-list-ul text-sm"></i>
                </span>
                Daftar Riwayat Pengajuan Stok
            </h2>
            <?php if (has_access(['admin'])): ?>
            <form method="POST" id="bulkDeleteFormPermintaan">
                <button type="submit" name="bulk_delete_permintaan" id="btnBulkDeletePermintaan" class="inline-flex items-center px-4 py-2 bg-rose-50 text-rose-600 border border-rose-100 rounded-lg text-sm font-bold disabled:opacity-40 disabled:cursor-not-allowed transition-all hover:bg-rose-100" disabled onclick="return confirm('Yakin ingin menghapus pengajuan yang dipilih?');">
                    <i class="fas fa-trash-alt mr-2"></i> Hapus Terpilih
                </button>
            </form>
            <?php endif; ?>
        </div>
        
        <div class="overflow-x-auto rounded-xl border border-gray-100">
            <table class="w-full text-left border-collapse">
                 <thead>
                    <tr class="bg-gray-50 border-b border-gray-100">
                        <?php if (has_access(['admin'])): ?>
                        <th class="py-4 px-4 w-12 text-center">
                            <input type="checkbox" id="selectAllPermintaan" class="h-4 w-4 text-emerald-600 focus:ring-emerald-500 border-gray-300 rounded cursor-pointer transition-colors">
                        </th>
                        <?php endif; ?>
                        <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Tanggal</th>
                        <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Hadiah</th>
                        <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Jumlah</th>
                        <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Catatan Staf</th>
                        <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider text-center">Status</th>
                        <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if ($result_permintaan && $result_permintaan->num_rows > 0): ?>
                        <?php while ($row = $result_permintaan->fetch_assoc()): ?>
                        <tr class="hover:bg-emerald-50/50 transition-colors group">
                            <?php if (has_access(['admin'])): ?>
                            <td class="py-4 px-4 text-center">
                                <input type="checkbox" class="row-check-permintaan h-4 w-4 text-emerald-600 focus:ring-emerald-500 border-gray-300 rounded cursor-pointer transition-colors" name="selected_ids[]" value="<?php echo $row['id']; ?>" form="bulkDeleteFormPermintaan">
                            </td>
                            <?php endif; ?>
                            <td class="py-4 px-4 text-sm text-gray-600 whitespace-nowrap font-medium">
                                <?php echo date('d M Y, H:i', strtotime($row['tanggal_permintaan'])); ?>
                            </td>
                            <td class="py-4 px-4 font-bold text-gray-800">
                                <?php echo htmlspecialchars($row['nama_hadiah']); ?>
                            </td>
                            <td class="py-4 px-4">
                                <span class="inline-block px-2.5 py-1 bg-gray-100 text-gray-700 font-bold rounded text-xs border border-gray-200">
                                    <?php echo number_format($row['jumlah_diminta']); ?> unit
                                </span>
                            </td>
                            <td class="py-4 px-4">
                                <div class="text-[11px] text-gray-500 italic max-w-[150px] truncate" title="<?php echo htmlspecialchars($row['catatan_staff']); ?>">
                                    <?php echo htmlspecialchars($row['catatan_staff'] ?: '-'); ?>
                                </div>
                            </td>
                            <td class="py-4 px-4 text-center">
                                <?php
                                $status = $row['status'];
                                if ($status == 'diajukan') {
                                    echo "<span class='inline-flex px-3 py-1 text-[10px] font-bold rounded-full bg-blue-50 text-blue-600 border border-blue-100 uppercase tracking-wide'>Diajukan</span>";
                                } elseif ($status == 'disetujui') {
                                    echo "<span class='inline-flex px-3 py-1 text-[10px] font-bold rounded-full bg-amber-50 text-amber-600 border border-amber-100 uppercase tracking-wide'><i class='fas fa-truck mr-1 mt-0.5'></i> Proses Supplier</span>";
                                } elseif ($status == 'selesai') {
                                    echo "<span class='inline-flex px-3 py-1 text-[10px] font-bold rounded-full bg-emerald-50 text-emerald-600 border border-emerald-100 uppercase tracking-wide'><i class='fas fa-check-double mr-1 mt-0.5'></i> Selesai</span>";
                                } elseif ($status == 'ditolak') {
                                    echo "<span class='inline-flex px-3 py-1 text-[10px] font-bold rounded-full bg-rose-50 text-rose-600 border border-rose-100 uppercase tracking-wide'>Ditolak</span>";
                                }
                                ?>
                            </td>
                            <td class="py-4 px-4 text-center">
                                <?php if (has_access(['staff','admin']) && $row['status'] == 'diajukan'): ?>
                                    <div class="flex items-center justify-center gap-2 opacity-90 group-hover:opacity-100 transition-opacity">
                                        <button type="button" onclick="openApproveModal(<?php echo htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8'); ?>)" class="inline-flex items-center justify-center px-3 py-1.5 bg-emerald-500 text-white text-xs font-bold rounded-lg hover:bg-emerald-600 shadow-sm transition-all transform hover:scale-105" title="Setujui Pengajuan">
                                            <i class="fas fa-check mr-1.5"></i> Setujui
                                        </button>
                                        <button type="button" onclick="openRejectModal(<?php echo $row['id']; ?>)" class="inline-flex items-center justify-center px-3 py-1.5 bg-rose-500 text-white text-xs font-bold rounded-lg hover:bg-rose-600 shadow-sm transition-all transform hover:scale-105" title="Tolak">
                                            <i class="fas fa-times"></i>
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
                            <td colspan="<?php echo has_access(['admin']) ? '7' : '6'; ?>" class="text-center py-16">
                                <div class="flex flex-col items-center justify-center text-gray-400">
                                    <i class="fas fa-file-signature text-4xl mb-3 text-gray-300"></i>
                                    <p class="font-medium text-sm">Belum ada riwayat pengajuan stok hadiah.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ========================================== -->
<!-- MODAL DIALOGS -->
<!-- ========================================== -->

<!-- Modal Tambah Permintaan Stok (BARU) -->
<div id="request-modal" class="fixed inset-0 bg-gray-900/60 z-50 hidden items-center justify-center p-4 backdrop-blur-sm transition-opacity">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden transform transition-all animate-fade-in-up border border-emerald-100">
        <div class="bg-gradient-to-r from-emerald-600 to-teal-600 px-6 py-4 flex items-center justify-between">
            <h3 class="text-xl font-bold text-white flex items-center"><i class="fas fa-file-signature mr-3 opacity-90"></i> Buat Pengajuan Baru</h3>
            <button type="button" onclick="closeRequestModal()" class="text-white/80 hover:text-white transition-colors"><i class="fas fa-times text-lg"></i></button>
        </div>
        <form method="POST" action="dashboard.php?page=permintaan_stok" class="p-6 space-y-5 bg-gray-50/50">
            <div>
                <label for="id_hadiah" class="block text-sm font-bold text-gray-700 mb-1.5">Pilih Hadiah (Stok Saat Ini)</label>
                <select name="id_hadiah" id="id_hadiah" required class="w-full border-gray-300 rounded-xl px-4 py-3 bg-white focus:bg-emerald-50 focus:ring-2 focus:ring-emerald-500 outline-none transition-all appearance-none cursor-pointer shadow-sm">
                    <option value="">-- Pilih Hadiah --</option>
                    <?php mysqli_data_seek($hadiah_list, 0); while($row = $hadiah_list->fetch_assoc()): ?>
                        <option value="<?php echo $row['id']; ?>"><?php echo htmlspecialchars($row['nama_hadiah']) . " (Sisa: " . $row['stok'] . " unit)"; ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div>
                <label for="jumlah_diminta" class="block text-sm font-bold text-gray-700 mb-1.5">Jumlah yang Dibutuhkan</label>
                <input type="number" name="jumlah_diminta" id="jumlah_diminta" min="1" required class="w-full border-gray-300 rounded-xl px-4 py-3 bg-white focus:bg-emerald-50 focus:ring-2 focus:ring-emerald-500 outline-none transition-all shadow-sm" placeholder="Contoh: 50">
            </div>
            <div>
                <label for="catatan_staff" class="block text-sm font-bold text-gray-700 mb-1.5">Catatan/Pesan (Opsional)</label>
                <textarea name="catatan_staff" id="catatan_staff" rows="3" class="w-full border-gray-300 rounded-xl px-4 py-3 bg-white focus:bg-emerald-50 focus:ring-2 focus:ring-emerald-500 outline-none transition-all shadow-sm" placeholder="Sampaikan instruksi kepada Supplier..."></textarea>
            </div>
            <div class="pt-4 flex space-x-3">
                <button type="button" onclick="closeRequestModal()" class="flex-1 py-3 bg-white border border-gray-300 text-gray-700 font-bold rounded-xl hover:bg-gray-50 transition-all shadow-sm">Batal</button>
                <button type="submit" name="submit_permintaan" class="flex-1 py-3 bg-emerald-600 text-white font-bold rounded-xl hover:bg-emerald-700 shadow-md shadow-emerald-200 transition-all transform hover:-translate-y-0.5">Kirim Pengajuan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Setujui & Tugaskan Supplier -->
<div id="approve-modal" class="fixed inset-0 bg-gray-900/60 z-50 hidden items-center justify-center p-4 backdrop-blur-sm transition-opacity">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden transform transition-all animate-fade-in-up border border-emerald-100">
        <div class="bg-gradient-to-r from-emerald-600 to-teal-600 px-6 py-4 flex items-center justify-between">
            <h3 class="text-xl font-bold text-white flex items-center"><i class="fas fa-check-circle mr-3 opacity-90"></i> Setujui & Tugaskan</h3>
            <button type="button" onclick="closeApproveModal()" class="text-white/80 hover:text-white transition-colors"><i class="fas fa-times text-lg"></i></button>
        </div>
        <form method="POST" action="dashboard.php?page=permintaan_stok" class="p-6 bg-gray-50/50">
            <input type="hidden" name="id_permintaan_approve" id="id_permintaan_approve">
            
            <div class="mb-6 p-4 bg-white rounded-xl border border-gray-200 shadow-sm text-center">
                <p class="text-[11px] text-gray-500 uppercase font-bold mb-1 tracking-wider">Item yang diajukan:</p>
                <p class="text-emerald-700 font-extrabold text-lg" id="approve-nama-hadiah"></p>
            </div>

            <div class="space-y-4 mb-6">
                <div>
                    <label for="id_supplier_ditugaskan" class="block text-sm font-bold text-gray-700 mb-1.5">Tugaskan ke Rekanan Supplier</label>
                    <select name="id_supplier_ditugaskan" id="id_supplier_ditugaskan" required class="w-full border-gray-300 rounded-xl px-4 py-3 bg-white focus:bg-emerald-50 focus:ring-2 focus:ring-emerald-500 outline-none transition-all appearance-none cursor-pointer shadow-sm">
                        <option value="">-- Pilih Supplier --</option>
                        <?php mysqli_data_seek($supplier_list, 0); while($row = $supplier_list->fetch_assoc()): ?>
                        <option value="<?php echo $row['id']; ?>"><?php echo htmlspecialchars($row['name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </div>

            <div class="pt-2 flex space-x-3">
                <button type="button" onclick="closeApproveModal()" class="flex-1 py-3 bg-white border border-gray-300 text-gray-700 font-bold rounded-xl hover:bg-gray-50 transition-all shadow-sm">Batal</button>
                <button type="submit" name="approve_permintaan" class="flex-1 py-3 bg-emerald-600 text-white font-bold rounded-xl hover:bg-emerald-700 shadow-md shadow-emerald-200 transition-all transform hover:-translate-y-0.5">Konfirmasi</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Tolak -->
<div id="reject-modal" class="fixed inset-0 bg-gray-900/60 z-50 hidden items-center justify-center p-4 backdrop-blur-sm transition-opacity">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden transform transition-all animate-fade-in-up border border-rose-100">
        <div class="bg-gradient-to-r from-rose-500 to-red-600 px-6 py-4 flex items-center justify-between">
            <h3 class="text-xl font-bold text-white flex items-center"><i class="fas fa-exclamation-triangle mr-3 opacity-90"></i> Tolak Pengajuan</h3>
            <button type="button" onclick="closeRejectModal()" class="text-white/80 hover:text-white transition-colors"><i class="fas fa-times text-lg"></i></button>
        </div>
        <form method="POST" action="dashboard.php?page=permintaan_stok" class="p-6 bg-gray-50/50">
            <input type="hidden" name="id_permintaan_reject" id="id_permintaan_reject">
            <div class="mb-6">
                <label for="catatan_admin_reject" class="block text-sm font-bold text-gray-700 mb-1.5">Alasan Penolakan (Wajib)</label>
                <textarea name="catatan_admin_reject" id="catatan_admin_reject" required rows="4" class="w-full border-gray-300 rounded-xl px-4 py-3 bg-white focus:bg-rose-50 focus:ring-2 focus:ring-rose-500 outline-none transition-all shadow-sm" placeholder="Mengapa pengajuan ini tidak disetujui?"></textarea>
            </div>
            <div class="flex space-x-3">
                <button type="button" onclick="closeRejectModal()" class="flex-1 py-3 bg-white border border-gray-300 text-gray-700 font-bold rounded-xl hover:bg-gray-50 transition-all shadow-sm">Batal</button>
                <button type="submit" name="reject_permintaan" class="flex-1 py-3 bg-rose-600 text-white font-bold rounded-xl shadow-md shadow-rose-200 hover:bg-rose-700 transition-all">Tolak Permanen</button>
            </div>
        </form>
    </div>
</div>

<script>
    const requestModal = document.getElementById('request-modal');
    const approveModal = document.getElementById('approve-modal');
    const rejectModal = document.getElementById('reject-modal');
    
    function openRequestModal() {
        requestModal.classList.remove('hidden');
        requestModal.classList.add('flex');
    }
    function closeRequestModal() {
        requestModal.classList.add('hidden');
        requestModal.classList.remove('flex');
    }

    function openApproveModal(permintaan) {
        document.getElementById('id_permintaan_approve').value = permintaan.id;
        document.getElementById('approve-nama-hadiah').innerText = permintaan.nama_hadiah + ' (' + permintaan.jumlah_diminta + ' unit)';
        approveModal.classList.remove('hidden');
        approveModal.classList.add('flex');
    }
    function closeApproveModal() { 
        approveModal.classList.add('hidden'); 
        approveModal.classList.remove('flex'); 
    }
    
    function openRejectModal(id) {
        document.getElementById('id_permintaan_reject').value = id;
        rejectModal.classList.remove('hidden');
        rejectModal.classList.add('flex');
    }
    function closeRejectModal() { 
        rejectModal.classList.add('hidden'); 
        rejectModal.classList.remove('flex'); 
    }

    // Bulk Delete Logic
    document.addEventListener('DOMContentLoaded', function() {
        const selectAll = document.getElementById('selectAllPermintaan');
        const btnDel = document.getElementById('btnBulkDeletePermintaan');
        const getChecks = () => Array.from(document.querySelectorAll('.row-check-permintaan'));
        
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
            if(e.target && e.target.classList.contains('row-check-permintaan')) {
                updateBtn();
            }
        });
        
        updateBtn(); // Init
    });
</script>

<style>
    @keyframes fadeInUp { 
        0% { opacity: 0; transform: translateY(15px) scale(0.98); } 
        100% { opacity: 1; transform: translateY(0) scale(1); } 
    }
    .animate-fade-in-up { animation: fadeInUp 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
</style>