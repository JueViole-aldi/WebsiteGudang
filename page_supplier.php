<?php
// page_supplier.php

defined('APP_LOADED') or die('Akses langsung ke file ini tidak diizinkan.');

// Hanya Admin yang bisa mengakses halaman ini
if (!has_access(['admin'])) {
    echo "<div class='bg-red-100 p-4 rounded-md'>Anda tidak memiliki hak akses untuk halaman ini.</div>";
    return;
}

$error_message = '';
$success_message = '';

// Handle Tambah Data Supplier
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_supplier'])) {
    $user_id = (int)$_POST['user_id'];
    $nama_perusahaan = trim($_POST['nama_perusahaan']);
    $nama_kontak = trim($_POST['nama_kontak']);
    $telepon = trim($_POST['telepon']);
    $email = trim($_POST['email']);
    $alamat = trim($_POST['alamat']);

    if ($user_id > 0 && !empty($nama_perusahaan)) {
        $sql = "INSERT INTO supplier_details (user_id, nama_perusahaan, nama_kontak, telepon, email, alamat) VALUES (?, ?, ?, ?, ?, ?)";
        if ($stmt = $mysqli->prepare($sql)) {
            $stmt->bind_param("isssss", $user_id, $nama_perusahaan, $nama_kontak, $telepon, $email, $alamat);
            if ($stmt->execute()) {
                log_activity($mysqli, $_SESSION['id'], "Menambah data detail untuk supplier ID: {$user_id}");
                $success_message = "Data supplier berhasil ditambahkan.";
            } else {
                $error_message = "Gagal menambahkan data. Pastikan supplier belum memiliki data detail.";
            }
            $stmt->close();
        }
    } else {
        $error_message = "Harap pilih user supplier dan isi nama perusahaan.";
    }
}

// Handle Edit Data Supplier
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_supplier'])) {
    $id = (int)$_POST['id_supplier_detail'];
    $nama_perusahaan = trim($_POST['nama_perusahaan_edit']);
    $nama_kontak = trim($_POST['nama_kontak_edit']);
    $telepon = trim($_POST['telepon_edit']);
    $email = trim($_POST['email_edit']);
    $alamat = trim($_POST['alamat_edit']);

    if ($id > 0 && !empty($nama_perusahaan)) {
        $sql = "UPDATE supplier_details SET nama_perusahaan = ?, nama_kontak = ?, telepon = ?, email = ?, alamat = ? WHERE id = ?";
        if ($stmt = $mysqli->prepare($sql)) {
            $stmt->bind_param("sssssi", $nama_perusahaan, $nama_kontak, $telepon, $email, $alamat, $id);
            if ($stmt->execute()) {
                log_activity($mysqli, $_SESSION['id'], "Mengubah data detail supplier: {$nama_perusahaan}");
                $success_message = "Data supplier berhasil diperbarui.";
            } else {
                $error_message = "Gagal memperbarui data supplier.";
            }
            $stmt->close();
        }
    } else {
        $error_message = "Nama perusahaan tidak boleh kosong.";
    }
}

