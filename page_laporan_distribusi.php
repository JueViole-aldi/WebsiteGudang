<?php
// page_laporan_distribusi.php
defined('APP_LOADED') or die('Akses langsung ke file ini tidak diizinkan.');

$is_accessible = has_access(['admin', 'manajer']);

// Ambil daftar donatur untuk filter
$donatur_list = $mysqli->query("SELECT id, nama_donatur FROM donatur ORDER BY nama_donatur ASC");

// Inisialisasi variabel
$selected_donatur_id = null;
$result = null;
$total_hadiah = 0;

// Cek apakah form filter sudah disubmit
if (isset($_GET['id_donatur']) && !empty($_GET['id_donatur'])) {
    $selected_donatur_id = (int)$_GET['id_donatur'];

    // Ambil data transaksi untuk donatur yang dipilih
    $stmt = $mysqli->prepare("
        SELECT t.tanggal_transaksi, h.nama_hadiah, t.jumlah, u.name as nama_user
        FROM transaksi_hadiah t
        JOIN hadiah h ON t.id_hadiah = h.id
        JOIN users u ON t.id_user = u.id
        WHERE t.tipe_transaksi = 'keluar' AND t.id_donatur = ?
        ORDER BY t.tanggal_transaksi DESC
    ");
    $stmt->bind_param("i", $selected_donatur_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
}

?>
<div id="laporan-distribusi">
    <!-- Header Halaman -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
        <div>
            <h1 class="text-3xl font-extrabold text-gray-800 tracking-tight">Laporan Distribusi Hadiah</h1>
            <p class="text-sm text-gray-500 mt-1">Pantau riwayat pemberian apresiasi berdasarkan spesifik donatur.</p>
        </div>
    </div>

    <?php if ($is_accessible): ?>
    
    <!-- Area Filter Pencarian -->
    <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 mb-6">
        <form method="GET" action="dashboard.php" class="flex flex-col md:flex-row md:items-end gap-4">
            <input type="hidden" name="page" value="laporan_distribusi">
            <div class="flex-grow">
                <label for="id_donatur" class="block text-sm font-bold text-gray-700 mb-2">
                    <i class="fas fa-search mr-1 text-emerald-600"></i> Pilih Donatur
                </label>
                <div class="relative">
                    <select name="id_donatur" id="id_donatur" class="w-full border-gray-300 rounded-xl px-4 py-3 bg-gray-50 hover:bg-white focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none transition-all shadow-sm appearance-none cursor-pointer font-medium text-gray-700">
                        <option value="">-- Tampilkan Semua Donatur --</option>
                        <?php while($row = $donatur_list->fetch_assoc()): ?>
                            <option value="<?php echo $row['id']; ?>" <?php echo ($selected_donatur_id == $row['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($row['nama_donatur']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-4 text-gray-500">
                        <i class="fas fa-chevron-down text-sm"></i>
                    </div>
                </div>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="px-6 py-3 bg-emerald-600 text-white font-bold rounded-xl shadow-md shadow-emerald-200 hover:bg-emerald-700 transition-all transform hover:-translate-y-0.5 flex items-center justify-center">
                    <i class="fas fa-filter mr-2"></i> Filter
                </button>
                <?php if ($selected_donatur_id): ?>
                <a href="dashboard.php?page=laporan_distribusi" class="px-4 py-3 bg-gray-100 text-gray-600 font-bold rounded-xl hover:bg-gray-200 transition-all flex items-center justify-center" title="Reset Filter">
                    <i class="fas fa-sync-alt"></i>
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Hasil Laporan -->
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <?php if ($selected_donatur_id && $result): ?>
            
            <!-- Header Tabel Hasil -->
            <div class="bg-gradient-to-r from-emerald-50 to-teal-50 px-6 py-5 border-b border-emerald-100 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <p class="text-xs font-bold text-emerald-600 uppercase tracking-wider mb-1">Menampilkan Riwayat Untuk:</p>
                    <h3 class="text-xl font-extrabold text-gray-800 flex items-center">
                        <i class="fas fa-user-circle text-emerald-500 mr-2 text-2xl"></i>
                        <?php 
                        mysqli_data_seek($donatur_list, 0);
                        while($d = $donatur_list->fetch_assoc()){
                            if($d['id'] == $selected_donatur_id) echo htmlspecialchars($d['nama_donatur']);
                        }
                        ?>
                    </h3>
                </div>
                <div class="bg-white px-4 py-2 rounded-lg border border-emerald-100 shadow-sm text-center">
                    <span class="block text-[10px] font-bold text-gray-500 uppercase">Total Transaksi</span>
                    <span class="block text-lg font-extrabold text-emerald-600"><?php echo $result->num_rows; ?> <span class="text-xs font-medium text-gray-500">kali</span></span>
                </div>
            </div>

            <!-- Tabel Data -->
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-gray-50 border-b border-gray-100">
                            <th class="py-4 px-6 text-xs font-bold text-gray-500 uppercase tracking-wider">Tanggal Diberikan</th>
                            <th class="py-4 px-6 text-xs font-bold text-gray-500 uppercase tracking-wider">Nama Hadiah</th>
                            <th class="py-4 px-6 text-xs font-bold text-gray-500 uppercase tracking-wider">Jumlah</th>
                            <th class="py-4 px-6 text-xs font-bold text-gray-500 uppercase tracking-wider">Dicatat oleh Admin</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if ($result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                            <tr class="hover:bg-emerald-50/30 transition-colors group">
                                <td class="py-4 px-6 text-sm text-gray-600 font-medium whitespace-nowrap">
                                    <div class="flex items-center">
                                        <i class="far fa-calendar-alt text-gray-400 mr-2"></i>
                                        <?php echo date('d M Y, H:i', strtotime($row['tanggal_transaksi'])); ?>
                                    </div>
                                </td>
                                <td class="py-4 px-6 font-bold text-gray-800 text-base">
                                    <?php echo htmlspecialchars($row['nama_hadiah']); ?>
                                </td>
                                <td class="py-4 px-6">
                                    <span class="inline-flex items-center px-3 py-1 bg-emerald-50 text-emerald-700 font-bold rounded-lg text-xs border border-emerald-200">
                                        <?php echo $row['jumlah']; ?> unit
                                    </span>
                                </td>
                                <td class="py-4 px-6">
                                    <div class="flex items-center">
                                        <div class="w-6 h-6 rounded-full bg-gray-200 text-gray-500 flex items-center justify-center text-[10px] mr-2 font-bold uppercase">
                                            <?php echo substr($row['nama_user'], 0, 1); ?>
                                        </div>
                                        <span class="text-sm text-gray-600 font-medium"><?php echo htmlspecialchars($row['nama_user']); ?></span>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="text-center py-16">
                                    <div class="flex flex-col items-center justify-center text-gray-400">
                                        <div class="w-16 h-16 bg-gray-50 rounded-full flex items-center justify-center mb-3">
                                            <i class="fas fa-box-open text-3xl text-gray-300"></i>
                                        </div>
                                        <p class="font-medium text-sm text-gray-500">Donatur ini belum pernah menerima hadiah.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
        <?php else: ?>
            <!-- Tampilan Empty State Awal (Belum Pilih Donatur) -->
            <div class="flex flex-col items-center justify-center py-20 px-4 text-center">
                <div class="w-24 h-24 bg-emerald-50 rounded-full flex items-center justify-center mb-5 border border-emerald-100 shadow-sm">
                    <i class="fas fa-search text-4xl text-emerald-300"></i>
                </div>
                <h3 class="text-xl font-bold text-gray-800 mb-2">Belum Ada Donatur Dipilih</h3>
                <p class="text-gray-500 max-w-md mx-auto text-sm leading-relaxed">
                    Silakan pilih nama donatur pada kolom pencarian di atas, lalu klik <b>Filter</b> untuk melihat riwayat distribusi hadiah yang telah diberikan kepada mereka.
                </p>
            </div>
        <?php endif; ?>
    </div>

    <?php else: ?>
        <div class="bg-amber-50 border-l-4 border-amber-500 text-amber-800 p-5 rounded-xl shadow-sm flex items-start" role="alert">
            <i class="fas fa-exclamation-triangle text-amber-500 mt-1 mr-3 text-lg"></i>
            <div>
                <h3 class="font-bold">Akses Dibatasi</h3>
                <p class="text-sm mt-1">Anda tidak memiliki hak akses untuk melihat halaman laporan ini. Silakan hubungi Administrator.</p>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
    /* Sedikit animasi halus untuk load data */
    @keyframes fadeIn { 
        from { opacity: 0; transform: translateY(10px); } 
        to { opacity: 1; transform: translateY(0); } 
    }
    #laporan-distribusi { animation: fadeIn 0.4s ease-out forwards; }
</style>