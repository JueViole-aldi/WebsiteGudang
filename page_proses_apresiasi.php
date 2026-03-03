<?php
// page_proses_apresiasi.php

defined('APP_LOADED') or die('Akses langsung ke file ini tidak diizinkan.');

$user_id = $_SESSION['id'];
$user_role = $_SESSION['role'];
$error_message = '';
$success_message = '';

if (!has_access(['staff', 'admin'])) {
    echo "<div class='bg-red-100 p-4 rounded-md border border-red-200 text-red-700 font-medium'>Anda tidak memiliki hak akses untuk halaman ini.</div>";
    return;
}

// LOGIKA: INPUT DONASI CASH
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_cash_donation']) && has_access(['staff', 'admin'])) {
    $nama_donatur = trim($_POST['nama_donatur']);
    $kontak = trim($_POST['kontak']);
    $alamat = trim($_POST['alamat']);
    $jumlah_donasi = (float)$_POST['jumlah_donasi'];
    $id_hadiah = (int)$_POST['id_hadiah'];

    if (empty($nama_donatur) || empty($kontak) || $jumlah_donasi <= 0 || $id_hadiah <= 0) {
        $error_message = "Harap isi semua data donatur, nominal, dan pilih hadiah.";
    } else {
        $mysqli->begin_transaction();
        try {
            $stmt_cek = $mysqli->prepare("SELECT id FROM donatur WHERE kontak = ?");
            $stmt_cek->bind_param("s", $kontak);
            $stmt_cek->execute();
            $res_cek = $stmt_cek->get_result();
            if ($res_cek->num_rows > 0) {
                $id_donatur = $res_cek->fetch_assoc()['id'];
            } else {
                $stmt_ins = $mysqli->prepare("INSERT INTO donatur (nama_donatur, kontak, alamat, level_donasi) VALUES (?, ?, ?, 'Standard')");
                $stmt_ins->bind_param("sss", $nama_donatur, $kontak, $alamat);
                $stmt_ins->execute();
                $id_donatur = $stmt_ins->insert_id;
            }

            // Update stok hadiah
            $mysqli->query("UPDATE hadiah SET stok = stok - 1 WHERE id = $id_hadiah");
            
            // Simpan donasi
            $stmt_donasi = $mysqli->prepare("INSERT INTO donasi (id_donatur, jumlah_donasi, status_pembayaran, metode_pembayaran, status_hadiah, id_hadiah_diberikan, divalidasi_oleh_admin, diproses_oleh_staff) VALUES (?, ?, 'PAID', 'Cash', 'terkirim', ?, ?, ?)");
            $stmt_donasi->bind_param("idiii", $id_donatur, $jumlah_donasi, $id_hadiah, $user_id, $user_id);
            $stmt_donasi->execute();
            $id_donasi_baru = $stmt_donasi->insert_id;

            // SINKRONISASI KE LAPORAN KELUAR (CASH)
            $today_prefix = date('Ymd');
            $stmt_last_num = $mysqli->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(keterangan, '-', -1) AS UNSIGNED)) as last_num FROM transaksi_hadiah WHERE keterangan LIKE ?");
            $search_pattern = "Apresiasi Harian #{$today_prefix}-%";
            $stmt_last_num->bind_param("s", $search_pattern);
            $stmt_last_num->execute();
            $last_num_result = $stmt_last_num->get_result()->fetch_assoc();
            $next_num = ($last_num_result['last_num'] ?? 0) + 1;
            $keterangan_harian = sprintf("Apresiasi Harian #%s-%03d", $today_prefix, $next_num);
            $keterangan_final = $keterangan_harian . " (Cash - Ref. Donasi: " . $id_donasi_baru . ")";

            $stmt_trans_h = $mysqli->prepare("INSERT INTO transaksi_hadiah (id_hadiah, id_user, id_donatur, tipe_transaksi, jumlah, keterangan) VALUES (?, ?, ?, 'keluar', 1, ?)");
            $stmt_trans_h->bind_param("iiis", $id_hadiah, $user_id, $id_donatur, $keterangan_final);
            $stmt_trans_h->execute();

            $mysqli->commit();
            if (function_exists('log_activity')) { log_activity($mysqli, $user_id, "Mencatat donasi CASH Rp " . number_format($jumlah_donasi) . " dari $nama_donatur"); }
            $success_message = "Donasi Cash berhasil dicatat, hadiah diberikan, dan stok diperbarui.";
        } catch (Exception $e) {
            $mysqli->rollback();
            $error_message = "Gagal mencatat donasi: " . $e->getMessage();
        }
    }
}

