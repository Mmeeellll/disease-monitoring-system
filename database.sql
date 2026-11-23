-- ============================================================================
-- FILE: database.sql
-- PURPOSE: Defines the relational MySQL schema and seeds initial data for the
--          Butuan City Disease Monitoring System (86 barangays, demo cases,
--          default settings, and initial admin account).
-- ============================================================================

/*!40101 DROP DATABASE IF EXISTS `disease_monitoring` */;
/*!40101 CREATE DATABASE `disease_monitoring` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */;
/*!40101 USE `disease_monitoring` */;

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

CREATE TABLE `alert_recipients`(
    id INT AUTO_INCREMENT PRIMARY KEY,
    alert_id INT NOT NULL,
    barangay_id INT NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'Pending',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_alert_brgy (alert_id, barangay_id),
    CONSTRAINT fk_ar_alert FOREIGN KEY (alert_id) REFERENCES alerts(id) ON DELETE CASCADE,
    CONSTRAINT fk_ar_brgy FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `recommendation_recipients` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recommendation_id INT NOT NULL,
    barangay_id INT NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'Pending',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rec_brgy (recommendation_id, barangay_id),
    CONSTRAINT fk_rr_rec FOREIGN KEY (recommendation_id) REFERENCES recommendations(id) ON DELETE CASCADE,
    CONSTRAINT fk_rr_brgy FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Migrate existing alerts into alert_recipients
INSERT IGNORE INTO alert_recipients (alert_id, barangay_id, status)
SELECT a.id, b.id, a.status
FROM alerts a
JOIN barangays b ON (a.barangay_id IS NULL OR a.barangay_id = b.id);

-- Migrate existing recommendations into recommendation_recipients
INSERT IGNORE INTO recommendation_recipients (recommendation_id, barangay_id, status)
SELECT r.id, b.id, r.status
FROM recommendations r
JOIN barangays b ON (r.barangay_id IS NULL OR r.barangay_id = b.id);
-- --------------------------------------------------------
-- Table: `alerts`
-- Purpose: Stores health advisories, priority levels, status, and optional PDF memo paths.
-- --------------------------------------------------------
CREATE TABLE `alerts` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` varchar(180) NOT NULL,
  `message` text NOT NULL,
  `attachment` varchar(255) DEFAULT NULL,
  `priority` enum('Low','Medium','High') NOT NULL DEFAULT 'Medium',
  `barangay_id` int(10) UNSIGNED DEFAULT NULL,
  `status` enum('Open','In Progress','Resolved') NOT NULL DEFAULT 'Open',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `barangay_id` (`barangay_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `alerts` (`id`, `title`, `message`, `attachment`, `priority`, `barangay_id`, `status`, `created_at`) VALUES
(1, 'Elevated case reports', 'Review recent reports and verify prevention measures.', NULL, 'High', 29, 'Open', '2026-10-15 05:23:57');

-- --------------------------------------------------------
-- Table: `barangays`
-- Purpose: Master directory of all 86 Butuan City barangays with population,
--          GPS coordinates (for Leaflet GIS map), and dynamically synced risk_level.
-- --------------------------------------------------------
CREATE TABLE `barangays` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `population` int(10) UNSIGNED DEFAULT 0,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `risk_level` enum('Low','Medium','High','Very High') NOT NULL DEFAULT 'Low',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `barangays` (`id`, `name`, `population`, `latitude`, `longitude`, `risk_level`, `notes`, `created_at`) VALUES
(1, 'Agao (Bgy. 3)', 4120, 8.9482000, 125.5435000, 'Low', NULL, '2026-10-15 05:23:57'),
(2, 'Agusan Pequeño', 5430, 8.9725000, 125.5342000, 'Low', NULL, '2026-10-15 05:23:57'),
(3, 'Ambago', 14250, 8.9665000, 125.5180000, 'Low', NULL, '2026-10-15 05:23:57'),
(4, 'Amparo', 3210, 8.8540000, 125.5280000, 'Low', NULL, '2026-10-15 05:23:57'),
(5, 'Ampayon', 13820, 8.9562000, 125.5956000, 'Low', NULL, '2026-10-15 05:23:57'),
(6, 'Anticala', 3450, 8.9985000, 125.6320000, 'Low', NULL, '2026-10-15 05:23:57'),
(7, 'Antongalon', 3180, 8.9610000, 125.6225000, 'Low', NULL, '2026-10-15 05:23:57'),
(8, 'Aupagan', 2410, 8.8760000, 125.5520000, 'Low', NULL, '2026-10-15 05:23:57'),
(9, 'Baan KM 3', 14539, 8.9492000, 125.5568000, 'Low', NULL, '2026-10-15 05:23:57'),
(10, 'Baan Riverside (Bgy. 20)', 5620, 8.9510000, 125.5485000, 'Low', NULL, '2026-10-15 05:23:57'),
(11, 'Babag', 4890, 8.9780000, 125.5220000, 'Low', NULL, '2026-10-15 05:23:57'),
(12, 'Bading (Bgy. 22)', 4310, 8.9565000, 125.5390000, 'Low', NULL, '2026-10-15 05:23:57'),
(13, 'Bancasi', 6840, 8.9485000, 125.4820000, 'Low', NULL, '2026-10-15 05:23:57'),
(14, 'Banza', 4750, 8.9710000, 125.5450000, 'Low', NULL, '2026-10-15 05:23:57'),
(15, 'Baobaoan', 2890, 8.9850000, 125.5780000, 'Low', NULL, '2026-10-15 05:23:57'),
(16, 'Basag', 3640, 8.9450000, 125.6350000, 'Low', NULL, '2026-10-15 05:23:57'),
(17, 'Bayanihan (Bgy. 27)', 4920, 8.9420000, 125.5310000, 'Low', NULL, '2026-10-15 05:23:57'),
(18, 'Bilay', 2150, 8.8520000, 125.5580000, 'Low', NULL, '2026-10-15 05:23:57'),
(19, 'Bit-os', 2340, 8.8950000, 125.5410000, 'Low', NULL, '2026-10-15 05:23:57'),
(20, 'Bitan-agan', 1890, 8.8680000, 125.5150000, 'Low', NULL, '2026-10-15 05:23:57'),
(21, 'Bobon', 3120, 8.9880000, 125.5410000, 'Low', NULL, '2026-10-15 05:23:57'),
(22, 'Bonbon', 8450, 8.9240000, 125.5120000, 'Low', NULL, '2026-10-15 05:23:57'),
(23, 'Bugabus', 1760, 8.7650000, 125.5650000, 'Low', NULL, '2026-10-15 05:23:57'),
(24, 'Bugsukan', 1940, 8.9280000, 125.6520000, 'Low', NULL, '2026-10-15 05:23:57'),
(25, 'Buhangin (Bgy. 19)', 4580, 8.9535000, 125.5510000, 'Low', NULL, '2026-10-15 05:23:57'),
(26, 'Cabcabon', 2680, 8.9680000, 125.6080000, 'Low', NULL, '2026-10-15 05:23:57'),
(27, 'Camayahan', 1469, 8.9180000, 125.5680000, 'Low', NULL, '2026-10-15 05:23:57'),
(28, 'Dagohoy (Bgy. 7)', 3150, 8.9465000, 125.5385000, 'Low', NULL, '2026-10-15 05:23:57'),
(29, 'Dankias', 1385, 8.7950000, 125.5550000, 'Low', NULL, '2026-10-15 05:23:57'),
(30, 'De Oro', 2190, 8.9210000, 125.6380000, 'Low', NULL, '2026-10-15 05:23:57'),
(31, 'Diego Silang (Bgy. 6)', 2840, 8.9475000, 125.5415000, 'Low', NULL, '2026-10-15 05:23:57'),
(32, 'Don Francisco', 1820, 8.8380000, 125.5510000, 'Low', NULL, '2026-10-15 05:23:57'),
(33, 'Doongan', 14890, 8.9585000, 125.5260000, 'Low', NULL, '2026-10-15 05:23:57'),
(34, 'Dulag', 1650, 8.8210000, 125.5420000, 'Low', NULL, '2026-10-15 05:23:57'),
(35, 'Dumalagan', 4910, 8.9410000, 125.4680000, 'Low', NULL, '2026-10-15 05:23:57'),
(36, 'Florida', 2280, 8.8150000, 125.5620000, 'Low', NULL, '2026-10-15 05:23:57'),
(37, 'Golden Ribbon (Bgy. 2)', 5120, 8.9385000, 125.5410000, 'Low', NULL, '2026-10-15 05:23:57'),
(38, 'Holy Redeemer (Bgy. 23)', 6340, 8.9530000, 125.5345000, 'Low', NULL, '2026-10-15 05:23:57'),
(39, 'Humabon (Bgy. 11)', 2190, 8.9460000, 125.5425000, 'Low', NULL, '2026-10-15 05:23:57'),
(40, 'Imadejas (Bgy. 24)', 3480, 8.9495000, 125.5335000, 'Low', NULL, '2026-10-15 05:23:57'),
(41, 'Jose Rizal (Bgy. 25)', 3120, 8.9455000, 125.5355000, 'Low', NULL, '2026-10-15 05:23:57'),
(42, 'Kinamlutan', 3850, 8.9120000, 125.5020000, 'Low', NULL, '2026-10-15 05:23:57'),
(43, 'Lapu-lapu (Bgy. 8)', 2790, 8.9445000, 125.5395000, 'Low', NULL, '2026-10-15 05:23:57'),
(44, 'Lemon', 2540, 8.9410000, 125.5880000, 'Low', NULL, '2026-10-15 05:23:57'),
(45, 'Leon Kilat (Bgy. 13)', 1980, 8.9470000, 125.5430000, 'Low', NULL, '2026-10-15 05:23:57'),
(46, 'Libertad', 25296, 8.9438000, 125.5125000, 'Low', NULL, '2026-10-15 05:23:57'),
(47, 'Limaha (Bgy. 14)', 5840, 8.9415000, 125.5370000, 'Low', NULL, '2026-10-15 05:23:57'),
(48, 'Los Angeles', 4120, 9.0120000, 125.5680000, 'Low', NULL, '2026-10-15 05:23:57'),
(49, 'Lumbocan', 25296, 8.9950000, 125.5280000, 'Low', NULL, '2026-10-15 05:23:57'),
(50, 'Maguinda', 3290, 8.8620000, 125.5750000, 'Low', NULL, '2026-10-15 05:23:57'),
(51, 'Mahay', 5180, 8.9280000, 125.5520000, 'Low', NULL, '2026-10-15 05:23:57'),
(52, 'Mahogany (Bgy. 21)', 4620, 8.9545000, 125.5455000, 'Low', NULL, '2026-10-15 05:23:57'),
(53, 'Maibu', 2410, 8.8820000, 125.5680000, 'Low', NULL, '2026-10-15 05:23:57'),
(54, 'Mandamo', 1890, 8.8020000, 125.5780000, 'Low', NULL, '2026-10-15 05:23:57'),
(55, 'Manila de Bugabus', 1540, 8.7480000, 125.5580000, 'Low', NULL, '2026-10-15 05:23:57'),
(56, 'Maon (Bgy. 1)', 4890, 8.9365000, 125.5445000, 'Low', NULL, '2026-10-15 05:23:57'),
(57, 'Masao', 4320, 9.0050000, 125.4920000, 'Low', NULL, '2026-10-15 05:23:57'),
(58, 'Maug', 2650, 8.9820000, 125.5580000, 'Low', NULL, '2026-10-15 05:23:57'),
(59, 'New Society Village (Bgy. 26)', 3410, 8.9435000, 125.5330000, 'Low', NULL, '2026-10-15 05:23:57'),
(60, 'Nong-nong', 2180, 8.8920000, 125.5120000, 'Low', NULL, '2026-10-15 05:23:57'),
(61, 'Obrero (Bgy. 18)', 6420, 8.9525000, 125.5375000, 'Low', NULL, '2026-10-15 05:23:57'),
(62, 'Ong Yiu (Bgy. 16)', 4780, 8.9540000, 125.5420000, 'Low', NULL, '2026-10-15 05:23:57'),
(63, 'Pagatpatan', 4190, 8.9910000, 125.5150000, 'Low', NULL, '2026-10-15 05:23:57'),
(64, 'Pangabugan', 4560, 8.9320000, 125.5480000, 'Low', NULL, '2026-10-15 05:23:57'),
(65, 'Pianing', 2740, 8.9750000, 125.6380000, 'Low', NULL, '2026-10-15 05:23:57'),
(66, 'Pigdaulan', 2980, 8.9350000, 125.5750000, 'Low', NULL, '2026-10-15 05:23:57'),
(67, 'Pinamanculan', 3428, 8.9780000, 125.5520000, 'Low', NULL, '2026-10-15 05:23:57'),
(68, 'Port Poyohon (Bgy. 17)', 6607, 8.9515000, 125.5432000, 'Low', NULL, '2026-10-15 05:23:57'),
(69, 'Rajah Soliman (Bgy. 4)', 2340, 8.9480000, 125.5425000, 'Low', NULL, '2026-10-15 05:23:57'),
(70, 'Salvacion', 1950, 8.8320000, 125.5720000, 'Low', NULL, '2026-10-15 05:23:57'),
(71, 'San Ignacio (Bgy. 15)', 4120, 8.9495000, 125.5415000, 'Low', NULL, '2026-10-15 05:23:57'),
(72, 'San Mateo', 2480, 8.8750000, 125.5880000, 'Low', NULL, '2026-10-15 05:23:57'),
(73, 'San Vicente', 19500, 8.9285000, 125.5350000, 'Low', NULL, '2026-10-15 05:23:57'),
(74, 'Santo Niño', 2390, 8.9920000, 125.5950000, 'Low', NULL, '2026-10-15 05:23:57'),
(75, 'Sikatuna (Bgy. 10)', 2120, 8.9450000, 125.5415000, 'Low', NULL, '2026-10-15 05:23:57'),
(76, 'Silongan (Bgy. 5)', 2450, 8.9485000, 125.5410000, 'Low', NULL, '2026-10-15 05:23:57'),
(77, 'Sumile', 2310, 8.9020000, 125.6120000, 'Low', NULL, '2026-10-15 05:23:57'),
(78, 'Sumilihon', 3680, 9.0180000, 125.6050000, 'Low', NULL, '2026-10-15 05:23:57'),
(79, 'Tagabaca', 3140, 8.9520000, 125.5750000, 'Low', NULL, '2026-10-15 05:23:57'),
(80, 'Taguibo', 5240, 8.9740000, 125.5850000, 'Low', NULL, '2026-10-15 05:23:57'),
(81, 'Taligaman', 4325, 8.9321000, 125.6114000, 'Low', NULL, '2026-10-15 05:23:57'),
(82, 'Tandang Sora (Bgy. 12)', 3890, 8.9430000, 125.5405000, 'Low', NULL, '2026-10-15 05:23:57'),
(83, 'Tiniwisan', 5610, 8.9615000, 125.5680000, 'Low', NULL, '2026-10-15 05:23:57'),
(84, 'Tungao', 3920, 8.7780000, 125.5250000, 'Low', NULL, '2026-10-15 05:23:57'),
(85, 'Urduja (Bgy. 9)', 2260, 8.9455000, 125.5405000, 'Low', NULL, '2026-10-15 05:23:57'),
(86, 'Villa Kananga', 8940, 8.9340000, 125.5320000, 'Low', NULL, '2026-10-15 05:23:57');

-- --------------------------------------------------------
-- Table: `cases`
-- Purpose: Core epidemiological ledger storing individual or grouped disease reports.
--          Indexed on date, disease, and status for fast dashboard & analytics queries.
-- --------------------------------------------------------
CREATE TABLE `cases` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `patient_code` varchar(40) NOT NULL,
  `disease` varchar(120) NOT NULL,
  `barangay_id` int(10) UNSIGNED NOT NULL,
  `report_date` date NOT NULL,
  `case_count` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `risk_level` enum('Low','Medium','High','Very High') NOT NULL DEFAULT 'Low',
  `status` enum('Active','Recovered','Deceased') NOT NULL DEFAULT 'Active',
  `notes` text DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_case_barangay` (`barangay_id`),
  KEY `fk_case_user` (`created_by`),
  KEY `idx_cases_date` (`report_date`),
  KEY `idx_cases_disease` (`disease`),
  KEY `idx_cases_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `cases` (`id`, `patient_code`, `disease`, `barangay_id`, `report_date`, `case_count`, `risk_level`, `status`, `notes`, `created_by`, `created_at`) VALUES
-- (1, 'DM-DEMO-001', 'Cough', 29, '2026-05-02', 3, 'Low', 'Active', NULL, NULL, '2026-10-15 05:23:57'),
-- -- (2, 'DM-DEMO-002', 'Chickenpox', 67, '2026-05-01', 2, 'Low', 'Recovered', NULL, NULL, '2026-10-15 05:23:57'),
-- (3, 'DM-DEMO-003', 'Fever', 49, '2026-05-01', 4, 'Low', 'Recovered', NULL, NULL, '2026-10-15 05:23:57'),
-- (4, 'DM-DEMO-004', 'Tuberculosis', 68, '2026-04-30', 1, 'Low', 'Active', NULL, NULL, '2026-10-15 05:23:57'),
-- (5, 'DM-DEMO-005', 'Diarrhea', 27, '2026-04-30', 2, 'Low', 'Active', NULL, NULL, '2026-10-15 05:23:57');

-- --------------------------------------------------------
-- Table: `recommendations`
-- Purpose: Tracks public health prevention activities, target barangays, due dates,
--          implementation status, and attached PDF action memos.
-- --------------------------------------------------------
CREATE TABLE `recommendations` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` varchar(180) NOT NULL,
  `description` text NOT NULL,
  `attachment` varchar(255) DEFAULT NULL,
  `barangay_id` int(10) UNSIGNED DEFAULT NULL,
  `status` enum('Pending','In Progress','Completed') NOT NULL DEFAULT 'Pending',
  `due_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `barangay_id` (`barangay_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `recommendations` (`id`, `title`, `description`, `attachment`, `barangay_id`, `status`, `due_date`, `created_at`) VALUES
(1, 'Community clean-up drive', 'Coordinate waste removal and eliminate stagnant water.', NULL, NULL, 'Pending', NULL, '2026-10-15 05:28:16');

-- --------------------------------------------------------
-- Table: `reports`
-- Purpose: Archives custom generated report parameters and stores file paths
--          for bulk-imported Excel/CSV spreadsheets.
-- --------------------------------------------------------
CREATE TABLE `reports` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` varchar(180) NOT NULL,
  `report_type` enum('Weekly','Monthly','Custom') NOT NULL DEFAULT 'Custom',
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `disease` varchar(120) DEFAULT NULL,
  `barangay_id` int(10) UNSIGNED DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `generated_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `barangay_id` (`barangay_id`),
  KEY `generated_by` (`generated_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: `settings`
-- Purpose: Key-value configuration store for health unit branding (city_name, system_name).
-- --------------------------------------------------------
CREATE TABLE `settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('city_name', 'Butuan City'),
('system_name', 'Disease Monitoring');

-- --------------------------------------------------------
-- Table: `users`
-- Purpose: Stores staff credentials, hashed passwords, and role-based access levels.
-- --------------------------------------------------------
CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('Administrator','Health Officer','Encoder') NOT NULL DEFAULT 'Encoder',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`id`, `name`, `email`, `password_hash`, `role`, `active`, `created_at`) VALUES
(1, 'System Administrator', 'admin@disease.local', '$2y$12$jph9kekKdPIvP3YqvUwvyOvkwRstR3BtEqDMHuRL/EGy/YLvNgwhO', 'Administrator', 1, '2026-10-15 05:23:57');

COMMIT;