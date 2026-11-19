<?php
session_start();

if (
    !isset($_SESSION["login"]) ||
    $_SESSION["login"] !== true
) {
    header("Location: login.php");
    exit;
}

$role = $_SESSION["role"] ?? "admin";
$username = $_SESSION["username"] ?? "Pengguna Admin";

// --- KONFIGURASI KONEKSI DATABASE MYSQL ---
$host = 'localhost';
$db   = 'db_secops';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Koneksi database gagal: ' . $e->getMessage()]);
        exit;
    }
    die("Koneksi Database Gagal: " . $e->getMessage());
}

// Fungsi helper membaca seluruh data dari database MySQL
function readDatabase($pdo) {
    $data = [
        'portalLogs' => [],
        'tamuLogs' => [],
        'pengaduanList' => [],
        'jadwalList' => [],
        'petugasList' => [],
        'suratMasukList' => [],
        'laporanGedung' => []
    ];

    // 1. Portal Logs
    $stmt = $pdo->query("SELECT id, jam, arah, plat, driver, pemeriksaan, status FROM portal_logs");
    $data['portalLogs'] = $stmt->fetchAll();

    // 2. Tamu Logs
    $stmt = $pdo->query("SELECT id, jam, nama, instansi, tujuan, identitas FROM tamu_logs");
    $data['tamuLogs'] = $stmt->fetchAll();

   // 3. Pengaduan List
    $stmt = $pdo->query("SELECT id, kode, waktu, pelapor, lokasi, kategori, urgensi, deskripsi, status, catatan, foto FROM pengaduan_list");
    $data['pengaduanList'] = $stmt->fetchAll();

    // 4. Jadwal List
    $stmt = $pdo->query("SELECT id, hari, shift, pos, petugas FROM jadwal_list");
    $data['jadwalList'] = $stmt->fetchAll();

    // 5. Petugas List (Mapping username ke nip agar cocok dengan frontend)
    $stmt = $pdo->query("SELECT id, nama, username as nip, role, status FROM petugas");
    $petugas = $stmt->fetchAll();
    foreach ($petugas as &$p) {
        $p['kontak'] = $p['nip'];
    }
    $data['petugasList'] = $petugas;

    // 6. Surat Masuk List (Mapping snake_case ke camelCase)
    $stmt = $pdo->query("SELECT id, tgl_surat_masuk, tgl_surat, no_surat, perihal, instansi, file_data, file_name FROM surat_masuk_list");
    $surat = $stmt->fetchAll();
    $data['suratMasukList'] = array_map(function($s) {
        return [
            'id' => (int)$s['id'],
            'tglSuratMasuk' => $s['tgl_surat_masuk'],
            'tglSurat' => $s['tgl_surat'],
            'noSurat' => $s['no_surat'],
            'perihal' => $s['perihal'],
            'instansi' => $s['instansi'],
            'fileData' => $s['file_data'],
            'fileName' => $s['file_name']
        ];
    }, $surat);

    // 7. Laporan Gedung & Detail
    $stmt = $pdo->query("SELECT id, waktu, gedung, petugas FROM laporan_gedung");
    $laporan = $stmt->fetchAll();
    foreach ($laporan as $lap) {
        $lapId = $lap['id'];
        $stmtDetail = $pdo->prepare("SELECT nama_area, keterangan, foto FROM laporan_gedung_detail WHERE laporan_id = ?");
        $stmtDetail->execute([$lapId]);
        $details = $stmtDetail->fetchAll();
        
        $formattedDetails = array_map(function($d) {
            return [
                'namaArea' => $d['nama_area'],
                'keterangan' => $d['keterangan'],
                'foto' => $d['foto']
            ];
        }, $details);

        $data['laporanGedung'][] = [
            'id' => (int)$lap['id'],
            'waktu' => $lap['waktu'],
            'gedung' => $lap['gedung'],
            'petugas' => $lap['petugas'],
            'detail' => $formattedDetails
        ];
    }

    return $data;
}

