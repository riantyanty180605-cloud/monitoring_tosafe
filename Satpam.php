<?php
session_start();

// Validasi sesi login
if (!isset($_SESSION["login"]) || $_SESSION["login"] !== true) {
    header("Location: login.php");
    exit;
}

$role = $_SESSION["role"] ?? "satpam";
$username = $_SESSION["username"] ?? "Petugas Satpam";

// --- KONEKSI DATABASE MYSQL ---
$host = "localhost";
$user = "root";
$pass = "";
$dbname = "db_secops";

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    die(json_encode(['status' => 'error', 'message' => 'Koneksi database gagal: ' . $conn->connect_error]));
}

// --- HANDLER SINKRONISASI DATA (GET DATA) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_data') {
    header('Content-Type: application/json');

    $response = [
        'portalLogs' => [],
        'tamuLogs' => [],
        'pengaduanList' => [],
        'jadwalList' => [],
        'petugasList' => [],
        'suratMasukList' => [],
        'laporanGedung' => []
    ];

    // 1. Fetch portal_logs
    $res = $conn->query("SELECT id, jam, arah, plat, driver, pemeriksaan, status FROM portal_logs ORDER BY id DESC");
    while ($row = $res->fetch_assoc()) { $response['portalLogs'][] = $row; }

    // 2. Fetch tamu_logs
    $res = $conn->query("SELECT id, jam, nama, instansi, tujuan, identitas FROM tamu_logs ORDER BY id DESC");
    while ($row = $res->fetch_assoc()) { $response['tamuLogs'][] = $row; }

    /// 3. Fetch pengaduan_list
    $res = $conn->query("SELECT id, kode, waktu, pelapor, lokasi, kategori, urgensi, deskripsi, status, catatan, foto FROM pengaduan_list ORDER BY id DESC");
    while ($row = $res->fetch_assoc()) { $response['pengaduanList'][] = $row; }

    // 4. Fetch jadwal_list
    $res = $conn->query("SELECT id, hari, shift, pos, petugas FROM jadwal_list ORDER BY id DESC");
    while ($row = $res->fetch_assoc()) { $response['jadwalList'][] = $row; }

    // 5. Fetch petugas
    $res = $conn->query("SELECT id, nama, username, role, status FROM petugas ORDER BY id DESC");
    while ($row = $res->fetch_assoc()) { $response['petugasList'][] = $row; }

    // 6. Fetch surat_masuk_list
    $res = $conn->query("SELECT id, tgl_surat_masuk AS tglSuratMasuk, tgl_surat AS tglSurat, no_surat AS noSurat, perihal, instansi, file_data AS fileData, file_name AS fileName FROM surat_masuk_list ORDER BY id DESC");
    while ($row = $res->fetch_assoc()) { $response['suratMasukList'][] = $row; }

    // 7. Fetch laporan_gedung & detail
    $res = $conn->query("SELECT id, waktu, gedung, petugas FROM laporan_gedung ORDER BY id DESC");
    while ($row = $res->fetch_assoc()) {
        $laporan_id = $row['id'];
        $detailRes = $conn->query("SELECT nama_area AS namaArea, keterangan, foto FROM laporan_gedung_detail WHERE laporan_id = $laporan_id");
        $details = [];
        while ($dRow = $detailRes->fetch_assoc()) { $details[] = $dRow; }
        $row['detail'] = $details;
        $response['laporanGedung'][] = $row;
    }

    echo json_encode($response);
    exit;
}

