<?php
// page_dashboard.php (Versi Interaktif Baru)
defined('APP_LOADED') or die('Akses langsung ke file ini tidak diizinkan.');

// 1. DATA UNTUK STATS CARDS
$total_hadiah = $mysqli->query("SELECT COUNT(*) as total FROM hadiah WHERE is_active = 1")->fetch_assoc()['total'] ?? 0;
$stok_kritis_count = $mysqli->query("SELECT COUNT(*) as total FROM hadiah WHERE stok <= stok_minimum AND is_active = 1")->fetch_assoc()['total'] ?? 0;
$hadiah_keluar_bulan_ini = $mysqli->query("SELECT SUM(jumlah) as total FROM transaksi_hadiah WHERE tipe_transaksi = 'keluar' AND MONTH(tanggal_transaksi) = MONTH(CURDATE()) AND YEAR(tanggal_transaksi) = YEAR(CURDATE())")->fetch_assoc()['total'] ?? 0;
$hadiah_masuk_bulan_ini = $mysqli->query("SELECT SUM(jumlah) as total FROM transaksi_hadiah WHERE tipe_transaksi = 'masuk' AND MONTH(tanggal_transaksi) = MONTH(CURDATE()) AND YEAR(tanggal_transaksi) = YEAR(CURDATE())")->fetch_assoc()['total'] ?? 0;


// 2. DATA UNTUK GRAFIK
// Grafik 1: Top 5 Stok Hadiah Tertinggi
$top_stok_query = $mysqli->query("SELECT nama_hadiah, stok FROM hadiah WHERE is_active = 1 ORDER BY stok DESC LIMIT 5");
$top_stok_data = [];
if ($top_stok_query) {
    while($row = $top_stok_query->fetch_assoc()) {
        $top_stok_data[] = $row;
    }
}

