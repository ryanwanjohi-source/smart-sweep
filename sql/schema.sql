-- ============================================================
--  Smart Sweep — database
--  Database: smart_sweep (MySQL, run under XAMPP)
--
--  The database has 7 tables:
--    users              — everyone in the system, including the admin
--    bins               — the smart bins and how full they are
--    alerts             — alerts raised by bins and drivers
--    routes             — collection routes (stops kept as one list)
--    invoices           — bills sent to residents
--    payments           — money received for the invoices
--    telemetry_readings — every garbage-level reading the
--                         telemetry system has received
--
--  HOW TO SET UP:
--  1. Start MySQL in the XAMPP Control Panel.
--  2. Open phpMyAdmin (http://localhost/phpmyadmin), go to
--     the "Import" tab and choose this file — OR run it from
--     the command line:
--       C:\xampp\mysql\bin\mysql.exe -u root < schema.sql
--  Running this file again is safe: it drops the old tables
--  and builds the database fresh.
--
--  The administrator account is in the users table
--  (username: admin). Its password is stored as a bcrypt
--  hash, never in plain text.
-- ============================================================

CREATE DATABASE IF NOT EXISTS smart_sweep CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE smart_sweep;
SET NAMES utf8mb4;

-- ------------------------------------------------------------
--  users — everyone in the system: the administrator, staff
--  and residents, all in one table. The role says who
--  each person is.
-- ------------------------------------------------------------
DROP TABLE IF EXISTS users;
CREATE TABLE users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    full_name     VARCHAR(100) NOT NULL,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NULL,
    role          VARCHAR(30)  NOT NULL,
    phone         VARCHAR(20)  NOT NULL,
    status        VARCHAR(30)  NOT NULL DEFAULT 'Active'
);

INSERT INTO users (full_name, username, password_hash, role, phone, status) VALUES
('Ryan Wachira',  'admin',   '$2y$10$ALBaMgkiOco6wOtfeNajjuchCwuzvvE08l/d8GEyBkuxJYmwDH4/W', 'Administrator',     '0712 345 678', 'Active'),
('David Kamau',   'dkamau',  NULL, 'Dispatcher',        '0733 218 540', 'Active'),
('James Mwangi',  'jmwangi', NULL, 'Collection Driver', '0701 884 236', 'Active'),
('Ann Wambui',    'awambui', NULL, 'Accountant',        '0722 450 917', 'Active'),
('Peter Otieno',  'potieno', NULL, 'Collection Driver', '0740 663 128', 'Active'),
('Grace Achieng', 'gachieng',NULL, 'Resident',          '0715 902 344', 'Active'),
('Faith Njeri',   'fnjeri',  NULL, 'Resident',          '0768 117 205', 'Unpaid Invoice'),
('John Kiprop',   'jkiprop', NULL, 'Resident',          '0790 554 731', 'Active');

-- ------------------------------------------------------------
--  bins — every smart bin. The status is stored as a word:
--  Full, Near Full, Normal or Offline. An offline bin has
--  no fill level or weight (NULL) until it reports again.
-- ------------------------------------------------------------
DROP TABLE IF EXISTS bins;
CREATE TABLE bins (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    bin_code   VARCHAR(10)  NOT NULL UNIQUE,
    location   VARCHAR(120) NOT NULL,
    waste_type VARCHAR(50)  NOT NULL,
    fill_level INT          NULL,
    weight_kg  DECIMAL(6,1) NULL,
    status     VARCHAR(20)  NOT NULL
);

