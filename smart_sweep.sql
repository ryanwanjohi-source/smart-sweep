-- ============================================================
-- SMART SWEEP — DATABASE CREATION SCRIPT (PostgreSQL)
--
-- How to use this file:
--   1. Open it in psql or pgAdmin.
--   2. Run the whole file. It creates the database called
--      smart_sweep, builds all 15 tables, and puts in the
--      demo data that the Smart Sweep website shows.
--
-- The table designs come from the Smart Sweep database
-- schema (database-schema.sql) and are not changed here.
-- ============================================================

-- Step 1: create the database, then connect to it.
CREATE DATABASE smart_sweep;
\c smart_sweep

-- Step 2: create the tables.

-- ============================================================================
-- Smart Garbage Management System — Database Schema (PostgreSQL)
-- Design Assessment — 5-actor model:
--   Dispatcher, Collection Driver, Accountant, Administrator, Residents/Home Owners
-- Every table declares its PRIMARY KEY explicitly; FOREIGN KEYs show relationships.
-- ============================================================================

-- All system users (one row per login account; role determines the actor type)
CREATE TABLE IF NOT EXISTS system_user (
    user_id       SERIAL PRIMARY KEY,
    username      TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    role          TEXT NOT NULL CHECK (role IN ('dispatcher','driver','accountant','administrator','resident')),
    created_at    TIMESTAMPTZ DEFAULT now()
);

-- Residents / Home Owners (service subscribers)
CREATE TABLE IF NOT EXISTS resident (
    resident_id   SERIAL PRIMARY KEY,
    user_id       INTEGER NOT NULL REFERENCES system_user(user_id),
    name          TEXT NOT NULL,
    phone         TEXT NOT NULL,
    location      TEXT NOT NULL
);

-- Dispatchers (plan routes, monitor operations)
CREATE TABLE IF NOT EXISTS dispatcher (
    dispatcher_id SERIAL PRIMARY KEY,
    user_id       INTEGER NOT NULL REFERENCES system_user(user_id),
    name          TEXT NOT NULL
);

-- Collection Drivers (execute collection tasks)
CREATE TABLE IF NOT EXISTS driver (
    driver_id     SERIAL PRIMARY KEY,
    user_id       INTEGER NOT NULL REFERENCES system_user(user_id),
    name          TEXT NOT NULL,
    phone         TEXT NOT NULL
);

-- Smart bins (sensor-equipped waste containers)
CREATE TABLE IF NOT EXISTS smart_bin (
    bin_id        SERIAL PRIMARY KEY,
    bin_code      TEXT UNIQUE NOT NULL,          -- e.g. NRB-001 (FR03: must be unique)
    location      TEXT NOT NULL,
    waste_type    TEXT NOT NULL,
    resident_id   INTEGER REFERENCES resident(resident_id)
);

-- Sensor telemetry readings from bins (append-only)
CREATE TABLE IF NOT EXISTS telemetry_reading (
    reading_id    SERIAL PRIMARY KEY,
    bin_id        INTEGER NOT NULL REFERENCES smart_bin(bin_id) ON DELETE CASCADE,
    timestamp     TIMESTAMPTZ NOT NULL DEFAULT now(),
    fill_level    REAL NOT NULL,                 -- percentage (FR09)
    weight        REAL
);
CREATE INDEX IF NOT EXISTS idx_telemetry_bin_time ON telemetry_reading(bin_id, timestamp DESC);

-- Optimized collection routes planned by dispatchers
CREATE TABLE IF NOT EXISTS collection_route (
    route_id      SERIAL PRIMARY KEY,
    name          TEXT NOT NULL,
    dispatcher_id INTEGER NOT NULL REFERENCES dispatcher(dispatcher_id),
    status        TEXT NOT NULL DEFAULT 'planned'
);

-- Ordered stops within a route (FR24: stored optimized route)
CREATE TABLE IF NOT EXISTS route_stop (
    stop_id       SERIAL PRIMARY KEY,
    route_id      INTEGER NOT NULL REFERENCES collection_route(route_id) ON DELETE CASCADE,
    bin_id        INTEGER NOT NULL REFERENCES smart_bin(bin_id),
    sequence      INTEGER NOT NULL
);

-- Collection vehicles
CREATE TABLE IF NOT EXISTS vehicle (
    vehicle_id    SERIAL PRIMARY KEY,
    plate_number  TEXT UNIQUE NOT NULL,         -- (FR05)
    capacity      INTEGER NOT NULL
);

-- Collection tasks assigned to drivers (FR13)
CREATE TABLE IF NOT EXISTS collection_task (
    task_id       SERIAL PRIMARY KEY,
    route_id      INTEGER NOT NULL REFERENCES collection_route(route_id),
    driver_id     INTEGER NOT NULL REFERENCES driver(driver_id),
    vehicle_id    INTEGER REFERENCES vehicle(vehicle_id),
    status        TEXT NOT NULL DEFAULT 'assigned'   -- (FR06: driver updates this)
);

