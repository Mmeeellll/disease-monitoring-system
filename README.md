# Disease Monitoring — Health Unit Management System

A responsive PHP/MySQL starter application based on the uploaded capstone reference. It includes login/session handling, a dashboard, disease-case CRUD, barangay management, risk classification overview, analytics charts, alerts, recommendations, report generation/CSV export, user management, and general settings.

## Requirements
- XAMPP (Apache, PHP 8.1+ recommended, MySQL/MariaDB)
- Internet access for the Bootstrap, Bootstrap Icons, Chart.js, and Google Fonts CDN assets (or replace them with local copies)

## Install with XAMPP
1. Copy the `disease-monitoring` folder into `C:\xampp\htdocs\`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Open `http://localhost/phpmyadmin`.
4. Import `schema.sql` (it creates the `disease_monitoring` database and demo records).
5. Check `config.php`. The default XAMPP settings are host `127.0.0.1`, user `root`, and an empty password. Change these for your environment.
6. Visit `http://localhost/disease-monitoring/login.php`.

## Demo login
- Email: `admin@disease.local`
- Password: `password`

Change this demo password before putting real data into the system. If the demo hash does not authenticate in your PHP environment, reset it by running this temporary PHP snippet locally and using the resulting hash in the `users.password_hash` column:

```php
<?php echo password_hash('your-new-password', PASSWORD_DEFAULT); ?>
```

## Notes
- The included barangay “map” is a risk overview, not a true geographic map. Add verified barangay boundary data and a mapping library (such as Leaflet) if the capstone requires actual geographic hotspot mapping.
- Dashboard numbers are calculated from the database. Seed records are illustrative demo data and should not be treated as real public-health statistics.
- The application uses PDO prepared statements, password hashing, session regeneration, output escaping, and CSRF tokens for POST forms. Before production use, add HTTPS, role-specific authorization checks on every action, audit logs, account lockout/rate limiting, password reset flow, automated backups, and a privacy/security review. The current role control is strongest for user/settings pages; enforce least-privilege access for all modules before deployment.
- Patient code, age, gender, disease, and status can still be sensitive health data. Use fictitious data for demos and follow applicable privacy and health-data rules.

## Main files
- `index.php` — application routes and module views / POST handlers
- `login.php`, `logout.php` — authentication
- `config.php` — database connection and session/security helpers
- `schema.sql` — database schema and sample data
- `export.php` — CSV exports
- `assets/style.css`, `assets/app.js` — styling and client-side charts/navigation