// --- BACKEND SERVER PERSISTENCE HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_data') {
    header('Content-Type: application/json');
    $inputRaw = file_get_contents('php://input');
    $inputData = json_decode($inputRaw, true);

    if (is_array($inputData)) {
        try {
            $pdo->beginTransaction();

            // Sync Portal Logs
            if (isset($inputData['portalLogs'])) {
                $pdo->exec("DELETE FROM portal_logs");
                $stmt = $pdo->prepare("INSERT INTO portal_logs (jam, arah, plat, driver, pemeriksaan, status) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($inputData['portalLogs'] as $item) {
                    $stmt->execute([
                        $item['jam'] ?? '',
                        $item['arah'] ?? '',
                        $item['plat'] ?? '',
                        $item['driver'] ?? '',
                        $item['pemeriksaan'] ?? '',
                        $item['status'] ?? 'AMAN'
                    ]);
                }
            }

            // Sync Tamu Logs
            if (isset($inputData['tamuLogs'])) {
                $pdo->exec("DELETE FROM tamu_logs");
                $stmt = $pdo->prepare("INSERT INTO tamu_logs (jam, nama, instansi, tujuan, identitas) VALUES (?, ?, ?, ?, ?)");
                foreach ($inputData['tamuLogs'] as $item) {
                    $stmt->execute([
                        $item['jam'] ?? '',
                        $item['nama'] ?? '',
                        $item['instansi'] ?? '',
                        $item['tujuan'] ?? '',
                        $item['identitas'] ?? ''
                    ]);
                }
            }

            // Sync Pengaduan List (Foto tetap disimpan)
            if (isset($inputData['pengaduanList'])) {
                $pdo->exec("DELETE FROM pengaduan_list");
                $stmt = $pdo->prepare("INSERT INTO pengaduan_list (kode, waktu, pelapor, lokasi, kategori, urgensi, deskripsi, status, catatan, foto) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                foreach ($inputData['pengaduanList'] as $item) {
                    $stmt->execute([
                        $item['kode'] ?? '',
                        $item['waktu'] ?? '',
                        $item['pelapor'] ?? '',
                        $item['lokasi'] ?? '',
                        $item['kategori'] ?? '',
                        $item['urgensi'] ?? '',
                        $item['deskripsi'] ?? '',
                        $item['status'] ?? 'MENUNGGU',
                        $item['catatan'] ?? '',
                        $item['foto'] ?? null
                    ]);
                }
            }

            // Sync Jadwal List
            if (isset($inputData['jadwalList'])) {
                $pdo->exec("DELETE FROM jadwal_list");
                $stmt = $pdo->prepare("INSERT INTO jadwal_list (hari, shift, pos, petugas) VALUES (?, ?, ?, ?)");
                foreach ($inputData['jadwalList'] as $item) {
                    $stmt->execute([
                        $item['hari'] ?? '',
                        $item['shift'] ?? '',
                        $item['pos'] ?? '',
                        $item['petugas'] ?? ''
                    ]);
                }
            }

            // Sync Petugas List
            if (isset($inputData['petugasList'])) {
                $pdo->exec("DELETE FROM petugas");
                $stmt = $pdo->prepare("INSERT INTO petugas (nama, username, role, status) VALUES (?, ?, ?, ?)");
                foreach ($inputData['petugasList'] as $item) {
                    $stmt->execute([
                        $item['nama'] ?? '',
                        $item['nip'] ?? ($item['username'] ?? ''),
                        'satpam',
                        $item['status'] ?? 'AKTIF'
                    ]);
                }
            }

            // Sync Surat Masuk List
            if (isset($inputData['suratMasukList'])) {
                $pdo->exec("DELETE FROM surat_masuk_list");
                $stmt = $pdo->prepare("INSERT INTO surat_masuk_list (tgl_surat_masuk, tgl_surat, no_surat, perihal, instansi, file_data, file_name) VALUES (?, ?, ?, ?, ?, ?, ?)");
                foreach ($inputData['suratMasukList'] as $item) {
                    $stmt->execute([
                        $item['tglSuratMasuk'] ?? '',
                        $item['tglSurat'] ?? '',
                        $item['noSurat'] ?? '',
                        $item['perihal'] ?? '',
                        $item['instansi'] ?? '',
                        $item['fileData'] ?? null,
                        $item['fileName'] ?? null
                    ]);
                }
            }

            // Sync Laporan Gedung & Detail
            if (isset($inputData['laporanGedung'])) {
                $pdo->exec("DELETE FROM laporan_gedung_detail");
                $pdo->exec("DELETE FROM laporan_gedung");
                $stmtLap = $pdo->prepare("INSERT INTO laporan_gedung (waktu, gedung, petugas) VALUES (?, ?, ?)");
                $stmtDet = $pdo->prepare("INSERT INTO laporan_gedung_detail (laporan_id, nama_area, keterangan, foto) VALUES (?, ?, ?, ?)");
                
                foreach ($inputData['laporanGedung'] as $lap) {
                    $stmtLap->execute([
                        $lap['waktu'] ?? '',
                        $lap['gedung'] ?? '',
                        $lap['petugas'] ?? ''
                    ]);
                    $lapId = $pdo->lastInsertId();

                    if (isset($lap['detail']) && is_array($lap['detail'])) {
                        foreach ($lap['detail'] as $det) {
                            $stmtDet->execute([
                                $lapId,
                                $det['namaArea'] ?? '',
                                $det['keterangan'] ?? '',
                                $det['foto'] ?? null
                            ]);
                        }
                    }
                }
            }

            $pdo->commit();
            echo json_encode(['status' => 'success', 'data' => readDatabase($pdo)]);
        } catch (\Exception $e) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan ke database: ' . $e->getMessage()]);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Data tidak valid']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_data') {
    header('Content-Type: application/json');
    echo json_encode(readDatabase($pdo));
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin Monitoring - SECURE-OPS</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- SheetJS (XLSX) untuk Export Excel -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
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
            <span class="font-bold text-lg tracking-wide">SECURE-OPS ADMIN</span>
        </div>
        <button id="mobileMenuBtn" onclick="toggleMobileMenu()" class="text-slate-300 hover:text-white focus:outline-none text-2xl">
            <i class="fa-solid fa-bars"></i>
        </button> 
    </div>

    <!-- Sidebar Navigasi -->
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
                    <span>Pengguna Aktif</span>
                    <i class="fa-solid fa-user-shield text-blue-400"></i>
                </div>
                <div class="flex items-center space-x-3 bg-slate-900 p-2.5 rounded-lg border border-slate-700/50">
                    <div class="w-8 h-8 rounded-full bg-blue-600 text-white flex items-center justify-center font-bold text-xs uppercase shadow">
                        <?= strtoupper(substr($username, 0, 2)) ?>
                    </div>
                    <div class="overflow-hidden">
                        <p class="text-xs font-bold text-white truncate"><?= htmlspecialchars($username) ?></p>
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-blue-400">
                            <?= htmlspecialchars($role) ?>
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
                    <i class="fa-solid fa-users w-5 text-center text-cyan-400"></i>
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
                    <span class="px-2.5 py-1 rounded-full bg-white/20 border border-white/30 backdrop-blur-sm">MODE ADMIN MONITORING</span>
                    <span>•</span>
                    <span id="posCurrent">Pusat Kendali</span>
                </div>
                <h2 id="pageTitle" class="text-2xl md:text-3xl font-extrabold tracking-tight">Ringkasan Utama & Monitoring Harian</h2>
                <p id="pageSubtitle" class="text-blue-100 text-sm mt-1 max-w-xl">Pilih tanggal pada kalender untuk langsung melihat rekap laporan satpam pada tanggal tersebut.</p>
            </div>
            <button type="button" onclick="exportToExcel()" class="bg-emerald-500 hover:bg-emerald-400 text-white text-xs font-bold px-4 py-2.5 rounded-xl transition flex items-center space-x-2 shadow-lg shadow-emerald-900/30">
                <i class="fa-solid fa-file-excel text-sm"></i>
                <span>Tarik Data (Excel)</span>
            </button>
        </div>

        <div id="toastContainer" class="fixed bottom-5 right-5 z-50 space-y-2 pointer-events-none"></div>

        <!-- 1. DASHBOARD OVERVIEW SECTION -->
        <section id="sec-dashboard" class="space-y-6">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm hover:shadow-md transition">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Ceklis Portal (Tanggal Ini)</p>
                            <h3 id="statPortalCount" class="text-3xl font-black text-slate-800 mt-1">0</h3>
                        </div>
                        <div class="p-3 bg-emerald-50 text-emerald-600 rounded-2xl border border-emerald-100">
                            <i class="fa-solid fa-car text-xl"></i>
                        </div>
                    </div>
                    <p class="text-xs text-slate-500 mt-3"><span class="text-emerald-600 font-bold"><i class="fa-solid fa-check"></i> Realtime</span> log kendaraan & tamu</p>
                </div>

                <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm hover:shadow-md transition">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Laporan Gedung</p>
                            <h3 id="statLaporanCount" class="text-3xl font-black text-slate-800 mt-1">0</h3>
                        </div>
                        <div class="p-3 bg-amber-50 text-amber-600 rounded-2xl border border-amber-100">
                            <i class="fa-solid fa-clipboard-list text-xl"></i>
                        </div>
                    </div>
                    <p class="text-xs text-slate-500 mt-3">Gedung A & Gedung B</p>
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
                    <p class="text-xs text-slate-500 mt-3"><span id="statPendingPengaduan" class="text-amber-600 font-bold">0 Pending</span> butuh perhatian</p>
                </div>

                <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm hover:shadow-md transition">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Surat Masuk</p>
                            <h3 id="statSuratCount" class="text-3xl font-black text-indigo-600 mt-1">0</h3>
                        </div>
                        <div class="p-3 bg-indigo-50 text-indigo-600 rounded-2xl border border-indigo-100">
                            <i class="fa-solid fa-envelope-open-text text-xl"></i>
                        </div>
                    </div>
                    <p class="text-xs text-slate-500 mt-3 font-medium">Arsip surat masuk pos</p>
                </div>
            </div>

            <!-- KALENDER REKAP & FEED LAPORAN PER TANGGAL -->
            <div class="grid grid-cols-1 xl:grid-cols-12 gap-6 items-start">
                <!-- KALENDER BULANAN INTERAKTIF -->
                <div class="xl:col-span-5 bg-white p-6 rounded-3xl border border-slate-200 shadow-sm space-y-4">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                        <div>
                            <h3 class="font-bold text-base text-slate-800 flex items-center gap-2">
                                <i class="fa-solid fa-calendar-days text-blue-600"></i>
                                <span>Kalender Rekap Laporan</span>
                            </h3>
                            <p id="calendarMonthLabel" class="text-xs font-semibold text-slate-500 mt-0.5">-</p>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button type="button" onclick="changeCalendarMonth(-1)" class="w-8 h-8 rounded-xl border border-slate-200 hover:bg-slate-100 text-slate-600 flex items-center justify-center transition" title="Bulan Sebelumnya">
                                <i class="fa-solid fa-chevron-left text-xs"></i>
                            </button>
                            <button type="button" onclick="jumpToToday()" class="px-2.5 h-8 rounded-xl bg-blue-50 hover:bg-blue-100 text-blue-700 text-[11px] font-bold transition">
                                Hari Ini
                            </button>
                            <button type="button" onclick="changeCalendarMonth(1)" class="w-8 h-8 rounded-xl border border-slate-200 hover:bg-slate-100 text-slate-600 flex items-center justify-center transition" title="Bulan Berikutnya">
                                <i class="fa-solid fa-chevron-right text-xs"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Date Picker Cepat -->
                    <div class="flex items-center justify-between gap-2 bg-slate-50 p-2.5 rounded-2xl border border-slate-200/80">
                        <label for="quickDatePicker" class="text-[11px] font-bold text-slate-600 whitespace-nowrap">
                            <i class="fa-solid fa-magnifying-glass mr-1 text-blue-600"></i> Pilih Tanggal:
                        </label>
                        <input type="date" id="quickDatePicker" onchange="onQuickDateChange(this.value)" class="bg-white border border-slate-200 rounded-xl px-2.5 py-1 text-xs font-semibold text-slate-700 focus:outline-none focus:border-blue-600">
                    </div>

                    <!-- Nama Hari -->
                    <div class="grid grid-cols-7 gap-1 text-center text-[11px] font-bold text-slate-400 uppercase pt-1">
                        <div>Min</div>
                        <div>Sen</div>
                        <div>Sel</div>
                        <div>Rab</div>
                        <div>Kam</div>
                        <div>Jum</div>
                        <div>Sab</div>
                    </div>

                    <!-- Grid Tanggal -->
                    <div id="calendarGrid" class="grid grid-cols-7 gap-1.5 text-center"></div>

                    <!-- Legenda -->
                    <div class="flex flex-wrap items-center justify-between gap-2 pt-3 border-t border-slate-100 text-[11px] text-slate-500">
                        <div class="flex items-center gap-1.5">
                            <span class="w-2.5 h-2.5 rounded-full bg-blue-600 inline-block"></span>
                            <span>Tanggal Dipilih</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 inline-block"></span>
                            <span>Ada Laporan Satpam</span>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="w-2.5 h-2.5 rounded-full border border-blue-500 inline-block"></span>
                            <span>Hari Ini</span>
                        </div>
                    </div>
                </div>

                <!-- KOTAK UTAMA: REKAPITULASI LAPORAN SATPAM PER TANGGAL -->
                <div class="xl:col-span-7 bg-white p-6 rounded-3xl border border-slate-200 shadow-sm space-y-4">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 border-b border-slate-100 pb-4">
                        <div>
                            <h3 class="font-bold text-lg text-slate-800 flex items-center space-x-2">
                                <i class="fa-solid fa-clipboard-user text-blue-600"></i>
                                <span>Rekapitulasi Laporan Satpam</span>
                            </h3>
                            <p id="selectedDateSubtitle" class="text-xs text-slate-500 mt-1">Menampilkan laporan satpam pada tanggal terpilih.</p>
                        </div>
                        <div class="flex items-center space-x-2">
                            <span class="text-xs bg-blue-50 text-blue-700 font-bold px-3 py-1.5 rounded-xl border border-blue-200">
                                <i class="fa-solid fa-calendar-day mr-1"></i> <span id="currentDateBadge">-</span>
                            </span>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs text-slate-600">
                            <thead class="bg-slate-50 text-slate-500 uppercase font-bold border-b border-slate-200">
                                <tr>
                                    <th class="p-3 rounded-l-xl">Waktu / Jam</th>
                                    <th class="p-3">Kategori Input</th>
                                    <th class="p-3">Petugas / Pelapor</th>
                                    <th class="p-3">Detail / Keterangan</th>
                                    <th class="p-3 rounded-r-xl text-right">Status / Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="allInOneDailyTableBody" class="divide-y divide-slate-100"></tbody>
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

            <!-- KONTEN TAB KENDARAAN -->
            <div id="tabKendaraanContent" class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm space-y-4">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 border-b border-slate-100 pb-3">
                    <div>
                        <h3 class="font-bold text-lg text-slate-800">Monitoring Log Riwayat Kendaraan</h3>
                        <p class="text-xs text-slate-500">Mode Admin: Hanya monitoring dan hapus data.</p>
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
                                <th class="p-3 rounded-r-xl text-right">Aksi (Hapus)</th>
                            </tr>
                        </thead>
                        <tbody id="portalTableBody" class="divide-y divide-slate-100"></tbody>
                    </table>
                </div>
            </div>

            <!-- KONTEN TAB TAMU -->
            <div id="tabTamuContent" class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm space-y-4 hidden">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 border-b border-slate-100 pb-3">
                    <div>
                        <h3 class="font-bold text-lg text-slate-800">Monitoring Log Buku Tamu</h3>
                        <p class="text-xs text-slate-500">Mode Admin: Hanya monitoring dan hapus data.</p>
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
                                <th class="p-3 rounded-r-xl text-right">Aksi (Hapus)</th>
                            </tr>
                        </thead>
                        <tbody id="tamuTableBody" class="divide-y divide-slate-100"></tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- 3. LAPORAN OPERASIONAL GEDUNG -->
        <section id="sec-laporan" class="hidden space-y-6">
            <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm space-y-4">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-3">
                    <div>
                        <h3 class="text-slate-800 font-bold text-base">Monitoring Laporan Operasional Gedung</h3>
                        <p class="text-slate-500 text-xs mt-1">Daftar laporan patroli area Gedung A & B yang diinput oleh satpam</p>
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
                        <tbody id="tabelLaporanBody"></tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- 4. PENGADUAN KENDALA SECTION -->
        <section id="sec-pengaduan" class="hidden space-y-6">
            <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm space-y-4">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 border-b border-slate-100 pb-3">
                    <div>
                        <h3 class="font-bold text-lg text-slate-800">Monitoring Pengaduan Kendala Lapangan</h3>
                        <p class="text-xs text-slate-500">Tanggapi atau hapus aduan kendala operasional</p>
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
                                <th class="p-3">Foto</th>
                                <th class="p-3">Urgensi</th>
                                <th class="p-3">Status</th>
                                <th class="p-3 rounded-r-xl text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="pengaduanTableBody" class="divide-y divide-slate-100"></tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- 5. JADWAL SHIFT & AREA -->
        <section id="sec-jadwal" class="hidden space-y-6">
            <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm space-y-4">
                <div class="border-b border-slate-100 pb-4">
                    <h3 class="font-bold text-lg text-slate-800 flex items-center space-x-2">
                        <i class="fa-solid fa-calendar-days text-blue-600"></i>
                        <span>Monitoring Jadwal Shift & Area Satpam</span>
                    </h3>
                    <p class="text-xs text-slate-500 mt-1">Daftar jadwal kerja tim pengamanan.</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs text-slate-600">
                        <thead class="bg-slate-50 text-slate-500 uppercase font-bold border-b border-slate-200">
                            <tr>
                                <th class="p-3 rounded-l-xl">Hari / Tanggal</th>
                                <th class="p-3">Shift Kerja</th>
                                <th class="p-3">Pos Penugasan</th>
                                <th class="p-3">Petugas Assigned</th>
                                <th class="p-3 rounded-r-xl text-right">Aksi (Hapus)</th>
                            </tr>
                        </thead>
                        <tbody id="jadwalTableBody" class="divide-y divide-slate-100"></tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- 6. MANAJEMEN PETUGAS -->
        <section id="sec-petugas" class="hidden space-y-6">
            <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm space-y-4">
                <div class="border-b border-slate-100 pb-4">
                    <h3 class="font-bold text-lg text-slate-800 flex items-center space-x-2">
                        <i class="fa-solid fa-users text-cyan-600"></i>
                        <span>Monitoring Personil Petugas</span>
                    </h3>
                    <p class="text-xs text-slate-500 mt-1">Daftar anggota tim pengamanan terdaftar.</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs text-slate-600">
                        <thead class="bg-slate-50 text-slate-500 uppercase font-bold border-b border-slate-200">
                            <tr>
                                <th class="p-3 rounded-l-xl">No. REG</th>
                                <th class="p-3">Nama Lengkap</th>
                                <th class="p-3">Kontak / No HP</th>
                                <th class="p-3">Status Keaktifan</th>
                                <th class="p-3 rounded-r-xl text-right">Aksi (Hapus)</th>
                            </tr>
                        </thead>
                        <tbody id="petugasTableBody" class="divide-y divide-slate-100"></tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- 7. MENU SURAT MASUK -->
        <section id="sec-suratmasuk" class="hidden space-y-6">
            <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm space-y-4">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 border-b border-slate-100 pb-3">
                    <div>
                        <h3 class="font-bold text-lg text-slate-800">Monitoring Rekapitulasi Surat Masuk</h3>
                        <p class="text-xs text-slate-500">Arsip digital surat masuk operasional</p>
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
        </section>

    </main>

    <!-- MODAL DETAIL & TANGGAPAN PENGADUAN -->
    <div id="modalPengaduan" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm hidden items-center justify-center z-50 p-4">
        <div class="bg-white border border-slate-200 rounded-3xl p-6 w-full max-w-lg max-h-[90vh] overflow-y-auto space-y-4 shadow-2xl">
            <div class="flex justify-between items-center border-b border-slate-100 pb-3">
                <h3 class="font-bold text-slate-800 text-base flex items-center gap-2">
                    <i class="fa-solid fa-circle-exclamation text-rose-600"></i>
                    <span>Detail & Penanganan Kendala</span>
                </h3>
                <button onclick="tutupModalPengaduan()" class="text-slate-400 hover:text-slate-700"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>
            <div class="space-y-3 text-xs text-slate-600">
                <div class="bg-slate-50 p-4 rounded-2xl border border-slate-200 space-y-1.5">
                    <div class="flex justify-between font-mono text-[11px] text-slate-400">
                        <span id="mPengaduanKode" class="text-rose-600 font-bold">#KND-000</span>
                        <span id="mPengaduanWaktu">-</span>
                    </div>
                    <p class="text-slate-800 font-bold text-sm" id="mPengaduanPelaporLokasi">-</p>
                    <p class="text-slate-500 font-medium" id="mPengaduanKategori">-</p>
                    <div class="mt-2 p-3 bg-white rounded-xl text-slate-700 border border-slate-200" id="mPengaduanDeskripsi"></div>
                    <div id="mPengaduanFotoBox" class="pt-2">
                        <p class="text-[11px] font-bold text-slate-600 mb-1.5">Foto Bukti Kendala:</p>
                        <div id="mPengaduanFotoContainer" class="bg-white p-2.5 rounded-xl border border-slate-200 flex justify-center items-center"></div>
                    </div>
                </div>

                <form id="formTanggapanPengaduan" onsubmit="simpanTanggapanPengaduan(event)" class="space-y-3.5 pt-2">
                    <input type="hidden" id="mPengaduanId">
                    <div>
                        <label class="block text-slate-700 mb-1 font-bold">Ubah Status Penanganan</label>
                        <select id="mPengaduanStatus" class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-slate-800 font-medium focus:border-blue-600 focus:outline-none">
                            <option value="MENUNGGU">PENDING / MENUNGGU</option>
                            <option value="PROSES">DALAM PROSES</option>
                            <option value="SELESAI">SELESAI / TERATASI</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-slate-700 mb-1 font-bold">Catatan / Tindak Lanjut Balasan</label>
                        <textarea id="mPengaduanCatatan" rows="3" placeholder="Masukkan catatan tindakan..." class="w-full bg-slate-50 border border-slate-200 rounded-xl p-2.5 text-slate-800 focus:border-blue-600 focus:outline-none"></textarea>
                    </div>
                    <div class="flex justify-end space-x-2 pt-2 border-t border-slate-100">
                        <button type="button" onclick="tutupModalPengaduan()" class="bg-slate-100 text-slate-700 px-4 py-2 rounded-xl font-bold hover:bg-slate-200 transition">Batal</button>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-500 text-white font-bold px-5 py-2 rounded-xl shadow-lg shadow-blue-600/30 transition">Simpan Perubahan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL DETAIL LAPORAN GEDUNG -->
    <div id="modalDetailLaporan" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white border border-slate-200 rounded-3xl w-full max-w-3xl max-h-[90vh] flex flex-col shadow-2xl overflow-hidden">
            <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50">
                <div>
                    <h3 id="modalTitle" class="text-slate-800 font-bold text-base flex items-center">
                        <i class="fa-solid fa-circle-info text-blue-600 mr-2"></i> Detail Laporan Gedung
                    </h3>
                    <p id="modalSubTitle" class="text-slate-500 text-xs mt-0.5">Informasi lengkap seluruh area pemeriksaan</p>
                </div>
                <button onclick="tutupModalDetail()" class="text-slate-400 hover:text-slate-700 text-lg px-2 py-1 rounded-xl hover:bg-slate-200 transition cursor-pointer">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div id="modalBodyContent" class="p-6 overflow-y-auto space-y-4"></div>
            <div class="p-4 border-t border-slate-100 bg-slate-50 flex justify-end">
                <button onclick="tutupModalDetail()" class="bg-slate-200 hover:bg-slate-300 text-slate-800 px-5 py-2 rounded-xl text-xs font-bold transition cursor-pointer">Tutup</button>
            </div>
        </div>
    </div>

    <!-- SCRIPTS -->
    <script>
        const apiEndpoint = window.location.pathname || 'Admin.php';
        let isDataLoaded = false;
        let tamuLogs = [];
        let appState = {
            role: "<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>",
            activeSection: 'dashboard',
            portalLogs: [],
            pengaduanList: [],
            jadwalList: [],
            petugasList: [],
            suratMasukList: []
        };
        let dataLaporanGedung = [];

        // State Kalender Ringkasan Utama
        function getTodayIsoKey() {
            const now = new Date();
            const y = now.getFullYear();
            const m = String(now.getMonth() + 1).padStart(2, '0');
            const d = String(now.getDate()).padStart(2, '0');
            return `${y}-${m}-${d}`;
        }

        let selectedDateKey = getTodayIsoKey();
        const [initY, initM] = selectedDateKey.split('-').map(Number);
        let calendarViewYear = initY;
        let calendarViewMonth = initM - 1; // 0-based

        const monthNamesId = [
            'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
        ];

        function formatIsoToDisplay(isoKey) {
            if (!isoKey || !/^\d{4}-\d{2}-\d{2}$/.test(isoKey)) return isoKey || '-';
            const [y, m, d] = isoKey.split('-').map(Number);
            const dt = new Date(y, m - 1, d);
            return dt.toLocaleDateString('id-ID', { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' });
        }

        // Mengekstrak tanggal YYYY-MM-DD dari berbagai format string tanggal/jam
        function extractDateKey(rawVal) {
            if (!rawVal || typeof rawVal !== 'string') return getTodayIsoKey();
            const trimmed = rawVal.trim();

            // 1. Format ISO / SQL: YYYY-MM-DD atau YYYY/MM/DD
            const isoMatch = trimmed.match(/\b(\d{4})[-/](\d{1,2})[-/](\d{1,2})\b/);
            if (isoMatch) {
                const y = isoMatch[1];
                const m = String(isoMatch[2]).padStart(2, '0');
                const d = String(isoMatch[3]).padStart(2, '0');
                return `${y}-${m}-${d}`;
            }

            // 2. Format Indonesia/Eropa: DD-MM-YYYY atau DD/MM/YYYY
            const dmyMatch = trimmed.match(/\b(\d{1,2})[-/](\d{1,2})[-/](\d{4})\b/);
            if (dmyMatch) {
                const d = String(dmyMatch[1]).padStart(2, '0');
                const m = String(dmyMatch[2]).padStart(2, '0');
                const y = dmyMatch[3];
                return `${y}-${m}-${d}`;
            }

            // 3. Format nama bulan (ID / EN), misal: "19 Nov 2026" atau "19 November 2026, 10:30"
            const monthMap = {
                jan: '01', januari: '01', january: '01',
                feb: '02', februari: '02', february: '02',
                mar: '03', maret: '03', march: '03',
                apr: '04', april: '04',
                mei: '05', may: '05',
                jun: '06', juni: '06', june: '06',
                jul: '07', juli: '07', july: '07',
                agu: '08', ags: '08', agustus: '08', aug: '08', august: '08',
                sep: '09', sept: '09', september: '09',
                okt: '10', oktober: '10', oct: '10', october: '10',
                nov: '11', november: '11',
                des: '12', desember: '12', dec: '12', december: '12'
            };
            const textMonthMatch = trimmed.toLowerCase().match(/\b(\d{1,2})\s+([a-z]+)\s+(\d{4})\b/);
            if (textMonthMatch && monthMap[textMonthMatch[2]]) {
                const d = String(textMonthMatch[1]).padStart(2, '0');
                const m = monthMap[textMonthMatch[2]];
                const y = textMonthMatch[3];
                return `${y}-${m}-${d}`;
            }

            // 4. Jika hanya format jam (HH:MM atau HH:MM:SS), anggap milik hari ini
            if (/^\d{1,2}:\d{2}(:\d{2})?$/.test(trimmed)) {
                return getTodayIsoKey();
            }

            // 5. Fallback Date.parse jika valid
            const parsed = new Date(trimmed);
            if (!isNaN(parsed.getTime())) {
                const y = parsed.getFullYear();
                const m = String(parsed.getMonth() + 1).padStart(2, '0');
                const d = String(parsed.getDate()).padStart(2, '0');
                return `${y}-${m}-${d}`;
            }

            return getTodayIsoKey();
        }

        // Mengumpulkan semua input satpam per tanggal
        function getAllInputsGroupedByDate() {
            const grouped = {};

            const pushEntry = (dateKey, entry) => {
                if (!grouped[dateKey]) grouped[dateKey] = [];
                grouped[dateKey].push(entry);
            };

            (appState.portalLogs || []).forEach(p => {
                const dKey = extractDateKey(p.jam);
                pushEntry(dKey, {
                    waktu: p.jam || '-',
                    kategori: `Portal (${p.arah || '-'})`,
                    petugas: p.driver || '-',
                    detail: `Plat: ${p.plat || '-'} - ${p.pemeriksaan || '-'}`,
                    statusHtml: `<span class="px-2.5 py-0.5 rounded-md text-[10px] font-bold bg-emerald-100 text-emerald-800">${p.status || 'AMAN'}</span>`,
                    type: 'portal'
                });
            });

            (tamuLogs || []).forEach(t => {
                const dKey = extractDateKey(t.jam);
                pushEntry(dKey, {
                    waktu: t.jam || '-',
                    kategori: 'Buku Tamu',
                    petugas: t.nama || '-',
                    detail: `Instansi: ${t.instansi || '-'} | Tujuan: ${t.tujuan || '-'} (${t.identitas || '-'})`,
                    statusHtml: `<span class="px-2.5 py-0.5 rounded-md text-[10px] font-bold bg-blue-100 text-blue-800">MASUK</span>`,
                    type: 'tamu'
                });
            });

            (dataLaporanGedung || []).forEach(l => {
                const dKey = extractDateKey(l.waktu);
                pushEntry(dKey, {
                    waktu: l.waktu || '-',
                    kategori: `Laporan ${l.gedung || '-'}`,
                    petugas: l.petugas || '-',
                    detail: `${(l.detail || []).length} Area Terperiksa`,
                    statusHtml: `
                        <div class="inline-flex items-center gap-1.5">
                            <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-amber-100 text-amber-800">SELESAI</span>
                            <button type="button" onclick="lihatDetailLaporan(${l.id})" class="px-2.5 py-1 rounded-lg text-[10px] font-bold bg-blue-50 hover:bg-blue-600 text-blue-700 hover:text-white border border-blue-200 transition cursor-pointer" title="Lihat Detail Laporan">
                                <i class="fa-solid fa-eye mr-1"></i>Detail
                            </button>
                        </div>
                    `,
                    type: 'laporan',
                    laporanId: l.id
                });
            });

            (appState.pengaduanList || []).forEach(pg => {
                const dKey = extractDateKey(pg.waktu);
                pushEntry(dKey, {
                    waktu: pg.waktu || '-',
                    kategori: `Pengaduan (${pg.kode || '-'})`,
                    petugas: pg.pelapor || '-',
                    detail: `[${pg.lokasi || '-'}] ${pg.kategori || '-'}: ${pg.deskripsi || '-'}`,
                    statusHtml: `<span class="px-2.5 py-0.5 rounded-md text-[10px] font-bold bg-rose-100 text-rose-800">${pg.status || 'MENUNGGU'}</span>`,
                    type: 'pengaduan'
                });
            });

            (appState.suratMasukList || []).forEach(s => {
                const dKey = extractDateKey(s.tglSuratMasuk || s.tglSurat);
                pushEntry(dKey, {
                    waktu: s.tglSuratMasuk || '-',
                    kategori: 'Surat Masuk',
                    petugas: s.instansi || '-',
                    detail: `No: ${s.noSurat || '-'} - Perihal: ${s.perihal || '-'}`,
                    statusHtml: `<span class="px-2.5 py-0.5 rounded-md text-[10px] font-bold bg-indigo-100 text-indigo-800">ARSIP</span>`,
                    type: 'surat'
                });
            });

            return grouped;
        }

        function renderCalendar(groupedInputs) {
            const grid = document.getElementById('calendarGrid');
            const monthLabel = document.getElementById('calendarMonthLabel');
            const quickPicker = document.getElementById('quickDatePicker');
            if (!grid || !monthLabel) return;

            monthLabel.innerText = `${monthNamesId[calendarViewMonth]} ${calendarViewYear}`;
            if (quickPicker && quickPicker.value !== selectedDateKey) {
                quickPicker.value = selectedDateKey;
            }

            const firstDayOfMonth = new Date(calendarViewYear, calendarViewMonth, 1).getDay();
            const daysInMonth = new Date(calendarViewYear, calendarViewMonth + 1, 0).getDate();
            const todayKey = getTodayIsoKey();

            grid.innerHTML = '';

            // Sel kosong sebelum tanggal 1
            for (let i = 0; i < firstDayOfMonth; i++) {
                const emptyCell = document.createElement('div');
                emptyCell.className = 'h-11 rounded-xl bg-slate-50/50';
                grid.appendChild(emptyCell);
            }

            for (let day = 1; day <= daysInMonth; day++) {
                const mm = String(calendarViewMonth + 1).padStart(2, '0');
                const dd = String(day).padStart(2, '0');
                const dateKey = `${calendarViewYear}-${mm}-${dd}`;
                const itemsForDay = groupedInputs[dateKey] || [];
                const count = itemsForDay.length;

                const isSelected = dateKey === selectedDateKey;
                const isToday = dateKey === todayKey;

                let btnClass = 'h-11 rounded-xl border text-xs font-bold flex flex-col items-center justify-center relative transition cursor-pointer ';
                if (isSelected) {
                    btnClass += 'bg-blue-600 text-white border-blue-600 shadow-md shadow-blue-600/30 ';
                } else if (isToday) {
                    btnClass += 'bg-blue-50 text-blue-700 border-blue-300 hover:bg-blue-100 ';
                } else if (count > 0) {
                    btnClass += 'bg-emerald-50/70 text-slate-800 border-emerald-200 hover:bg-emerald-100 ';
                } else {
                    btnClass += 'bg-white text-slate-600 border-slate-100 hover:bg-slate-50 ';
                }

                const dotColor = isSelected ? 'bg-white' : 'bg-emerald-500';
                const indicatorHtml = count > 0
                    ? `<span class="mt-0.5 inline-flex items-center gap-0.5 text-[9px] font-extrabold ${isSelected ? 'text-blue-100' : 'text-emerald-700'}"><span class="w-1.5 h-1.5 rounded-full ${dotColor}"></span>${count}</span>`
                    : `<span class="mt-0.5 h-1.5"></span>`;

                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = btnClass;
                btn.title = count > 0 ? `${count} laporan/aktivitas pada ${dateKey}` : `Tidak ada laporan pada ${dateKey}`;
                btn.onclick = () => selectCalendarDate(dateKey);
                btn.innerHTML = `<span>${day}</span>${indicatorHtml}`;

                grid.appendChild(btn);
            }
        }

        function selectCalendarDate(dateKey) {
            selectedDateKey = dateKey;
            const [y, m] = dateKey.split('-').map(Number);
            calendarViewYear = y;
            calendarViewMonth = m - 1;
            renderDashboard();
            showToast(`Menampilkan laporan tanggal ${formatIsoToDisplay(dateKey)}`, 'blue');
        }

        function changeCalendarMonth(delta) {
            calendarViewMonth += delta;
            if (calendarViewMonth < 0) {
                calendarViewMonth = 11;
                calendarViewYear -= 1;
            } else if (calendarViewMonth > 11) {
                calendarViewMonth = 0;
                calendarViewYear += 1;
            }
            renderCalendar(getAllInputsGroupedByDate());
        }

        function jumpToToday() {
            selectCalendarDate(getTodayIsoKey());
        }

        function onQuickDateChange(val) {
            if (val && /^\d{4}-\d{2}-\d{2}$/.test(val)) {
                selectCalendarDate(val);
            }
        }

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

        function deleteTamuItem(id) {
            tamuLogs = tamuLogs.filter(item => item.id !== id);
            saveAppData();
            renderTamuTable();
            renderDashboard();
            showToast("Data tamu dihapus.", "rose");
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
                            <button type="button" onclick="deleteTamuItem(${item.id})" class="text-rose-500 hover:text-rose-700 p-1.5 transition" title="Hapus Data">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
        }

        async function fetchServerData() {
            try {
                const response = await fetch(`${apiEndpoint}?action=get_data`);
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
                await fetch(`${apiEndpoint}?action=save_data`, {
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
            fetchServerData();
            setInterval(fetchServerData, 3000);
        };

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
            const sections = ['dashboard', 'portal', 'laporan', 'pengaduan', 'jadwal', 'petugas', 'suratmasuk'];
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
                'dashboard': ['Ringkasan Utama & Monitoring Harian', 'Pilih tanggal pada kalender untuk langsung melihat rekap laporan satpam pada tanggal tersebut.'],
                'portal': ['Ceklis Portal Post', 'Monitoring Log Kendaraan & Buku Tamu Pos Keamanan'],
                'laporan': ['Laporan Operasional Gedung', 'Monitoring Pemeriksaan Terperinci Area Gedung A dan Gedung B'],
                'pengaduan': ['Pengaduan Kendala', 'Monitoring Gangguan Operasional & Penanganan Sarpras'],
                'jadwal': ['Jadwal Shift & Area', 'Monitoring Rotasi Penugasan Pos Keamanan'],
                'petugas': ['Manajemen Personil Satpam', 'Monitoring Data Anggota & Status Keaktifan'],
                'suratmasuk': ['Surat Masuk', 'Monitoring Arsip Surat Masuk Operasional']
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
                blue: 'bg-blue-600 text-white shadow-blue-600/30',
                rose: 'bg-rose-600 text-white shadow-rose-600/30',
                indigo: 'bg-indigo-600 text-white shadow-indigo-600/30'
            };
            toast.className = `${bgColors[type] || bgColors.emerald} px-4 py-3 rounded-2xl shadow-xl text-xs font-bold flex items-center space-x-2 transition-all duration-300`;
            toast.innerHTML = `<i class="fa-solid fa-circle-check"></i><span>${message}</span>`;
            container.appendChild(toast);
            setTimeout(() => { toast.remove(); }, 3000);
        }

        function renderDashboard() {
            const groupedInputs = getAllInputsGroupedByDate();
            renderCalendar(groupedInputs);

            const displayDate = formatIsoToDisplay(selectedDateKey);
            const dateBadge = document.getElementById('currentDateBadge');
            const subtitleEl = document.getElementById('selectedDateSubtitle');
            if (dateBadge) dateBadge.innerText = displayDate;
            if (subtitleEl) {
                subtitleEl.innerText = `Menampilkan laporan & aktivitas satpam untuk tanggal ${displayDate} (${selectedDateKey}).`;
            }

            // Hitung statistik sesuai tanggal yang dipilih
            const portalForDate = (appState.portalLogs || []).filter(p => extractDateKey(p.jam) === selectedDateKey);
            const tamuForDate = (tamuLogs || []).filter(t => extractDateKey(t.jam) === selectedDateKey);
            const laporanForDate = (dataLaporanGedung || []).filter(l => extractDateKey(l.waktu) === selectedDateKey);
            const pengaduanForDate = (appState.pengaduanList || []).filter(pg => extractDateKey(pg.waktu) === selectedDateKey);
            const suratForDate = (appState.suratMasukList || []).filter(s => extractDateKey(s.tglSuratMasuk || s.tglSurat) === selectedDateKey);

            document.getElementById('statPortalCount').innerText = portalForDate.length + tamuForDate.length;
            document.getElementById('statLaporanCount').innerText = laporanForDate.length;
            document.getElementById('statPengaduanCount').innerText = pengaduanForDate.length;
            document.getElementById('statPendingPengaduan').innerText = pengaduanForDate.filter(i => i.status === 'MENUNGGU').length + ' Pending';
            document.getElementById('statSuratCount').innerText = suratForDate.length;

            const dailyTableBody = document.getElementById('allInOneDailyTableBody');
            if (!dailyTableBody) return;
            dailyTableBody.innerHTML = '';

            const dayInputs = groupedInputs[selectedDateKey] || [];

            if (dayInputs.length === 0) {
                dailyTableBody.innerHTML = `
                    <tr>
                        <td colspan="5" class="p-8 text-center text-slate-400 italic font-medium">
                            <i class="fa-regular fa-calendar-xmark text-2xl text-slate-300 block mb-2"></i>
                            Belum ada laporan atau aktivitas dari satpam pada tanggal <strong>${displayDate}</strong>.
                        </td>
                    </tr>
                `;
                return;
            }

            dayInputs.forEach(item => {
                dailyTableBody.innerHTML += `
                    <tr class="hover:bg-slate-50 transition">
                        <td class="p-3 font-mono font-semibold text-slate-600 whitespace-nowrap">${item.waktu}</td>
                        <td class="p-3 font-bold text-blue-700 whitespace-nowrap">${item.kategori}</td>
                        <td class="p-3 font-semibold text-slate-800">${item.petugas}</td>
                        <td class="p-3 text-slate-600">${item.detail}</td>
                        <td class="p-3 text-right whitespace-nowrap">${item.statusHtml}</td>
                    </tr>
                `;
            });
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
                            <button type="button" onclick="hapusPortalLog(${item.id})" class="text-rose-500 hover:text-rose-700 p-1.5 transition" title="Hapus Data"><i class="fa-solid fa-trash"></i></button>
                        </td>
                    </tr>
                `;
            });
        }

        function hapusPortalLog(id) {
            appState.portalLogs = appState.portalLogs.filter(i => i.id !== id);
            saveAppData();
            renderAll();
            showToast("Data log portal dihapus.", "rose");
        }

        function renderTabelLaporan(dataToRender = dataLaporanGedung) {
            const tbody = document.getElementById('tabelLaporanBody');
            if (!tbody) return;
            tbody.innerHTML = '';

            if (dataToRender.length === 0) {
                tbody.innerHTML = `
                    <tr>
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
                        <td class="px-4 py-3 text-right space-x-2">
                            <button type="button" onclick="lihatDetailLaporan(${item.id})" class="bg-blue-50 hover:bg-blue-600 text-blue-700 hover:text-white px-3 py-1.5 rounded-lg font-bold transition cursor-pointer border border-blue-200"><i class="fa-solid fa-eye mr-1"></i> Detail</button>
                            <button type="button" onclick="hapusLaporan(${item.id})" class="bg-rose-50 hover:bg-rose-600 text-rose-600 hover:text-white px-2.5 py-1.5 rounded-lg transition cursor-pointer border border-rose-200"><i class="fa-solid fa-trash"></i></button>
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

        function hapusLaporan(id) {
            if (confirm("Hapus laporan ini?")) {
                dataLaporanGedung = dataLaporanGedung.filter(item => item.id !== id);
                saveAppData();
                renderTabelLaporan();
                renderDashboard();
                showToast("Laporan dihapus.", "rose");
            }
        }

        function lihatDetailLaporan(id) {
            const item = dataLaporanGedung.find(l => Number(l.id) === Number(id));
            if (!item) return;
            document.getElementById('modalSubTitle').innerText = `${item.gedung} — Petugas: ${item.petugas} (${item.waktu})`;
            const containerBody = document.getElementById('modalBodyContent');
            containerBody.innerHTML = '';

            (item.detail || []).forEach((d, idx) => {
                let bagianFoto = d.foto ? `<div class="mt-2 flex justify-center bg-slate-100 p-2 rounded-xl border border-slate-200"><img src="${d.foto}" class="max-h-64 object-contain rounded-lg"></div>` : `<div class="mt-2 text-[11px] text-slate-400 italic">Tidak ada foto.</div>`;
                containerBody.innerHTML += `
                    <div class="bg-slate-50 p-4 rounded-2xl border border-slate-200 space-y-2">
                        <span class="text-xs font-bold text-blue-700 bg-blue-100 px-2.5 py-1 rounded-lg">Area ${idx + 1}: ${d.namaArea || '-'}</span>
                        <div class="text-xs text-slate-700"><strong class="text-slate-500">Keterangan:</strong> ${d.keterangan || '-'}</div>
                        ${bagianFoto}
                    </div>
                `;
            });
            document.getElementById('modalDetailLaporan').classList.remove('hidden');
        }

        function tutupModalDetail() {
            document.getElementById('modalDetailLaporan').classList.add('hidden');
        }

        // --- RENDER TABEL PENGADUAN DENGAN KOLOM FOTO TERPISAH ---
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
                tbody.innerHTML = `<tr><td colspan="7" class="p-4 text-center text-slate-400 italic font-medium">Belum ada data pengaduan.</td></tr>`;
                return;
            }

            filtered.forEach(item => {
                // Kolom Foto Terpisah
                const fotoCol = item.foto
                    ? `<div class="flex items-center space-x-2">
                           <img src="${item.foto}" alt="Foto Kendala" onclick="bukaModalPengaduan(${item.id})" class="w-10 h-10 object-cover rounded-lg border border-slate-200 cursor-pointer hover:scale-105 transition" title="Klik untuk lihat foto">
                           <button type="button" onclick="bukaModalPengaduan(${item.id})" class="text-[11px] font-bold text-blue-600 hover:underline cursor-pointer">Lihat Foto</button>
                       </div>`
                    : `<span class="text-[10px] text-slate-400 italic">Tidak ada foto</span>`;

                tbody.innerHTML += `
                    <tr class="hover:bg-slate-50 transition">
                        <td class="p-3 font-mono"><span class="font-bold text-rose-600 block">${item.kode || '-'}</span><span class="text-[10px] text-slate-400">${item.waktu || '-'}</span></td>
                        <td class="p-3"><span class="font-bold text-slate-800 block">${item.pelapor || '-'}</span><span class="text-[11px] text-slate-500">${item.lokasi || '-'}</span></td>
                        <td class="p-3"><span class="text-xs font-bold text-slate-700 block">${item.kategori || '-'}</span><span class="text-[11px] text-slate-500 truncate max-w-xs block">${item.deskripsi || '-'}</span></td>
                        <td class="p-3">${fotoCol}</td>
                        <td class="p-3"><span class="px-2.5 py-0.5 rounded-md text-[10px] font-bold border bg-slate-100 border-slate-300 text-slate-700">${item.urgensi || '-'}</span></td>
                        <td class="p-3"><span class="px-2.5 py-0.5 rounded-md text-[10px] font-bold border text-amber-700 bg-amber-50 border-amber-200">${item.status || 'MENUNGGU'}</span></td>
                        <td class="p-3 text-right space-x-1">
                            <button type="button" onclick="bukaModalPengaduan(${item.id})" class="text-blue-600 hover:text-blue-800 p-1.5 transition" title="Tanggapi & Lihat Foto"><i class="fa-solid fa-pen-to-square"></i></button>
                            <button type="button" onclick="hapusPengaduan(${item.id})" class="text-rose-500 hover:text-rose-700 p-1.5 transition" title="Hapus"><i class="fa-solid fa-trash"></i></button>
                        </td>
                    </tr>
                `;
            });
        }

        function bukaModalPengaduan(id) {
            const item = appState.pengaduanList.find(i => Number(i.id) === Number(id));
            if (!item) return;
            document.getElementById('mPengaduanId').value = item.id;
            document.getElementById('mPengaduanKode').innerText = '#' + (item.kode || 'KND-000');
            document.getElementById('mPengaduanWaktu').innerText = item.waktu || '-';
            document.getElementById('mPengaduanPelaporLokasi').innerText = `${item.pelapor || '-'} (${item.lokasi || '-'})`;
            document.getElementById('mPengaduanKategori').innerText = `Kategori: ${item.kategori || '-'}`;
            document.getElementById('mPengaduanDeskripsi').innerText = item.deskripsi || '-';

            const fotoContainer = document.getElementById('mPengaduanFotoContainer');
            if (fotoContainer) {
                if (item.foto) {
                    fotoContainer.innerHTML = `
                        <div class="space-y-2 w-full text-center">
                            <img src="${item.foto}" alt="Foto Kendala" class="max-h-64 mx-auto object-contain rounded-xl border border-slate-200">
                            <a href="${item.foto}" target="_blank" class="inline-flex items-center gap-1 text-[11px] font-bold text-blue-600 hover:underline">
                                <i class="fa-solid fa-up-right-from-square"></i> Buka Foto Ukuran Penuh
                            </a>
                        </div>
                    `;
                } else {
                    fotoContainer.innerHTML = `<span class="text-[11px] text-slate-400 italic py-2">Tidak ada foto yang diupload oleh satpam.</span>`;
                }
            }

            document.getElementById('mPengaduanStatus').value = item.status || 'MENUNGGU';
            document.getElementById('mPengaduanCatatan').value = item.catatan || '';
            document.getElementById('modalPengaduan').classList.remove('hidden');
            document.getElementById('modalPengaduan').classList.add('flex');
        }

        function tutupModalPengaduan() {
            document.getElementById('modalPengaduan').classList.remove('flex');
            document.getElementById('modalPengaduan').classList.add('hidden');
        }

        function simpanTanggapanPengaduan(e) {
            e.preventDefault();
            const id = Number(document.getElementById('mPengaduanId').value);
            const item = appState.pengaduanList.find(i => Number(i.id) === id);
            if (item) {
                item.status = document.getElementById('mPengaduanStatus').value;
                item.catatan = document.getElementById('mPengaduanCatatan').value;
            }
            saveAppData();
            tutupModalPengaduan();
            renderAll();
            showToast("Status pengaduan diperbarui!", "blue");
        }

        function hapusPengaduan(id) {
            if (confirm("Hapus pengaduan ini?")) {
                appState.pengaduanList = appState.pengaduanList.filter(i => i.id !== id);
                saveAppData();
                renderAll();
                showToast("Pengaduan dihapus.", "rose");
            }
        }

        function renderJadwalTable() {
            const tbody = document.getElementById('jadwalTableBody');
            if (!tbody) return;
            tbody.innerHTML = '';
            appState.jadwalList.forEach(item => {
                tbody.innerHTML += `
                    <tr class="hover:bg-slate-50 transition">
                        <td class="p-3 font-bold text-slate-800">${item.hari || '-'}</td>
                        <td class="p-3"><span class="px-2.5 py-0.5 rounded-md text-[11px] font-bold bg-blue-50 text-blue-700 border border-blue-200">${item.shift || '-'}</span></td>
                        <td class="p-3 text-slate-700 font-medium">${item.pos || '-'}</td>
                        <td class="p-3 text-slate-700 font-medium">${item.petugas || '-'}</td>
                        <td class="p-3 text-right"><button type="button" onclick="hapusJadwal(${item.id})" class="text-rose-500 hover:text-rose-700 p-1.5 transition"><i class="fa-solid fa-trash"></i></button></td>
                    </tr>
                `;
            });
        }

        function hapusJadwal(id) {
            appState.jadwalList = appState.jadwalList.filter(i => i.id !== id);
            saveAppData();
            renderAll();
            showToast("Jadwal dihapus.", "rose");
        }

        function renderPetugasTable() {
            const tbody = document.getElementById('petugasTableBody');
            if (!tbody) return;
            tbody.innerHTML = '';
            appState.petugasList.forEach(item => {
                tbody.innerHTML += `
                    <tr class="hover:bg-slate-50 transition">
                        <td class="p-3 font-mono text-cyan-700 font-bold">${item.nip || '-'}</td>
                        <td class="p-3 font-bold text-slate-800">${item.nama || '-'}</td>
                        <td class="p-3 text-slate-600">${item.kontak || '-'}</td>
                        <td class="p-3"><span class="px-2.5 py-0.5 rounded-md text-[10px] font-bold border bg-emerald-50 text-emerald-700 border-emerald-200">${item.status || 'AKTIF'}</span></td>
                        <td class="p-3 text-right"><button type="button" onclick="hapusPetugas(${item.id})" class="text-rose-500 hover:text-rose-700 p-1.5 transition"><i class="fa-solid fa-trash"></i></button></td>
                    </tr>
                `;
            });
        }

        function hapusPetugas(id) {
            appState.petugasList = appState.petugasList.filter(i => i.id !== id);
            saveAppData();
            renderAll();
            showToast("Petugas dihapus.", "rose");
        }

        function hapusSuratMasuk(id) {
            if (confirm("Apakah Anda yakin ingin menghapus arsip surat ini?")) {
                appState.suratMasukList = appState.suratMasukList.filter(s => s.id !== id);
                saveAppData();
                renderSuratMasukTable();
                renderDashboard();
                showToast("Surat masuk berhasil dihapus.", "rose");
            }
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
                            <button type="button" onclick="hapusSuratMasuk(${item.id})" class="text-rose-500 hover:text-rose-700 p-1.5 transition" title="Hapus Data"><i class="fa-solid fa-trash"></i></button>
                        </td>
                    </tr>
                `;
            });
        }

        function renderAll() {
            renderDashboard();
            renderPortalTable();
            renderPengaduanTable();
            renderJadwalTable();
            renderPetugasTable();
            renderSuratMasukTable();
            renderTabelLaporan();
        }

        function exportToExcel() {
            try {
                const formattedPortal = (appState.portalLogs || []).map((item, idx) => ({
                    'No': idx + 1,
                    'Jam': item.jam || '-',
                    'Arah': item.arah || '-',
                    'Plat Nomor': item.plat || '-',
                    'Pengemudi / Jenis': item.driver || '-',
                    'Pemeriksaan Barang': item.pemeriksaan || '-',
                    'Status': item.status || 'AMAN'
                }));

                const formattedTamu = (tamuLogs || []).map((item, idx) => ({
                    'No': idx + 1,
                    'Jam Masuk': item.jam || '-',
                    'Nama Tamu': item.nama || '-',
                    'Asal Instansi': item.instansi || '-',
                    'Bertemu / Tujuan': item.tujuan || '-',
                    'No. Identitas': item.identitas || '-'
                }));

                const formattedLaporan = [];
                (dataLaporanGedung || []).forEach((lap, idx) => {
                    const details = lap.detail || [];
                    if (details.length > 0) {
                        details.forEach((d, dIdx) => {
                            formattedLaporan.push({
                                'No. Laporan': idx + 1,
                                'Waktu Inspeksi': lap.waktu || '-',
                                'Gedung': lap.gedung || '-',
                                'Petugas': lap.petugas || '-',
                                'No. Area': dIdx + 1,
                                'Nama Area': d.namaArea || '-',
                                'Keterangan / Kondisi': d.keterangan || '-',
                                'Foto Lampiran': d.foto ? 'Ada Foto (Base64)' : 'Tidak Ada Foto'
                            });
                        });
                    } else {
                        formattedLaporan.push({
                            'No. Laporan': idx + 1,
                            'Waktu Inspeksi': lap.waktu || '-',
                            'Gedung': lap.gedung || '-',
                            'Petugas': lap.petugas || '-',
                            'No. Area': '-',
                            'Nama Area': 'Tidak ada detail',
                            'Keterangan / Kondisi': '-',
                            'Foto Lampiran': '-'
                        });
                    }
                });

                const formattedPengaduan = (appState.pengaduanList || []).map((item, idx) => ({
                    'No': idx + 1,
                    'Kode Tiket': item.kode || '-',
                    'Waktu Laporan': item.waktu || '-',
                    'Pelapor': item.pelapor || '-',
                    'Lokasi / Pos': item.lokasi || '-',
                    'Kategori Kendala': item.kategori || '-',
                    'Tingkat Urgensi': item.urgensi || '-',
                    'Status Penanganan': item.status || 'MENUNGGU',
                    'Deskripsi Masalah': item.deskripsi || '-',
                    'Catatan / Tindak Lanjut Admin': item.catatan || '-'
                }));

                const formattedJadwal = (appState.jadwalList || []).map((item, idx) => ({
                    'No': idx + 1,
                    'Hari / Tanggal': item.hari || '-',
                    'Shift Kerja': item.shift || '-',
                    'Pos Penugasan': item.pos || '-',
                    'Petugas Assigned': item.petugas || '-'
                }));

                const formattedPetugas = (appState.petugasList || []).map((item, idx) => ({
                    'No': idx + 1,
                    'No. REG / NIP': item.nip || '-',
                    'Nama Lengkap': item.nama || '-',
                    'Role': item.role || 'satpam',
                    'Kontak / No. HP': item.kontak || '-',
                    'Status Keaktifan': item.status || 'AKTIF'
                }));

                const formattedSurat = (appState.suratMasukList || []).map((item, idx) => ({
                    'No': idx + 1,
                    'Tgl Surat Masuk': item.tglSuratMasuk || '-',
                    'Tanggal Surat': item.tglSurat || '-',
                    'No. Surat': item.noSurat || '-',
                    'Perihal': item.perihal || '-',
                    'Instansi Pengirim': item.instansi || '-',
                    'Nama File Dokumen': item.fileName || 'Tidak Ada File'
                }));

                const groupedInputs = getAllInputsGroupedByDate();
                const formattedSelectedDate = (groupedInputs[selectedDateKey] || []).map(item => ({
                    'Tanggal': selectedDateKey,
                    'Waktu': item.waktu || '-',
                    'Kategori Input': item.kategori || '-',
                    'Petugas / Pelapor': item.petugas || '-',
                    'Detail / Keterangan': item.detail || '-'
                }));

                const wb = XLSX.utils.book_new();

                XLSX.utils.book_append_sheet(wb, XLSX.utils.json_to_sheet(formattedSelectedDate.length ? formattedSelectedDate : [{ Status: `Belum Ada Input pada ${selectedDateKey}` }]), "Rekap Tanggal Dipilih");
                XLSX.utils.book_append_sheet(wb, XLSX.utils.json_to_sheet(formattedPortal.length ? formattedPortal : [{ Status: 'Belum Ada Data Log Kendaraan' }]), "Log Kendaraan Portal");
                XLSX.utils.book_append_sheet(wb, XLSX.utils.json_to_sheet(formattedTamu.length ? formattedTamu : [{ Status: 'Belum Ada Data Buku Tamu' }]), "Buku Tamu");
                XLSX.utils.book_append_sheet(wb, XLSX.utils.json_to_sheet(formattedLaporan.length ? formattedLaporan : [{ Status: 'Belum Ada Laporan Gedung' }]), "Detail Laporan Gedung");
                XLSX.utils.book_append_sheet(wb, XLSX.utils.json_to_sheet(formattedPengaduan.length ? formattedPengaduan : [{ Status: 'Belum Ada Pengaduan Kendala' }]), "Pengaduan Kendala");
                XLSX.utils.book_append_sheet(wb, XLSX.utils.json_to_sheet(formattedJadwal.length ? formattedJadwal : [{ Status: 'Belum Ada Jadwal Shift' }]), "Jadwal Shift");
                XLSX.utils.book_append_sheet(wb, XLSX.utils.json_to_sheet(formattedPetugas.length ? formattedPetugas : [{ Status: 'Belum Ada Data Petugas' }]), "Data Petugas");
                XLSX.utils.book_append_sheet(wb, XLSX.utils.json_to_sheet(formattedSurat.length ? formattedSurat : [{ Status: 'Belum Ada Surat Masuk' }]), "Surat Masuk");

                const dateStr = new Date().toISOString().slice(0, 10);
                XLSX.writeFile(wb, `SECURE-OPS-Rekap-Lengkap-${dateStr}.xlsx`);

                showToast("Semua data berhasil ditarik penuh ke Excel!", "emerald");
            } catch (err) {
                console.error("Gagal export Excel:", err);
                showToast("Gagal menarik data ke Excel.", "rose");
            }
        }
    </script>
</body>
</html>