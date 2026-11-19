-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 16, 2026 at 02:49 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `db_secops`
--

-- --------------------------------------------------------

--
-- Table structure for table `jadwal_list`
--

CREATE TABLE `jadwal_list` (
  `id` int(11) NOT NULL,
  `hari` varchar(100) NOT NULL,
  `shift` varchar(100) NOT NULL,
  `pos` varchar(150) NOT NULL,
  `petugas` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `laporan_gedung`
--

CREATE TABLE `laporan_gedung` (
  `id` int(11) NOT NULL,
  `waktu` varchar(100) NOT NULL,
  `gedung` varchar(50) NOT NULL,
  `petugas` varchar(150) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `laporan_gedung_detail`
--

CREATE TABLE `laporan_gedung_detail` (
  `id` int(11) NOT NULL,
  `laporan_id` int(11) NOT NULL,
  `nama_area` varchar(150) NOT NULL,
  `keterangan` text DEFAULT NULL,
  `foto` longtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pengaduan_list`
--

CREATE TABLE `pengaduan_list` (
  `id` int(11) NOT NULL,
  `kode` varchar(50) NOT NULL,
  `waktu` varchar(100) NOT NULL,
  `pelapor` varchar(150) NOT NULL,
  `lokasi` varchar(150) NOT NULL,
  `kategori` varchar(100) NOT NULL,
  `urgensi` varchar(50) NOT NULL,
  `deskripsi` text NOT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'MENUNGGU',
  `catatan` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `petugas`
--

CREATE TABLE `petugas` (
  `id` int(11) NOT NULL,
  `nama` varchar(150) NOT NULL,
  `username` varchar(100) NOT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'satpam',
  `status` varchar(50) NOT NULL DEFAULT 'AKTIF'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `portal_logs`
--

CREATE TABLE `portal_logs` (
  `id` int(11) NOT NULL,
  `jam` varchar(50) NOT NULL,
  `arah` varchar(50) NOT NULL,
  `plat` varchar(50) NOT NULL,
  `driver` varchar(150) NOT NULL,
  `pemeriksaan` text DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'AMAN',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `surat_masuk_list`
--

CREATE TABLE `surat_masuk_list` (
  `id` int(11) NOT NULL,
  `tgl_surat_masuk` varchar(50) NOT NULL,
  `tgl_surat` varchar(50) NOT NULL,
  `no_surat` varchar(100) NOT NULL,
  `perihal` text NOT NULL,
  `instansi` varchar(150) NOT NULL,
  `file_data` longtext DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tamu_logs`
--

CREATE TABLE `tamu_logs` (
  `id` int(11) NOT NULL,
  `jam` varchar(50) NOT NULL,
  `nama` varchar(150) NOT NULL,
  `instansi` varchar(150) NOT NULL,
  `tujuan` varchar(150) NOT NULL,
  `identitas` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `jadwal_list`
--
ALTER TABLE `jadwal_list`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `laporan_gedung`
--
ALTER TABLE `laporan_gedung`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `laporan_gedung_detail`
--
ALTER TABLE `laporan_gedung_detail`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_laporan_gedung` (`laporan_id`);

--
-- Indexes for table `pengaduan_list`
--
ALTER TABLE `pengaduan_list`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `petugas`
--
ALTER TABLE `petugas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `portal_logs`
--
ALTER TABLE `portal_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `surat_masuk_list`
--
ALTER TABLE `surat_masuk_list`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tamu_logs`
--
ALTER TABLE `tamu_logs`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `jadwal_list`
--
ALTER TABLE `jadwal_list`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `laporan_gedung`
--
ALTER TABLE `laporan_gedung`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `laporan_gedung_detail`
--
ALTER TABLE `laporan_gedung_detail`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pengaduan_list`
--
ALTER TABLE `pengaduan_list`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `petugas`
--
ALTER TABLE `petugas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `portal_logs`
--
ALTER TABLE `portal_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `surat_masuk_list`
--
ALTER TABLE `surat_masuk_list`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tamu_logs`
--
ALTER TABLE `tamu_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `laporan_gedung_detail`
--
ALTER TABLE `laporan_gedung_detail`
  ADD CONSTRAINT `fk_laporan_gedung` FOREIGN KEY (`laporan_id`) REFERENCES `laporan_gedung` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
