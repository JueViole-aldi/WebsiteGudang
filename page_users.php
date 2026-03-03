<?php
// page_users.php

// Pastikan hanya admin yang bisa mengakses halaman ini
if (!has_access(['admin'])) {
    echo "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-md' role='alert'><p>Anda tidak memiliki hak akses untuk mengakses halaman ini.</p></div>";
    return; // Hentikan eksekusi skrip jika bukan admin
}

$error_message = '';
$success_message = '';

// Handle Tambah User
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_user'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $role = $_POST['role'];
    $name = trim($_POST['name']); // Tambahkan input untuk nama lengkap
    $avatar_char = strtoupper(substr($name, 0, 1)); // Ambil karakter pertama dari nama

    if (empty($username) || empty($password) || empty($role) || empty($name)) {
        $error_message = "Semua field harus diisi.";
    } else {
        // Cek apakah username sudah ada
        $check_sql = "SELECT id FROM users WHERE username = ?";
        if($check_stmt = $mysqli->prepare($check_sql)) {
            $check_stmt->bind_param("s", $username);
            $check_stmt->execute();
            $check_stmt->store_result();
            if($check_stmt->num_rows > 0){
                $error_message = "Username '{$username}' sudah digunakan.";
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $sql = "INSERT INTO users (username, password, name, role, avatar_char) VALUES (?, ?, ?, ?, ?)";
                if ($stmt = $mysqli->prepare($sql)) {
                    $stmt->bind_param("sssss", $username, $hashed_password, $name, $role, $avatar_char);
                    if ($stmt->execute()) {
                        log_activity($mysqli, $_SESSION['id'], "Menambah user baru: {$username} ({$role})");
                        $success_message = "User '{$username}' berhasil ditambahkan.";
                    } else {
                        $error_message = "Gagal menambahkan user.";
                    }
                    $stmt->close();
                }
            }
            $check_stmt->close();
        }
    }
}


// Handle Hapus User
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_user'])) {
    $id_user = $_POST['id_user'];
    if ($id_user == $_SESSION['id']) {
        $error_message = "Anda tidak bisa menghapus akun Anda sendiri.";
    } else {
        $user_query = $mysqli->query("SELECT username FROM users WHERE id = " . intval($id_user));
        $username = $user_query->fetch_assoc()['username'] ?? 'N/A';
        
        $sql = "DELETE FROM users WHERE id = ?";
        if ($stmt = $mysqli->prepare($sql)) {
            $stmt->bind_param("i", $id_user);
            if ($stmt->execute()) {
                log_activity($mysqli, $_SESSION['id'], "Menghapus user: {$username}");
                $success_message = "User '{$username}' berhasil dihapus.";
            } else {
                $error_message = "Gagal menghapus user.";
            }
            $stmt->close();
        }
    }
}

// Handle Edit User
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_user'])) {
    $id_user = $_POST['id_user_edit'];
    $username = trim($_POST['username_edit']);
    $name = trim($_POST['name_edit']); // Tambahkan edit nama
    $role = $_POST['role_edit'];
    $password = $_POST['password_edit'];
    $avatar_char = strtoupper(substr($name, 0, 1)); // Update avatar char

    if(empty($username) || empty($role) || empty($name)) {
        $error_message = "Username, Nama Lengkap, dan Role tidak boleh kosong.";
    } else {
        if (!empty($password)) {
            // Jika password diisi, update password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $sql = "UPDATE users SET username = ?, name = ?, role = ?, password = ?, avatar_char = ? WHERE id = ?";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("sssssi", $username, $name, $role, $hashed_password, $avatar_char, $id_user);
        } else {
            // Jika password kosong, jangan update password
            $sql = "UPDATE users SET username = ?, name = ?, role = ?, avatar_char = ? WHERE id = ?";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("ssssi", $username, $name, $role, $avatar_char, $id_user);
        }

        if ($stmt->execute()) {
            log_activity($mysqli, $_SESSION['id'], "Mengubah data user: {$username}");
            $success_message = "Data user '{$username}' berhasil diperbarui.";
        } else {
            $error_message = "Gagal memperbarui data user. Mungkin username sudah ada.";
        }
        $stmt->close();
    }
}


// Ambil semua data user untuk ditampilkan
$result = $mysqli->query("SELECT id, username, name, role FROM users ORDER BY username ASC");
?>

