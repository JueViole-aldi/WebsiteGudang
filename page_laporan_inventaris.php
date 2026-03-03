<?php
// page_laporan_inventaris.php

defined('APP_LOADED') or die('Akses langsung ke file ini tidak diizinkan.');

if (!has_access(['admin', 'manajer'])) {
    echo "<div class='bg-red-100 p-4 rounded-md'>Anda tidak memiliki hak akses untuk halaman ini.</div>";
    return;
}

// Inisialisasi tanggal default (bulan ini)
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

$laporan_data = [];

// Query untuk mengambil semua hadiah
$hadiah_list_query = "SELECT id, nama_hadiah, stok as stok_sekarang, stok_minimum FROM hadiah ORDER BY nama_hadiah ASC";
$hadiah_list_result = $mysqli->query($hadiah_list_query);

if ($hadiah_list_result) {
    while ($hadiah = $hadiah_list_result->fetch_assoc()) {
        $id_hadiah = $hadiah['id'];

        // 1. Hitung total masuk dalam rentang tanggal
        $stmt_masuk = $mysqli->prepare("SELECT SUM(jumlah) as total FROM transaksi_hadiah WHERE id_hadiah = ? AND tipe_transaksi = 'masuk' AND DATE(tanggal_transaksi) <= ?");
        $stmt_masuk->bind_param("is", $id_hadiah, $end_date);
        $stmt_masuk->execute();
        $total_masuk_all_time = $stmt_masuk->get_result()->fetch_assoc()['total'] ?? 0;

        // 2. Hitung total keluar dalam rentang tanggal
        $stmt_keluar = $mysqli->prepare("SELECT SUM(jumlah) as total FROM transaksi_hadiah WHERE id_hadiah = ? AND tipe_transaksi = 'keluar' AND DATE(tanggal_transaksi) <= ?");
        $stmt_keluar->bind_param("is", $id_hadiah, $end_date);
        $stmt_keluar->execute();
        $total_keluar_all_time = $stmt_keluar->get_result()->fetch_assoc()['total'] ?? 0;
        
        // 3. Hitung Stok Awal pada start_date
        $stmt_masuk_sebelum = $mysqli->prepare("SELECT SUM(jumlah) as total FROM transaksi_hadiah WHERE id_hadiah = ? AND tipe_transaksi = 'masuk' AND DATE(tanggal_transaksi) < ?");
        $stmt_masuk_sebelum->bind_param("is", $id_hadiah, $start_date);
        $stmt_masuk_sebelum->execute();
        $masuk_sebelum = $stmt_masuk_sebelum->get_result()->fetch_assoc()['total'] ?? 0;

        $stmt_keluar_sebelum = $mysqli->prepare("SELECT SUM(jumlah) as total FROM transaksi_hadiah WHERE id_hadiah = ? AND tipe_transaksi = 'keluar' AND DATE(tanggal_transaksi) < ?");
        $stmt_keluar_sebelum->bind_param("is", $id_hadiah, $start_date);
        $stmt_keluar_sebelum->execute();
        $keluar_sebelum = $stmt_keluar_sebelum->get_result()->fetch_assoc()['total'] ?? 0;

        $stok_awal = $masuk_sebelum - $keluar_sebelum;

        // 4. Hitung total masuk dan keluar HANYA dalam rentang tanggal yang dipilih
        $stmt_masuk_periode = $mysqli->prepare("SELECT SUM(jumlah) as total FROM transaksi_hadiah WHERE id_hadiah = ? AND tipe_transaksi = 'masuk' AND DATE(tanggal_transaksi) BETWEEN ? AND ?");
        $stmt_masuk_periode->bind_param("iss", $id_hadiah, $start_date, $end_date);
        $stmt_masuk_periode->execute();
        $masuk_periode = $stmt_masuk_periode->get_result()->fetch_assoc()['total'] ?? 0;
        
        $stmt_keluar_periode = $mysqli->prepare("SELECT SUM(jumlah) as total FROM transaksi_hadiah WHERE id_hadiah = ? AND tipe_transaksi = 'keluar' AND DATE(tanggal_transaksi) BETWEEN ? AND ?");
        $stmt_keluar_periode->bind_param("iss", $id_hadiah, $start_date, $end_date);
        $stmt_keluar_periode->execute();
        $keluar_periode = $stmt_keluar_periode->get_result()->fetch_assoc()['total'] ?? 0;
        
        // 5. Hitung Stok Akhir
        $stok_akhir = $stok_awal + $masuk_periode - $keluar_periode;

        // 6. Tentukan Status
        $status = 'Aman';
        if ($stok_akhir <= $hadiah['stok_minimum'] && $stok_akhir > 0) {
            $status = 'Kritis';
        } elseif ($stok_akhir <= 0) {
            $status = 'Habis';
        }

        $laporan_data[] = [
            'nama_hadiah' => $hadiah['nama_hadiah'],
            'stok_awal' => $stok_awal,
            'masuk' => $masuk_periode,
            'keluar' => $keluar_periode,
            'stok_akhir' => $stok_akhir,
            'stok_sekarang' => $hadiah['stok_sekarang'],
            'status' => $status
        ];
    }
}
?>