// LOGIKA: VALIDASI PEMBAYARAN ONLINE (QR/TRANSFER) -> DARI PENDING KE PAID
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['validate_payment']) && has_access(['staff', 'admin'])) {
    $id_donasi = (int)$_POST['id_donasi_payment'];
    
    $sql = "UPDATE donasi SET status_pembayaran = 'PAID' WHERE id = ? AND status_pembayaran = 'PENDING'";
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param("i", $id_donasi);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            if (function_exists('log_activity')) { log_activity($mysqli, $user_id, "Memvalidasi dana masuk untuk donasi ID: {$id_donasi}"); }
            $success_message = "Dana telah dikonfirmasi masuk. Silakan lanjutkan ke proses penyiapan hadiah.";
        } else {
            $error_message = "Gagal memvalidasi pembayaran atau pembayaran sudah divalidasi sebelumnya.";
        }
    }
}


// LOGIKA: SIAPKAN HADIAH (PROSES)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['process_gift']) && has_access(['staff', 'admin'])) {
    $id_donasi = (int)$_POST['id_donasi'];
    $id_hadiah = (int)$_POST['id_hadiah'];
    if ($id_donasi > 0 && $id_hadiah > 0) {
        $sql = "UPDATE donasi SET status_hadiah = 'siap_dikirim', id_hadiah_diberikan = ?, diproses_oleh_staff = ? WHERE id = ? AND status_hadiah = 'menunggu_diproses'";
        if ($stmt = $mysqli->prepare($sql)) {
            $stmt->bind_param("iii", $id_hadiah, $user_id, $id_donasi);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                if (function_exists('log_activity')) { log_activity($mysqli, $user_id, "Menyiapkan hadiah untuk donasi ID: {$id_donasi}"); }
                $success_message = "Hadiah telah disiapkan dan siap dikirim.";
            } else {
                $error_message = "Gagal memproses hadiah.";
            }
        }
    }
}

// LOGIKA: KIRIM HADIAH (VALIDASI PENGIRIMAN)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['validate_shipment']) && has_access(['staff', 'admin'])) {
    $id_donasi = (int)$_POST['id_donasi_validate'];
    $nomor_resi = trim($_POST['nomor_resi']);
    
    $mysqli->begin_transaction();
    try {
        $data_query = $mysqli->query("SELECT id_hadiah_diberikan, id_donatur FROM donasi WHERE id = $id_donasi AND status_hadiah = 'siap_dikirim'");
        if ($data_query->num_rows == 0) throw new Exception("Data tidak valid.");
        $donasi_data = $data_query->fetch_assoc();
        $id_hadiah = $donasi_data['id_hadiah_diberikan'];
        $id_donatur = $donasi_data['id_donatur'];

        $mysqli->query("UPDATE donasi SET status_hadiah = 'terkirim', nomor_resi = '$nomor_resi', divalidasi_oleh_admin = $user_id WHERE id = $id_donasi");
        $mysqli->query("UPDATE hadiah SET stok = stok - 1 WHERE id = $id_hadiah");

        // GENERATE ID HARIAN UNTUK PENGIRIMAN ONLINE
        $today_prefix = date('Ymd');
        $stmt_last_num = $mysqli->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(keterangan, '-', -1) AS UNSIGNED)) as last_num FROM transaksi_hadiah WHERE keterangan LIKE ?");
        $search_pattern = "Apresiasi Harian #{$today_prefix}-%";
        $stmt_last_num->bind_param("s", $search_pattern);
        $stmt_last_num->execute();
        $last_num_result = $stmt_last_num->get_result()->fetch_assoc();
        $next_num = ($last_num_result['last_num'] ?? 0) + 1;
        $keterangan_harian = sprintf("Apresiasi Harian #%s-%03d", $today_prefix, $next_num);
        $keterangan_final = $keterangan_harian . " (Ref. Donasi: " . $id_donasi . ")";

        $stmt_trans_h = $mysqli->prepare("INSERT INTO transaksi_hadiah (id_hadiah, id_user, id_donatur, tipe_transaksi, jumlah, keterangan) VALUES (?, ?, ?, 'keluar', 1, ?)");
        $stmt_trans_h->bind_param("iiis", $id_hadiah, $user_id, $id_donatur, $keterangan_final);
        $stmt_trans_h->execute();

        $mysqli->commit();
        if (function_exists('log_activity')) { log_activity($mysqli, $user_id, "Mengirim hadiah untuk donasi ID: {$id_donasi}"); }
        $success_message = "Hadiah berhasil divalidasi sebagai terkirim.";
    } catch (Exception $e) {
        $mysqli->rollback();
        $error_message = "Gagal kirim: " . $e->getMessage();
    }
}

