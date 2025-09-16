-- PON Data Migration SQL
-- Generated from assets/data/pon.json
-- Run this after creating the pon table

USE wiratama_db;

INSERT INTO pon (pon, type, client, nama_proyek, job_type, berat, qty, progress, date_pon, date_finish, status, alamat_kontrak, no_contract, pic, owner) VALUES
('WGJ-2025-FR', 'Baleyy girder', NULL, NULL, 'pengadaan', 900.00, NULL, 0, '2025-09-27', '2025-09-30', 'Selesai', NULL, NULL, NULL, NULL),
('WGJ-2025-CVI', 'Rangka Balley', NULL, NULL, 'pemasangan', 1000.00, NULL, 0, '2025-09-01', '2025-09-30', 'Progres', NULL, NULL, NULL, NULL),
('2025-WGJ-JEF', 'Gantung', 'PUR BEKASI', 'Penggantian Jembatan Paramasan Bawah I Cs.', 'pengadaan', 100.00, 2, 0, '2025-09-01', '2025-09-30', 'Progres', 'JLN AHMAD YANI NO 20', 'PO. 037/GB/LOG-PPI/VII/2025', 'YUSUF', 'KEMENTERIAN PEKERJAAN UMUM DIREKTORAT JENDERAL BINA MARGA                                                     BALAI PELAKSANA JALAN NASIONAL KALIMANTAN SELATAN'),
('WGJ-2025-wewe', 'Baja', 'pt apk aulian', 'baleesy girder', 'pengiriman', 90.00, 2, 0, '2025-09-01', '2025-09-30', 'Progres', 'jalan, burhan', '25/03/2003', 'Yusuf', 'kementarina aulian'),
('WGJ-2024-TEST', 'Gantung', 'PUR', 'Gantung Mesi Waras', 'pengadaan', 100.00, 2, 0, '2025-09-10', '2025-09-12', 'Progres', 'Jl. Taman Mecca', '80820222', 'Udin', 'Kira'),
('KAKA', 'Gantung', 'Kira', 'Gantung', 'pengiriman', 50.00, 1, 0, '2025-09-11', '2025-09-12', 'Progres', 'Jl. Jatwar', '08073', 'Rakit', 'Yuli'),
('2025-WGJ-WLF', 'Gantung', 'PUR', 'Pembangunan jembatan di suka maju', 'pemasangan', 100.00, 2, 0, '2025-09-01', '2025-09-30', 'Progres', 'jln, tegal amba no 7 duren sawit jakarta timur', '26/29/30', 'Yusuf', 'PT ERK');

-- End of PON data migration
