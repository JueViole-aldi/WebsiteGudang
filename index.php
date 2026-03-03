<?php
// index.php
require_once 'db_connect.php';

// Jika user sudah login, arahkan ke dashboard
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    header("location: dashboard.php");
    exit;
}

$login_err = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST["username"]);
    $password = trim($_POST["password"]);

    if (empty($username) || empty($password)) {
        $login_err = "Username dan password harus diisi.";
    } else {
        $sql = "SELECT id, username, password, name, role, avatar_char FROM users WHERE username = ?";
        
        if ($stmt = $mysqli->prepare($sql)) {
            $stmt->bind_param("s", $username);
            
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                
                if ($result->num_rows == 1) {
                    $user = $result->fetch_assoc();
                    
                    // Verifikasi password
                    if (password_verify($password, $user['password'])) {
                        // Password benar, mulai session
                        $_SESSION["loggedin"] = true;
                        $_SESSION["id"] = $user['id'];
                        $_SESSION["username"] = $user['username'];
                        $_SESSION["name"] = $user['name'];
                        $_SESSION["role"] = $user['role'];
                        $_SESSION["avatar_char"] = $user['avatar_char'];
                        
                        header("location: dashboard.php");
                        exit;
                    } else {
                        // Password salah
                        $login_err = "Username atau password salah.";
                    }
                } else {
                    // Username tidak ditemukan
                    $login_err = "Username atau password salah.";
                }
            } else {
                $login_err = "Oops! Terjadi kesalahan. Silakan coba lagi.";
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Gudang Hadiah Donatur</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { 
            font-family: 'Inter', sans-serif;
            background: linear-gradient(-45deg, #f0f9ff, #f0fdf4, #ecfdf5, #f0fdfa);
            background-size: 400% 400%;
            animation: gradientBG 15s ease infinite;
        }

        @keyframes gradientBG {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        .login-container {
            animation: fadeIn 1s ease-out forwards;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .form-input:focus {
            border-color: #0d9488;
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.2);
            outline: none;
        }
        
        .submit-button {
            transition: all 0.3s ease-in-out;
        }
        .submit-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px -8px rgba(13, 148, 136, 0.5);
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="login-container w-full max-w-4xl mx-auto bg-white rounded-2xl shadow-2xl grid lg:grid-cols-2 overflow-hidden">
            <!-- Bagian Kiri (Branding) -->
            <div class="hidden lg:flex flex-col justify-center p-12 bg-gradient-to-br from-teal-500 to-cyan-600 text-white">
                <div class="flex items-center mb-4">
                    <div class="bg-white/30 p-3 rounded-full">
                        <i data-lucide="gift" class="w-8 h-8 text-white"></i>
                    </div>
                </div>
                <h1 class="text-4xl font-extrabold tracking-tight">Selamat Datang di Gudang AKSI</h1>
                <p class="mt-4 text-lg text-teal-100">Sistem Manajemen Inventaris Hadiah Apresiasi. Efisien, terintegrasi, dan mudah digunakan.</p>
            </div>
            
            <!-- Bagian Kanan (Form Login) -->
            <div class="flex flex-col justify-center p-8 md:p-12">
                <div class="lg:hidden text-center mb-8">
                    <i data-lucide="gift" class="w-12 h-12 text-teal-600 mx-auto"></i>
                </div>
                <h2 class="text-3xl font-bold text-gray-900 text-center">Login Akun</h2>
                <p class="mt-2 text-gray-600 text-center">Silakan masukkan kredensial Anda.</p>
                
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="mt-8 space-y-6">
                    <div>
                        <label for="username" class="sr-only">Username</label>
                        <div class="flex items-center">
                            <span class="inline-flex items-center px-3 py-3 border border-r-0 border-gray-300 bg-gray-50 text-gray-500 rounded-l-md">
                                <i data-lucide="user" class="w-5 h-5"></i>
                            </span>
                            <input id="username" name="username" type="text" required class="form-input flex-1 block w-full px-4 py-3 rounded-none rounded-r-lg bg-gray-50 border-gray-300" placeholder="Username">
                        </div>
                    </div>
                    
                    <div>
                        <label for="password" class="sr-only">Password</label>
                        <div class="flex items-center">
                             <span class="inline-flex items-center px-3 py-3 border border-r-0 border-gray-300 bg-gray-50 text-gray-500 rounded-l-md">
                                <i data-lucide="lock" class="w-5 h-5"></i>
                            </span>
                            <input id="password" name="password" type="password" required class="form-input flex-1 block w-full px-4 py-3 rounded-none rounded-r-lg bg-gray-50 border-gray-300" placeholder="Password">
                        </div>
                    </div>

                    <?php if(!empty($login_err)): ?>
                        <div class="bg-red-100 text-red-700 p-3 rounded-lg text-sm text-center">
                            <?php echo $login_err; ?>
                        </div>
                    <?php endif; ?>
                    
                    <div>
                        <button type="submit" class="submit-button w-full flex justify-center py-3 px-4 border border-transparent rounded-lg shadow-sm font-semibold text-white bg-gradient-to-r from-teal-500 to-cyan-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-teal-500">
                            Masuk
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        lucide.createIcons();
    </script>
</body>
</html>

