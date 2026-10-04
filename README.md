# Village Courtyard

A restaurant website with online ordering, table reservations and a full admin panel.

| Part | Built with | Folder |
|---|---|---|
| Public website | React 19 + Vite, Bootstrap 5, jQuery effects | `frontend/` |
| JSON API | Core PHP 8 (PDO, no framework) | `backend/api/` |
| Admin panel | Core PHP, Bootstrap 5, jQuery, Chart.js | `backend/admin/` |
| Database | MySQL / MariaDB | `database.sql` |
| Logo & brand files | SVG sources, PNG + PSD exports, export script | `branding/` |

Payments use **Razorpay** (UPI, cards, net banking, wallets), with **Cash on Delivery** as a second option.

---

## Contents

1. [Requirements](#1-requirements)
2. [Local setup on XAMPP / WAMP](#2-local-setup-on-xampp--wamp)
3. [Configuration (`backend/.env`)](#3-configuration-backendenv)
4. [Run the React website](#4-run-the-react-website)
5. [Default logins](#5-default-logins)
6. [Razorpay: test keys and webhook](#6-razorpay-test-keys-and-webhook)
7. [Production build and deployment](#7-production-build-and-deployment)
8. [Logo and branding](#8-logo-and-branding)
9. [Project structure](#9-project-structure)
10. [Useful tools and troubleshooting](#10-useful-tools-and-troubleshooting)

---

## 1. Requirements

- **PHP 8.1 or newer** with these extensions: `pdo_mysql`, `curl`, `mbstring`, `fileinfo`, `gd` (all on by default in current XAMPP/WAMP)
- **MySQL 5.7+ or MariaDB 10.4+**
- **Node.js 18+** and npm (only to run or build the React site and to re-export the logo)
- Apache with `mod_rewrite` (for clean website URLs in production)

## 2. Local setup on XAMPP / WAMP

1. **Put the project in the web root**

   ```bash
   cd C:\xampp\htdocs            # WAMP: C:\wamp64\www
   git clone https://github.com/tridevacharya96/village-courtyard.git
   ```

   The backend is then at `http://localhost/village-courtyard/backend/`.

2. **Create the database.** Open phpMyAdmin (`http://localhost/phpmyadmin`), click **Import**, choose `database.sql` and press **Import**.
   It creates the `village_courtyard` database with every table plus demo content: menu, tables, orders, reservations, gallery, homepage sections and two user accounts.

   Or from a terminal:

   ```bash
   mysql -u root -p < database.sql
   ```

3. **Create the config file**

   ```bash
   cd village-courtyard/backend
   copy .env.example .env        # macOS / Linux: cp .env.example .env
   ```

   The defaults match a standard XAMPP install (user `root`, no password). See [section 3](#3-configuration-backendenv) for every option.

4. **Open the admin panel:** `http://localhost/village-courtyard/backend/admin/`, and sign in with the [default login](#5-default-logins).

5. **Start the website:** see [section 4](#4-run-the-react-website).

> Demo photos for dishes, the gallery and the homepage are included in `backend/uploads/`. If any are missing, run
> `php backend/tools/generate-placeholders.php` to draw placeholders for every image the database refers to.

## 3. Configuration (`backend/.env`)

| Key | What it does | Local example |
|---|---|---|
| `APP_ENV` | `local` or `production` | `local` |
| `APP_DEBUG` | Show error details. **Set `false` in production.** | `true` |
| `APP_TIMEZONE` | Time zone for orders, bookings and reports | `Asia/Kolkata` |
| `BASE_URL` | Public URL of the `backend` folder, no trailing slash | `http://localhost/village-courtyard/backend` |
| `FRONTEND_URL` | Address of the React site (used in links and emails) | `http://localhost:5173` |
| `CORS_ORIGINS` | Sites allowed to call the API, comma separated | `http://localhost:5173,http://127.0.0.1:5173` |
| `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASS` | Database connection | `127.0.0.1` / `3306` / `village_courtyard` / `root` / *(empty)* |
| `RAZORPAY_KEY_ID` / `RAZORPAY_KEY_SECRET` / `RAZORPAY_WEBHOOK_SECRET` | Razorpay keys. Leave empty to manage them in **Admin → Settings → Payments** instead; values here take priority. | *(empty)* |
| `SESSION_NAME` / `SESSION_LIFETIME` | Admin session cookie name and idle timeout (seconds) | `vc_admin` / `7200` |
| `LOGIN_MAX_ATTEMPTS` / `LOGIN_LOCKOUT_MINUTES` | Lock a login after this many failures within this many minutes | `5` / `15` |
| `UPLOAD_MAX_MB` | Largest image an admin can upload | `5` |
| `ANALYTICS_SALT` | Any long random text; anonymises visitor IDs in analytics | *(change it)* |

`.env` is ignored by Git. Never commit real keys or passwords.

Everything else (restaurant name, logo, contact details, opening hours, GST %, delivery charge, minimum order, reservation rules, SEO and maintenance mode) is managed in **Admin → Settings**.

## 4. Run the React website

```bash
cd frontend
copy .env.example .env           # macOS / Linux: cp .env.example .env
npm install
npm run dev
```

Open `http://localhost:5173`.

`frontend/.env` has two settings:

| Key | Meaning | Local value |
|---|---|---|
| `VITE_API_URL` | URL of the PHP API, no trailing slash | `http://localhost/village-courtyard/backend/api` |
| `VITE_BASE` | Folder the built site is served from | `/` |

If the site shows "We can't reach the kitchen", check that Apache and MySQL are running in the XAMPP control panel, and that `CORS_ORIGINS` in `backend/.env` includes the address in your browser bar.

**Dine-in ordering from a table:** link or QR-code each table to `/checkout?table=T4` (the table's number). The order is then tagged to that table in the admin Table List.

## 5. Default logins

| Role | Email | Password |
|---|---|---|
| Super Admin | `admin@villagecourtyard.com` | `Admin@123` |
| Staff | `staff@villagecourtyard.com` | `Staff@123` |

Both accounts must set a new password at first sign-in. The **Super Admin** can do everything, including users, roles, payments and settings. **Staff** rights are set per role in **Admin → Users & Roles → Staff permissions**.
Five failed sign-ins within 15 minutes lock that login for 15 minutes (configurable in `.env`).

## 6. Razorpay: test keys and webhook

1. **Get test keys.** Sign up at [razorpay.com](https://razorpay.com). In the Dashboard, switch to **Test Mode**, open **Account & Settings → API Keys**, and generate a key pair (`rzp_test_…` plus a secret).
2. **Add the keys** in **Admin → Settings → Payments** (or in `backend/.env`), then switch **Razorpay** on. Use the **Test connection** button to check them.
3. **Pay with test details.** At checkout, choose *Pay online* and use one of Razorpay's [test cards or test UPI IDs](https://razorpay.com/docs/payments/payments/test-card-details/). No real money moves in Test Mode.
4. **Set up the webhook** (needed on a public server; Razorpay cannot reach `localhost`).
   - In the Dashboard, go to **Account & Settings → Webhooks → Add New Webhook**.
   - **URL:** `{BASE_URL}/api/razorpay-webhook.php`, e.g. `https://yourdomain.com/backend/api/razorpay-webhook.php`.
   - **Secret:** any strong random text. Enter the same value as **Razorpay Webhook Secret** in Admin → Settings → Payments, or as `RAZORPAY_WEBHOOK_SECRET`.
   - **Events:** `payment.captured`, `payment.failed`, `order.paid`, `refund.processed`.

   The webhook is a safety net: if a guest pays and closes the browser before returning to the site, the order is still marked paid.
5. **Go live.** Complete Razorpay's account activation, generate **Live Mode** keys, replace the test keys, and add the same webhook again in Live Mode.

How payments flow:
- Online orders stay hidden from the kitchen until Razorpay confirms the payment. Every payment and webhook is signature-checked.
- Refunds, full or partial, are made from **Admin → Payments** or the order page.

## 7. Production build and deployment

The recommended layout puts the built website and the backend side by side on one domain:

```
public_html/                 ← contents of frontend/dist (index.html, assets/, .htaccess, favicon…)
public_html/backend/         ← the backend folder (api/, admin/, uploads/, .env …)
```

1. **Build the website** with the live API address:

   ```bash
   cd frontend
   # frontend/.env.production
   #   VITE_API_URL=https://yourdomain.com/backend/api
   #   VITE_BASE=/
   npm run build
   ```

   For a sub-folder such as `https://yourdomain.com/restaurant/`, set `VITE_BASE=/restaurant/` and adjust `VITE_API_URL` to match.

2. **Upload** everything inside `frontend/dist/` to the web root, and the `backend/` folder next to it.
   The included `.htaccess` sends routes like `/menu` or `/order/VC-…` to `index.html`, so links survive a page refresh.
3. **Import** `database.sql` on the server (phpMyAdmin), then create `backend/.env` with:
   - `APP_ENV=production` and `APP_DEBUG=false`;
   - `BASE_URL=https://yourdomain.com/backend`;
   - `FRONTEND_URL=https://yourdomain.com` and `CORS_ORIGINS=https://yourdomain.com`;
   - the server's database details;
   - a new `ANALYTICS_SALT`.
4. **Permissions:** the web server must be able to write to `backend/uploads/` and `backend/storage/logs/`.
5. **HTTPS** is required for Razorpay Live Mode. Most hosts offer free Let's Encrypt certificates.
6. **Sign in** at `https://yourdomain.com/backend/admin/` and change the default passwords. Then fill in **Settings**: name, logo, contact details, hours, GST and payments.

Security features already in place:
- `backend/uploads/.htaccess` blocks scripts from running in the uploads folder;
- uploads are checked by real file type;
- every form is protected against CSRF;
- all database queries use prepared statements.

## 8. Logo and branding

The `branding/` folder holds the brand identity:

| File | Use |
|---|---|
| `logo.svg` | Master logo for light backgrounds. Editable vector with named layers: `icon` (arch, leaf, lantern), `wordmark`, `tagline`. |
| `logo-white.svg` | Same logo in light colours, for dark backgrounds |
| `logo-icon.svg` | The courtyard-arch mark on its own |
| `favicon.svg` | Simplified mark on a forest-green tile, legible at 16–64 px |
| `export.mjs` | Script that regenerates every PNG and the PSD from the SVGs |
| `exports/` | `logo-full.png` (2000 px wide), `logo-white.png` (2000 px), `logo-icon.png` (512 × 512), `favicon.png` (64 × 64), `logo.psd` (layered) |

Brand palette:

| Colour | Hex |
|---|---|
| Deep forest green | `#1F3D2B` |
| Terracotta | `#B5582E` |
| Warm gold | `#C9A24D` |
| Cream | `#F6F0E4` |

Fonts: **Cormorant Garamond** for headings, **Lato** for body text and the tagline. In the SVGs the text is converted to outlines, so the logo looks identical everywhere without the fonts installed.

**Regenerate the PNGs and PSD after editing an SVG** (Illustrator, Figma, Inkscape…):

```bash
cd branding
npm install
npm run export
```

This writes `branding/exports/` and copies the PNGs to `backend/uploads/logo/` (where the site reads them) and `favicon.png` to `frontend/public/`. Use `npm run export -- --no-install` to write only `exports/`.

`logo.psd` opens in Photoshop with four layers: Background (hidden), Icon, Wordmark and Tagline. The layers are high-resolution pixel layers rendered from the SVG; keep the SVG as the master for vector edits.

**Changing the logo without code:** in **Admin → Settings → General**, upload a new logo, a light logo for dark backgrounds, and a favicon. The website and admin pick them up immediately.

## 9. Project structure

```
village-courtyard/
├── database.sql                 schema + demo data
├── branding/                    logo sources, exports, export script
├── backend/
│   ├── .env.example             copy to .env
│   ├── config/                  config.php (loads .env), db.php (PDO helpers)
│   ├── includes/                auth, permissions, csrf, validation, orders, razorpay, reservations…
│   ├── api/                     public JSON endpoints used by the React site
│   ├── admin/                   admin panel pages, ajax/, assets/, includes/
│   ├── uploads/                 menu, gallery, logo, slides… (scripts blocked by .htaccess)
│   ├── storage/logs/            application and webhook logs
│   └── tools/                   generate-placeholders.php
└── frontend/
    ├── .env.example             copy to .env / .env.production
    ├── public/                  favicon, .htaccess for the built site
    └── src/
        ├── components/          header, footer, cart drawer, dish cards, lightbox…
        ├── pages/               Home, Menu, Gallery, Checkout, Reservations, Track order, Contact…
        ├── context/             settings, cart, toasts
        ├── services/api.js      every API call in one place
        └── styles/main.css
```

Website pages: `/`, `/menu`, `/about`, `/gallery`, `/reservations`, `/contact`, `/checkout`, `/track`, `/order/:number`, `/page/:slug`.

## 10. Useful tools and troubleshooting

| Problem | Fix |
|---|---|
| Admin shows "Database connection failed" | Check MySQL is running and the `DB_*` values in `backend/.env`. |
| Website shows "We can't reach the kitchen" | Check `VITE_API_URL` in `frontend/.env` and `CORS_ORIGINS` in `backend/.env`, then restart `npm run dev`. |
| Website links give 404 after refresh (production) | Make sure `.htaccess` was uploaded with `dist/` and Apache `mod_rewrite` is on. |
| Images missing | `php backend/tools/generate-placeholders.php` |
| Image upload fails | Check `UPLOAD_MAX_MB`, PHP's `upload_max_filesize` / `post_max_size`, and that `backend/uploads/` is writable. |
| Locked out after wrong passwords | Wait 15 minutes, or ask the Super Admin to reset the password in **Admin → Users & Roles**. |
| Online payments never confirm | Check the Razorpay keys (**Settings → Payments → Test connection**) and the webhook secret, then read `backend/storage/logs/`. |

New orders in the admin play a sound. Browsers only allow this after you click once anywhere on the admin page.
