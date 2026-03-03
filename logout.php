<?php
// logout.php

// Inisialisasi session
session_start();

// Unset semua variabel session
$_SESSION = array();

// Hancurkan session
session_destroy();

// Redirect ke halaman login
header("location: index.php");
exit;
?>
