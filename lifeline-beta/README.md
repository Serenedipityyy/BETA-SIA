# Lifeline Beta — Database + Mobile PWA

This package contains the Lifeline campus emergency dispatch prototype, a MySQL/MariaDB database, PHP API, and an installable mobile web app (PWA). It is for supervised beta testing, not a certified emergency service.

## What is included

- MySQL schema with 13 related tables, foreign keys, indexes, and login audit logging.
- Seed data for campus buildings/rooms, incident types, squads, sample CPR roster, announcements, and training sessions.
- PHP/PDO endpoints for authentication, incidents, roster, announcements, training, analytics, and metadata.
- Installable PWA shell for Android/iOS browsers. It requires internet/server access for sign-in and live incident data. Offline mode does **not** submit emergency reports.
- Fixed file layout and API paths so the pages can find CSS, JavaScript, and PHP endpoints.

## Requirements

- PHP 8.1+ with `pdo_mysql` and `mbstring`
- MySQL 8.0.16+ or MariaDB 10.5+
- Apache (XAMPP is fine for local development)
- For phone installation, use HTTPS on a publicly reachable host. Service workers do not work on plain HTTP except localhost.

## A. Run locally on Windows (XAMPP)

1. Install/start XAMPP and start **Apache** and **MySQL**.
2. Extract this folder to `C:\xampp\htdocs\lifeline-beta` (the folder should contain `index.html`, `auth.php`, `01_schema.sql`, etc.).
3. Open XAMPP Shell or Command Prompt and import schema + seed data:

   ```bat
   cd C:\xampp\htdocs\lifeline-beta
   mysql -u root -p < 01_schema.sql
   mysql -u root -p < 02_seed.sql
   ```

   If your local MySQL root has no password, press Enter when prompted. Alternatively, open phpMyAdmin, select **Import**, and import `01_schema.sql` first, then `02_seed.sql`. If using phpMyAdmin, import each file separately.

4. Edit `config.php`. For a typical local setup, keep `db_host` as `127.0.0.1`, `db_name` as `lifeline`, `db_user` as `lifeline_app`, and set `db_pass` to the same password used in `01_schema.sql`. **Change the example password before any shared/public deployment.**
5. Open `http://localhost/lifeline-beta/index.html`.
6. Register a test student using a valid-format ID like `26-1234-567`, then sign in. The default CPR accounts in `02_seed.sql` are sample data; don't use real student details in public testing. Existing preloaded accounts activate by matching their name and setting a password; use activation codes for real CPR members.

## B. Test on a phone on the same Wi-Fi

For initial testing only, connect the phone and computer to the same Wi-Fi.

1. Find your computer's LAN IPv4 address with `ipconfig` (for example `192.168.1.20`).
2. Allow Apache through Windows Firewall on a private network.
3. On the phone, visit `http://COMPUTER-LAN-IP/lifeline-beta/index.html`, replacing the example with your actual IP.
4. This HTTP LAN test lets you check the responsive interface, but PWA installation/service worker support may be blocked because it is not HTTPS. Do not port-forward XAMPP or expose it directly to the internet.

## C. Deploy for external beta testing

Use a PHP hosting provider with **HTTPS + MySQL/MariaDB** (shared hosting/cPanel is often the simplest). Avoid static-only hosting such as GitHub Pages because PHP and MySQL will not run there.

1. Create a hosting account and a MySQL database + database user in its control panel.
2. Import `01_schema.sql`, then `02_seed.sql` into that database. If your provider does not allow `CREATE USER`/`GRANT`, remove the final database-user section from `01_schema.sql` and create the database/user in the hosting panel instead. Grant the hosting database user access to the Lifeline database.
3. Upload the app files to a private subfolder or document root. Put `index.html`, `dashboard.html`, PHP files, `config.php`, `css/`, `js/`, `icons/`, `manifest.webmanifest`, and `service-worker.js` together. Do not upload SQL files to a publicly browsable directory; import them first and remove them from the web root.
4. Set `config.php` to the host's database hostname, database name, database username, and strong database password. The hosting provider may require a hostname other than `127.0.0.1`.
5. Turn on HTTPS/SSL. Visit `https://your-domain.example/index.html` and test registration, login, creating an incident, and responder status changes with two separate test accounts/devices.
6. Install on Android: open the HTTPS URL in Chrome, then use **Install app** or **Add to Home screen**. On iPhone: open the URL in Safari, tap **Share**, then **Add to Home Screen**.
7. Keep the beta invite-only. Remove demo accounts/data before real testing, use one-time activation codes for CPR accounts, and back up the database.

## Database tables

`buildings`, `rooms`, `squads`, `zones`, `incident_types`, `users`, `cpr_members`, `incidents`, `incident_logs`, `announcements`, `training_sessions`, `training_registrations`, `login_logs`.

## Important safety/security notes

- This is a beta prototype and is **not** a replacement for the university's official emergency number or campus protocols.
- The mobile app is an installable PWA, not a native APK. It uses the same PHP/MySQL backend as the website.
- The current incident location is selected using campus building/room fields. Continuous GPS tracking and Google Maps live tracking are **not implemented** in this package; they require explicit location permission, map configuration/API keys, and additional backend/UI work.
- Use HTTPS for any non-local deployment. Never put database credentials or Google Maps API keys in client-side code.
- Do not use real medical/personal data in an unapproved public beta. Restrict access and tell testers what data is collected.
- The included roster and announcements are demo records. Replace them with authorized data before testing.
- Keep database backups and use a strong, unique DB password.

## Troubleshooting

- `could not find driver`: enable `pdo_mysql` in the PHP configuration used by Apache.
- `Access denied for user`: confirm database host, username, password, and grants match the hosting panel.
- `Not Found`: ensure the folder is under the web root and the URL includes `/lifeline-beta/`.
- PHP source is displayed instead of executing: PHP is not configured on that host.
- PWA install is missing: use HTTPS and a supported browser; open the site in the browser rather than an in-app browser.