// LOGIKA: HAPUS MASSAL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete']) && has_access(['admin'])) {
    $ids = isset($_POST['selected_ids']) ? array_map('intval', $_POST['selected_ids']) : [];
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "DELETE FROM donasi WHERE id IN ($placeholders)";
        if ($stmt = $mysqli->prepare($sql)) {
            $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
            $stmt->execute();
            $success_message = $stmt->affected_rows . " data donasi berhasil dihapus.";
        }
    }
}

// Ambil data donasi (Menarik data yang PENDING maupun PAID)
$result_donasi = $mysqli->query("
    SELECT d.*, don.nama_donatur, don.alamat, h.nama_hadiah AS nama_hadiah_diberikan
    FROM donasi d
    JOIN donatur don ON d.id_donatur = don.id
    LEFT JOIN hadiah h ON d.id_hadiah_diberikan = h.id
    WHERE d.status_pembayaran IN ('PENDING', 'PAID')
    ORDER BY d.tanggal_donasi DESC
");

$hadiah_list = $mysqli->query("SELECT id, nama_hadiah, stok FROM hadiah WHERE stok > 0 AND is_active = 1 ORDER BY nama_hadiah ASC");
?>

<div id="proses-apresiasi">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
        <div>
            <h1 class="text-3xl font-extrabold text-gray-800 tracking-tight">Proses Apresiasi Donatur</h1>
            <p class="text-sm text-gray-500 mt-1 flex items-center">
                <span class="relative flex h-2 w-2 mr-2">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                </span>
                Monitoring real-time aktif (Otomatis muncul tanpa refresh)
            </p>
        </div>
        <div class="flex gap-2">
            <button onclick="openCashModal()" class="inline-flex items-center px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-bold rounded-xl transition-all shadow-lg shadow-emerald-200 transform hover:-translate-y-0.5">
                <i class="fas fa-hand-holding-usd mr-2"></i> Input Donasi Cash
            </button>
        </div>
    </div>

    <?php if ($success_message): ?>
        <div class="bg-emerald-50 border-l-4 border-emerald-500 p-4 mb-6 rounded-md shadow-sm animate-fade-in flex items-center">
            <i class="fas fa-check-circle text-emerald-500 mr-3 text-lg"></i>
            <p class="text-sm text-emerald-800 font-bold"><?php echo $success_message; ?></p>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="bg-rose-50 border-l-4 border-rose-500 p-4 mb-6 rounded-md shadow-sm animate-fade-in flex items-center">
            <i class="fas fa-exclamation-circle text-rose-500 mr-3 text-lg"></i>
            <p class="text-sm text-rose-800 font-bold"><?php echo $error_message; ?></p>
        </div>
    <?php endif; ?>

    <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="flex justify-between items-center mb-6 px-2">
            <h2 class="text-xl font-bold text-gray-800">Daftar Antrean Donasi</h2>
            <?php if (has_access(['admin'])): ?>
            <button type="submit" name="bulk_delete" form="bulkDeleteForm" id="btnBulkDelete" disabled class="px-4 py-2 bg-rose-50 text-rose-600 border border-rose-100 rounded-lg text-sm font-bold disabled:opacity-30 disabled:cursor-not-allowed transition-all hover:bg-rose-100">
                <i class="fas fa-trash-alt mr-2"></i> Hapus Terpilih
            </button>
            <?php endif; ?>
        </div>

        <form method="POST" id="bulkDeleteForm" onsubmit="return confirm('Hapus data donasi yang dipilih secara permanen?');">
            <input type="hidden" name="bulk_delete" value="1">
            <div class="overflow-x-auto rounded-xl border border-gray-100">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-gray-50 border-b border-gray-100">
                            <th class="py-4 px-4 w-10 text-center"><input type="checkbox" id="selectAll" class="rounded border-gray-300"></th>
                            <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Tanggal</th>
                            <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Donatur</th>
                            <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Nominal</th>
                            <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Metode Pembayaran</th>
                            <th class="py-4 px-4 text-xs font-bold text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="py-4 px-4 text-center text-xs font-bold text-gray-500 uppercase tracking-wider">Tindakan</th>
                        </tr>
                    </thead>
                    <tbody id="donasiTbody" class="divide-y divide-gray-100">
                        <?php if ($result_donasi && $result_donasi->num_rows > 0): ?>
                            <?php while ($row = $result_donasi->fetch_assoc()): ?>
                            <tr class="hover:bg-gray-50/50 transition-colors">
                                <td class="py-4 px-4 text-center">
                                    <input type="checkbox" name="selected_ids[]" value="<?php echo $row['id']; ?>" class="row-check rounded border-gray-300">
                                </td>
                                <td class="py-4 px-4 text-sm text-gray-600 whitespace-nowrap font-medium"><?php echo date('d/m/Y H:i', strtotime($row['tanggal_donasi'])); ?></td>
                                <td class="py-4 px-4">
                                    <div class="font-bold text-gray-800"><?php echo htmlspecialchars($row['nama_donatur']); ?></div>
                                    <div class="text-[11px] text-gray-400 truncate max-w-[150px]" title="<?php echo htmlspecialchars($row['alamat']); ?>"><?php echo htmlspecialchars($row['alamat']); ?></div>
                                </td>
                                <td class="py-4 px-4 font-bold text-teal-600">Rp <?php echo number_format($row['jumlah_donasi'], 0, ',', '.'); ?></td>
                                <td class="py-4 px-4">
                                    <span class="inline-block px-2.5 py-1 rounded text-[10px] font-bold uppercase <?php echo (strpos($row['metode_pembayaran'], 'Cash') !== false ? 'bg-emerald-100 text-emerald-700' : 'bg-indigo-50 text-indigo-700 border border-indigo-100'); ?>">
                                        <?php echo htmlspecialchars($row['metode_pembayaran']); ?>
                                    </span>
                                    <?php if (!empty($row['bukti_transfer'])): ?>
                                        <a href="<?php echo htmlspecialchars($row['bukti_transfer']); ?>" target="_blank" class="block mt-1.5 text-[11px] text-blue-600 font-semibold hover:text-blue-800 hover:underline">
                                            <i class="fas fa-paperclip mr-1"></i>Lihat Bukti
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td class="py-4 px-4">
                                    <?php
                                    if ($row['status_pembayaran'] === 'PENDING') {
                                        echo "<span class='inline-flex px-3 py-1 text-[10px] font-bold rounded-full bg-rose-50 text-rose-600 border border-rose-100'><i class='fas fa-search-dollar mr-1 mt-0.5'></i> CEK TRANSFER</span>";
                                    } else {
                                        $st = $row['status_hadiah'];
                                        $map = ['menunggu_diproses' => 'bg-blue-50 text-blue-600 border border-blue-100', 'siap_dikirim' => 'bg-amber-50 text-amber-600 border border-amber-100', 'terkirim' => 'bg-emerald-50 text-emerald-600 border border-emerald-100'];
                                        echo "<span class='inline-flex px-3 py-1 text-[10px] font-bold rounded-full ".($map[$st] ?? 'bg-gray-100')."'>".strtoupper(str_replace('_',' ',$st))."</span>";
                                        if ($st == 'terkirim' && !empty($row['nama_hadiah_diberikan'])) {
                                            echo "<p class='text-[10px] text-gray-500 mt-1 italic font-medium'>" . htmlspecialchars($row['nama_hadiah_diberikan']) . "</p>";
                                        }
                                    }
                                    ?>
                                </td>
                                <td class="py-4 px-4 text-center">
                                    <?php if ($row['status_pembayaran'] === 'PENDING'): ?>
                                        <form method="POST" onsubmit="return confirm('Apakah Anda yakin dana donasi ini sudah masuk ke rekening?');" class="m-0 p-0">
                                            <input type="hidden" name="id_donasi_payment" value="<?php echo $row['id']; ?>">
                                            <button type="submit" name="validate_payment" class="inline-flex items-center px-3 py-1.5 bg-rose-500 text-white text-[10px] font-bold rounded-lg hover:bg-rose-600 transition-all shadow-sm transform hover:scale-105" title="Konfirmasi Uang Masuk">
                                                Terima Dana
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <?php $st = $row['status_hadiah']; ?>
                                        <?php if ($st == 'menunggu_diproses'): ?>
                                            <button type="button" onclick="openProcessModal(<?php echo $row['id']; ?>)" class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-[10px] font-bold rounded-lg hover:bg-indigo-700 transition-all shadow-sm transform hover:scale-105">
                                                Proses Hadiah
                                            </button>
                                        <?php elseif ($st == 'siap_dikirim'): ?>
                                            <button type="button" onclick="openValidateModal(<?php echo $row['id']; ?>)" class="inline-flex items-center px-3 py-1.5 bg-amber-500 text-white text-[10px] font-bold rounded-lg hover:bg-amber-600 transition-all shadow-sm transform hover:scale-105">
                                                Kirim Hadiah
                                            </button>
                                        <?php else: ?>
                                            <span class="text-xs text-gray-300 font-medium">-</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr id="emptyStateRow"><td colspan="7" class="text-center py-20 text-gray-400 font-medium italic">Belum ada donasi yang masuk.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
    </div>
</div>

<!-- Modal Input Donasi Cash -->
<div id="cash-modal" class="fixed inset-0 bg-gray-900/60 z-50 hidden items-center justify-center p-4 backdrop-blur-sm transition-opacity">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden transform animate-fade-in-up border border-emerald-100">
        <div class="bg-gradient-to-r from-emerald-600 to-teal-600 px-6 py-4 flex items-center justify-between">
            <h3 class="text-xl font-bold text-white"><i class="fas fa-hand-holding-usd mr-2"></i> Input Donasi Tunai</h3>
            <button onclick="closeCashModal()" class="text-white hover:text-gray-200 transition-colors"><i class="fas fa-times text-lg"></i></button>
        </div>
        <form method="POST" class="p-6 space-y-4 bg-gray-50/50">
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1.5">Nama Donatur</label>
                <input type="text" name="nama_donatur" required class="w-full border-gray-300 rounded-xl px-4 py-3 bg-white focus:bg-emerald-50 focus:ring-2 focus:ring-emerald-500 outline-none transition-all shadow-sm">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1.5">No. Telepon</label>
                    <input type="text" name="kontak" required class="w-full border-gray-300 rounded-xl px-4 py-3 bg-white focus:bg-emerald-50 focus:ring-2 focus:ring-emerald-500 outline-none shadow-sm">
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1.5">Nominal (Rp)</label>
                    <input type="number" name="jumlah_donasi" required class="w-full border-gray-300 rounded-xl px-4 py-3 bg-white focus:bg-emerald-50 focus:ring-2 focus:ring-emerald-500 outline-none shadow-sm">
                </div>
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1.5">Alamat</label>
                <textarea name="alamat" rows="2" class="w-full border-gray-300 rounded-xl px-4 py-3 bg-white focus:bg-emerald-50 focus:ring-2 focus:ring-emerald-500 outline-none shadow-sm"></textarea>
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1.5">Hadiah Langsung</label>
                <select name="id_hadiah" required class="w-full border-gray-300 rounded-xl px-4 py-3 bg-white focus:bg-emerald-50 focus:ring-2 focus:ring-emerald-500 outline-none appearance-none cursor-pointer shadow-sm">
                    <option value="">-- Pilih Hadiah --</option>
                    <?php mysqli_data_seek($hadiah_list, 0); while($h = $hadiah_list->fetch_assoc()): ?>
                        <option value="<?php echo $h['id']; ?>"><?php echo htmlspecialchars($h['nama_hadiah']); ?> (Sisa Stok: <?php echo $h['stok']; ?>)</option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="pt-4 flex space-x-3">
                <button type="button" onclick="closeCashModal()" class="flex-1 py-3 bg-white border border-gray-300 text-gray-700 font-bold rounded-xl hover:bg-gray-50 transition-all shadow-sm">Batal</button>
                <button type="submit" name="submit_cash_donation" class="flex-1 py-3 bg-emerald-600 text-white font-bold rounded-xl shadow-md shadow-emerald-200 hover:bg-emerald-700 transition-all transform hover:-translate-y-0.5">Simpan Data</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Siapkan Hadiah -->
<div id="process-modal" class="fixed inset-0 bg-gray-900/60 z-50 hidden items-center justify-center p-4 backdrop-blur-sm transition-opacity">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden transform animate-fade-in-up border border-indigo-100">
        <div class="bg-gradient-to-r from-indigo-500 to-indigo-700 px-6 py-4 flex items-center">
            <i class="fas fa-gift text-white text-xl mr-3 opacity-90"></i>
            <h3 class="text-xl font-bold text-white">Siapkan Hadiah</h3>
        </div>
        <form method="POST" class="p-6 space-y-4 bg-gray-50/50">
            <input type="hidden" name="id_donasi" id="id_donasi_process">
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2 tracking-tight">Alokasikan Hadiah untuk Donatur</label>
                <select name="id_hadiah" required class="w-full border-gray-300 rounded-xl px-4 py-3 bg-white focus:bg-indigo-50 focus:ring-2 focus:ring-indigo-500 outline-none transition-all appearance-none cursor-pointer shadow-sm">
                    <option value="">-- Pilih Barang Tersedia --</option>
                    <?php mysqli_data_seek($hadiah_list, 0); while($h = $hadiah_list->fetch_assoc()): ?>
                        <option value="<?php echo $h['id']; ?>"><?php echo htmlspecialchars($h['nama_hadiah']); ?> (Stok: <?php echo $h['stok']; ?>)</option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="pt-4 flex space-x-3">
                <button type="button" onclick="closeProcessModal()" class="flex-1 py-3 bg-white border border-gray-300 text-gray-700 font-bold rounded-xl hover:bg-gray-50 transition-all shadow-sm">Batal</button>
                <button type="submit" name="process_gift" class="flex-1 py-3 bg-indigo-600 text-white font-bold rounded-xl shadow-md shadow-indigo-200 hover:bg-indigo-700 transition-all transform hover:-translate-y-0.5">Proses</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Konfirmasi Pengiriman -->
<div id="validate-modal" class="fixed inset-0 bg-gray-900/60 z-50 hidden items-center justify-center p-4 backdrop-blur-sm transition-opacity">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden transform animate-fade-in-up border border-amber-100">
        <div class="bg-gradient-to-r from-amber-500 to-orange-500 px-6 py-4 flex items-center">
            <i class="fas fa-shipping-fast text-white text-xl mr-3 opacity-90"></i>
            <h3 class="text-xl font-bold text-white">Konfirmasi Pengiriman</h3>
        </div>
        <form method="POST" class="p-6 space-y-4 bg-gray-50/50">
            <input type="hidden" name="id_donasi_validate" id="id_donasi_validate">
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">No. Resi / Kurir (Opsional)</label>
                <input type="text" name="nomor_resi" class="w-full border-gray-300 rounded-xl px-4 py-3 bg-white focus:bg-amber-50 focus:ring-2 focus:ring-amber-500 outline-none transition-all shadow-sm" placeholder="Contoh: JNE - 123456789">
            </div>
            <p class="text-[11px] text-amber-700 bg-amber-50 p-3 rounded-lg border border-amber-100">
                <i class="fas fa-info-circle mr-1"></i> Aksi ini otomatis akan <b>mengurangi stok hadiah</b> di gudang dan mencatat di laporan.
            </p>
            <div class="pt-2 flex space-x-3">
                <button type="button" onclick="closeValidateModal()" class="flex-1 py-3 bg-white border border-gray-300 text-gray-700 font-bold rounded-xl hover:bg-gray-50 transition-all shadow-sm">Batal</button>
                <button type="submit" name="validate_shipment" class="flex-1 py-3 bg-amber-600 text-white font-bold rounded-xl shadow-md shadow-amber-200 hover:bg-amber-700 transition-all transform hover:-translate-y-0.5">Konfirmasi Kirim</button>
            </div>
        </form>
    </div>
</div>

<script>
(function(){
    // CONFIG REALTIME MENGGUNAKAN FILE ajax/donasi_updates_qupro.php
    // Ganti path ini jika file ajax Anda ada di folder yang berbeda
    const UPDATES_URL = 'ajax/donasi_updates_qupro.php'; 
    const POLL_INTERVAL = 4000; 
    const tbody = document.getElementById('donasiTbody');

    let lastSeenId = 0;
    function updateLastSeenId() {
        document.querySelectorAll('.row-check').forEach(el => {
            let id = parseInt(el.value);
            if(id > lastSeenId) lastSeenId = id;
        });
    }
    updateLastSeenId();

    function createRowHtml(r) {
        const methodClass = r.metode_pembayaran.includes('Cash') ? 'bg-emerald-100 text-emerald-700' : 'bg-indigo-50 text-indigo-700 border border-indigo-100';
        
        // Link Bukti Transfer Jika Ada
        let buktiHtml = '';
        if (r.bukti_transfer) {
            buktiHtml = `<a href="${r.bukti_transfer}" target="_blank" class="block mt-1.5 text-[11px] text-blue-600 font-semibold hover:text-blue-800 hover:underline"><i class="fas fa-paperclip mr-1"></i>Lihat Bukti</a>`;
        }

        let statusHtml = '';
        let aksiBtn = '<span class="text-xs text-gray-300 font-medium">-</span>';

        if (r.status_pembayaran === 'PENDING') {
            statusHtml = `<span class='inline-flex px-3 py-1 text-[10px] font-bold rounded-full bg-rose-50 text-rose-600 border border-rose-100'><i class='fas fa-search-dollar mr-1 mt-0.5'></i> CEK TRANSFER</span>`;
            aksiBtn = `
                <form method="POST" onsubmit="return confirm('Apakah Anda yakin dana donasi ini sudah masuk ke rekening?');" class="m-0 p-0">
                    <input type="hidden" name="id_donasi_payment" value="${r.id}">
                    <button type="submit" name="validate_payment" class="inline-flex items-center px-3 py-1.5 bg-rose-500 text-white text-[10px] font-bold rounded-lg hover:bg-rose-600 transition-all shadow-sm transform hover:scale-105" title="Konfirmasi Uang Masuk">
                        Terima Dana
                    </button>
                </form>
            `;
        } else {
            const statusMap = {'menunggu_diproses': 'bg-blue-50 text-blue-600 border border-blue-100', 'siap_dikirim': 'bg-amber-50 text-amber-600 border border-amber-100', 'terkirim': 'bg-emerald-50 text-emerald-600 border border-emerald-100'};
            const statusClass = statusMap[r.status_hadiah] || 'bg-gray-100';
            const statusLabel = r.status_hadiah.replace(/_/g, ' ').toUpperCase();
            
            statusHtml = `<span class="inline-flex px-3 py-1 text-[10px] font-bold rounded-full ${statusClass}">${statusLabel}</span>`;
            if (r.status_hadiah === 'terkirim' && r.nama_hadiah_diberikan) {
                statusHtml += `<p class='text-[10px] text-gray-500 mt-1 italic font-medium'>${r.nama_hadiah_diberikan}</p>`;
            }

            if (r.status_hadiah === 'menunggu_diproses') {
                aksiBtn = `<button type="button" onclick="openProcessModal(${r.id})" class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-[10px] font-bold rounded-lg hover:bg-indigo-700 transition-all shadow-sm transform hover:scale-105">Proses Hadiah</button>`;
            } else if (r.status_hadiah === 'siap_dikirim') {
                aksiBtn = `<button type="button" onclick="openValidateModal(${r.id})" class="inline-flex items-center px-3 py-1.5 bg-amber-500 text-white text-[10px] font-bold rounded-lg hover:bg-amber-600 transition-all shadow-sm transform hover:scale-105">Kirim Hadiah</button>`;
            }
        }

        return `
            <tr class="hover:bg-gray-50/50 transition-colors animate-new-row bg-yellow-50">
                <td class="py-4 px-4 text-center">
                    <input type="checkbox" name="selected_ids[]" value="${r.id}" class="row-check rounded border-gray-300">
                </td>
                <td class="py-4 px-4 text-sm text-gray-600 whitespace-nowrap font-medium">${r.tanggal_fmt}</td>
                <td class="py-4 px-4">
                    <div class="font-bold text-gray-800">${r.nama_donatur}</div>
                    <div class="text-[11px] text-gray-400 truncate max-w-[150px]">${r.alamat}</div>
                </td>
                <td class="py-4 px-4 font-bold text-teal-600">Rp ${r.jumlah_fmt}</td>
                <td class="py-4 px-4">
                    <span class="inline-block px-2.5 py-1 rounded text-[10px] font-bold uppercase ${methodClass}">${r.metode_pembayaran}</span>
                    ${buktiHtml}
                </td>
                <td class="py-4 px-4">
                    ${statusHtml}
                </td>
                <td class="py-4 px-4 text-center">${aksiBtn}</td>
            </tr>`;
    }

    async function checkNewDonations() {
        try {
            // Memanggil file ajax terpisah dengan parameter ?after=
            const response = await fetch(`${UPDATES_URL}?after=${lastSeenId}`);
            if (!response.ok) return;
            const data = await response.json();
            
            if (data.rows && data.rows.length > 0) {
                // Hapus tulisan "Belum ada donasi" jika ada data baru
                const emptyState = document.getElementById('emptyStateRow');
                if(emptyState) emptyState.remove();

                data.rows.forEach(row => {
                    tbody.insertAdjacentHTML('afterbegin', createRowHtml(row));
                });
                
                lastSeenId = data.max_id;
                
                // Hilangkan efek warna kuning penanda baris baru setelah 5 detik
                setTimeout(() => {
                    document.querySelectorAll('.bg-yellow-50').forEach(el => el.classList.remove('bg-yellow-50'));
                }, 5000);
                
                // Update status tombol hapus massal
                updateDelBtn();
            }
        } catch (e) { 
            console.error("Realtime error (kemungkinan session/koneksi terputus atau URL salah):", e); 
        }
    }

    // Jalankan pengecekan setiap 4 detik
    setInterval(checkNewDonations, POLL_INTERVAL);

    // Modal Control Helpers
    window.openCashModal = () => { document.getElementById('cash-modal').classList.remove('hidden'); document.getElementById('cash-modal').classList.add('flex'); };
    window.closeCashModal = () => { document.getElementById('cash-modal').classList.add('hidden'); document.getElementById('cash-modal').classList.remove('flex'); };
    
    window.openProcessModal = (id) => {
        document.getElementById('id_donasi_process').value = id;
        document.getElementById('process-modal').classList.remove('hidden');
        document.getElementById('process-modal').classList.add('flex');
    };
    window.closeProcessModal = () => {
        document.getElementById('process-modal').classList.add('hidden');
        document.getElementById('process-modal').classList.remove('flex');
    };

    window.openValidateModal = (id) => {
        document.getElementById('id_donasi_validate').value = id;
        document.getElementById('validate-modal').classList.remove('hidden');
        document.getElementById('validate-modal').classList.add('flex');
    };
    window.closeValidateModal = () => {
        document.getElementById('validate-modal').classList.add('hidden');
        document.getElementById('validate-modal').classList.remove('flex');
    };

    // Bulk Delete helper
    const selectAll = document.getElementById('selectAll');
    const btnDel = document.getElementById('btnBulkDelete');
    if(selectAll){
        selectAll.addEventListener('change', () => {
            document.querySelectorAll('.row-check').forEach(c => c.checked = selectAll.checked);
            updateDelBtn();
        });
    }
    document.addEventListener('change', (e) => {
        if(e.target.classList.contains('row-check')) updateDelBtn();
    });
    function updateDelBtn() {
        const sel = document.querySelectorAll('.row-check:checked').length;
        if(btnDel) btnDel.disabled = sel === 0;
    }
})();
</script>

<style>
@keyframes newRowFade { 
    0% { background-color: #fef3c7; transform: translateX(-10px); } 
    100% { background-color: transparent; transform: translateX(0); } 
}
.animate-new-row { animation: newRowFade 3s ease-out; }
@keyframes fadeInUp { 
    0% { opacity: 0; transform: translateY(15px) scale(0.98); } 
    100% { opacity: 1; transform: translateY(0) scale(1); } 
}
.animate-fade-in-up { animation: fadeInUp 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
</style>