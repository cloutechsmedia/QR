# Techzo Kochi Scratch & Win

A mobile-first, Kochi-themed temporary campaign website built with **PHP 8+**, **MySQL**, and **vanilla JavaScript/CSS**.

Visitors watch a Kochi-inspired loading animation, follow `@techzo__official` on Instagram, enter their details (with a 10-second unlock timer), and scratch a shimmering gold HTML5 canvas card. **Exactly 3 randomly pre-selected participants win a Bluetooth Speaker**, while every other participant wins a **Rs. 1000 discount coupon** valid exclusively from **30 November 2026 to 5 December 2026**.

---

## Folder Structure

```text
/
├── .htaccess                     # Security headers, blocks direct access to config/, includes/, sql/
├── index.php                     # Public campaign page (Loading -> Rule No. 1 -> Form -> Scratch Card)
├── setup.php                     # One-time admin & gift_slots initializer (DELETE after setup!)
├── api/
│   └── play.php                  # POST endpoint: validates, rate-limits, locks DB, assigns prize & code
├── config/
│   ├── config.php                # Active configuration (DB credentials, timezone, HTTPS cookies)
│   └── config.sample.php         # Sample configuration template
├── includes/
│   ├── db.php                    # PDO connection singleton (prepared statements, IST timezone)
│   ├── security.php              # Secure sessions, CSRF tokens, IP rate limiting, output escaping
│   ├── settings.php              # Campaign settings loader/saver, gift_slots & draw_remaining_gifts()
│   └── helpers.php               # Phone/IG normalization, code generator, validity checks, CSV safety
├── admin/
│   ├── index.php                 # Dashboard (KPIs, gift winners, quick verify, Draw Remaining Gifts)
│   ├── login.php                 # Admin login (password_hash / password_verify + IP rate limit)
│   ├── logout.php                # CSRF-protected logout
│   ├── participants.php          # Search, filters, Verify IG, Redeem, Deliver, Revoke Gift, CSV Export
│   ├── verify.php                # Coupon/Gift verification page (valid / not yet valid / expired / redeemed)
│   ├── settings.php              # Edit gift name, pool N, discount, dates, IG handle, colors & text
│   ├── _header.php               # Shared admin navigation & flash messages
│   └── _footer.php               # Shared admin footer
├── assets/
│   ├── css/
│   │   ├── style.css             # Public mobile-first Kochi teal/navy + Kasavu gold styles
│   │   └── admin.css             # Responsive admin panel styles
│   ├── js/
│   │   ├── app.js                # Kochi loader (GSAP + fallback), 10s follow timer, API integration
│   │   ├── scratch.js            # HTML5 Canvas scratch foil (touch/mouse, ~55% auto-reveal, confetti)
│   │   └── admin.js              # Confirmation prompts for critical admin actions
│   └── img/
│       └── og-image.svg          # Open Graph social preview card
└── sql/
    └── schema.sql                # MySQL tables (settings, participants, gift_slots, admin_users, rate_limits)
```

---

## How Winner Selection Works (Concurrency-Safe)

1. At campaign initialization (`setup.php` or first play), the server pre-selects `total_gifts` (default `3`) unique random entry numbers between `1` and `pool_size` (`N`, default `100`) using PHP's cryptographically secure `random_int()` and stores them privately in `gift_slots`.
2. When a visitor submits the form (`POST /api/play.php`):
   - A MySQL transaction (`BEGIN`) locks the `entry_counter` row (`SELECT ... FOR UPDATE`), serializing simultaneous users.
   - Duplicate WhatsApp numbers and case-insensitive Instagram usernames are checked under row lock and enforced by `UNIQUE` database constraints.
   - The participant receives sequential `entry_no`. If `entry_no` matches an unawarded row in `gift_slots` **and** total active awarded gifts `< total_gifts`, they win a `gift` (`GIFT-XXXXXX`); otherwise they win a `discount` (`TZ1000-XXXXXX`).
   - Because of `FOR UPDATE` row locking inside the transaction, awarded gifts can **never exceed 3** even under concurrent traffic spikes.
3. If fewer than `N` participants joined when the campaign ends, an admin can click **Draw Remaining Gifts** on `/admin/index.php` to randomly upgrade existing non-gift participants using `random_int()` inside a locked transaction.

---