<div id="manajemen-user">
    <h1 class="text-3xl font-bold text-gray-800 mb-6">Manajemen User</h1>

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

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        
        <div class="lg:col-span-1">
            <div class="bg-white p-6 rounded-xl shadow-md">
                <h2 class="text-xl font-semibold mb-4 text-gray-800">Tambah User Baru</h2>
                <form method="POST" action="dashboard.php?page=users" class="space-y-4">
                    <div>
                        <label for="username" class="block text-sm font-medium text-gray-700">Username</label>
                        <input type="text" name="username" id="username" required class="mt-1 block w-full px-3 py-2 border border-slate-300 rounded-md">
                    </div>
                     <div>
                        <label for="name" class="block text-sm font-medium text-gray-700">Nama Lengkap</label>
                        <input type="text" name="name" id="name" required class="mt-1 block w-full px-3 py-2 border border-slate-300 rounded-md">
                    </div>
                     <div>
                        <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                        <input type="password" name="password" id="password" required class="mt-1 block w-full px-3 py-2 border border-slate-300 rounded-md">
                    </div>
                    <div>
                        <label for="role" class="block text-sm font-medium text-gray-700">Role</label>
                        <select name="role" id="role" required class="mt-1 block w-full px-3 py-2 border border-slate-300 rounded-md">
                            <option value="admin">Admin Gudang</option>
                            <option value="staff">Staff Gudang</option>
                            <option value="manajer">Manajer Donasi</option>
                            <option value="supplier">Supplier</option> </select>
                    </div>
                    <button type="submit" name="add_user" class="btn w-full bg-teal-600 text-white px-4 py-2 rounded-md hover:bg-teal-700">
                        Tambah User
                    </button>
                </form>
            </div>
        </div>

        <div class="lg:col-span-2">
            <div class="bg-white p-6 rounded-xl shadow-md">
                <h2 class="text-xl font-semibold mb-4 text-gray-800">Daftar Pengguna</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="border-b-2">
                                <th class="py-3 px-2">Username</th>
                                <th class="py-3 px-2">Nama Lengkap</th>
                                <th class="py-3 px-2">Role</th>
                                <th class="py-3 px-2 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td class="py-3 px-2 font-medium"><?php echo htmlspecialchars($row['username']); ?></td>
                                    <td class="py-3 px-2"><?php echo htmlspecialchars($row['name']); ?></td>
                                    <td class="py-3 px-2 capitalize"><?php echo htmlspecialchars($row['role']); ?></td>
                                    <td class="py-3 px-2 text-right">
                                        <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8'); ?>)" class="text-blue-500 hover:text-blue-700 mr-2"><i data-lucide="edit" class="w-4 h-4"></i></button>
                                        <form method="POST" action="dashboard.php?page=users" onsubmit="return confirm('Apakah Anda yakin ingin menghapus user ini?');" class="inline-block">
                                            <input type="hidden" name="id_user" value="<?php echo $row['id']; ?>">
                                            <button type="submit" name="delete_user" class="text-red-500 hover:text-red-700" <?php echo ($row['id'] == $_SESSION['id']) ? 'disabled' : ''; ?> ><i data-lucide="trash-2" class="w-4 h-4"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="py-4 text-center">Belum ada data user.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="edit-user-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="p-6">
            <h3 class="text-xl font-semibold mb-4">Edit User</h3>
            <form method="POST" action="dashboard.php?page=users" class="space-y-4">
                <input type="hidden" name="id_user_edit" id="id_user_edit">
                <div>
                    <label for="username_edit" class="block text-sm font-medium">Username</label>
                    <input type="text" name="username_edit" id="username_edit" required class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2">
                </div>
                 <div>
                    <label for="name_edit" class="block text-sm font-medium">Nama Lengkap</label>
                    <input type="text" name="name_edit" id="name_edit" required class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2">
                </div>
                <div>
                    <label for="password_edit" class="block text-sm font-medium">Password Baru (opsional)</label>
                    <input type="password" name="password_edit" id="password_edit" class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2" placeholder="Kosongkan jika tidak diubah">
                </div>
                 <div>
                    <label for="role_edit" class="block text-sm font-medium">Role</label>
                    <select name="role_edit" id="role_edit" required class="mt-1 w-full border border-slate-300 rounded-md px-3 py-2">
                        <option value="admin">Admin Gudang</option>
                        <option value="staff">Staff Gudang</option>
                        <option value="manajer">Manajer Donasi</option>
                        <option value="supplier">Supplier</option> </select>
                </div>
                <div class="mt-6 flex justify-end space-x-3">
                    <button type="button" onclick="closeEditModal()" class="px-4 py-2 bg-gray-200 rounded-md">Batal</button>
                    <button type="submit" name="edit_user" class="px-4 py-2 bg-teal-600 text-white rounded-md">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    const editModal = document.getElementById('edit-user-modal');
    
    function openEditModal(user) {
        document.getElementById('id_user_edit').value = user.id;
        document.getElementById('username_edit').value = user.username;
        document.getElementById('name_edit').value = user.name;
        document.getElementById('role_edit').value = user.role;
        document.getElementById('password_edit').value = '';
        editModal.classList.remove('hidden');
        editModal.classList.add('flex');
    }

    function closeEditModal() {
        editModal.classList.add('hidden');
        editModal.classList.remove('flex');
    }
</script>