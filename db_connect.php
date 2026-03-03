<?php
// db_connect.php

// Memulai session di paling atas untuk memastikan konsistensi
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Konfigurasi koneksi database
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');
define('DB_NAME', 'gudang_qupro');

// Membuat koneksi ke database
$mysqli = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Memeriksa koneksi
if ($mysqli === false) {
    // Tampilkan pesan error yang lebih detail jika koneksi gagal
    die("ERROR: Tidak dapat terhubung ke database. " . $mysqli->connect_error);
}
?>