// Ambil data supplier yang sudah memiliki detail untuk ditampilkan di tabel
$result_suppliers = $mysqli->query("
    SELECT sd.*, u.name as user_name
    FROM supplier_details sd
    JOIN users u ON sd.user_id = u.id
    ORDER BY sd.nama_perusahaan ASC
");

// Ambil daftar user dengan peran 'supplier' yang BELUM punya data detail
$new_suppliers_list = $mysqli->query("
    SELECT id, name FROM users 
    WHERE role = 'supplier' AND id NOT IN (SELECT user_id FROM supplier_details)
    ORDER BY name ASC
");

?>
<div id="manajemen-supplier">
    <h1 class="text-3xl font-bold text-gray-800 mb-6">Manajemen Data Supplier</h1>

    <?php if ($success_message): ?><div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-md" role="alert"><p><?php echo $success_message; ?></p></div><?php endif; ?>
    <?php if ($error_message): ?><div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-md" role="alert"><p><?php echo $error_message; ?></p></div><?php endif; ?>

    <div class="bg-white p-6 rounded-xl shadow-md">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-semibold text-gray-800">Daftar Supplier</h2>
            <button onclick="openAddModal()" class="btn bg-teal-600 text-white px-4 py-2 rounded-md hover:bg-teal-700 flex items-center">
                <i data-lucide="plus" class="w-4 h-4 mr-2"></i>Tambah Data Supplier
            </button>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="bg-slate-50 border-b">
                        <th class="py-3 px-4">Nama Perusahaan</th>
                        <th class="py-3 px-4">Nama Kontak</th>
                        <th class="py-3 px-4">Telepon</th>
                        <th class="py-3 px-4">Email</th>
                        <th class="py-3 px-4 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result_suppliers && $result_suppliers->num_rows > 0): ?>
                        <?php while ($row = $result_suppliers->fetch_assoc()): ?>
                        <tr class="border-b hover:bg-slate-50">
                            <td class="py-3 px-4 font-medium"><?php echo htmlspecialchars($row['nama_perusahaan']); ?></td>
                            <td class="py-3 px-4"><?php echo htmlspecialchars($row['nama_kontak']); ?></td>
                            <td class="py-3 px-4"><?php echo htmlspecialchars($row['telepon']); ?></td>
                            <td class="py-3 px-4"><?php echo htmlspecialchars($row['email']); ?></td>
                            <td class="py-3 px-4 text-right">
                                <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8'); ?>)" class="text-blue-500 hover:text-blue-700"><i data-lucide="edit" class="w-4 h-4"></i></button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center py-4 text-gray-500">Belum ada data detail supplier.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="add-supplier-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-lg">
        <form method="POST" action="dashboard.php?page=supplier" class="p-6">
            <h3 class="text-xl font-semibold mb-4">Tambah Data Detail Supplier</h3>
            <div class="space-y-4">
                <div>
                    <label for="user_id" class="block text-sm font-medium">Pilih Akun Supplier</label>
                    <select name="user_id" id="user_id" required class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2">
                        <option value="">-- Pilih Akun --</option>
                        <?php if ($new_suppliers_list->num_rows > 0): while($row = $new_suppliers_list->fetch_assoc()): ?>
                        <option value="<?php echo $row['id']; ?>"><?php echo htmlspecialchars($row['name']); ?></option>
                        <?php endwhile; else: ?>
                        <option value="" disabled>Semua supplier sudah memiliki data</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div>
                    <label for="nama_perusahaan" class="block text-sm font-medium">Nama Perusahaan</label>
                    <input type="text" name="nama_perusahaan" id="nama_perusahaan" required class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2">
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="nama_kontak" class="block text-sm font-medium">Nama Kontak</label>
                        <input type="text" name="nama_kontak" id="nama_kontak" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2">
                    </div>
                    <div>
                        <label for="telepon" class="block text-sm font-medium">Telepon</label>
                        <input type="text" name="telepon" id="telepon" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2">
                    </div>
                </div>
                <div>
                    <label for="email" class="block text-sm font-medium">Email</label>
                    <input type="email" name="email" id="email" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2">
                </div>
                <div>
                    <label for="alamat" class="block text-sm font-medium">Alamat</label>
                    <textarea name="alamat" id="alamat" rows="3" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2"></textarea>
                </div>
            </div>
            <div class="mt-6 flex justify-end space-x-3">
                <button type="button" onclick="closeAddModal()" class="px-4 py-2 bg-gray-200 rounded-md">Batal</button>
                <button type="submit" name="add_supplier" class="px-4 py-2 bg-teal-600 text-white rounded-md">Simpan</button>
            </div>
        </form>
    </div>
</div>

<div id="edit-supplier-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-lg">
        <form method="POST" action="dashboard.php?page=supplier" class="p-6">
            <h3 class="text-xl font-semibold mb-4">Edit Data Supplier</h3>
            <input type="hidden" name="id_supplier_detail" id="id_supplier_detail">
            <div class="space-y-4">
                <div>
                    <label for="nama_perusahaan_edit" class="block text-sm font-medium">Nama Perusahaan</label>
                    <input type="text" name="nama_perusahaan_edit" id="nama_perusahaan_edit" required class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2">
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="nama_kontak_edit" class="block text-sm font-medium">Nama Kontak</label>
                        <input type="text" name="nama_kontak_edit" id="nama_kontak_edit" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2">
                    </div>
                    <div>
                        <label for="telepon_edit" class="block text-sm font-medium">Telepon</label>
                        <input type="text" name="telepon_edit" id="telepon_edit" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2">
                    </div>
                </div>
                <div>
                    <label for="email_edit" class="block text-sm font-medium">Email</label>
                    <input type="email" name="email_edit" id="email_edit" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2">
                </div>
                <div>
                    <label for="alamat_edit" class="block text-sm font-medium">Alamat</label>
                    <textarea name="alamat_edit" id="alamat_edit" rows="3" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2"></textarea>
                </div>
            </div>
            <div class="mt-6 flex justify-end space-x-3">
                <button type="button" onclick="closeEditModal()" class="px-4 py-2 bg-gray-200 rounded-md">Batal</button>
                <button type="submit" name="edit_supplier" class="px-4 py-2 bg-teal-600 text-white rounded-md">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<script>
    const addModal = document.getElementById('add-supplier-modal');
    const editModal = document.getElementById('edit-supplier-modal');

    function openAddModal() { addModal.classList.remove('hidden'); addModal.classList.add('flex'); }
    function closeAddModal() { addModal.classList.add('hidden'); addModal.classList.remove('flex'); }

    function openEditModal(supplier) {
        document.getElementById('id_supplier_detail').value = supplier.id;
        document.getElementById('nama_perusahaan_edit').value = supplier.nama_perusahaan;
        document.getElementById('nama_kontak_edit').value = supplier.nama_kontak;
        document.getElementById('telepon_edit').value = supplier.telepon;
        document.getElementById('email_edit').value = supplier.email;
        document.getElementById('alamat_edit').value = supplier.alamat;
        editModal.classList.remove('hidden');
        editModal.classList.add('flex');
    }
    function closeEditModal() { editModal.classList.add('hidden'); editModal.classList.remove('flex'); }
</script>