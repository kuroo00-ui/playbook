-- Playbook: Centralized Facilities and Equipment Reservation System
-- Run in phpMyAdmin > SQL tab (MySQL/MariaDB via XAMPP)

CREATE DATABASE IF NOT EXISTS playbook;
USE playbook;

-- 1. Users (customers, staff, administrators)
CREATE TABLE users (
  user_id        INT AUTO_INCREMENT PRIMARY KEY,
  first_name     VARCHAR(50)  NOT NULL,
  last_name      VARCHAR(50)  NOT NULL,
  email          VARCHAR(100) NOT NULL UNIQUE,
  contact_number VARCHAR(20),
  password_hash  VARCHAR(255) NOT NULL,
  role           ENUM('customer','staff','admin') NOT NULL DEFAULT 'customer',
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Courts
CREATE TABLE courts (
  court_id        INT AUTO_INCREMENT PRIMARY KEY,
  court_name      VARCHAR(100) NOT NULL,
  capacity        INT NOT NULL,
  opening_time    TIME NOT NULL,
  closing_time    TIME NOT NULL,
  rental_rate     DECIMAL(10,2) NOT NULL DEFAULT 0,
  status          ENUM('available','maintenance','closed') NOT NULL DEFAULT 'available'
);

-- 3. Reservations
CREATE TABLE reservations (
  reservation_id   INT AUTO_INCREMENT PRIMARY KEY,
  user_id          INT NOT NULL,
  court_id         INT NOT NULL,
  reservation_date DATE NOT NULL,
  start_time       TIME NOT NULL,
  end_time         TIME NOT NULL,
  purpose          VARCHAR(255),
  date_requested   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  status           ENUM('pending','approved','rejected','cancelled','completed') NOT NULL DEFAULT 'pending',
  FOREIGN KEY (user_id)  REFERENCES users(user_id),
  FOREIGN KEY (court_id) REFERENCES courts(court_id),
  CHECK (end_time > start_time)
);

-- 4. Approvals
CREATE TABLE approvals (
  approval_id    INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT NOT NULL,
  approver_id    INT NOT NULL,
  decision       ENUM('approved','rejected') NOT NULL,
  approval_date  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  remarks        VARCHAR(255),
  FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id),
  FOREIGN KEY (approver_id)    REFERENCES users(user_id)
);

-- 5. Schedules (reserved slots + maintenance blocks)
CREATE TABLE schedules (
  schedule_id      INT AUTO_INCREMENT PRIMARY KEY,
  court_id         INT NOT NULL,
  reservation_id   INT NULL,            -- NULL = blocked for maintenance/other
  schedule_date    DATE NOT NULL,
  start_time       TIME NOT NULL,
  end_time         TIME NOT NULL,
  schedule_status  ENUM('reserved','blocked','released') NOT NULL DEFAULT 'reserved',
  FOREIGN KEY (court_id)       REFERENCES courts(court_id),
  FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id)
);

-- 6. Notifications
CREATE TABLE notifications (
  notification_id     INT AUTO_INCREMENT PRIMARY KEY,
  user_id             INT NOT NULL,
  message             VARCHAR(255) NOT NULL,
  date_sent           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  notification_status ENUM('unread','read') NOT NULL DEFAULT 'unread',
  FOREIGN KEY (user_id) REFERENCES users(user_id)
);

-- 7. Equipment inventory
CREATE TABLE equipment (
  equipment_id   INT AUTO_INCREMENT PRIMARY KEY,
  equipment_name VARCHAR(100) NOT NULL,
  total_quantity INT NOT NULL DEFAULT 0,
  available_qty  INT NOT NULL DEFAULT 0,
  equipment_condition ENUM('good','fair','damaged') NOT NULL DEFAULT 'good',
  rental_fee     DECIMAL(10,2) NOT NULL DEFAULT 0
);

-- 8. Equipment requests / borrowing / returns
CREATE TABLE equipment_borrowings (
  borrowing_id     INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id   INT NOT NULL,
  equipment_id     INT NOT NULL,
  quantity         INT NOT NULL,
  checkout_time    DATETIME NULL,
  return_time      DATETIME NULL,
  qty_returned     INT NOT NULL DEFAULT 0,
  return_condition ENUM('good','fair','damaged','missing') NULL,
  FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id),
  FOREIGN KEY (equipment_id)   REFERENCES equipment(equipment_id)
);

-- 9. Payments
CREATE TABLE payments (
  payment_id     INT AUTO_INCREMENT PRIMARY KEY,
  reservation_id INT NOT NULL,
  amount         DECIMAL(10,2) NOT NULL,
  method         VARCHAR(30),
  payment_date   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  status         ENUM('pending','paid','refunded','cancelled') NOT NULL DEFAULT 'pending',
  FOREIGN KEY (reservation_id) REFERENCES reservations(reservation_id)
);

-- 10. Maintenance and damage reports
CREATE TABLE maintenance_reports (
  report_id     INT AUTO_INCREMENT PRIMARY KEY,
  court_id      INT NULL,
  equipment_id  INT NULL,
  qty_damaged   INT NULL, 
  qty_fixed     INT NOT NULL DEFAULT 0,  
  reported_by   INT NOT NULL,
  issue         VARCHAR(255) NOT NULL,
  date_reported TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  repair_status ENUM('reported','in_repair','fixed') NOT NULL DEFAULT 'reported',
  FOREIGN KEY (court_id)     REFERENCES courts(court_id),
  FOREIGN KEY (equipment_id) REFERENCES equipment(equipment_id),
  FOREIGN KEY (reported_by)  REFERENCES users(user_id)
);

-- 11. System activity logs
CREATE TABLE activity_logs (
  log_id     INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NULL,
  action     VARCHAR(255) NOT NULL,
  logged_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(user_id)
);

-- Sample data so you can test right away
-- (password_hash below is a placeholder; real accounts will use PHP password_hash())
INSERT INTO users (first_name, last_name, email, contact_number, password_hash, role) VALUES
  ('Test', 'Customer', 'customer@playbook.test', '09170000001', 'placeholder', 'customer'),
  ('Test', 'Admin',    'admin@playbook.test',    '09170000002', 'placeholder', 'admin');

INSERT INTO courts (court_name, capacity, opening_time, closing_time, rental_rate) VALUES
  ('Basketball Court A', 10, '08:00:00', '21:00:00', 500.00),
  ('Volleyball Court',   12, '08:00:00', '21:00:00', 400.00),
  ('Badminton Court 1',   4, '08:00:00', '21:00:00', 250.00);

INSERT INTO equipment (equipment_name, total_quantity, available_qty, rental_fee) VALUES
  ('Basketball',  10, 10, 20.00),
  ('Volleyball',   8,  8, 20.00),
  ('Badminton Racket', 12, 12, 15.00);