-- Fill-level / delay alerts (FR19, FR20)
CREATE TABLE IF NOT EXISTS alert (
    alert_id      SERIAL PRIMARY KEY,
    bin_id        INTEGER NOT NULL REFERENCES smart_bin(bin_id),
    type          TEXT NOT NULL,                 -- 'fill_level' | 'delay' | 'emergency'
    timestamp     TIMESTAMPTZ NOT NULL DEFAULT now(),
    acknowledged  BOOLEAN NOT NULL DEFAULT FALSE    -- (FR32)
);
CREATE INDEX IF NOT EXISTS idx_alert_bin ON alert(bin_id, timestamp DESC);

-- Invoices issued to residents (FR28: billing fees)
CREATE TABLE IF NOT EXISTS invoice (
    invoice_id    SERIAL PRIMARY KEY,
    resident_id   INTEGER NOT NULL REFERENCES resident(resident_id),
    amount        NUMERIC(10,2) NOT NULL,
    status        TEXT NOT NULL DEFAULT 'unpaid'
);

-- Payments against invoices (FR30: financial reports read these)
CREATE TABLE IF NOT EXISTS payment (
    payment_id    SERIAL PRIMARY KEY,
    invoice_id    INTEGER NOT NULL REFERENCES invoice(invoice_id),
    amount        NUMERIC(10,2) NOT NULL,
    method        TEXT NOT NULL,                 -- e.g. 'mpesa' | 'card'
    paid_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Resident complaints (Submit complains)
CREATE TABLE IF NOT EXISTS complaint (
    complaint_id  SERIAL PRIMARY KEY,
    resident_id   INTEGER NOT NULL REFERENCES resident(resident_id),
    description   TEXT NOT NULL,
    status        TEXT NOT NULL DEFAULT 'open'
);

-- Append-only audit trail (FR25: no updates or deletes)
CREATE TABLE IF NOT EXISTS audit_log (
    log_id        SERIAL PRIMARY KEY,
    user_id       INTEGER NOT NULL REFERENCES system_user(user_id),
    timestamp     TIMESTAMPTZ NOT NULL DEFAULT now(),
    action        TEXT NOT NULL
);

-- ============================================================
-- Step 3: demo data — the same data the website shows.
-- IDs are written out so each row is easy to follow.
-- (password_hash holds a demo value; a live system stores
--  a real hashed password here.)
-- ============================================================

-- The login accounts. The role says what each person is.
INSERT INTO system_user (user_id, username, password_hash, role) VALUES
(1, 'admin',    'demo_hash', 'administrator'),
(2, 'dkamau',   'demo_hash', 'dispatcher'),
(3, 'jmwangi',  'demo_hash', 'driver'),
(4, 'awambui',  'demo_hash', 'accountant'),
(5, 'potieno',  'demo_hash', 'driver'),
(6, 'gachieng', 'demo_hash', 'resident'),
(7, 'fnjeri',   'demo_hash', 'resident'),
(8, 'jkiprop',  'demo_hash', 'resident'),
(9, 'pmaina',   'demo_hash', 'resident'),
(10, 'mwanjiku', 'demo_hash', 'resident'),
(11, 'sodhiambo', 'demo_hash', 'resident'),
(12, 'mnjoroge', 'demo_hash', 'driver');

-- Residents: each one is linked to a login account (user_id).
INSERT INTO resident (resident_id, user_id, name, phone, location) VALUES
(1, 6,  'Grace Achieng',   '0715 902 344', 'Kilimani'),
(2, 7,  'Faith Njeri',     '0768 117 205', 'Ruaka'),
(3, 8,  'John Kiprop',     '0790 554 731', 'Karen'),
(4, 9,  'Peter Maina',     '0720 661 402', 'Westlands'),
(5, 10, 'Mary Wanjiku',    '0739 208 551', 'South C'),
(6, 11, 'Samuel Odhiambo', '0706 417 930', 'Kasarani');

-- The dispatcher (David Kamau plans the routes).
INSERT INTO dispatcher (dispatcher_id, user_id, name) VALUES
(1, 2, 'David Kamau');

-- The collection drivers.
INSERT INTO driver (driver_id, user_id, name, phone) VALUES
(1, 3,  'James Mwangi',   '0701 884 236'),
(2, 5,  'Peter Otieno',   '0740 663 128'),
(3, 12, 'Michael Njoroge', '0711 309 864');

-- The smart bins. resident_id is the resident the bin serves
-- (some bins serve a whole estate, so theirs is left empty).
INSERT INTO smart_bin (bin_id, bin_code, location, waste_type, resident_id) VALUES
(1,  'NRB-001', 'Kilimani, Yaya Centre',       'General',         1),
(2,  'NRB-002', 'Westlands, Sarit Centre',     'Dry Recyclables', 4),
(3,  'NRB-003', 'Karen, Hardy',                'Wet Organic',     3),
(4,  'NRB-004', 'Ruaka, Two Rivers',           'Metallic',        2),
(5,  'NRB-005', 'Embakasi, Fedha Estate',      'General',         NULL),
(6,  'NRB-006', 'Kasarani, TRM Drive',         'Dry Recyclables', 6),
(7,  'NRB-007', 'South C, Mugoya Estate',      'Wet Organic',     5),
(8,  'NRB-008', 'Lavington, James Gichuru Rd', 'General',         NULL),
(9,  'NRB-009', 'Kileleshwa, Oloitokitok Rd',  'Dry Recyclables', NULL),
(10, 'NRB-010', 'Ngong Road, Prestige Plaza',  'General',         NULL),
(11, 'NRB-011', 'Ruaraka, Garden Estate',      'Metallic',        NULL),
(12, 'NRB-012', 'Upper Hill, Community Area',  'Wet Organic',     NULL);

-- The latest sensor reading from each online bin.
INSERT INTO telemetry_reading (reading_id, bin_id, timestamp, fill_level, weight) VALUES
(1,  1,  '2026-11-09 08:42', 92, 148.2),
(2,  2,  '2026-11-09 08:37', 74, 96.5),
(3,  3,  '2026-11-09 08:31', 41, 58.9),
(4,  4,  '2026-11-09 07:15', 85, 132.7),
(5,  5,  '2026-11-09 08:44', 23, 31.4),
(6,  6,  '2026-11-09 08:29', 68, 88.1),
(7,  7,  '2026-11-09 08:40', 37, 49.6),
(8,  8,  '2026-11-09 08:12', 79, 117.3),
(9,  9,  '2026-11-09 08:26', 54, 70.8),
(10, 10, '2026-11-09 08:45', 12, 15.2);

-- Collection routes planned by the dispatcher.
-- (Route numbers match the Routes page: R-01, R-02, R-03, R-05.)
INSERT INTO collection_route (route_id, name, dispatcher_id, status) VALUES
(1, 'Karen & South C Round',    1, 'completed'),
(2, 'Westlands Round',          1, 'completed'),
(3, 'Kasarani Round',           1, 'in_progress'),
(5, 'Kilimani & Ruaka Pickups', 1, 'planned');

-- The stops of each route, in visiting order (sequence).
INSERT INTO route_stop (stop_id, route_id, bin_id, sequence) VALUES
(1, 1, 3, 1),
(2, 1, 7, 2),
(3, 1, 5, 3),
(4, 2, 2, 1),
(5, 2, 8, 2),
(6, 3, 6, 1),
(7, 3, 11, 2),
(8, 5, 1, 1),
(9, 5, 4, 2),
(10, 5, 9, 3);

-- The collection vehicles.
INSERT INTO vehicle (vehicle_id, plate_number, capacity) VALUES
(1, 'KDL 452B', 40),
(2, 'KCX 219A', 35),
(3, 'KDL 907C', 40);

-- Collection tasks: one route, one driver, one vehicle.
INSERT INTO collection_task (task_id, route_id, driver_id, vehicle_id, status) VALUES
(1, 1, 2, 1, 'completed'),
(2, 2, 3, 3, 'completed'),
(3, 3, 1, 2, 'in_progress'),
(4, 5, 2, 1, 'assigned');

-- Alerts raised by the bins.
INSERT INTO alert (alert_id, bin_id, type, timestamp, acknowledged) VALUES
(1, 1,  'fill_level', '2026-11-09 08:42', FALSE),
(2, 4,  'fill_level', '2026-11-09 07:15', FALSE),
(3, 8,  'fill_level', '2026-11-09 08:12', TRUE),
(4, 2,  'fill_level', '2026-11-09 06:58', TRUE),
(5, 11, 'offline',    '2026-11-08 18:20', TRUE);

-- Invoices sent to residents.
INSERT INTO invoice (invoice_id, resident_id, amount, status) VALUES
(1, 1, 1500.00, 'paid'),
(2, 4, 1500.00, 'paid'),
(3, 2, 2000.00, 'unpaid'),
(4, 3, 2500.00, 'paid'),
(5, 5, 1500.00, 'unpaid'),
(6, 6, 1200.00, 'paid');

-- Payments made against the invoices.
INSERT INTO payment (payment_id, invoice_id, amount, method, paid_at) VALUES
(1, 1, 1500.00, 'mpesa', '2026-11-08 10:24'),
(2, 2, 1500.00, 'mpesa', '2026-11-08 11:03'),
(3, 4, 2500.00, 'mpesa', '2026-11-07 09:47'),
(4, 6, 1200.00, 'card',  '2026-11-07 14:15');

-- Complaints sent in by residents.
INSERT INTO complaint (complaint_id, resident_id, description, status) VALUES
(1, 2, 'The bin at Two Rivers was not emptied on Saturday', 'open'),
(2, 5, 'Requesting an extra bin for our estate', 'open');

-- The audit log: a record of who did what.
INSERT INTO audit_log (log_id, user_id, timestamp, action) VALUES
(1, 2, '2026-11-09 07:58', 'Dispatcher logged in'),
(2, 2, '2026-11-09 08:15', 'Dispatcher acknowledged the alert for bin NRB-008'),
(3, 3, '2026-11-09 09:03', 'Driver reported a delay on the Kasarani route'),
(4, 1, '2026-11-09 09:20', 'Administrator logged in');

-- ============================================================
-- Done. The smart_sweep database is created with all
-- 15 tables and the demo data inside.
-- ============================================================