## Local Setup (XAMPP / Laragon / WAMP / PHP 8+)

1. Create a MySQL database named `techzo_kochi_campaign`.
2. Import [`sql/schema.sql`](sql/schema.sql) via phpMyAdmin or MySQL CLI:
   ```bash
   mysql -u root -p techzo_kochi_campaign < sql/schema.sql
   ```
3. Edit `config/config.php` with your database host, name, user, and password.
   - For local HTTP testing, keep `'force_https' => false`.
4. Open `http://localhost/your-folder/setup.php` in your browser to create your Admin login and initialize the 3 random gift slots.
5. **Delete `setup.php`** after creating your admin account.
6. Visit the public page at `http://localhost/your-folder/` and the admin panel at `http://localhost/your-folder/admin/`.

---

## cPanel Shared-Hosting Deployment Guide

Follow these steps to deploy cleanly on any cPanel shared host (Hostinger, Namecheap, Bluehost, BigRock, etc.):

### 1. Check PHP Version
- In cPanel, open **Select PHP Version** (or **MultiPHP Manager**).
- Select **PHP 8.1**, **8.2**, or **8.3** for your domain/subdomain.
- Ensure extensions `pdo`, `pdo_mysql`, `mbstring`, and `json` are enabled (enabled by default on almost all hosts).

### 2. Create MySQL Database & User
- Open **MySQL® Databases** in cPanel.
- Create a new database (e.g. `cpaneluser_techzo`).
- Create a new MySQL user with a strong password (e.g. `cpaneluser_tzuser`).
- Under **Add User To Database**, assign the user to the database with **ALL PRIVILEGES**.

### 3. Import the SQL Schema
- Open **phpMyAdmin** in cPanel.
- Select your new database on the left sidebar.
- Click the **Import** tab, upload `sql/schema.sql`, and click **Import / Go**.
- Verify that 5 tables are created: `settings`, `participants`, `gift_slots`, `admin_users`, and `rate_limits`.

### 4. Upload Project Files
- Open **File Manager** in cPanel and navigate to `public_html/` (or your campaign subdomain folder, e.g. `public_html/kochi/`).
- Zip the project files locally and upload the ZIP via **Upload**, then click **Extract**.
- Make sure hidden files (`.htaccess`) are visible (**Settings → Show Hidden Files**) and that `.htaccess` is in the root folder.

### 5. Configure `config/config.php` & Force HTTPS
- Edit `config/config.php` in File Manager:
  ```php
  'db' => [
      'host'    => 'localhost',
      'port'    => 3306,
      'name'    => 'cpaneluser_techzo',
      'user'    => 'cpaneluser_tzuser',
      'pass'    => 'YOUR_STRONG_DB_PASSWORD',
      'charset' => 'utf8mb4',
  ],
  'app' => [
      'name'        => 'Techzo Kochi Scratch & Win',
      'base_url'    => 'https://yourdomain.com', // or https://kochi.yourdomain.com
      'timezone'    => 'Asia/Kolkata',
      'force_https' => true, // Enforces Secure HTTPS-only session cookies
      'debug'       => false,
  ],
  ```
- Enable SSL in cPanel (**SSL/TLS Status → Run AutoSSL**).
- In `.htaccess`, uncomment these two lines to redirect all HTTP traffic to HTTPS:
  ```apache
  RewriteCond %{HTTPS} !=on
  RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
  ```

### 6. Run One-Time Setup & Delete `setup.php`
- Visit `https://yourdomain.com/setup.php`.
- Create your admin username and strong password (min 8 chars). This creates your hashed admin password and initializes the 3 random gift slots.
- **Immediately delete `setup.php`** in cPanel File Manager.

### 7. Final Pre-Launch Checklist
- Log in to `https://yourdomain.com/admin/`.
- Go to **Settings** and confirm:
  - Instagram handle (`techzo__official`)
  - WhatsApp claim number (with `91` country code, no `+`)
  - Validity dates (`2026-11-30` to `2026-12-05`)
  - Pool size `N` (default `100`) and Total Gifts (`3`)
- Test the full flow on a mobile phone (Android/iOS): Loading animation → Follow on Instagram → 10s countdown → Submit → Scratch card → Confetti → Screenshot & WhatsApp Share.
- Test `/admin/verify.php` with the generated code to confirm validity status and redemption flow.
