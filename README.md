# Playbook

Centralized facilities and equipment reservation system (PHP + MySQL).

## Run locally (XAMPP)

1. Copy this folder to `C:\xampp\htdocs\playbook`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Open phpMyAdmin, go to the **SQL** tab, and run `schema.sql`.
4. Open `http://localhost/playbook/` in your browser.
5. Register an account. Sample rows in `schema.sql` use placeholder
   passwords and cannot log in.

## Roles

- **Customer:** reserve courts and equipment
- **Staff:** mark reservations complete, check out equipment, report maintenance
- **Admin:** approve bookings, manage courts, equipment and payments

## Database

`connect.php` uses XAMPP defaults (`root`, empty password). Change it if your
MySQL setup differs.