<div id="laporan-inventaris">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Laporan Inventaris Hadiah</h1>
        <a href="export_handler.php?report=laporan_inventaris&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" target="_blank" class="btn bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 flex items-center">
            <i data-lucide="file-spreadsheet" class="w-4 h-4 mr-2"></i>Export ke Excel
        </a>
    </div>

    <div class="bg-white p-6 rounded-xl shadow-md">
        <h2 class="text-xl font-semibold text-gray-800 mb-4">Filter Laporan</h2>
        <form method="GET" action="dashboard.php" class="mb-6 border-b pb-6">
            <input type="hidden" name="page" value="laporan_inventaris">
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
                    <a href="dashboard.php?page=laporan_inventaris" class="btn w-full text-center bg-gray-200 px-4 py-2 rounded-md hover:bg-gray-300">Reset</a>
                </div>
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="bg-slate-50 border-b">
                        <th class="py-3 px-4">Nama Hadiah</th>
                        <th class="py-3 px-4 text-center">Stok Awal</th>
                        <th class="py-3 px-4 text-center">Masuk</th>
                        <th class="py-3 px-4 text-center">Keluar</th>
                        <th class="py-3 px-4 text-center">Stok Akhir</th>
                        <th class="py-3 px-4 text-center">Stok Fisik Saat Ini</th>
                        <th class="py-3 px-4 text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($laporan_data)): ?>
                        <?php foreach ($laporan_data as $data): ?>
                        <tr class="border-b hover:bg-slate-50">
                            <td class="py-3 px-4 font-medium"><?php echo htmlspecialchars($data['nama_hadiah']); ?></td>
                            <td class="py-3 px-4 text-center"><?php echo $data['stok_awal']; ?></td>
                            <td class="py-3 px-4 text-center text-green-600 font-semibold"><?php echo $data['masuk']; ?></td>
                            <td class="py-3 px-4 text-center text-red-600 font-semibold"><?php echo $data['keluar']; ?></td>
                            <td class="py-3 px-4 text-center font-bold"><?php echo $data['stok_akhir']; ?></td>
                            <td class="py-3 px-4 text-center font-bold text-blue-600"><?php echo $data['stok_sekarang']; ?></td>
                            <td class="py-3 px-4 text-center">
                                <?php
                                $status = $data['status'];
                                $badge_color = 'bg-green-100 text-green-700';
                                if ($status == 'Kritis') $badge_color = 'bg-yellow-100 text-yellow-700';
                                if ($status == 'Habis') $badge_color = 'bg-red-100 text-red-700';
                                echo "<span class='px-3 py-1 text-xs font-semibold rounded-full $badge_color'>" . $status . "</span>";
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="text-center py-4 text-gray-500">Tidak ada data untuk ditampilkan.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

