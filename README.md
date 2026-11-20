# Disease Monitoring — Health Unit Management System

A responsive PHP/MySQL web application for local health unit disease surveillance (configured for Butuan City). It includes role-based login/session handling, an analytics dashboard, disease-case CRUD with Excel/CSV bulk import, barangay management with interactive map coordinate picking, a live Leaflet Hotspot Map, automated risk classification, alerts with PDF memo attachments, recommendations, report generation/exports, user management, and system settings.

## Requirements
- **XAMPP** (PHP 8.1+ recommended, MySQL/MariaDB, Apache)
- **Internet access** for Bootstrap, Bootstrap Icons, Chart.js, Leaflet.js / OpenStreetMap tiles, and Google Fonts CDN assets

## Installation & Setup

1. Place the `disease_monitoring` project folder inside `C:\xampp\htdocs\` (so the path is `C:\xampp\htdocs\disease_monitoring`).
2. Open the **XAMPP Control Panel** and start **MySQL** (and **Apache** if not using PHP's built-in server).
3. Open `http://localhost/phpmyadmin` in your browser.
4. Create/import `schema.sql` to initialize the `disease_monitoring` database tables and initial barangay/demo records.
5. Verify database credentials in `config.php` (default XAMPP settings: host `127.0.0.1`, user `root`, empty password).

### Running the Application
You can run the system in either of two ways:

- **Option A — VS Code / PHP Built-in Server (Recommended for development):**
  Press `F5` in VS Code (using `.vscode/launch.json`) or run the following in your terminal inside `disease_monitoring`
  ```bash
  php -S localhost:8000

Then open http://localhost:8000/login.php.   
Option B — XAMPP Apache:
Ensure Apache is started in XAMPP and visit http://localhost/disease_monitoring/login.php.

## Demo login
- Email: `admin@disease.local`
- Password: `password`

- Email: `officer@disease.local`
- Password: `Officer@2026`

- Email: `encoder@disease.local`
- Password: `Encoder@2026`

Security Reminder:
Change the default administrator password via the Users page before entering real health unit records.
If you ever need to generate a new password hash manually, run:

<?php echo password_hash('your-new-password', PASSWORD_DEFAULT); ?>


Key Features & Notes
- Interactive Geographic Mapping: Uses Leaflet.js and OpenStreetMap tiles for both the Hotspot Map (displaying barangay risk levels synced from reported disease cases) and the click-to-pin coordinate picker in Barangay Management.
- Disease Cases & Locking: Case codes auto-generate using the Disease - Year format (e.g., Dengue - 2026), support Excel/CSV bulk imports, and automatically lock records older than 1 month from editing or deletion.
- Alerts & Memos: Supports creating priority alerts per barangay or city-wide with optional PDF memo attachments and live sidebar open-alert counters.
- Security Best Practices: Uses PDO prepared statements, password hashing, session regeneration, output escaping, and CSRF tokens on POST forms. Before deploying to a live production server, enable HTTPS, update demo credentials, and schedule regular database backups.

## Main files
index.php — Main application router, module views, and POST action handlers
header.php / footer.php — Shared navigation sidebar, alert counters, global styles, and client-side scripts
login.php, logout.php — User authentication and session management
config.php — Database connection, risk calculation helpers, and security utilities
schema.sql — Database schema and seed data
export.php — Report and case data exports
.vscode/launch.json — VS Code debug and built-in PHP server configuration (localhost:8000)
