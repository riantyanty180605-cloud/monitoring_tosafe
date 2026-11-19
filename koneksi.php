<?php
$host = "localhost";
$user = "root";     // Username database default
$pass = "";         // Password database (default kosong)
$db   = "db_user";  // Nama database Anda

$koneksi = mysqli_connect($host, $user, $pass, $db);

if (!$koneksi) {
    die("Koneksi ke database gagal: " . mysqli_connect_error());
}
?>