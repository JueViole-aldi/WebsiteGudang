<?php
// db_connect_public.php
// Versi koneksi database tanpa memulai session, untuk halaman publik.

// Koneksi ke database
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'gudang_qupro';

$mysqli = new mysqli($host, $username, $password, $database);

// Cek koneksi
if ($mysqli->connect_error) {
    // Mengirim respons error dalam format JSON agar bisa ditangkap oleh JavaScript
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => 'Koneksi database gagal: ' . $mysqli->connect_error]);
    exit;
}