// --- HANDLER SIMPAN DATA (SAVE DATA) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_data') {
    header('Content-Type: application/json');
    $inputRaw = file_get_contents('php://input');
    $inputData = json_decode($inputRaw, true);

    if (!is_array($inputData)) {
        echo json_encode(['status' => 'error', 'message' => 'Data tidak valid']);
        exit;
    }

    $conn->begin_transaction();
    try {
        // 1. Simpan Portal Logs
        if (isset($inputData['portalLogs'])) {
            $conn->query("TRUNCATE TABLE portal_logs");
            $stmt = $conn->prepare("INSERT INTO portal_logs (jam, arah, plat, driver, pemeriksaan, status) VALUES (?, ?, ?, ?, ?, ?)");
            foreach (array_reverse($inputData['portalLogs']) as $item) {
                $stmt->bind_param("ssssss", $item['jam'], $item['arah'], $item['plat'], $item['driver'], $item['pemeriksaan'], $item['status']);
                $stmt->execute();
            }
        }

        // 2. Simpan Tamu Logs
        if (isset($inputData['tamuLogs'])) {
            $conn->query("TRUNCATE TABLE tamu_logs");
            $stmt = $conn->prepare("INSERT INTO tamu_logs (jam, nama, instansi, tujuan, identitas) VALUES (?, ?, ?, ?, ?)");
            foreach (array_reverse($inputData['tamuLogs']) as $item) {
                $stmt->bind_param("sssss", $item['jam'], $item['nama'], $item['instansi'], $item['tujuan'], $item['identitas']);
                $stmt->execute();
            }
        }

       // 3. Simpan Pengaduan List
        if (isset($inputData['pengaduanList'])) {
            $conn->query("TRUNCATE TABLE pengaduan_list");
            $stmt = $conn->prepare("INSERT INTO pengaduan_list (kode, waktu, pelapor, lokasi, kategori, urgensi, deskripsi, status, catatan, foto) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach (array_reverse($inputData['pengaduanList']) as $item) {
                $catatan = $item['catatan'] ?? '';
                $foto = $item['foto'] ?? '';
                $stmt->bind_param("ssssssssss", $item['kode'], $item['waktu'], $item['pelapor'], $item['lokasi'], $item['kategori'], $item['urgensi'], $item['deskripsi'], $item['status'], $catatan, $foto);
                $stmt->execute();
            }
        }

        // 4. Simpan Jadwal List
        if (isset($inputData['jadwalList'])) {
            $conn->query("TRUNCATE TABLE jadwal_list");
            $stmt = $conn->prepare("INSERT INTO jadwal_list (hari, shift, pos, petugas) VALUES (?, ?, ?, ?)");
            foreach (array_reverse($inputData['jadwalList']) as $item) {
                $stmt->bind_param("ssss", $item['hari'], $item['shift'], $item['pos'], $item['petugas']);
                $stmt->execute();
            }
        }

        // 5. Simpan Petugas List
        if (isset($inputData['petugasList'])) {
            $conn->query("TRUNCATE TABLE petugas");
            $stmt = $conn->prepare("INSERT IGNORE INTO petugas (nama, username, role, status) VALUES (?, ?, ?, ?)");
            foreach (array_reverse($inputData['petugasList']) as $item) {
                $stmt->bind_param("ssss", $item['nama'], $item['username'], $item['role'], $item['status']);
                $stmt->execute();
            }
        }

        // 6. Simpan Surat Masuk List
        if (isset($inputData['suratMasukList'])) {
            $conn->query("TRUNCATE TABLE surat_masuk_list");
            $stmt = $conn->prepare("INSERT INTO surat_masuk_list (tgl_surat_masuk, tgl_surat, no_surat, perihal, instansi, file_data, file_name) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach (array_reverse($inputData['suratMasukList']) as $item) {
                $stmt->bind_param("sssssss", $item['tglSuratMasuk'], $item['tglSurat'], $item['noSurat'], $item['perihal'], $item['instansi'], $item['fileData'], $item['fileName']);
                $stmt->execute();
            }
        }

        // 7. Simpan Laporan Gedung & Detail
        if (isset($inputData['laporanGedung'])) {
            $conn->query("SET FOREIGN_KEY_CHECKS = 0");
            $conn->query("TRUNCATE TABLE laporan_gedung_detail");
            $conn->query("TRUNCATE TABLE laporan_gedung");
            $conn->query("SET FOREIGN_KEY_CHECKS = 1");

            $stmtLap = $conn->prepare("INSERT INTO laporan_gedung (waktu, gedung, petugas) VALUES (?, ?, ?)");
            $stmtDet = $conn->prepare("INSERT INTO laporan_gedung_detail (laporan_id, nama_area, keterangan, foto) VALUES (?, ?, ?, ?)");

            foreach (array_reverse($inputData['laporanGedung']) as $item) {
                $stmtLap->bind_param("sss", $item['waktu'], $item['gedung'], $item['petugas']);
                $stmtLap->execute();
                $newLaporanId = $conn->insert_id;

                if (isset($item['detail']) && is_array($item['detail'])) {
                    foreach ($item['detail'] as $det) {
                        $stmtDet->bind_param("isss", $newLaporanId, $det['namaArea'], $det['keterangan'], $det['foto']);
                        $stmtDet->execute();
                    }
                }
            }
        }

        $conn->commit();
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Satpam - SECURE-OPS</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f5f9; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 min-h-screen flex flex-col md:flex-row selection:bg-blue-600 selection:text-white">

    <!-- Mobile Header -->
    <div class="md:hidden bg-slate-900 text-white p-4 flex items-center justify-between sticky top-0 z-50 shadow-md">
        <div class="flex items-center space-x-3">
            <div class="bg-blue-600 p-2 rounded-lg shadow">
                <i class="fa-solid fa-shield-halved text-white text-xl"></i>
            </div>
            <span class="font-bold text-lg tracking-wide">SECURE-OPS (SATPAM)</span>
        </div>
        <button id="mobileMenuBtn" onclick="toggleMobileMenu()" class="text-slate-300 hover:text-white focus:outline-none text-2xl">
            <i class="fa-solid fa-bars"></i>
        </button> 
    </div>

    <!-- Sidebar Navigasi Satpam -->
    <aside id="sidebar" class="hidden md:flex flex-col w-full md:w-64 bg-slate-900 text-slate-300 border-r border-slate-800 min-h-screen p-4 justify-between z-40 fixed md:relative inset-y-0 left-0 transition-all duration-300 shadow-xl">
        <div class="space-y-6">
            <!-- Brand Logo -->
            <div class="hidden md:flex items-center space-x-3 px-2 py-3 border-b border-slate-800">
                <div class="bg-blue-600 p-2.5 rounded-xl shadow-lg shadow-blue-500/30">
                    <i class="fa-solid fa-shield-halved text-white text-2xl"></i>
                </div>
                <div>
                    <h1 class="font-extrabold text-lg tracking-wider text-white">SECURE-OPS</h1>
                    <p class="text-xs text-slate-400"></p>
                </div>
            </div>

            <!-- Role Badge Display -->
            <div class="bg-slate-800/90 p-3 rounded-xl border border-slate-700/80">
                <div class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2 flex justify-between items-center">
                    <span>Status Petugas</span>
                    <i class="fa-solid fa-user-shield text-blue-400"></i>
                </div>
                <div class="flex items-center space-x-3 bg-slate-900 p-2.5 rounded-lg border border-slate-700/50">
                    <div class="w-8 h-8 rounded-full bg-blue-600 text-white flex items-center justify-center font-bold text-xs uppercase shadow">
                        <?= strtoupper(substr($username, 0, 2)) ?>
                    </div>
                    <div class="overflow-hidden">
                        <p class="text-xs font-bold text-white truncate"><?= htmlspecialchars($username) ?></p>
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-blue-400">
                        </p>
                    </div>
                </div>
            </div>

            <!-- Navigation Links -->
            <nav class="space-y-1.5">
                <a href="#" onclick="showSection('dashboard'); return false;" id="nav-dashboard" class="nav-item flex items-center space-x-3 px-3.5 py-2.5 rounded-xl transition-all font-semibold bg-blue-600 text-white shadow-md shadow-blue-600/30">
                    <i class="fa-solid fa-chart-pie w-5 text-center text-white"></i>
                    <span class="font-medium text-sm">Ringkasan Utama</span>
                </a>
                <a href="#" onclick="showSection('portal'); return false;" id="nav-portal" class="nav-item flex items-center space-x-3 px-3.5 py-2.5 rounded-xl text-slate-300 hover:bg-slate-800 hover:text-white transition-all">
                    <i class="fa-solid fa-torii-gate w-5 text-center text-emerald-400"></i>
                    <span class="font-medium text-sm">Ceklis Portal Post</span>
                </a>
                <a href="#" onclick="showSection('laporan'); return false;" id="nav-laporan" class="nav-item flex items-center space-x-3 px-3.5 py-2.5 rounded-xl text-slate-300 hover:bg-slate-800 hover:text-white transition-all">
                    <i class="fa-solid fa-file-invoice w-5 text-center text-amber-400"></i>
                    <span class="font-medium text-sm">Laporan Operasional</span>
                </a>
                <a href="#" onclick="showSection('pengaduan'); return false;" id="nav-pengaduan" class="nav-item flex items-center space-x-3 px-3.5 py-2.5 rounded-xl text-slate-300 hover:bg-slate-800 hover:text-white transition-all">
                    <i class="fa-solid fa-circle-exclamation w-5 text-center text-rose-400"></i>
                    <span class="font-medium text-sm">Pengaduan Kendala</span>
                </a>
                <a href="#" onclick="showSection('jadwal'); return false;" id="nav-jadwal" class="nav-item flex items-center space-x-3 px-3.5 py-2.5 rounded-xl text-slate-300 hover:bg-slate-800 hover:text-white transition-all">
                    <i class="fa-solid fa-calendar-days w-5 text-center text-purple-400"></i>
                    <span class="font-medium text-sm">Jadwal Shift & Area</span>
                </a>
                <a href="#" onclick="showSection('petugas'); return false;" id="nav-petugas" class="nav-item flex items-center space-x-3 px-3.5 py-2.5 rounded-xl text-slate-300 hover:bg-slate-800 hover:text-white transition-all">
                    <i class="fa-solid fa-users-gear w-5 text-center text-teal-400"></i>
                    <span class="font-medium text-sm">Manajemen Petugas</span>
                </a>
                <a href="#" onclick="showSection('suratmasuk'); return false;" id="nav-suratmasuk" class="nav-item flex items-center space-x-3 px-3.5 py-2.5 rounded-xl text-slate-300 hover:bg-slate-800 hover:text-white transition-all">
                    <i class="fa-solid fa-envelope-open-text w-5 text-center text-indigo-400"></i>
                    <span class="font-medium text-sm">Surat Masuk</span>
                </a>
                <a href="logout.php" class="flex items-center space-x-3 px-3.5 py-2.5 rounded-xl bg-rose-500/10 text-rose-400 hover:bg-rose-600 hover:text-white transition-all font-semibold mt-4">
                    <i class="fa-solid fa-right-from-bracket w-5 text-center"></i>
                    <span class="text-sm">Keluar</span>
                </a>
            </nav>
        </div>

        <div class="pt-4 border-t border-slate-800 text-xs text-slate-400 space-y-1">
            <div class="flex items-center space-x-2">
                <div id="dbStatusDot" class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse"></div>
                <span class="font-semibold text-slate-300">Database MySQL Active</span>
            </div>
            <p id="systemClock" class="font-mono text-slate-400 text-[11px]"></p>
        </div>
    </aside>

    <!-- Konten Utama -->
    <main class="flex-1 p-4 md:p-8 overflow-y-auto custom-scrollbar space-y-6">

        <!-- HEADER BANNER -->
        <div class="bg-gradient-to-r from-blue-700 via-blue-600 to-indigo-700 p-6 md:p-8 rounded-3xl shadow-xl text-white flex flex-col md:flex-row justify-between items-start md:items-center gap-4 relative overflow-hidden">
            <div class="absolute -right-10 -bottom-10 opacity-10 text-white pointer-events-none">
                <i class="fa-solid fa-shield-halved text-9xl"></i>
            </div>
            <div class="z-10">
                <div class="flex items-center space-x-2 text-xs font-bold text-blue-200 uppercase tracking-widest mb-2">
                    <span class="px-2.5 py-1 rounded-full bg-white/20 border border-white/30 backdrop-blur-sm">MODE SATPAM ACTIVE</span>
                    <span>•</span>
                    <span id="posCurrent">Pos Utama</span>
                </div>
                <h2 id="pageTitle" class="text-2xl md:text-3xl font-extrabold tracking-tight">Dashboard Operasional Satpam</h2>
                <p id="pageSubtitle" class="text-blue-100 text-sm mt-1 max-w-xl">Pencatatan & Pembaruan Data Log Ceklis Portal, Buku Tamu, dan Laporan Patroli</p>
            </div>
        </div>

        <div id="toastContainer" class="fixed bottom-5 right-5 z-50 space-y-2 pointer-events-none"></div>

        <!-- 1. DASHBOARD OVERVIEW SECTION -->
        <section id="sec-dashboard" class="space-y-6">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm hover:shadow-md transition">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Ceklis Portal Hari Ini</p>
                            <h3 id="statPortalCount" class="text-3xl font-black text-slate-800 mt-1">0</h3>
                        </div>
                        <div class="p-3 bg-emerald-50 text-emerald-600 rounded-2xl border border-emerald-100">
                            <i class="fa-solid fa-car text-xl"></i>
                        </div>
                    </div>
                    <p class="text-xs text-slate-500 mt-3"><span class="text-emerald-600 font-bold"><i class="fa-solid fa-check"></i> Realtime</span> log pemeriksaan</p>
                </div>

                <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm hover:shadow-md transition">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Total Laporan Masuk</p>
                            <h3 id="statLaporanCount" class="text-3xl font-black text-slate-800 mt-1">0</h3>
                        </div>
                        <div class="p-3 bg-amber-50 text-amber-600 rounded-2xl border border-amber-100">
                            <i class="fa-solid fa-clipboard-list text-xl"></i>
                        </div>
                    </div>
                    <p class="text-xs text-slate-500 mt-3">Laporan patroli gedung</p>
                </div>

                <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm hover:shadow-md transition">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Pengaduan Kendala</p>
                            <h3 id="statPengaduanCount" class="text-3xl font-black text-rose-600 mt-1">0</h3>
                        </div>
                        <div class="p-3 bg-rose-50 text-rose-600 rounded-2xl border border-rose-100">
                            <i class="fa-solid fa-triangle-exclamation text-xl"></i>
                        </div>
                    </div>
                    <p class="text-xs text-slate-500 mt-3"><span id="statPendingPengaduan" class="text-amber-600 font-bold">0 Pending</span> kendala dilaporkan</p>
                </div>

                <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm hover:shadow-md transition">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Total Petugas</p>
                            <h3 id="statPetugasCount" class="text-3xl font-black text-teal-600 mt-1">0</h3>
                        </div>
                        <div class="p-3 bg-teal-50 text-teal-600 rounded-2xl border border-teal-100">
                            <i class="fa-solid fa-users-gear text-xl"></i>
                        </div>
                    </div>
                    <p class="text-xs text-slate-500 mt-3">Personil terdaftar di sistem</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm space-y-4">
                    <h3 class="font-bold text-lg text-slate-800 flex items-center space-x-2">
                        <i class="fa-solid fa-bolt text-amber-500"></i>
                        <span>Aksi Cepat Satpam</span>
                    </h3>
                    <p class="text-xs text-slate-500">Pilih menu cepat untuk pencatatan & pembaruan data saat bertugas.</p>
                    <div class="grid grid-cols-1 gap-3 pt-2">
                        <button onclick="showSection('portal')" class="p-4 bg-slate-50 hover:bg-blue-50/60 border border-slate-200 hover:border-blue-200 rounded-2xl text-left flex items-center space-x-4 transition group">
                            <div class="p-3 bg-blue-100 text-blue-700 rounded-xl group-hover:scale-110 transition shadow-sm">
                                <i class="fa-solid fa-torii-gate text-xl"></i>
                            </div>
                            <div>
                                <h4 class="font-bold text-sm text-slate-800">Input / Edit Ceklis Portal Gate</h4>
                                <p class="text-xs text-slate-500">Catat kendaraan masuk/keluar & barang</p>
                            </div>
                        </button>
                        <button onclick="showSection('petugas')" class="p-4 bg-slate-50 hover:bg-teal-50/60 border border-slate-200 hover:border-teal-200 rounded-2xl text-left flex items-center space-x-4 transition group">
                            <div class="p-3 bg-teal-100 text-teal-700 rounded-xl group-hover:scale-110 transition shadow-sm">
                                <i class="fa-solid fa-users-gear text-xl"></i>
                            </div>
                            <div>
                                <h4 class="font-bold text-sm text-slate-800">Kelola Data Petugas</h4>
                                <p class="text-xs text-slate-500">Tambah atau perbarui akun personil</p>
                            </div>
                        </button>
                        <button onclick="showSection('laporan')" class="p-4 bg-slate-50 hover:bg-amber-50/60 border border-slate-200 hover:border-amber-200 rounded-2xl text-left flex items-center space-x-4 transition group">
                            <div class="p-3 bg-amber-100 text-amber-700 rounded-xl group-hover:scale-110 transition shadow-sm">
                                <i class="fa-solid fa-file-circle-check text-xl"></i>
                            </div>
                            <div>
                                <h4 class="font-bold text-sm text-slate-800">Buat / Edit Laporan Patroli Gedung</h4>
                                <p class="text-xs text-slate-500">Checklist area Gedung A & Gedung B</p>
                            </div>
                        </button>
                    </div>
                </div>

                <div class="lg:col-span-2 bg-white p-6 rounded-3xl border border-slate-200 shadow-sm space-y-4">
                    <div class="flex justify-between items-center">
                        <h3 class="font-bold text-lg text-slate-800 flex items-center space-x-2">
                            <i class="fa-solid fa-clock-rotate-left text-blue-600"></i>
                            <span>Aktivitas & Log Terbaru</span>
                        </h3>
                        <button onclick="showSection('portal')" class="text-xs font-bold text-blue-600 hover:underline">Lihat Semua</button>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs text-slate-600">
                            <thead class="bg-slate-50 text-slate-500 uppercase font-bold border-b border-slate-200">
                                <tr>
                                    <th class="p-3 rounded-l-xl">Waktu</th>
                                    <th class="p-3">Kategori / Sumber</th>
                                    <th class="p-3">Pelapor / Detail</th>
                                    <th class="p-3 rounded-r-xl">Status</th>
                                </tr>
                            </thead>
                            <tbody id="recentActivityTable" class="divide-y divide-slate-100"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <!-- 2. CEKLIS PORTAL POST SECTION -->
        <section id="sec-portal" class="hidden space-y-6">
            <div class="flex space-x-2 border-b border-slate-200 pb-3">
                <button onclick="switchPortalTab('kendaraan')" id="btnTabKendaraan" class="px-5 py-2.5 text-xs font-bold rounded-xl bg-blue-600 text-white transition shadow-md shadow-blue-600/20">
                    <i class="fa-solid fa-car mr-1.5"></i> Log Kendaraan
                </button>
                <button onclick="switchPortalTab('tamu')" id="btnTabTamu" class="px-5 py-2.5 text-xs font-bold rounded-xl bg-white text-slate-600 border border-slate-200 hover:bg-slate-50 transition">
                    <i class="fa-solid fa-id-badge mr-1.5"></i> Log Buku Tamu
                </button>
            </div>

            <!-- Tab Kendaraan -->
            <div id="tabKendaraanContent" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm space-y-4">
                    <div class="border-b border-slate-100 pb-3 flex justify-between items-center">
                        <div>
                            <h3 class="font-bold text-lg text-slate-800 flex items-center space-x-2">
                                <i class="fa-solid fa-shield-cat text-blue-600"></i>
                                <span id="formPortalTitle">Form Ceklis Portal Gate</span>
                            </h3>
                            <p class="text-xs text-slate-500 mt-1">Input atau perbarui log lalu lintas kendaraan.</p>
                        </div>
                        <button type="button" id="btnCancelEditPortal" onclick="resetPortalForm()" class="hidden text-xs font-bold text-rose-500 hover:underline">Batal Edit</button>
                    </div>
                    <form id="formPortal" onsubmit="handlePortalSubmit(event)" class="space-y-3.5 text-xs">
                        <input type="hidden" id="editPortalId">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Arah Kendaraan</label>
                            <select id="portalArah" class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-slate-800 focus:border-blue-600 focus:bg-white focus:outline-none transition font-medium">
                                <option value="MASUK">KENDARAAN MASUK</option>
                                <option value="KELUAR">KENDARAAN KELUAR</option>
                            </select>
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Nomor Polisi / Plat</label>
                            <input type="text" id="portalPlat" required placeholder="Contoh: B 1234 ABC" class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-slate-800 uppercase focus:border-blue-600 focus:bg-white focus:outline-none transition font-semibold">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Jenis Kendaraan & Pengemudi</label>
                            <input type="text" id="portalDriver" required placeholder="Contoh: Mobil Box (Bpk. Joko)" class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-slate-800 focus:border-blue-600 focus:bg-white focus:outline-none transition">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Hasil Pemeriksaan Barang & Bagasi</label>
                            <textarea id="portalPemeriksaan" rows="2" placeholder="Catatan hasil pemeriksaan..." class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-slate-800 focus:border-blue-600 focus:bg-white focus:outline-none transition"></textarea>
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Status Pemeriksaan Portal</label>
                            <select id="portalStatus" class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-slate-800 focus:border-blue-600 focus:bg-white focus:outline-none transition font-medium">
                                <option value="AMAN">AMAN / DIIZINKAN</option>
                                <option value="DIBATASI">DIBATASI / PERLU IZIN KHUSUS</option>
                                <option value="INSIDEN">INSIDEN / DITOLAK</option>
                            </select>
                        </div>
                        <button type="submit" id="btnSubmitPortal" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-bold py-3 rounded-xl transition shadow-lg shadow-blue-600/30 flex items-center justify-center space-x-2">
                            <i class="fa-solid fa-plus-circle"></i>
                            <span>Simpan Log Kendaraan</span>
                        </button>
                    </form>
                </div>

                <div class="lg:col-span-2 bg-white p-6 rounded-3xl border border-slate-200 shadow-sm space-y-4">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 border-b border-slate-100 pb-3">
                        <div>
                            <h3 class="font-bold text-lg text-slate-800">Log Riwayat Kendaraan</h3>
                            <p class="text-xs text-slate-500">Data lalu lintas portal terintegrasi otomatis</p>
                        </div>
                        <div class="w-full sm:w-auto flex items-center space-x-2">
                            <input type="text" id="searchPortal" onkeyup="renderPortalTable()" placeholder="Cari Plat / Driver..." class="bg-slate-50 border border-slate-200 text-xs rounded-xl px-3.5 py-2 focus:outline-none focus:border-blue-600 text-slate-700 w-full sm:w-auto">
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs text-slate-600">
                            <thead class="bg-slate-50 text-slate-500 uppercase font-bold border-b border-slate-200">
                                <tr>
                                    <th class="p-3 rounded-l-xl">Jam</th>
                                    <th class="p-3">Arah</th>
                                    <th class="p-3">Plat Nomor</th>
                                    <th class="p-3">Pengemudi / Jenis</th>
                                    <th class="p-3">Pemeriksaan Barang</th>
                                    <th class="p-3">Status</th>
                                    <th class="p-3 rounded-r-xl text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="portalTableBody" class="divide-y divide-slate-100"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Tab Tamu -->
            <div id="tabTamuContent" class="grid grid-cols-1 lg:grid-cols-3 gap-6 hidden">
                <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm space-y-4">
                    <div class="border-b border-slate-100 pb-3 flex justify-between items-center">
                        <div>
                            <h3 class="font-bold text-lg text-slate-800 flex items-center space-x-2">
                                <i class="fa-solid fa-id-badge text-blue-600"></i>
                                <span id="formTamuTitle">Form Buku Tamu Pos</span>
                            </h3>
                            <p class="text-xs text-slate-500 mt-1">Input atau perbarui data tamu.</p>
                        </div>
                        <button type="button" id="btnCancelEditTamu" onclick="resetTamuForm()" class="hidden text-xs font-bold text-rose-500 hover:underline">Batal Edit</button>
                    </div>
                    <form id="formTamu" onsubmit="handleTamuSubmit(event)" class="space-y-3.5 text-xs">
                        <input type="hidden" id="editTamuId">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Nama Lengkap Tamu</label>
                            <input type="text" id="tamuNama" required placeholder="Contoh: Andi Pratama" class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-slate-800 focus:border-blue-600 focus:bg-white focus:outline-none transition">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Instansi / Asal Tamu</label>
                            <input type="text" id="tamuInstansi" required placeholder="Contoh: PT Mitra Sejahtera" class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-slate-800 focus:border-blue-600 focus:bg-white focus:outline-none transition">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Bertemu Dengan (Tujuan)</label>
                            <input type="text" id="tamuTujuan" required placeholder="Contoh: Bpk. Budi (HRD)" class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-slate-800 focus:border-blue-600 focus:bg-white focus:outline-none transition">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Tanggal / No. Identitas</label>
                            <input type="text" id="tamuIdentitas" required placeholder="Contoh: KTP 317123456789" class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-slate-800 focus:border-blue-600 focus:bg-white focus:outline-none transition">
                        </div>
                        <button type="submit" id="btnSubmitTamu" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-bold py-3 rounded-xl transition shadow-lg shadow-blue-600/30 flex items-center justify-center space-x-2">
                            <i class="fa-solid fa-user-plus"></i>
                            <span>Simpan Buku Tamu</span>
                        </button>
                    </form>
                </div>

                <div class="lg:col-span-2 bg-white p-6 rounded-3xl border border-slate-200 shadow-sm space-y-4">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 border-b border-slate-100 pb-3">
                        <div>
                            <h3 class="font-bold text-lg text-slate-800">Log Riwayat Buku Tamu</h3>
                            <p class="text-xs text-slate-500">Daftar tamu masuk yang tercatat di pos</p>
                        </div>
                        <div class="w-full sm:w-auto flex items-center space-x-2">
                            <input type="text" id="searchTamu" onkeyup="renderTamuTable()" placeholder="Cari Nama / Instansi..." class="bg-slate-50 border border-slate-200 text-xs rounded-xl px-3.5 py-2 focus:outline-none focus:border-blue-600 text-slate-700 w-full sm:w-auto">
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs text-slate-600">
                            <thead class="bg-slate-50 text-slate-500 uppercase font-bold border-b border-slate-200">
                                <tr>
                                    <th class="p-3 rounded-l-xl">Jam Masuk</th>
                                    <th class="p-3">Nama Tamu</th>
                                    <th class="p-3">Asal Instansi</th>
                                    <th class="p-3">Bertemu Tujuan</th>
                                    <th class="p-3">No. Identitas</th>
                                    <th class="p-3 rounded-r-xl text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="tamuTableBody" class="divide-y divide-slate-100"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <!-- 3. LAPORAN OPERASIONAL GEDUNG -->
        <section id="sec-laporan" class="hidden space-y-6">
            <div id="laporanGedungContainer" class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
                <div class="mb-5">
                    <h3 class="text-slate-800 font-bold text-base">Pilih Gedung untuk Memulai Laporan</h3>
                    <p class="text-slate-500 text-xs mt-1">Pilih salah satu gedung untuk mengisi checklist semua area sekaligus</p>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <button type="button" onclick="bukaFormGedung('Gedung A')" class="group bg-slate-50 hover:bg-blue-600 border border-slate-200 hover:border-blue-600 rounded-2xl p-6 text-left transition cursor-pointer shadow-sm hover:shadow-lg hover:shadow-blue-600/20">
                        <div class="flex items-center gap-4">
                            <div class="w-14 h-14 rounded-2xl bg-blue-100 group-hover:bg-white/20 flex items-center justify-center transition">
                                <i class="fa-solid fa-building text-blue-600 group-hover:text-white text-2xl"></i>
                            </div>
                            <div>
                                <div class="text-slate-800 group-hover:text-white font-extrabold text-lg">Gedung A</div>
                                <div class="text-slate-500 group-hover:text-blue-100 text-xs mt-1">Isi 6 Area Pemeriksaan Sekaligus</div>
                            </div>
                        </div>
                    </button>
                    <button type="button" onclick="bukaFormGedung('Gedung B')" class="group bg-slate-50 hover:bg-emerald-600 border border-slate-200 hover:border-emerald-600 rounded-2xl p-6 text-left transition cursor-pointer shadow-sm hover:shadow-lg hover:shadow-emerald-600/20">
                        <div class="flex items-center gap-4">
                            <div class="w-14 h-14 rounded-2xl bg-emerald-100 group-hover:bg-white/20 flex items-center justify-center transition">
                                <i class="fa-solid fa-building text-emerald-600 group-hover:text-white text-2xl"></i>
                            </div>
                            <div>
                                <div class="text-slate-800 group-hover:text-white font-extrabold text-lg">Gedung B</div>
                                <div class="text-slate-500 group-hover:text-emerald-100 text-xs mt-1">Isi 6 Area Pemeriksaan Sekaligus</div>
                            </div>
                        </div>
                    </button>
                </div>
            </div>

            <div id="laporanFormContainer" class="hidden bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
                <div class="flex justify-between items-center mb-5 border-b border-slate-100 pb-4">
                    <div>
                        <h3 class="text-slate-800 font-bold text-base flex items-center">
                            <i class="fa-solid fa-file-circle-check text-blue-600 mr-2"></i>
                            <span id="formLaporanHeaderTitle">Form Laporan Operasional:</span> <span id="labelGedungAktif" class="text-blue-600 ml-1"></span>
                        </h3>
                        <p class="text-slate-500 text-xs mt-1">Lengkapi atau perbarui data petugas, keterangan, dan foto untuk masing-masing area.</p>
                    </div>
                    <button type="button" onclick="tutupFormGedung()" class="text-slate-400 hover:text-slate-700 text-lg cursor-pointer">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                <form id="formLaporanMasal" class="space-y-6" onsubmit="kirimLaporanMasal(event)">
                    <input type="hidden" id="editLaporanId">
                    <input type="hidden" id="inputGedungTerpilih" name="gedung">

                    <div class="bg-slate-50 p-4 rounded-2xl border border-slate-200 space-y-4">
                        <h4 class="text-slate-700 text-xs font-bold uppercase tracking-wider">Informasi Petugas</h4>
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-2">
                                Nama Petugas Pemeriksa <span class="text-rose-500">*</span>
                            </label>
                            <input type="text" id="lapPetugasMasal" required placeholder="Masukkan nama petugas..." class="w-full bg-white border border-slate-200 focus:border-blue-600 text-slate-800 rounded-xl px-4 py-3 text-sm outline-none transition font-medium">
                        </div>
                    </div>

                    <div class="space-y-4">
                        <h4 class="text-slate-700 text-xs font-bold uppercase tracking-wider">Checklist 6 Area Pemeriksaan</h4>
                        <div id="containerAreaList" class="space-y-4"></div>
                    </div>

                    <div class="flex justify-end gap-3 pt-4 border-t border-slate-100">
                        <button type="button" onclick="tutupFormGedung()" class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-5 py-2.5 rounded-xl text-xs font-bold transition cursor-pointer">Batal</button>
                        <button type="submit" id="btnSubmitLaporanGedung" class="bg-blue-600 hover:bg-blue-500 active:bg-blue-700 text-white font-bold px-6 py-2.5 rounded-xl text-xs transition shadow-lg shadow-blue-600/30 cursor-pointer flex items-center">
                            <i class="fa-solid fa-paper-plane mr-2"></i>
                            <span>Kirim Semua Laporan Gedung</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-5">
                    <div>
                        <h3 class="text-slate-800 font-bold text-base">Daftar Laporan Operasional Gedung</h3>
                        <p class="text-slate-500 text-xs mt-1">Data laporan gabungan area gedung (Klik Edit untuk memperbarui data)</p>
                    </div>
                    <div class="relative w-full md:w-80">
                        <i class="fa-solid fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                        <input type="text" id="searchLaporan" onkeyup="filterLaporan()" placeholder="Cari Gedung / Petugas..." autocomplete="off" class="w-full bg-slate-50 border border-slate-200 text-slate-700 rounded-xl pl-9 pr-4 py-2 text-xs outline-none focus:border-blue-600">
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="border-b border-slate-200 text-slate-500 font-bold text-xs bg-slate-50">
                                <th class="px-4 py-3 rounded-l-xl">Waktu</th>
                                <th class="px-4 py-3">Gedung</th>
                                <th class="px-4 py-3">Petugas & Status Area</th>
                                <th class="px-4 py-3 text-right rounded-r-xl">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="tabelLaporanBody">
                            <tr id="emptyRow">
                                <td colspan="4" class="px-4 py-12 text-center text-slate-400">
                                    <div class="flex flex-col items-center justify-center space-y-2">
                                        <i class="fa-regular fa-folder-open text-4xl text-slate-300"></i>
                                        <span class="text-sm font-medium">Belum ada laporan.</span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- 4. PENGADUAN KENDALA SECTION -->
        <section id="sec-pengaduan" class="hidden space-y-6">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm space-y-4">
                    <div class="border-b border-slate-100 pb-3 flex justify-between items-center">
                        <div>
                            <h3 class="font-bold text-lg text-slate-800 flex items-center space-x-2">
                                <i class="fa-solid fa-circle-exclamation text-rose-600"></i>
                                <span id="formPengaduanTitle">Form Pengaduan Kendala</span>
                            </h3>
                            <p class="text-xs text-slate-500 mt-1">Laporkan atau edit data gangguan operasional lapangan.</p>
                        </div>
                        <button type="button" id="btnCancelEditPengaduan" onclick="resetPengaduanForm()" class="hidden text-xs font-bold text-rose-500 hover:underline">Batal Edit</button>
                    </div>
                    <form id="formPengaduan" onsubmit="handlePengaduanSubmit(event)" class="space-y-3.5 text-xs">
                        <input type="hidden" id="editPengaduanId">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Nama Pelapor / Petugas <span class="text-rose-500">*</span></label>
                            <input type="text" id="pengaduanPelapor" required placeholder="Contoh: Ahmad Subagja" class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-slate-800 focus:border-rose-500 focus:bg-white focus:outline-none transition">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Kategori Kendala <span class="text-rose-500">*</span></label>
                            <input type="text" id="pengaduanKategori" required placeholder="Contoh: Kerusakan Lampu" class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-slate-800 focus:border-rose-500 focus:bg-white focus:outline-none transition">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Lokasi / Pos Terkait <span class="text-rose-500">*</span></label>
                            <input type="text" id="pengaduanLokasi" required placeholder="Contoh: Pos Utama Gate A" class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-slate-800 focus:border-rose-500 focus:bg-white focus:outline-none transition">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Tingkat Urgensi <span class="text-rose-500">*</span></label>
                            <input type="text" id="pengaduanUrgensi" required placeholder="Contoh: Tinggi / Normal" class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-slate-800 focus:border-rose-500 focus:bg-white focus:outline-none transition">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Upload Foto Kendala</label>
                            <input type="file" id="pengaduanFoto" accept="image/*" class="w-full text-xs text-slate-500 file:mr-3 file:py-2 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-rose-50 file:text-rose-700 hover:file:bg-rose-100 cursor-pointer border border-slate-200 rounded-xl p-1 bg-slate-50">
                            <p id="pengaduanFotoInfo" class="text-[10px] text-rose-600 mt-1 font-semibold hidden"></p>
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Status Penanganan</label>
                            <select id="pengaduanStatus" class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-slate-800 focus:border-rose-500 focus:bg-white focus:outline-none transition font-medium">
                                <option value="MENUNGGU">MENUNGGU</option>
                                <option value="DIPROSES">DIPROSES</option>
                                <option value="SELESAI">SELESAI</option>
                            </select>
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Deskripsi Kendala <span class="text-rose-500">*</span></label>
                            <textarea id="pengaduanDeskripsi" rows="3" required placeholder="Jelaskan detail kendala..." class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-slate-800 focus:border-rose-500 focus:bg-white focus:outline-none transition"></textarea>
                        </div>
                        <button type="submit" id="btnSubmitPengaduan" class="w-full bg-rose-600 hover:bg-rose-500 text-white font-bold py-3 rounded-xl transition shadow-lg shadow-rose-600/30 flex items-center justify-center space-x-2">
                            <i class="fa-solid fa-paper-plane"></i>
                            <span>Kirim Pengaduan Kendala</span>
                        </button>
                    </form>
                </div>

                <div class="lg:col-span-2 bg-white p-6 rounded-3xl border border-slate-200 shadow-sm space-y-4">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 border-b border-slate-100 pb-3">
                        <div>
                            <h3 class="font-bold text-lg text-slate-800">Daftar Pengaduan Kendala</h3>
                            <p class="text-xs text-slate-500">Monitoring pengaduan operasional pos</p>
                        </div>
                        <div class="w-full sm:w-auto flex items-center space-x-2">
                            <input type="text" id="searchPengaduan" onkeyup="renderPengaduanTable()" placeholder="Cari pelapor / kendala..." class="bg-slate-50 border border-slate-200 text-xs rounded-xl px-3.5 py-2 focus:outline-none focus:border-blue-600 text-slate-700 w-full sm:w-auto">
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs text-slate-600">
                            <thead class="bg-slate-50 text-slate-500 uppercase font-bold border-b border-slate-200">
                                <tr>
                                    <th class="p-3 rounded-l-xl">Kode & Waktu</th>
                                    <th class="p-3">Pelapor & Lokasi</th>
                                    <th class="p-3">Kategori & Detail</th>
                                    <th class="p-3">Urgensi</th>
                                    <th class="p-3">Status</th>
                                    <th class="p-3 rounded-r-xl text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="pengaduanTableBody" class="divide-y divide-slate-100"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <!-- 5. JADWAL SHIFT & AREA -->
        <section id="sec-jadwal" class="hidden space-y-6">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Form Input / Edit Jadwal Shift -->
                <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm space-y-4">
                    <div class="border-b border-slate-100 pb-3 flex justify-between items-center">
                        <div>
                            <h3 class="font-bold text-lg text-slate-800 flex items-center space-x-2">
                                <i class="fa-solid fa-calendar-plus text-purple-600"></i>
                                <span id="formJadwalTitle">Form Jadwal Shift</span>
                            </h3>
                            <p class="text-xs text-slate-500 mt-1">Tambah atau perbarui jadwal shift & pos.</p>
                        </div>
                        <button type="button" id="btnCancelEditJadwal" onclick="resetJadwalForm()" class="hidden text-xs font-bold text-rose-500 hover:underline">Batal Edit</button>
                    </div>
                    <form id="formJadwal" onsubmit="handleJadwalSubmit(event)" class="space-y-3.5 text-xs">
                        <input type="hidden" id="editJadwalId">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Tanggal Tugas <span class="text-purple-600">*</span></label>
                            <input type="date" id="jadwalHari" required class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-slate-800 focus:border-purple-600 focus:bg-white focus:outline-none transition">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Shift Kerja <span class="text-purple-600">*</span></label>
                            <input type="text" id="jadwalShift" required placeholder="Contoh: Pagi (08:00 - 18:00)" class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-slate-800 focus:border-purple-600 focus:bg-white focus:outline-none transition">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Pos Penugasan <span class="text-purple-600">*</span></label>
                            <input type="text" id="jadwalPos" required placeholder="Contoh: Pos Utama Gate A" class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-slate-800 focus:border-purple-600 focus:bg-white focus:outline-none transition">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Petugas Assigned <span class="text-purple-600">*</span></label>
                            <input type="text" id="jadwalPetugas" required placeholder="Contoh: Budi Santoso & Agus" class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-slate-800 focus:border-purple-600 focus:bg-white focus:outline-none transition">
                        </div>
                        <button type="submit" id="btnSubmitJadwal" class="w-full bg-purple-600 hover:bg-purple-500 text-white font-bold py-3 rounded-xl transition shadow-lg shadow-purple-600/30 flex items-center justify-center space-x-2">
                            <i class="fa-solid fa-floppy-disk"></i>
                            <span>Simpan Jadwal Shift</span>
                        </button>
                    </form>
                </div>

                <!-- Tabel Jadwal Shift -->
                <div class="lg:col-span-2 bg-white p-6 rounded-3xl border border-slate-200 shadow-sm space-y-4">
                    <div class="border-b border-slate-100 pb-4">
                        <h3 class="font-bold text-lg text-slate-800 flex items-center space-x-2">
                            <i class="fa-solid fa-calendar-days text-purple-600"></i>
                            <span>Jadwal Shift & Area Satpam</span>
                        </h3>
                        <p class="text-xs text-slate-500 mt-1">Daftar rotasi kerja dan pos penugasan tim.</p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs text-slate-600">
                            <thead class="bg-slate-50 text-slate-500 uppercase font-bold border-b border-slate-200">
                                <tr>
                                    <th class="p-3 rounded-l-xl">Hari / Tanggal</th>
                                    <th class="p-3">Shift Kerja</th>
                                    <th class="p-3">Pos Penugasan</th>
                                    <th class="p-3">Petugas Assigned</th>
                                    <th class="p-3 rounded-r-xl text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="jadwalTableBody" class="divide-y divide-slate-100"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <!-- 6. MENU SURAT MASUK -->
        <section id="sec-suratmasuk" class="hidden space-y-6">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm space-y-4">
                    <div class="border-b border-slate-100 pb-3 flex justify-between items-center">
                        <div>
                            <h3 class="font-bold text-lg text-slate-800 flex items-center space-x-2">
                                <i class="fa-solid fa-envelope-open-text text-indigo-600"></i>
                                <span id="formSuratTitle">Form Surat Masuk</span>
                            </h3>
                            <p class="text-xs text-slate-500 mt-1">Input atau perbarui pengarsipan surat masuk.</p>
                        </div>
                        <button type="button" id="btnCancelEditSurat" onclick="resetSuratForm()" class="hidden text-xs font-bold text-rose-500 hover:underline">Batal Edit</button>
                    </div>
                    <form id="formSuratMasuk" onsubmit="handleSuratMasukSubmit(event)" class="space-y-3.5 text-xs">
                        <input type="hidden" id="suratId">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">1. Tgl Surat Masuk <span class="text-indigo-600">*</span></label>
                            <input type="date" id="tglSuratMasuk" required class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-slate-800 focus:border-indigo-600 focus:bg-white focus:outline-none transition">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">2. Tanggal Surat <span class="text-indigo-600">*</span></label>
                            <input type="date" id="tglSurat" required class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-slate-800 focus:border-indigo-600 focus:bg-white focus:outline-none transition">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">3. No. Surat <span class="text-indigo-600">*</span></label>
                            <input type="text" id="noSurat" required placeholder="Contoh: 005/ADM/X/2026" class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-slate-800 focus:border-indigo-600 focus:bg-white focus:outline-none transition">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">4. Perihal <span class="text-indigo-600">*</span></label>
                            <input type="text" id="perihalSurat" required placeholder="Contoh: Undangan Rapat Koordinasi" class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-slate-800 focus:border-indigo-600 focus:bg-white focus:outline-none transition">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">5. Instansi <span class="text-indigo-600">*</span></label>
                            <input type="text" id="instansiSurat" required placeholder="Contoh: PT. Secure Ops Indonesia" class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-slate-800 focus:border-indigo-600 focus:bg-white focus:outline-none transition">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">6. Upload Dokumen</label>
                            <input type="file" id="uploadDokumen" accept=".pdf,image/*,.doc,.docx" class="w-full text-xs text-slate-500 file:mr-3 file:py-2 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 cursor-pointer border border-slate-200 rounded-xl p-1 bg-slate-50">
                            <p id="fileExistingInfo" class="text-[10px] text-indigo-600 mt-1 font-semibold hidden"></p>
                        </div>
                        <button type="submit" id="btnSubmitSurat" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-bold py-3 rounded-xl transition shadow-lg shadow-indigo-600/30 flex items-center justify-center space-x-2">
                            <i class="fa-solid fa-floppy-disk"></i>
                            <span>Simpan Surat Masuk</span>
                        </button>
                    </form>
                </div>

                <div class="lg:col-span-2 bg-white p-6 rounded-3xl border border-slate-200 shadow-sm space-y-4">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 border-b border-slate-100 pb-3">
                        <div>
                            <h3 class="font-bold text-lg text-slate-800">Daftar Rekapitulasi Surat Masuk</h3>
                            <p class="text-xs text-slate-500">Arsip digital dan log status surat masuk pos keamanan</p>
                        </div>
                        <div class="w-full sm:w-auto flex items-center space-x-2">
                            <input type="text" id="searchSuratMasuk" onkeyup="renderSuratMasukTable()" placeholder="Cari No. Surat / Perihal..." class="bg-slate-50 border border-slate-200 text-xs rounded-xl px-3.5 py-2 focus:outline-none focus:border-blue-600 text-slate-700 w-full sm:w-auto">
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs text-slate-600">
                            <thead class="bg-slate-50 text-slate-500 uppercase font-bold border-b border-slate-200">
                                <tr>
                                    <th class="p-3 rounded-l-xl">Tgl Masuk / Surat</th>
                                    <th class="p-3">No. Surat</th>
                                    <th class="p-3">Perihal</th>
                                    <th class="p-3">Instansi</th>
                                    <th class="p-3">Dokumen</th>
                                    <th class="p-3 rounded-r-xl text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="suratMasukTableBody" class="divide-y divide-slate-100"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <!-- 7. MANAJEMEN PETUGAS SECTION -->
        <section id="sec-petugas" class="hidden space-y-6">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Form Tambah / Edit Petugas -->
                <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm space-y-4">
                    <div class="border-b border-slate-100 pb-3 flex justify-between items-center">
                        <div>
                            <h3 class="font-bold text-lg text-slate-800 flex items-center space-x-2">
                                <i class="fa-solid fa-user-gear text-teal-600"></i>
                                <span id="formPetugasTitle">Form Petugas</span>
                            </h3>
                            <p class="text-xs text-slate-500 mt-1">Tambah atau perbarui data akun personil keamanan.</p>
                        </div>
                        <button type="button" id="btnCancelEditPetugas" onclick="resetPetugasForm()" class="hidden text-xs font-bold text-rose-500 hover:underline">Batal Edit</button>
                    </div>
                    <form id="formPetugas" onsubmit="handlePetugasSubmit(event)" class="space-y-3.5 text-xs">
                        <input type="hidden" id="editPetugasId">
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Nama Lengkap <span class="text-teal-600">*</span></label>
                            <input type="text" id="petugasNama" required placeholder="Contoh: Bripka Joko Widodo" class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-slate-800 focus:border-teal-600 focus:bg-white focus:outline-none transition">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Username / NIP <span class="text-teal-600">*</span></label>
                            <input type="text" id="petugasUsername" required placeholder="Contoh: joko_secops" class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-slate-800 focus:border-teal-600 focus:bg-white focus:outline-none transition">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Jabatan / Role <span class="text-teal-600">*</span></label>
                            <select id="petugasRole" class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-slate-800 focus:border-teal-600 focus:bg-white focus:outline-none transition font-medium">
                                <option value="satpam">Satpam / Anggota</option>
                                <option value="komandan">Komandan Regu (Danru)</option>
                                <option value="admin">Administrator</option>
                            </select>
                        </div>
                        <div>
                            <label class="block font-bold text-slate-700 mb-1">Status Keaktifan</label>
                            <select id="petugasStatus" class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-slate-800 focus:border-teal-600 focus:bg-white focus:outline-none transition font-medium">
                                <option value="AKTIF">AKTIF (ON DUTY)</option>
                                <option value="OFF">OFF / CUTI</option>
                            </select>
                        </div>
                        <button type="submit" id="btnSubmitPetugas" class="w-full bg-teal-600 hover:bg-teal-500 text-white font-bold py-3 rounded-xl transition shadow-lg shadow-teal-600/30 flex items-center justify-center space-x-2">
                            <i class="fa-solid fa-user-plus"></i>
                            <span>Simpan Petugas</span>
                        </button>
                    </form>
                </div>

                <!-- Tabel Daftar Petugas -->
                <div class="lg:col-span-2 bg-white p-6 rounded-3xl border border-slate-200 shadow-sm space-y-4">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 border-b border-slate-100 pb-3">
                        <div>
                            <h3 class="font-bold text-lg text-slate-800">Daftar Petugas Keamanan</h3>
                            <p class="text-xs text-slate-500">Kelola akun dan status personil satuan pengamanan</p>
                        </div>
                        <div class="w-full sm:w-auto flex items-center space-x-2">
                            <input type="text" id="searchPetugas" onkeyup="renderPetugasTable()" placeholder="Cari Nama / NIP..." class="bg-slate-50 border border-slate-200 text-xs rounded-xl px-3.5 py-2 focus:outline-none focus:border-teal-600 text-slate-700 w-full sm:w-auto">
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs text-slate-600">
                            <thead class="bg-slate-50 text-slate-500 uppercase font-bold border-b border-slate-200">
                                <tr>
                                    <th class="p-3 rounded-l-xl">Nama & Username / NIP</th>
                                    <th class="p-3">Jabatan / Role</th>
                                    <th class="p-3">Status</th>
                                    <th class="p-3 rounded-r-xl text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="petugasTableBody" class="divide-y divide-slate-100"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

    </main>

    <!-- SCRIPTS -->
    <script>
        let isDataLoaded = false;
        let tamuLogs = [];
        let appState = {
            activeSection: 'dashboard',
            portalLogs: [],
            pengaduanList: [],
            jadwalList: [],
            petugasList: [],
            suratMasukList: []
        };

        let dataLaporanGedung = [];

        function switchPortalTab(tab) {
            const btnKendaraan = document.getElementById('btnTabKendaraan');
            const btnTamu = document.getElementById('btnTabTamu');
            const tabKendaraanContent = document.getElementById('tabKendaraanContent');
            const tabTamuContent = document.getElementById('tabTamuContent');

            if (tab === 'kendaraan') {
                btnKendaraan.className = "px-5 py-2.5 text-xs font-bold rounded-xl bg-blue-600 text-white transition shadow-md shadow-blue-600/20";
                btnTamu.className = "px-5 py-2.5 text-xs font-bold rounded-xl bg-white text-slate-600 border border-slate-200 hover:bg-slate-50 transition";
                tabKendaraanContent.classList.remove('hidden');
                tabTamuContent.classList.add('hidden');
            } else {
                btnTamu.className = "px-5 py-2.5 text-xs font-bold rounded-xl bg-blue-600 text-white transition shadow-md shadow-blue-600/20";
                btnKendaraan.className = "px-5 py-2.5 text-xs font-bold rounded-xl bg-white text-slate-600 border border-slate-200 hover:bg-slate-50 transition";
                tabTamuContent.classList.remove('hidden');
                tabKendaraanContent.classList.add('hidden');
            }
        }

        // --- MANAJEMEN PETUGAS LOGIC ---
        function handlePetugasSubmit(e) {
            e.preventDefault();
            const editId = document.getElementById('editPetugasId').value;
            const nama = document.getElementById('petugasNama').value;
            const usernamePetugas = document.getElementById('petugasUsername').value;
            const role = document.getElementById('petugasRole').value;
            const status = document.getElementById('petugasStatus').value;

            if (editId) {
                const idx = appState.petugasList.findIndex(item => item.id == editId);
                if (idx !== -1) {
                    appState.petugasList[idx] = { ...appState.petugasList[idx], nama, username: usernamePetugas, role, status };
                }
                showToast("Data petugas berhasil diperbarui!", "teal");
            } else {
                const newPetugas = {
                    id: Date.now(),
                    nama,
                    username: usernamePetugas,
                    role,
                    status
                };
                appState.petugasList.unshift(newPetugas);
                showToast("Petugas baru berhasil ditambahkan!", "teal");
            }

            saveAppData();
            resetPetugasForm();
            renderPetugasTable();
            renderDashboard();
        }

        function editPetugas(id) {
            const item = appState.petugasList.find(p => p.id === id);
            if (!item) return;

            document.getElementById('editPetugasId').value = item.id;
            document.getElementById('petugasNama').value = item.nama;
            document.getElementById('petugasUsername').value = item.username;
            document.getElementById('petugasRole').value = item.role || 'satpam';
            document.getElementById('petugasStatus').value = item.status || 'AKTIF';

            document.getElementById('formPetugasTitle').innerText = "Edit Data Petugas";
            document.getElementById('btnSubmitPetugas').innerHTML = `<i class="fa-solid fa-pen-to-square"></i><span>Update Petugas</span>`;
            document.getElementById('btnCancelEditPetugas').classList.remove('hidden');
        }

        function deletePetugas(id) {
            if (confirm("Apakah Anda yakin ingin menghapus petugas ini dari sistem?")) {
                appState.petugasList = appState.petugasList.filter(p => p.id !== id);
                saveAppData();
                renderPetugasTable();
                renderDashboard();
                showToast("Petugas berhasil dihapus!", "rose");
            }
        }

        function resetPetugasForm() {
            document.getElementById('formPetugas').reset();
            document.getElementById('editPetugasId').value = '';
            document.getElementById('formPetugasTitle').innerText = "Form Petugas";
            document.getElementById('btnSubmitPetugas').innerHTML = `<i class="fa-solid fa-user-plus"></i><span>Simpan Petugas</span>`;
            document.getElementById('btnCancelEditPetugas').classList.add('hidden');
        }

        function renderPetugasTable() {
            const searchEl = document.getElementById('searchPetugas');
            const keyword = searchEl ? searchEl.value.toLowerCase() : '';
            const tbody = document.getElementById('petugasTableBody');
            if (!tbody) return;
            tbody.innerHTML = '';

            const filtered = appState.petugasList.filter(item => 
                (item.nama && item.nama.toLowerCase().includes(keyword)) || 
                (item.username && item.username.toLowerCase().includes(keyword))
            );

            if (filtered.length === 0) {
                tbody.innerHTML = `<tr><td colspan="4" class="p-4 text-center text-slate-400 italic font-medium">Belum ada data petugas terdaftar.</td></tr>`;
                return;
            }

            filtered.forEach(item => {
                const roleBadge = item.role === 'admin' ? 'bg-purple-100 text-purple-700 border-purple-300' : (item.role === 'komandan' ? 'bg-blue-100 text-blue-700 border-blue-300' : 'bg-slate-100 text-slate-700 border-slate-300');
                const statusBadge = item.status === 'AKTIF' ? 'bg-emerald-100 text-emerald-800 border-emerald-300' : 'bg-rose-100 text-rose-800 border-rose-300';
                
                tbody.innerHTML += `
                    <tr class="hover:bg-slate-50 transition">
                        <td class="p-3">
                            <div class="font-bold text-slate-800">${item.nama || '-'}</div>
                            <div class="text-[10px] text-slate-500 font-mono">@${item.username || '-'}</div>
                        </td>
                        <td class="p-3"><span class="px-2.5 py-0.5 rounded-md text-[10px] font-bold uppercase border ${roleBadge}">${item.role || 'satpam'}</span></td>
                        <td class="p-3"><span class="px-2.5 py-0.5 rounded-md text-[10px] font-bold border ${statusBadge}">${item.status || 'AKTIF'}</span></td>
                        <td class="p-3 text-right space-x-1">
                            <button type="button" onclick="editPetugas(${item.id})" class="bg-amber-50 hover:bg-amber-500 text-amber-700 hover:text-white px-2.5 py-1.5 rounded-lg font-bold transition border border-amber-200" title="Edit Petugas">
                                <i class="fa-solid fa-pen-to-square"></i>
                            </button>
                            <button type="button" onclick="deletePetugas(${item.id})" class="bg-rose-50 hover:bg-rose-500 text-rose-700 hover:text-white px-2.5 py-1.5 rounded-lg font-bold transition border border-rose-200" title="Hapus Petugas">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
        }
        // --- END MANAJEMEN PETUGAS LOGIC ---

        function handleTamuSubmit(e) {
            e.preventDefault();
            const editId = document.getElementById('editTamuId').value;
            const nama = document.getElementById('tamuNama').value;
            const instansi = document.getElementById('tamuInstansi').value;
            const tujuan = document.getElementById('tamuTujuan').value;
            const identitas = document.getElementById('tamuIdentitas').value;

            if (editId) {
                const idx = tamuLogs.findIndex(item => item.id == editId);
                if (idx !== -1) {
                    tamuLogs[idx] = { ...tamuLogs[idx], nama, instansi, tujuan, identitas };
                }
                showToast("Data Buku Tamu berhasil diperbarui!", "emerald");
            } else {
                const newTamu = {
                    id: Date.now(),
                    jam: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
                    nama,
                    instansi,
                    tujuan,
                    identitas
                };
                tamuLogs.unshift(newTamu);
                showToast("Log Tamu berhasil disimpan!", "emerald");
            }

            saveAppData();
            resetTamuForm();
            renderTamuTable();
        }

        function editTamuItem(id) {
            const item = tamuLogs.find(t => t.id === id);
            if (!item) return;

            document.getElementById('editTamuId').value = item.id;
            document.getElementById('tamuNama').value = item.nama;
            document.getElementById('tamuInstansi').value = item.instansi;
            document.getElementById('tamuTujuan').value = item.tujuan;
            document.getElementById('tamuIdentitas').value = item.identitas;

            document.getElementById('formTamuTitle').innerText = "Edit Buku Tamu Pos";
            document.getElementById('btnSubmitTamu').innerHTML = `<i class="fa-solid fa-pen-to-square"></i><span>Update Buku Tamu</span>`;
            document.getElementById('btnCancelEditTamu').classList.remove('hidden');
        }

        function resetTamuForm() {
            document.getElementById('formTamu').reset();
            document.getElementById('editTamuId').value = '';
            document.getElementById('formTamuTitle').innerText = "Form Buku Tamu Pos";
            document.getElementById('btnSubmitTamu').innerHTML = `<i class="fa-solid fa-user-plus"></i><span>Simpan Buku Tamu</span>`;
            document.getElementById('btnCancelEditTamu').classList.add('hidden');
        }

        function renderTamuTable() {
            const searchEl = document.getElementById('searchTamu');
            const keyword = searchEl ? searchEl.value.toLowerCase() : '';
            const tbody = document.getElementById('tamuTableBody');
            if (!tbody) return;
            tbody.innerHTML = '';

            const filtered = tamuLogs.filter(item => 
                (item.nama && item.nama.toLowerCase().includes(keyword)) || 
                (item.instansi && item.instansi.toLowerCase().includes(keyword))
            );

            if (filtered.length === 0) {
                tbody.innerHTML = `<tr><td colspan="6" class="p-4 text-center text-slate-400 italic font-medium">Belum ada data buku tamu.</td></tr>`;
                return;
            }

            filtered.forEach(item => {
                tbody.innerHTML += `
                    <tr class="hover:bg-slate-50 transition">
                        <td class="p-3 font-mono font-semibold text-slate-700">${item.jam || '-'}</td>
                        <td class="p-3 font-bold text-slate-800">${item.nama || '-'}</td>
                        <td class="p-3 text-slate-600">${item.instansi || '-'}</td>
                        <td class="p-3 text-slate-600">${item.tujuan || '-'}</td>
                        <td class="p-3 font-mono text-slate-500">${item.identitas || '-'}</td>
                        <td class="p-3 text-right">
                            <button type="button" onclick="editTamuItem(${item.id})" class="bg-amber-50 hover:bg-amber-500 text-amber-700 hover:text-white px-2.5 py-1.5 rounded-lg font-bold transition border border-amber-200" title="Edit Data">
                                <i class="fa-solid fa-pen-to-square mr-1"></i> Edit
                            </button>
                        </td>
                    </tr>
                `;
            });
        }

        async function fetchServerData() {
            try {
                const response = await fetch('Satpam.php?action=get_data');
                if (response.ok) {
                    const data = await response.json();
                    appState.portalLogs = data.portalLogs || [];
                    appState.pengaduanList = data.pengaduanList || [];
                    appState.jadwalList = data.jadwalList || [];
                    appState.petugasList = data.petugasList || [];
                    appState.suratMasukList = data.suratMasukList || [];
                    dataLaporanGedung = data.laporanGedung || [];
                    tamuLogs = data.tamuLogs || [];
                    isDataLoaded = true;
                    renderAll();
                    renderTamuTable();
                }
            } catch (err) {
                console.error("Gagal sinkronisasi data dari server:", err);
            }
        }

        async function saveAppData() {
            if (!isDataLoaded) return;
            const payload = {
                portalLogs: appState.portalLogs,
                pengaduanList: appState.pengaduanList,
                jadwalList: appState.jadwalList,
                petugasList: appState.petugasList,
                suratMasukList: appState.suratMasukList,
                laporanGedung: dataLaporanGedung,
                tamuLogs: tamuLogs
            };
            try {
                await fetch('Satpam.php?action=save_data', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
            } catch (err) {
                console.error("Gagal menyimpan data ke server:", err);
            }
        }

        window.onload = function() {
            startClock();
            setTodayDates();
            fetchServerData();
            setInterval(fetchServerData, 3000);
        };

        function setTodayDates() {
            const today = new Date().toISOString().split('T')[0];
            const tglMasukEl = document.getElementById('tglSuratMasuk');
            const tglSuratEl = document.getElementById('tglSurat');
            const jadwalHariEl = document.getElementById('jadwalHari');
            if (tglMasukEl && !tglMasukEl.value) tglMasukEl.value = today;
            if (tglSuratEl && !tglSuratEl.value) tglSuratEl.value = today;
            if (jadwalHariEl && !jadwalHariEl.value) jadwalHariEl.value = today;
        }

        function startClock() {
            setInterval(() => {
                const now = new Date();
                const options = { weekday: 'long', year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' };
                document.getElementById('systemClock').innerText = now.toLocaleDateString('id-ID', options) + ' WITA';
            }, 1000);
        }

        function toggleMobileMenu() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('hidden');
        }

        function showSection(sectionId) {
            appState.activeSection = sectionId;
            const sections = ['dashboard', 'portal', 'laporan', 'pengaduan', 'jadwal', 'suratmasuk', 'petugas'];
            sections.forEach(sec => {
                const el = document.getElementById(`sec-${sec}`);
                const nav = document.getElementById(`nav-${sec}`);
                if (sec === sectionId) {
                    if (el) el.classList.remove('hidden');
                    if (nav) nav.className = "nav-item flex items-center space-x-3 px-3.5 py-2.5 rounded-xl transition-all font-bold bg-blue-600 text-white shadow-md shadow-blue-600/30";
                } else {
                    if (el) el.classList.add('hidden');
                    if (nav) nav.className = "nav-item flex items-center space-x-3 px-3.5 py-2.5 rounded-xl text-slate-300 hover:bg-slate-800 hover:text-white transition-all font-medium";
                }
            });

            const titleMap = {
                'dashboard': ['Dashboard Operasional Satpam', 'Pencatatan & Pembaruan Data Log Ceklis Portal, Buku Tamu, dan Laporan Patroli'],
                'portal': ['Ceklis Portal Post', 'Pencatatan & Edit Keluar Masuk Kendaraan, Barang, dan Pengemudi'],
                'laporan': ['Laporan Operasional Gedung', 'Pemeriksaan & Pembaruan Area Gedung A dan Gedung B'],
                'pengaduan': ['Pengaduan Kendala', 'Pelaporan & Pembaruan Status Gangguan Operasional Sarpras'],
                'jadwal': ['Jadwal Shift & Area', 'Manajemen & Edit Rotasi Penugasan Pos Keamanan'],
                'suratmasuk': ['Surat Masuk', 'Pengarsipan & Edit Surat Masuk Operasional'],
                'petugas': ['Manajemen Petugas', 'Pengelolaan Data Akun dan Status Personil Keamanan']
            };
            if (titleMap[sectionId]) {
                document.getElementById('pageTitle').innerText = titleMap[sectionId][0];
                document.getElementById('pageSubtitle').innerText = titleMap[sectionId][1];
            }

            const sidebar = document.getElementById('sidebar');
            if (!sidebar.classList.contains('hidden') && window.innerWidth < 768) {
                toggleMobileMenu();
            }
        }

        function showToast(message, type = 'emerald') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            const bgColors = {
                emerald: 'bg-emerald-600 text-white shadow-emerald-600/30',
                rose: 'bg-rose-600 text-white shadow-rose-600/30',
                indigo: 'bg-indigo-600 text-white shadow-indigo-600/30',
                amber: 'bg-amber-600 text-white shadow-amber-600/30',
                teal: 'bg-teal-600 text-white shadow-teal-600/30'
            };
            toast.className = `${bgColors[type] || bgColors.emerald} px-4 py-3 rounded-2xl shadow-xl text-xs font-bold flex items-center space-x-2 transition-all duration-300`;
            toast.innerHTML = `<i class="fa-solid fa-circle-check"></i><span>${message}</span>`;
            container.appendChild(toast);
            setTimeout(() => { toast.remove(); }, 3000);
        }

        async function handleSuratMasukSubmit(e) {
            e.preventDefault();
            const editId = document.getElementById('suratId').value;
            const tglSuratMasuk = document.getElementById('tglSuratMasuk').value;
            const tglSurat = document.getElementById('tglSurat').value;
            const noSurat = document.getElementById('noSurat').value;
            const perihal = document.getElementById('perihalSurat').value;
            const instansi = document.getElementById('instansiSurat').value;
            const fileInput = document.getElementById('uploadDokumen');

            let fileData = '';
            let fileName = '';
            if (fileInput && fileInput.files[0]) {
                fileData = await convertBase64(fileInput.files[0]);
                fileName = fileInput.files[0].name;
            }

            if (editId) {
                const idx = appState.suratMasukList.findIndex(item => item.id == editId);
                if (idx !== -1) {
                    appState.suratMasukList[idx] = {
                        ...appState.suratMasukList[idx],
                        tglSuratMasuk,
                        tglSurat,
                        noSurat,
                        perihal,
                        instansi,
                        fileData: fileData || appState.suratMasukList[idx].fileData,
                        fileName: fileName || appState.suratMasukList[idx].fileName
                    };
                }
                showToast("Surat masuk berhasil diperbarui!", "indigo");
            } else {
                const newSurat = {
                    id: Date.now(),
                    tglSuratMasuk,
                    tglSurat,
                    noSurat,
                    perihal,
                    instansi,
                    fileData,
                    fileName
                };
                appState.suratMasukList.unshift(newSurat);
                showToast("Surat masuk baru berhasil disimpan!", "indigo");
            }

            saveAppData();
            resetSuratForm();
            renderSuratMasukTable();
            renderDashboard();
        }

        function editSuratMasuk(id) {
            const item = appState.suratMasukList.find(s => s.id === id);
            if (!item) return;

            document.getElementById('suratId').value = item.id;
            document.getElementById('tglSuratMasuk').value = item.tglSuratMasuk;
            document.getElementById('tglSurat').value = item.tglSurat;
            document.getElementById('noSurat').value = item.noSurat;
            document.getElementById('perihalSurat').value = item.perihal;
            document.getElementById('instansiSurat').value = item.instansi;

            const infoEl = document.getElementById('fileExistingInfo');
            if (item.fileName) {
                infoEl.innerText = `File tersimpan: ${item.fileName} (Upload ulang jika ingin mengganti)`;
                infoEl.classList.remove('hidden');
            } else {
                infoEl.classList.add('hidden');
            }

            document.getElementById('formSuratTitle').innerText = "Edit Surat Masuk";
            document.getElementById('btnSubmitSurat').innerHTML = `<i class="fa-solid fa-pen-to-square"></i><span>Update Surat Masuk</span>`;
            document.getElementById('btnCancelEditSurat').classList.remove('hidden');
        }

        function resetSuratForm() {
            document.getElementById('formSuratMasuk').reset();
            document.getElementById('suratId').value = '';
            document.getElementById('fileExistingInfo').classList.add('hidden');
            document.getElementById('formSuratTitle').innerText = "Form Surat Masuk";
            document.getElementById('btnSubmitSurat').innerHTML = `<i class="fa-solid fa-floppy-disk"></i><span>Simpan Surat Masuk</span>`;
            document.getElementById('btnCancelEditSurat').classList.add('hidden');
            setTodayDates();
        }

        function renderSuratMasukTable() {
            const tbody = document.getElementById('suratMasukTableBody');
            const query = (document.getElementById('searchSuratMasuk')?.value || '').toLowerCase();
            if (!tbody) return;
            tbody.innerHTML = '';

            const filtered = appState.suratMasukList.filter(item =>
                (item.noSurat && item.noSurat.toLowerCase().includes(query)) ||
                (item.perihal && item.perihal.toLowerCase().includes(query)) ||
                (item.instansi && item.instansi.toLowerCase().includes(query))
            );

            if (filtered.length === 0) {
                tbody.innerHTML = `<tr><td colspan="6" class="p-4 text-center text-slate-400 italic font-medium">Belum ada data surat masuk.</td></tr>`;
                return;
            }

            filtered.forEach(item => {
                const fileBtn = item.fileData ? 
                    `<a href="${item.fileData}" download="${item.fileName || 'dokumen'}" class="inline-flex items-center space-x-1.5 text-indigo-700 hover:text-indigo-800 bg-indigo-50 px-2.5 py-1 rounded-lg border border-indigo-200 font-bold" title="Unduh File">
                        <i class="fa-solid fa-file-arrow-down"></i> <span class="truncate max-w-[100px]">${item.fileName || 'Unduh'}</span>
                     </a>` : '<span class="text-slate-400 italic">Tidak Ada File</span>';

                tbody.innerHTML += `
                    <tr class="hover:bg-slate-50 transition">
                        <td class="p-3">
                            <div class="font-bold text-slate-800">${item.tglSuratMasuk || '-'}</div>
                            <div class="text-[10px] text-slate-500 font-medium">Tgl Surat: ${item.tglSurat || '-'}</div>
                        </td>
                        <td class="p-3 font-mono font-bold text-indigo-600">${item.noSurat || '-'}</td>
                        <td class="p-3 font-semibold text-slate-700">${item.perihal || '-'}</td>
                        <td class="p-3 text-slate-600">${item.instansi || '-'}</td>
                        <td class="p-3">${fileBtn}</td>
                        <td class="p-3 text-right">
                            <button type="button" onclick="editSuratMasuk(${item.id})" class="bg-amber-50 hover:bg-amber-500 text-amber-700 hover:text-white px-2.5 py-1.5 rounded-lg font-bold transition border border-amber-200" title="Edit Surat">
                                <i class="fa-solid fa-pen-to-square mr-1"></i> Edit
                            </button>
                        </td>
                    </tr>
                `;
            });
        }

        function handlePortalSubmit(e) {
            e.preventDefault();
            const editId = document.getElementById('editPortalId').value;
            const jam = new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
            const arah = document.getElementById('portalArah').value;
            const plat = document.getElementById('portalPlat').value.toUpperCase();
            const driver = document.getElementById('portalDriver').value;
            const pemeriksaan = document.getElementById('portalPemeriksaan').value || 'Standar aman.';
            const status = document.getElementById('portalStatus').value;

            if (editId) {
                const idx = appState.portalLogs.findIndex(item => item.id == editId);
                if (idx !== -1) {
                    appState.portalLogs[idx] = { ...appState.portalLogs[idx], arah, plat, driver, pemeriksaan, status };
                }
                showToast("Log Ceklis Portal berhasil diperbarui!", "emerald");
            } else {
                appState.portalLogs.unshift({ id: Date.now(), jam, arah, plat, driver, pemeriksaan, status });
                showToast("Log Ceklis Portal berhasil disimpan!", "emerald");
            }

            saveAppData();
            resetPortalForm();
            renderAll();
        }

        function editPortalLog(id) {
            const item = appState.portalLogs.find(p => p.id === id);
            if (!item) return;

            document.getElementById('editPortalId').value = item.id;
            document.getElementById('portalArah').value = item.arah;
            document.getElementById('portalPlat').value = item.plat;
            document.getElementById('portalDriver').value = item.driver;
            document.getElementById('portalPemeriksaan').value = item.pemeriksaan;
            document.getElementById('portalStatus').value = item.status;

            document.getElementById('formPortalTitle').innerText = "Edit Ceklis Portal Gate";
            document.getElementById('btnSubmitPortal').innerHTML = `<i class="fa-solid fa-pen-to-square"></i><span>Update Log Kendaraan</span>`;
            document.getElementById('btnCancelEditPortal').classList.remove('hidden');
        }

        function resetPortalForm() {
            document.getElementById('formPortal').reset();
            document.getElementById('editPortalId').value = '';
            document.getElementById('formPortalTitle').innerText = "Form Ceklis Portal Gate";
            document.getElementById('btnSubmitPortal').innerHTML = `<i class="fa-solid fa-plus-circle"></i><span>Simpan Log Kendaraan</span>`;
            document.getElementById('btnCancelEditPortal').classList.add('hidden');
        }

        function renderPortalTable() {
            const tbody = document.getElementById('portalTableBody');
            const query = (document.getElementById('searchPortal')?.value || '').toLowerCase();
            if (!tbody) return;
            tbody.innerHTML = '';
            const filtered = appState.portalLogs.filter(item => 
                (item.plat && item.plat.toLowerCase().includes(query)) || 
                (item.driver && item.driver.toLowerCase().includes(query))
            );

            if (filtered.length === 0) {
                tbody.innerHTML = `<tr><td colspan="7" class="p-4 text-center text-slate-400 italic font-medium">Belum ada data ceklis portal.</td></tr>`;
                return;
            }

            filtered.forEach(item => {
                const badgeColor = item.arah === 'MASUK' ? 'bg-emerald-100 text-emerald-800 border-emerald-300' : 'bg-blue-100 text-blue-800 border-blue-300';
                tbody.innerHTML += `
                    <tr class="hover:bg-slate-50 transition">
                        <td class="p-3 font-mono text-slate-600 font-semibold">${item.jam || '-'}</td>
                        <td class="p-3"><span class="px-2.5 py-0.5 rounded-md text-[10px] font-extrabold border ${badgeColor}">${item.arah || '-'}</span></td>
                        <td class="p-3 font-extrabold text-slate-800">${item.plat || '-'}</td>
                        <td class="p-3 text-slate-700 font-medium">${item.driver || '-'}</td>
                        <td class="p-3 text-slate-500 truncate max-w-xs">${item.pemeriksaan || '-'}</td>
                        <td class="p-3 font-bold text-emerald-600">${item.status || 'AMAN'}</td>
                        <td class="p-3 text-right">
                            <button type="button" onclick="editPortalLog(${item.id})" class="bg-amber-50 hover:bg-amber-500 text-amber-700 hover:text-white px-2.5 py-1.5 rounded-lg font-bold transition border border-amber-200" title="Edit Data">
                                <i class="fa-solid fa-pen-to-square mr-1"></i> Edit
                            </button>
                        </td>
                    </tr>
                `;
            });
        }

        async function handlePengaduanSubmit(e) {
            e.preventDefault();
            const editId = document.getElementById('editPengaduanId').value;
            const pelapor = document.getElementById('pengaduanPelapor').value;
            const lokasi = document.getElementById('pengaduanLokasi').value;
            const kategori = document.getElementById('pengaduanKategori').value;
            const urgensi = document.getElementById('pengaduanUrgensi').value;
            const status = document.getElementById('pengaduanStatus').value;
            const deskripsi = document.getElementById('pengaduanDeskripsi').value;
            const fotoInput = document.getElementById('pengaduanFoto');

            let fotoData = '';
            if (fotoInput && fotoInput.files[0]) {
                fotoData = await convertBase64(fotoInput.files[0]);
            }

            if (editId) {
                const idx = appState.pengaduanList.findIndex(item => item.id == editId);
                if (idx !== -1) {
                    const existingFoto = appState.pengaduanList[idx].foto || '';
                    appState.pengaduanList[idx] = {
                        ...appState.pengaduanList[idx],
                        pelapor,
                        lokasi,
                        kategori,
                        urgensi,
                        status,
                        deskripsi,
                        foto: fotoData || existingFoto
                    };
                }
                showToast("Pengaduan kendala berhasil diperbarui!", "amber");
            } else {
                const waktu = new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }) + ' WITA';
                const kode = 'KND-' + Math.floor(100 + Math.random() * 900);
                appState.pengaduanList.unshift({
                    id: Date.now(),
                    kode,
                    waktu,
                    pelapor,
                    lokasi,
                    kategori,
                    urgensi,
                    deskripsi,
                    status,
                    catatan: '',
                    foto: fotoData
                });
                showToast("Pengaduan kendala berhasil dikirim!", "rose");
            }

            saveAppData();
            resetPengaduanForm();
            renderAll();
        }

        function editPengaduan(id) {
            const item = appState.pengaduanList.find(p => p.id === id);
            if (!item) return;

            document.getElementById('editPengaduanId').value = item.id;
            document.getElementById('pengaduanPelapor').value = item.pelapor;
            document.getElementById('pengaduanLokasi').value = item.lokasi;
            document.getElementById('pengaduanKategori').value = item.kategori;
            document.getElementById('pengaduanUrgensi').value = item.urgensi;
            document.getElementById('pengaduanStatus').value = item.status;
            document.getElementById('pengaduanDeskripsi').value = item.deskripsi;

            const fotoInfoEl = document.getElementById('pengaduanFotoInfo');
            if (fotoInfoEl) {
                if (item.foto) {
                    fotoInfoEl.innerText = "Foto kendala tersimpan (Upload ulang jika ingin mengganti)";
                    fotoInfoEl.classList.remove('hidden');
                } else {
                    fotoInfoEl.classList.add('hidden');
                }
            }

            document.getElementById('formPengaduanTitle').innerText = "Edit Pengaduan Kendala";
            document.getElementById('btnSubmitPengaduan').innerHTML = `<i class="fa-solid fa-pen-to-square"></i><span>Update Pengaduan</span>`;
            document.getElementById('btnCancelEditPengaduan').classList.remove('hidden');
        }

        function resetPengaduanForm() {
            document.getElementById('formPengaduan').reset();
            document.getElementById('editPengaduanId').value = '';
            const fotoInfoEl = document.getElementById('pengaduanFotoInfo');
            if (fotoInfoEl) fotoInfoEl.classList.add('hidden');
            document.getElementById('formPengaduanTitle').innerText = "Form Pengaduan Kendala";
            document.getElementById('btnSubmitPengaduan').innerHTML = `<i class="fa-solid fa-paper-plane"></i><span>Kirim Pengaduan Kendala</span>`;
            document.getElementById('btnCancelEditPengaduan').classList.add('hidden');
        }

        function renderPengaduanTable() {
            const tbody = document.getElementById('pengaduanTableBody');
            const query = (document.getElementById('searchPengaduan')?.value || '').toLowerCase();
            if (!tbody) return;
            tbody.innerHTML = '';
            const filtered = appState.pengaduanList.filter(item => 
                (item.pelapor && item.pelapor.toLowerCase().includes(query)) || 
                (item.deskripsi && item.deskripsi.toLowerCase().includes(query))
            );

            if (filtered.length === 0) {
                tbody.innerHTML = `<tr><td colspan="6" class="p-4 text-center text-slate-400 italic font-medium">Belum ada data pengaduan.</td></tr>`;
                return;
            }

            filtered.forEach(item => {
                const fotoThumb = item.foto
                    ? `<a href="${item.foto}" target="_blank" class="mt-1.5 inline-flex items-center gap-2 group">
                           <img src="${item.foto}" alt="Foto Kendala" class="w-12 h-12 object-cover rounded-lg border border-slate-200 group-hover:scale-105 transition">
                           <span class="text-[10px] font-bold text-rose-600 underline">Lihat Foto</span>
                       </a>`
                    : `<span class="text-[10px] text-slate-400 italic block mt-1">Tidak ada foto</span>`;

                tbody.innerHTML += `
                    <tr class="hover:bg-slate-50 transition">
                        <td class="p-3 font-mono"><span class="font-bold text-rose-600 block">${item.kode || '-'}</span><span class="text-[10px] text-slate-400">${item.waktu || '-'}</span></td>
                        <td class="p-3"><span class="font-bold text-slate-800 block">${item.pelapor || '-'}</span><span class="text-[11px] text-slate-500">${item.lokasi || '-'}</span></td>
                        <td class="p-3">
                            <span class="text-xs font-bold text-slate-700 block">${item.kategori || '-'}</span>
                            <span class="text-[11px] text-slate-500 truncate max-w-xs block">${item.deskripsi || '-'}</span>
                            ${fotoThumb}
                        </td>
                        <td class="p-3"><span class="px-2.5 py-0.5 rounded-md text-[10px] font-bold border bg-slate-100 border-slate-300 text-slate-700">${item.urgensi || '-'}</span></td>
                        <td class="p-3"><span class="px-2.5 py-0.5 rounded-md text-[10px] font-bold border text-amber-700 bg-amber-50 border-amber-200">${item.status || 'MENUNGGU'}</span></td>
                        <td class="p-3 text-right">
                            <button type="button" onclick="editPengaduan(${item.id})" class="bg-amber-50 hover:bg-amber-500 text-amber-700 hover:text-white px-2.5 py-1.5 rounded-lg font-bold transition border border-amber-200" title="Edit Pengaduan">
                                <i class="fa-solid fa-pen-to-square mr-1"></i> Edit
                            </button>
                        </td>
                    </tr>
                `;
            });
        }
        function renderPengaduanTable() {
            const tbody = document.getElementById('pengaduanTableBody');
            const query = (document.getElementById('searchPengaduan')?.value || '').toLowerCase();
            if (!tbody) return;
            tbody.innerHTML = '';
            const filtered = appState.pengaduanList.filter(item => 
                (item.pelapor && item.pelapor.toLowerCase().includes(query)) || 
                (item.deskripsi && item.deskripsi.toLowerCase().includes(query))
            );

            if (filtered.length === 0) {
                tbody.innerHTML = `<tr><td colspan="6" class="p-4 text-center text-slate-400 italic font-medium">Belum ada data pengaduan.</td></tr>`;
                return;
            }

            filtered.forEach(item => {
                tbody.innerHTML += `
                    <tr class="hover:bg-slate-50 transition">
                        <td class="p-3 font-mono"><span class="font-bold text-rose-600 block">${item.kode || '-'}</span><span class="text-[10px] text-slate-400">${item.waktu || '-'}</span></td>
                        <td class="p-3"><span class="font-bold text-slate-800 block">${item.pelapor || '-'}</span><span class="text-[11px] text-slate-500">${item.lokasi || '-'}</span></td>
                        <td class="p-3"><span class="text-xs font-bold text-slate-700 block">${item.kategori || '-'}</span><span class="text-[11px] text-slate-500 truncate max-w-xs block">${item.deskripsi || '-'}</span></td>
                        <td class="p-3"><span class="px-2.5 py-0.5 rounded-md text-[10px] font-bold border bg-slate-100 border-slate-300 text-slate-700">${item.urgensi || '-'}</span></td>
                        <td class="p-3"><span class="px-2.5 py-0.5 rounded-md text-[10px] font-bold border text-amber-700 bg-amber-50 border-amber-200">${item.status || 'MENUNGGU'}</span></td>
                        <td class="p-3 text-right">
                            <button type="button" onclick="editPengaduan(${item.id})" class="bg-amber-50 hover:bg-amber-500 text-amber-700 hover:text-white px-2.5 py-1.5 rounded-lg font-bold transition border border-amber-200" title="Edit Pengaduan">
                                <i class="fa-solid fa-pen-to-square mr-1"></i> Edit
                            </button>
                        </td>
                    </tr>
                `;
            });
        }

        function handleJadwalSubmit(e) {
            e.preventDefault();
            const editId = document.getElementById('editJadwalId').value;
            const hari = document.getElementById('jadwalHari').value;
            const shift = document.getElementById('jadwalShift').value;
            const pos = document.getElementById('jadwalPos').value;
            const petugas = document.getElementById('jadwalPetugas').value;

            if (editId) {
                const idx = appState.jadwalList.findIndex(item => item.id == editId);
                if (idx !== -1) {
                    appState.jadwalList[idx] = { ...appState.jadwalList[idx], hari, shift, pos, petugas };
                }
                showToast("Jadwal Shift berhasil diperbarui!", "emerald");
            } else {
                appState.jadwalList.unshift({ id: Date.now(), hari, shift, pos, petugas });
                showToast("Jadwal Shift baru berhasil disimpan!", "emerald");
            }

            saveAppData();
            resetJadwalForm();
            renderJadwalTable();
        }

        function editJadwal(id) {
            const item = appState.jadwalList.find(j => j.id === id);
            if (!item) return;

            document.getElementById('editJadwalId').value = item.id;
            document.getElementById('jadwalHari').value = item.hari;
            document.getElementById('jadwalShift').value = item.shift;
            document.getElementById('jadwalPos').value = item.pos;
            document.getElementById('jadwalPetugas').value = item.petugas;

            document.getElementById('formJadwalTitle').innerText = "Edit Jadwal Shift";
            document.getElementById('btnSubmitJadwal').innerHTML = `<i class="fa-solid fa-pen-to-square"></i><span>Update Jadwal Shift</span>`;
            document.getElementById('btnCancelEditJadwal').classList.remove('hidden');
        }

        function resetJadwalForm() {
            document.getElementById('formJadwal').reset();
            document.getElementById('editJadwalId').value = '';
            document.getElementById('formJadwalTitle').innerText = "Form Jadwal Shift";
            document.getElementById('btnSubmitJadwal').innerHTML = `<i class="fa-solid fa-floppy-disk"></i><span>Simpan Jadwal Shift</span>`;
            document.getElementById('btnCancelEditJadwal').classList.add('hidden');
            setTodayDates();
        }

        function renderJadwalTable() {
            const tbody = document.getElementById('jadwalTableBody');
            if (!tbody) return;
            tbody.innerHTML = '';
            appState.jadwalList.forEach(item => {
                tbody.innerHTML += `
                    <tr class="hover:bg-slate-50 transition">
                        <td class="p-3 font-bold text-slate-800">${item.hari || '-'}</td>
                        <td class="p-3"><span class="px-2.5 py-0.5 rounded-md text-[11px] font-bold bg-purple-50 text-purple-700 border border-purple-200">${item.shift || '-'}</span></td>
                        <td class="p-3 text-slate-700 font-medium">${item.pos || '-'}</td>
                        <td class="p-3 text-slate-700 font-medium">${item.petugas || '-'}</td>
                        <td class="p-3 text-right">
                            <button type="button" onclick="editJadwal(${item.id})" class="bg-amber-50 hover:bg-amber-500 text-amber-700 hover:text-white px-2.5 py-1.5 rounded-lg font-bold transition border border-amber-200" title="Edit Jadwal">
                                <i class="fa-solid fa-pen-to-square mr-1"></i> Edit
                            </button>
                        </td>
                    </tr>
                `;
            });
        }

        function renderDashboard() {
            document.getElementById('statPortalCount').innerText = appState.portalLogs.length;
            document.getElementById('statLaporanCount').innerText = dataLaporanGedung.length;
            document.getElementById('statPengaduanCount').innerText = appState.pengaduanList.length;
            document.getElementById('statPendingPengaduan').innerText = appState.pengaduanList.filter(i => i.status === 'MENUNGGU').length + ' Pending';
            document.getElementById('statPetugasCount').innerText = appState.petugasList.length;

            const recentTbody = document.getElementById('recentActivityTable');
            if (!recentTbody) return;
            recentTbody.innerHTML = '';
            
            const combined = [
                ...appState.portalLogs.map(p => ({ waktu: p.jam || '-', kat: `Portal ${p.arah || '-'}`, petugas: p.driver || '-', status: p.status || 'AMAN' })),
                ...appState.suratMasukList.map(s => ({ waktu: s.tglSuratMasuk || '-', kat: `Surat Masuk`, petugas: s.instansi || '-', status: s.noSurat || '-' }))
            ].slice(0, 5);

            if (combined.length === 0) {
                recentTbody.innerHTML = `<tr><td colspan="4" class="p-4 text-center text-slate-400 italic font-medium">Belum ada aktivitas.</td></tr>`;
                return;
            }

            combined.forEach(item => {
                recentTbody.innerHTML += `
                    <tr class="hover:bg-slate-50 transition">
                        <td class="p-3 font-mono text-slate-600 font-semibold">${item.waktu}</td>
                        <td class="p-3 text-slate-700 font-medium">${item.kat}</td>
                        <td class="p-3 text-slate-700 font-medium">${item.petugas}</td>
                        <td class="p-3 font-bold text-indigo-600">${item.status}</td>
                    </tr>
                `;
            });
        }

        function renderAll() {
            renderDashboard();
            renderPortalTable();
            renderPengaduanTable();
            renderJadwalTable();
            renderSuratMasukTable();
            renderPetugasTable();
            renderTabelLaporan();
        }

        function convertBase64(file) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.readAsDataURL(file);
                reader.onload = () => resolve(reader.result);
                reader.onerror = error => reject(error);
            });
        }
    </script>

    <!-- SCRIPT LAPORAN OPERASIONAL GEDUNG -->
    <script>
        const daftarAreaGedung = {
            "Gedung A": ["Teras Depan", "Ruangan VIP", "Ruangan Pegawai", "Samping Kiri Gedung", "Samping Kanan Gedung", "Gudang Belakang Gedung"],
            "Gedung B": ["Teras Depan", "Ruangan Yantek", "Ruangan Pegawai", "Dapur", "Halaman Belakang Gedung", "Samping Kanan Gedung"]
        };

        function bukaFormGedung(namaGedung, existingData = null) {
            document.getElementById('laporanGedungContainer').classList.add('hidden');
            document.getElementById('laporanFormContainer').classList.remove('hidden');
            document.getElementById('labelGedungAktif').innerText = namaGedung;
            document.getElementById('inputGedungTerpilih').value = namaGedung;

            if (existingData) {
                document.getElementById('editLaporanId').value = existingData.id;
                document.getElementById('lapPetugasMasal').value = existingData.petugas;
                document.getElementById('formLaporanHeaderTitle').innerText = "Edit Laporan Operasional:";
                document.getElementById('btnSubmitLaporanGedung').innerHTML = `<i class="fa-solid fa-pen-to-square mr-2"></i><span>Update Laporan Gedung</span>`;
            } else {
                document.getElementById('editLaporanId').value = '';
                document.getElementById('lapPetugasMasal').value = '';
                document.getElementById('formLaporanHeaderTitle').innerText = "Form Laporan Operasional:";
                document.getElementById('btnSubmitLaporanGedung').innerHTML = `<i class="fa-solid fa-paper-plane mr-2"></i><span>Kirim Semua Laporan Gedung</span>`;
            }

            const container = document.getElementById('containerAreaList');
            container.innerHTML = '';
            const areaList = daftarAreaGedung[namaGedung] || [];

            areaList.forEach((area, index) => {
                const oldDetail = existingData && existingData.detail && existingData.detail[index] ? existingData.detail[index] : null;
                const oldKet = oldDetail ? oldDetail.keterangan : '';
                const oldFotoStatus = oldDetail && oldDetail.foto ? `<span class="text-[10px] text-emerald-600 font-semibold block mt-1">Foto tersimpan (Upload ulang jika ingin mengganti)</span>` : '';

                container.innerHTML += `
                    <div class="bg-slate-50 p-4 rounded-2xl border border-slate-200 space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-blue-700 text-xs font-extrabold bg-blue-100 px-3 py-1 rounded-lg">
                                Area ${index + 1}: ${area}
                            </span>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-[11px] font-bold text-slate-600 mb-1">Keterangan / Kondisi Area</label>
                                <input type="text" id="ket_area_${index}" value="${oldKet}" placeholder="Kondisi aman..." class="w-full bg-white border border-slate-200 text-slate-800 rounded-xl px-3 py-2 text-xs outline-none focus:border-blue-600 transition">
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-slate-600 mb-1">Upload Foto Area</label>
                                <input type="file" id="foto_area_${index}" accept="image/*" class="w-full text-xs text-slate-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-blue-600 file:text-white hover:file:bg-blue-500 cursor-pointer border border-slate-200 rounded-xl p-1 bg-white">
                                ${oldFotoStatus}
                            </div>
                        </div>
                    </div>
                `;
            });
        }

        function editLaporanGedung(id) {
            const item = dataLaporanGedung.find(l => l.id === id);
            if (!item) return;
            bukaFormGedung(item.gedung, item);
            document.getElementById('laporanFormContainer').scrollIntoView({ behavior: 'smooth' });
        }

        function tutupFormGedung() {
            document.getElementById('laporanFormContainer').classList.add('hidden');
            document.getElementById('laporanGedungContainer').classList.remove('hidden');
            document.getElementById('formLaporanMasal').reset();
            document.getElementById('editLaporanId').value = '';
        }

        async function kirimLaporanMasal(event) {
            event.preventDefault();
            const editId = document.getElementById('editLaporanId').value;
            const gedung = document.getElementById('inputGedungTerpilih').value;
            const petugas = document.getElementById('lapPetugasMasal').value;
            const skrg = new Date();
            const waktuFormat = skrg.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) + ' ' + skrg.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });

            const existingItem = editId ? dataLaporanGedung.find(l => l.id == editId) : null;
            let detailArea = [];
            const areaList = daftarAreaGedung[gedung] || [];

            for (let i = 0; i < areaList.length; i++) {
                const ket = document.getElementById(`ket_area_${i}`).value || 'Aman / Normal';
                const fileInput = document.getElementById(`foto_area_${i}`);
                let fotoUrl = existingItem && existingItem.detail && existingItem.detail[i] ? existingItem.detail[i].foto : '';
                if (fileInput && fileInput.files[0]) {
                    fotoUrl = await convertBase64(fileInput.files[0]);
                }
                detailArea.push({ namaArea: areaList[i], keterangan: ket, foto: fotoUrl });
            }

            if (editId) {
                const idx = dataLaporanGedung.findIndex(l => l.id == editId);
                if (idx !== -1) {
                    dataLaporanGedung[idx] = { ...dataLaporanGedung[idx], petugas, detail: detailArea };
                }
                showToast(`Laporan ${gedung} berhasil diperbarui!`, "amber");
            } else {
                dataLaporanGedung.unshift({ id: Date.now(), waktu: waktuFormat, gedung, petugas, detail: detailArea });
                showToast(`Laporan ${gedung} berhasil dikirim!`, "emerald");
            }

            saveAppData();
            renderTabelLaporan();
            renderDashboard();
            tutupFormGedung();
        }

        function renderTabelLaporan(dataToRender = dataLaporanGedung) {
            const tbody = document.getElementById('tabelLaporanBody');
            if (!tbody) return;
            tbody.innerHTML = '';

            if (dataToRender.length === 0) {
                tbody.innerHTML = `
                    <tr id="emptyRow">
                        <td colspan="4" class="px-4 py-12 text-center text-slate-400">
                            <div class="flex flex-col items-center justify-center space-y-2">
                                <i class="fa-regular fa-folder-open text-4xl text-slate-300"></i>
                                <span class="text-sm font-medium">Belum ada laporan gedung.</span>
                            </div>
                        </td>
                    </tr>
                `;
                return;
            }

            dataToRender.forEach(item => {
                const badgeGedungColor = item.gedung === 'Gedung A' ? 'bg-blue-100 text-blue-700 border-blue-200' : 'bg-emerald-100 text-emerald-700 border-emerald-200';
                tbody.innerHTML += `
                    <tr class="border-b border-slate-100 hover:bg-slate-50 transition text-xs">
                        <td class="px-4 py-3 text-slate-600 font-semibold whitespace-nowrap"><i class="fa-regular fa-clock text-slate-400 mr-1.5"></i>${item.waktu || '-'}</td>
                        <td class="px-4 py-3 font-bold"><span class="px-2.5 py-1 rounded-md border ${badgeGedungColor}">${item.gedung || '-'}</span></td>
                        <td class="px-4 py-3 text-slate-600"><div class="font-bold text-slate-800 mb-0.5">${item.petugas || '-'}</div><div class="text-[11px] text-slate-500">${(item.detail || []).length} Area Terperiksa</div></td>
                        <td class="px-4 py-3 text-right">
                            <button type="button" onclick="editLaporanGedung(${item.id})" class="bg-amber-50 hover:bg-amber-500 text-amber-700 hover:text-white px-3 py-1.5 rounded-lg font-bold transition cursor-pointer border border-amber-200" title="Edit Laporan">
                                <i class="fa-solid fa-pen-to-square mr-1"></i> Edit
                            </button>
                        </td>
                    </tr>
                `;
            });
        }

        function filterLaporan() {
            const query = document.getElementById('searchLaporan').value.toLowerCase();
            const filtered = dataLaporanGedung.filter(item => 
                (item.gedung && item.gedung.toLowerCase().includes(query)) || 
                (item.petugas && item.petugas.toLowerCase().includes(query))
            );
            renderTabelLaporan(filtered);
        }
    </script>
</body>
</html>