// Grafik 2: Komposisi Stok per Kategori
$stok_kategori_query = $mysqli->query("
    SELECT k.nama_kategori, SUM(h.stok) as total_stok
    FROM hadiah h
    JOIN kategori k ON h.id_kategori = k.id
    WHERE h.is_active = 1
    GROUP BY k.nama_kategori
    HAVING SUM(h.stok) > 0
    ORDER BY total_stok DESC
");
$stok_kategori_data = [];
if ($stok_kategori_query) {
    while($row = $stok_kategori_query->fetch_assoc()) {
        $stok_kategori_data[] = $row;
    }
}

// Grafik 3: Tren Transaksi 6 Bulan Terakhir
$tren_transaksi_query = $mysqli->query("
    SELECT 
        DATE_FORMAT(tanggal_transaksi, '%Y-%m') as bulan,
        SUM(CASE WHEN tipe_transaksi = 'masuk' THEN jumlah ELSE 0 END) as total_masuk,
        SUM(CASE WHEN tipe_transaksi = 'keluar' THEN jumlah ELSE 0 END) as total_keluar
    FROM transaksi_hadiah
    WHERE tanggal_transaksi >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY bulan
    ORDER BY bulan ASC
");
$tren_transaksi_data = [];
if ($tren_transaksi_query) {
    while($row = $tren_transaksi_query->fetch_assoc()) {
        $tren_transaksi_data[] = $row;
    }
}

// 3. DATA UNTUK DAFTAR STOK KRITIS
$stok_kritis_list = $mysqli->query("
    SELECT nama_hadiah, stok, stok_minimum 
    FROM hadiah 
    WHERE stok <= stok_minimum AND is_active = 1
    ORDER BY (stok / stok_minimum) ASC
    LIMIT 5
");


// 4. DATA UNTUK AKTIVITAS TERBARU
$aktivitas_terbaru = $mysqli->query("
    SELECT l.aktivitas, l.timestamp, u.name, u.avatar_char
    FROM log_aktivitas l 
    JOIN users u ON l.id_user = u.id 
    ORDER BY l.timestamp DESC 
    LIMIT 5
");

// Encode data ke JSON untuk digunakan oleh JavaScript
$json_top_stok = json_encode($top_stok_data);
$json_stok_kategori = json_encode($stok_kategori_data);
$json_tren_transaksi = json_encode($tren_transaksi_data);
?>

<style>
    .chart-container, .stats-card-new, .info-card {
        transition: all 0.3s ease-in-out;
        animation: fadeInUp 0.5s ease-out forwards;
        opacity: 0;
    }
    .chart-container:hover, .stats-card-new:hover, .info-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
    }
    /* Animasi delay untuk setiap elemen */
    <?php for ($i=0; $i < 10; $i++): ?>
    .anim-delay-<?php echo $i; ?> { animation-delay: <?php echo $i * 0.08; ?>s; }
    <?php endfor; ?>

    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }
</style>

<div id="dashboard-revamp">
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-800">Selamat Datang Kembali, <?php echo htmlspecialchars(explode(' ', $_SESSION['name'])[0]); ?>!</h1>
        <p class="text-gray-600">Berikut ringkasan visual aktivitas gudang hadiah Anda.</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <div class="stats-card-new bg-white p-6 rounded-xl shadow-lg flex items-center space-x-4 anim-delay-0">
            <div class="p-4 bg-gradient-to-tr from-sky-500 to-indigo-500 rounded-full"><i data-lucide="package" class="w-8 h-8 text-white"></i></div>
            <div><p class="text-sm text-gray-500">Total Jenis Hadiah</p><p class="text-3xl font-bold text-gray-800"><?php echo $total_hadiah; ?></p></div>
        </div>
        <div class="stats-card-new bg-white p-6 rounded-xl shadow-lg flex items-center space-x-4 anim-delay-1">
             <div class="p-4 bg-gradient-to-tr from-green-500 to-teal-500 rounded-full"><i data-lucide="arrow-down-circle" class="w-8 h-8 text-white"></i></div>
            <div><p class="text-sm text-gray-500">Masuk (Bulan Ini)</p><p class="text-3xl font-bold text-gray-800"><?php echo $hadiah_masuk_bulan_ini ?? 0; ?></p></div>
        </div>
        <div class="stats-card-new bg-white p-6 rounded-xl shadow-lg flex items-center space-x-4 anim-delay-2">
            <div class="p-4 bg-gradient-to-tr from-red-500 to-orange-500 rounded-full"><i data-lucide="arrow-up-circle" class="w-8 h-8 text-white"></i></div>
            <div><p class="text-sm text-gray-500">Keluar (Bulan Ini)</p><p class="text-3xl font-bold text-gray-800"><?php echo $hadiah_keluar_bulan_ini ?? 0; ?></p></div>
        </div>
        <div class="stats-card-new bg-white p-6 rounded-xl shadow-lg flex items-center space-x-4 anim-delay-3">
            <div class="p-4 bg-gradient-to-tr from-yellow-500 to-amber-500 rounded-full"><i data-lucide="alert-triangle" class="w-8 h-8 text-white"></i></div>
            <div><p class="text-sm text-gray-500">Stok Kritis</p><p class="text-3xl font-bold text-gray-800"><?php echo $stok_kritis_count; ?></p></div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <div class="lg:col-span-2 flex flex-col gap-8">
            <div class="chart-container bg-white p-6 rounded-xl shadow-lg anim-delay-4">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Tren Transaksi (6 Bulan Terakhir)</h2>
                <div id="chart-tren-transaksi"></div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                 <div class="info-card bg-white p-6 rounded-xl shadow-lg anim-delay-5">
                    <h2 class="text-xl font-semibold text-gray-800 mb-4">Hadiah Perlu Restock</h2>
                    <ul class="space-y-3">
                         <?php if ($stok_kritis_list && $stok_kritis_list->num_rows > 0): while ($row = $stok_kritis_list->fetch_assoc()): ?>
                        <li class="flex items-center justify-between">
                            <span class="text-sm text-gray-700"><?php echo htmlspecialchars($row['nama_hadiah']); ?></span>
                            <span class="text-sm font-bold text-red-500"><?php echo $row['stok']; ?> / <?php echo $row['stok_minimum']; ?></span>
                        </li>
                         <?php endwhile; else: ?>
                        <p class="text-center py-6 text-slate-500">Stok aman!</p>
                        <?php endif; ?>
                    </ul>
                </div>
                 <div class="info-card bg-white p-6 rounded-xl shadow-lg anim-delay-6">
                    <h2 class="text-xl font-semibold text-gray-800 mb-4">Aktivitas Terbaru</h2>
                    <ul class="space-y-4">
                        <?php if ($aktivitas_terbaru && $aktivitas_terbaru->num_rows > 0): while ($row = $aktivitas_terbaru->fetch_assoc()): ?>
                        <li class="flex items-start space-x-3">
                            <div class="w-8 h-8 rounded-full bg-teal-100 text-teal-600 flex items-center justify-center font-bold flex-shrink-0 mt-1"><?php echo htmlspecialchars($row['avatar_char']); ?></div>
                            <div>
                                <p class="text-sm text-gray-700"><?php echo htmlspecialchars($row['aktivitas']); ?></p>
                                <p class="text-xs text-gray-400"><?php echo htmlspecialchars($row['name']); ?> - <?php echo date('d M, H:i', strtotime($row['timestamp'])); ?></p>
                            </div>
                        </li>
                        <?php endwhile; else: ?>
                        <p class="text-center py-6 text-slate-500">Belum ada aktivitas.</p>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>

        <div class="lg:col-span-1 flex flex-col gap-8">
            <div class="chart-container bg-white p-6 rounded-xl shadow-lg anim-delay-7 flex-1">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Stok per Kategori</h2>
                <div id="chart-stok-kategori"></div>
            </div>
            <div class="chart-container bg-white p-6 rounded-xl shadow-lg anim-delay-8 flex-1">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Top 5 Stok Hadiah</h2>
                <div id="chart-top-stok"></div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const topStokData = <?php echo $json_top_stok; ?>;
    const stokKategoriData = <?php echo $json_stok_kategori; ?>;
    const trenTransaksiData = <?php echo $json_tren_transaksi; ?>;
    
    const chartTheme = {
        colors: ['#14B8A6', '#F59E0B', '#3B82F6', '#EF4444', '#8B5CF6', '#6366F1', '#EC4899'],
        chart: { fontFamily: 'Inter, sans-serif', toolbar: { show: false } },
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: 2 },
    };

    if (document.getElementById('chart-top-stok') && topStokData.length > 0) {
        const optionsTopStok = {
            series: [{ name: 'Stok', data: topStokData.map(item => item.stok) }],
            chart: { type: 'bar', height: 200, ...chartTheme.chart },
            plotOptions: { bar: { borderRadius: 4, horizontal: true, } },
            dataLabels: { ...chartTheme.dataLabels },
            xaxis: { categories: topStokData.map(item => item.nama_hadiah.length > 15 ? item.nama_hadiah.substring(0, 15) + '...' : item.nama_hadiah) },
            colors: [chartTheme.colors[0]],
            tooltip: { y: { formatter: (val) => val + " pcs" } }
        };
        new ApexCharts(document.getElementById('chart-top-stok'), optionsTopStok).render();
    }
    
    if (document.getElementById('chart-stok-kategori') && stokKategoriData.length > 0) {
        const optionsStokKategori = {
            series: stokKategoriData.map(item => Number(item.total_stok)),
            labels: stokKategoriData.map(item => item.nama_kategori),
            chart: { type: 'donut', height: 250, ...chartTheme.chart },
            colors: chartTheme.colors,
            legend: { show: true, position: 'bottom', offsetY: 0, fontSize: '12px' },
            responsive: [{ breakpoint: 480, options: { chart: { width: 200 }, legend: { position: 'bottom' } } }]
        };
        new ApexCharts(document.getElementById('chart-stok-kategori'), optionsStokKategori).render();
    }

    if (document.getElementById('chart-tren-transaksi') && trenTransaksiData.length > 0) {
        const optionsTren = {
          series: [
            { name: 'Barang Masuk', data: trenTransaksiData.map(item => item.total_masuk) },
            { name: 'Barang Keluar', data: trenTransaksiData.map(item => item.total_keluar) }
          ],
          chart: { type: 'area', height: 280, stacked: false, ...chartTheme.chart },
          colors: [chartTheme.colors[0], chartTheme.colors[3]],
          dataLabels: { ...chartTheme.dataLabels },
          stroke: { ...chartTheme.stroke },
          xaxis: {
            type: 'category',
            categories: trenTransaksiData.map(item => new Date(item.bulan+'-01').toLocaleString('id-ID', { month: 'short' })),
          },
          tooltip: { x: { format: 'MMM yyyy' } },
          fill: {
            type: 'gradient',
            gradient: { opacityFrom: 0.6, opacityTo: 0.05, }
          },
          legend: { position: 'top', horizontalAlign: 'right' }
        };
        new ApexCharts(document.getElementById('chart-tren-transaksi'), optionsTren).render();
    }
});
</script>