INSERT INTO bins (bin_code, location, waste_type, fill_level, weight_kg, status) VALUES
('NRB-001', 'Kilimani, Yaya Centre',       'General',         92, 148.2, 'Full'),
('NRB-002', 'Westlands, Sarit Centre',     'Dry Recyclables', 74,  96.5, 'Near Full'),
('NRB-003', 'Karen, Hardy',                'Wet Organic',     41,  58.9, 'Normal'),
('NRB-004', 'Ruaka, Two Rivers',           'Metallic',        85, 132.7, 'Full'),
('NRB-005', 'Embakasi, Fedha Estate',      'General',         23,  31.4, 'Normal'),
('NRB-006', 'Kasarani, TRM Drive',         'Dry Recyclables', 68,  88.1, 'Near Full'),
('NRB-007', 'South C, Mugoya Estate',      'Wet Organic',     37,  49.6, 'Normal'),
('NRB-008', 'Lavington, James Gichuru Rd', 'General',         79, 117.3, 'Near Full'),
('NRB-009', 'Kileleshwa, Oloitokitok Rd',  'Dry Recyclables', 54,  70.8, 'Normal'),
('NRB-010', 'Ngong Road, Prestige Plaza',  'General',         12,  15.2, 'Normal'),
('NRB-011', 'Ruaraka, Garden Estate',      'Metallic',        NULL, NULL, 'Offline'),
('NRB-012', 'Upper Hill, Community Area',  'Wet Organic',     NULL, NULL, 'Offline');

-- ------------------------------------------------------------
--  alerts — one row per alert. The message is the sentence
--  shown about the alert; the status says whether it has
--  been acknowledged or not.
-- ------------------------------------------------------------
DROP TABLE IF EXISTS alerts;
CREATE TABLE alerts (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    alert_code VARCHAR(10)  NOT NULL UNIQUE,
    subject    VARCHAR(120) NOT NULL,
    alert_type VARCHAR(30)  NOT NULL,
    message    VARCHAR(160) NOT NULL,
    status     VARCHAR(20)  NOT NULL DEFAULT 'Not Acknowledged'
);

INSERT INTO alerts (alert_code, subject, alert_type, message, status) VALUES
('AL-1042', 'NRB-001 — Kilimani',    'Fill Level',    'NRB-001 reached 92% — fill-level alert', 'Not Acknowledged'),
('AL-1041', 'NRB-004 — Ruaka',       'Fill Level',    'NRB-004 reached 85% — fill-level alert', 'Not Acknowledged'),
('AL-1040', 'Route R-03 — Kasarani', 'Delay',         'Route R-03 delayed by 25 minutes',       'Not Acknowledged'),
('AL-1039', 'NRB-008 — Lavington',   'Fill Level',    'NRB-008 reached 79% — fill-level alert', 'Acknowledged'),
('AL-1038', 'NRB-002 — Westlands',   'Fill Level',    'NRB-002 reached 74% — fill-level alert', 'Acknowledged'),
('AL-1037', 'NRB-011 — Ruaraka',     'Sensor Offline','NRB-011 sensor went offline',            'Acknowledged');

-- ------------------------------------------------------------
--  routes — one route is one truck trip. The stops are kept
--  as one simple list, in the order the driver visits them.
-- ------------------------------------------------------------
DROP TABLE IF EXISTS routes;
CREATE TABLE routes (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    route_code VARCHAR(10)  NOT NULL UNIQUE,
    name       VARCHAR(120) NOT NULL,
    driver     VARCHAR(60)  NOT NULL,
    vehicle    VARCHAR(20)  NOT NULL,
    status     VARCHAR(20)  NOT NULL,
    stops      VARCHAR(255) NOT NULL
);

INSERT INTO routes (route_code, name, driver, vehicle, status, stops) VALUES
('R-05', 'Kilimani & Ruaka Pickups', 'P. Otieno',  'KDL 452B', 'Planned',     'Depot → NRB-001 Kilimani (92%) → NRB-004 Ruaka (85%) → NRB-009 Kileleshwa (54%) → Dandora Dumpsite'),
('R-03', 'Kasarani Round',           'J. Mwangi',  'KCX 219A', 'In Progress', 'Depot → NRB-006 Kasarani (68%) → NRB-011 Ruaraka (offline) → Dandora Dumpsite'),
('R-02', 'Westlands Round',          'M. Njoroge', 'KDL 907C', 'Completed',   'Depot → NRB-002 Westlands (74%) → NRB-008 Lavington (79%) → Dandora Dumpsite'),
('R-01', 'Karen & South C Round',    'P. Otieno',  'KDL 452B', 'Completed',   'Depot → NRB-003 Karen (41%) → NRB-007 South C (37%) → NRB-005 Embakasi (23%) → Dandora Dumpsite');

-- ------------------------------------------------------------
--  invoices — bills sent to residents.
-- ------------------------------------------------------------
DROP TABLE IF EXISTS invoices;
CREATE TABLE invoices (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    invoice_code  VARCHAR(10)  NOT NULL UNIQUE,
    resident_name VARCHAR(100) NOT NULL,
    estate        VARCHAR(80)  NOT NULL,
    amount        INT          NOT NULL,
    status        VARCHAR(10)  NOT NULL
);

INSERT INTO invoices (invoice_code, resident_name, estate, amount, status) VALUES
('INV-2214', 'Grace Achieng',   'Kilimani',  1500, 'Paid'),
('INV-2213', 'Peter Maina',     'Westlands', 1500, 'Paid'),
('INV-2212', 'Faith Njeri',     'Ruaka',     2000, 'Unpaid'),
('INV-2211', 'John Kiprop',     'Karen',     2500, 'Paid'),
('INV-2210', 'Mary Wanjiku',    'South C',   1500, 'Unpaid'),
('INV-2209', 'Samuel Odhiambo', 'Kasarani',  1200, 'Paid');

-- ------------------------------------------------------------
--  payments — money received, matched to an invoice by its
--  invoice code. mpesa_ref is empty for card payments.
-- ------------------------------------------------------------
DROP TABLE IF EXISTS payments;
CREATE TABLE payments (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    payment_code VARCHAR(10) NOT NULL UNIQUE,
    invoice_code VARCHAR(10) NOT NULL,
    method       VARCHAR(20) NOT NULL,
    mpesa_ref    VARCHAR(20) NULL,
    reference    VARCHAR(30) NULL,
    status       VARCHAR(15) NOT NULL DEFAULT 'Completed',
    phone        VARCHAR(20) NULL,
    pay_date     VARCHAR(20) NOT NULL,
    amount       INT         NOT NULL
);

CREATE TABLE reviews (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    resident_name VARCHAR(60) NOT NULL,
    estate        VARCHAR(60) NOT NULL,
    rating        INT         NOT NULL,
    message       TEXT        NOT NULL,
    review_date   VARCHAR(20) NOT NULL
);

INSERT INTO reviews (resident_name, estate, rating, message, review_date) VALUES
('Faith Njeri',   'Ruaka',     5, 'The bin near our gate is emptied before it overflows now. The alerts really work.', '18 Nov 2026'),
('Mary Wanjiku',  'South C',   4, 'Collection is on time most weeks. The truck missed our street once but the office answered when I called.', '20 Nov 2026'),
('Brian Kiprop',  'Westlands', 5, 'Paying my invoice on the site took two minutes. This is how it should be.', '22 Nov 2026');

INSERT INTO payments (payment_code, invoice_code, method, mpesa_ref, pay_date, amount) VALUES
('PAY-0871', 'INV-2214', 'M-Pesa', 'QK72HD81PX', '8 Nov 2026', 1500),
('PAY-0870', 'INV-2213', 'M-Pesa', 'QK71MB30RD', '8 Nov 2026', 1500),
('PAY-0869', 'INV-2211', 'M-Pesa', 'QK70ZT55NK', '7 Nov 2026', 2500),
('PAY-0868', 'INV-2209', 'Card',   NULL,         '7 Nov 2026', 1200);

-- ------------------------------------------------------------
--  telemetry_readings — the log of the telemetry system.
--  Every time a bin reports its garbage level, one row is
--  added here: which bin, how full it was, how heavy, and
--  when the reading arrived. The table starts empty and
--  fills up as the telemetry system runs.
-- ------------------------------------------------------------
DROP TABLE IF EXISTS telemetry_readings;
CREATE TABLE telemetry_readings (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    bin_code    VARCHAR(10)   NOT NULL,
    fill_level  INT           NOT NULL,
    weight_kg   DECIMAL(6,1)  NOT NULL,
    recorded_at TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
);
