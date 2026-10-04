-- =====================================================================
--  Village Courtyard — Restaurant Website & Admin Panel
--  MySQL 5.7+ / MariaDB 10.3+   |   Charset: utf8mb4
--  Import:  mysql -u root -p < database.sql
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET time_zone = '+05:30';

CREATE DATABASE IF NOT EXISTS village_courtyard
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE village_courtyard;

-- ---------------------------------------------------------------------
--  Drop (safe re-import)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS page_views, newsletter, contacts, testimonials, gallery_images,
  gallery_categories, reservations, payments, order_status_history, order_items, orders,
  restaurant_tables, coupons, menu_items, categories, pages, hero_slides, homepage_sections,
  settings, activity_logs, login_attempts, rate_limits, role_permissions, permissions, users, roles;

-- =====================================================================
--  1. USERS, ROLES & PERMISSIONS
-- =====================================================================
CREATE TABLE roles (
  id          TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug        VARCHAR(40)  NOT NULL UNIQUE,          -- super_admin | staff
  name        VARCHAR(80)  NOT NULL,
  description VARCHAR(255) NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE permissions (
  id          SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug        VARCHAR(60)  NOT NULL UNIQUE,          -- e.g. orders.update
  module      VARCHAR(40)  NOT NULL,                 -- grouping for UI
  name        VARCHAR(120) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
  role_id       TINYINT UNSIGNED  NOT NULL,
  permission_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id)       REFERENCES roles(id)       ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE users (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id               TINYINT UNSIGNED NOT NULL,
  name                  VARCHAR(100) NOT NULL,
  email                 VARCHAR(150) NOT NULL UNIQUE,
  phone                 VARCHAR(20)  NULL,
  password_hash         VARCHAR(255) NOT NULL,
  avatar                VARCHAR(255) NULL,
  is_active             TINYINT(1) NOT NULL DEFAULT 1,
  force_password_change TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at         DATETIME NULL,
  last_login_ip         VARCHAR(45) NULL,
  created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id),
  INDEX idx_users_role (role_id)
) ENGINE=InnoDB;

-- Brute-force protection for admin login
CREATE TABLE login_attempts (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email        VARCHAR(150) NOT NULL,
  ip_address   VARCHAR(45)  NOT NULL,
  success      TINYINT(1)   NOT NULL DEFAULT 0,
  attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_la_email_time (email, attempted_at),
  INDEX idx_la_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB;

-- Generic rate limiting for public endpoints (contact, reservation, orders…)
CREATE TABLE rate_limits (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bucket     VARCHAR(60) NOT NULL,                   -- e.g. contact, order
  ip_address VARCHAR(45) NOT NULL,
  hit_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_rl (bucket, ip_address, hit_at)
) ENGINE=InnoDB;

CREATE TABLE activity_logs (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NULL,
  action      VARCHAR(60)  NOT NULL,                 -- create | update | delete | login | status_change…
  entity_type VARCHAR(60)  NULL,                     -- order | menu_item | setting…
  entity_id   INT UNSIGNED NULL,
  description VARCHAR(500) NULL,
  ip_address  VARCHAR(45)  NULL,
  user_agent  VARCHAR(255) NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_log_user (user_id),
  INDEX idx_log_entity (entity_type, entity_id),
  INDEX idx_log_created (created_at)
) ENGINE=InnoDB;

-- =====================================================================
--  2. SITE SETTINGS & CMS
-- =====================================================================
CREATE TABLE settings (
  id          SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(80) NOT NULL UNIQUE,
  value       TEXT NULL,
  type        ENUM('text','textarea','number','boolean','image','json','email','url','secret') NOT NULL DEFAULT 'text',
  grp         VARCHAR(40) NOT NULL DEFAULT 'general',   -- general | contact | social | order | reservation | seo | payment
  label       VARCHAR(120) NOT NULL,
  is_public   TINYINT(1) NOT NULL DEFAULT 1,            -- exposed via public API?
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Each homepage block. `content` holds section-specific JSON.
CREATE TABLE homepage_sections (
  id          SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  section_key VARCHAR(50)  NOT NULL UNIQUE,  -- hero | about | signature | specials | why_us | gallery | testimonials | hours | cta
  title       VARCHAR(150) NULL,
  subtitle    VARCHAR(255) NULL,
  content     JSON NULL,
  image       VARCHAR(255) NULL,
  is_visible  TINYINT(1) NOT NULL DEFAULT 1,
  sort_order  SMALLINT NOT NULL DEFAULT 0,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_hs_order (is_visible, sort_order)
) ENGINE=InnoDB;

CREATE TABLE hero_slides (
  id           SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  image        VARCHAR(255) NOT NULL,
  heading      VARCHAR(150) NOT NULL,
  subheading   VARCHAR(255) NULL,
  btn1_text    VARCHAR(50)  NULL,
  btn1_link    VARCHAR(255) NULL,
  btn2_text    VARCHAR(50)  NULL,
  btn2_link    VARCHAR(255) NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  sort_order   SMALLINT NOT NULL DEFAULT 0,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_slides (is_active, sort_order)
) ENGINE=InnoDB;

CREATE TABLE pages (
  id               SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title            VARCHAR(150) NOT NULL,
  slug             VARCHAR(160) NOT NULL UNIQUE,
  content          LONGTEXT NULL,
  banner_image     VARCHAR(255) NULL,
  meta_title       VARCHAR(160) NULL,
  meta_description VARCHAR(320) NULL,
  show_in_menu     TINYINT(1) NOT NULL DEFAULT 0,
  menu_order       SMALLINT NOT NULL DEFAULT 0,
  is_published     TINYINT(1) NOT NULL DEFAULT 1,
  is_system        TINYINT(1) NOT NULL DEFAULT 0,   -- system pages (about) can't be deleted
  created_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_pages_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================================
--  3. MENU
-- =====================================================================
CREATE TABLE categories (
  id          SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(80)  NOT NULL,
  slug        VARCHAR(90)  NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  image       VARCHAR(255) NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  sort_order  SMALLINT NOT NULL DEFAULT 0,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE menu_items (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id   SMALLINT UNSIGNED NOT NULL,
  name          VARCHAR(120) NOT NULL,
  slug          VARCHAR(140) NOT NULL UNIQUE,
  description   VARCHAR(500) NULL,
  price         DECIMAL(10,2) NOT NULL,
  image         VARCHAR(255) NULL,
  food_type     ENUM('veg','non_veg','egg') NOT NULL DEFAULT 'veg',
  spice_level   TINYINT UNSIGNED NOT NULL DEFAULT 0,   -- 0..3
  prep_minutes  SMALLINT UNSIGNED NOT NULL DEFAULT 20,
  is_available  TINYINT(1) NOT NULL DEFAULT 1,
  is_featured   TINYINT(1) NOT NULL DEFAULT 0,         -- "Signature dishes"
  is_special    TINYINT(1) NOT NULL DEFAULT 0,         -- "Today's specials"
  sort_order    SMALLINT NOT NULL DEFAULT 0,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_item_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
  INDEX idx_item_cat (category_id, is_available),
  INDEX idx_item_featured (is_featured),
  FULLTEXT INDEX ft_item_search (name, description)
) ENGINE=InnoDB;

-- =====================================================================
--  4. TABLES, ORDERS & PAYMENTS
-- =====================================================================
CREATE TABLE restaurant_tables (
  id           SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  table_number VARCHAR(10) NOT NULL UNIQUE,
  capacity     TINYINT UNSIGNED NOT NULL DEFAULT 4,
  location     VARCHAR(50) NULL,                       -- Courtyard | Indoor | Terrace
  status       ENUM('available','occupied','reserved') NOT NULL DEFAULT 'available',
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE coupons (
  id               SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code             VARCHAR(30) NOT NULL UNIQUE,
  description      VARCHAR(255) NULL,
  discount_type    ENUM('percent','flat') NOT NULL DEFAULT 'percent',
  discount_value   DECIMAL(10,2) NOT NULL,
  min_order_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  max_discount     DECIMAL(10,2) NULL,
  usage_limit      INT UNSIGNED NULL,
  used_count       INT UNSIGNED NOT NULL DEFAULT 0,
  valid_from       DATETIME NULL,
  valid_until      DATETIME NULL,
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE orders (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_number     VARCHAR(20) NOT NULL UNIQUE,          -- VC-20261003-0001
  customer_name    VARCHAR(100) NOT NULL,
  customer_phone   VARCHAR(20)  NOT NULL,
  customer_email   VARCHAR(150) NULL,
  order_type       ENUM('delivery','takeaway','dine_in') NOT NULL,
  table_id         SMALLINT UNSIGNED NULL,
  delivery_address TEXT NULL,
  notes            VARCHAR(500) NULL,
  subtotal         DECIMAL(10,2) NOT NULL,
  discount         DECIMAL(10,2) NOT NULL DEFAULT 0,
  coupon_id        SMALLINT UNSIGNED NULL,
  tax_percent      DECIMAL(5,2)  NOT NULL DEFAULT 0,
  tax_amount       DECIMAL(10,2) NOT NULL DEFAULT 0,
  delivery_charge  DECIMAL(10,2) NOT NULL DEFAULT 0,
  total            DECIMAL(10,2) NOT NULL,
  payment_method   ENUM('razorpay','cod') NOT NULL,
  payment_status   ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
  status           ENUM('pending','confirmed','preparing','ready','out_for_delivery','served','completed','cancelled') NOT NULL DEFAULT 'pending',
  is_seen          TINYINT(1) NOT NULL DEFAULT 0,        -- for new-order sound alert
  ip_address       VARCHAR(45) NULL,
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_order_table  FOREIGN KEY (table_id)  REFERENCES restaurant_tables(id) ON DELETE SET NULL,
  CONSTRAINT fk_order_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE SET NULL,
  INDEX idx_order_status (status),
  INDEX idx_order_created (created_at),
  INDEX idx_order_phone (customer_phone),
  INDEX idx_order_table_status (table_id, status),
  INDEX idx_order_seen (is_seen)
) ENGINE=InnoDB;

CREATE TABLE order_items (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id      INT UNSIGNED NOT NULL,
  menu_item_id  INT UNSIGNED NULL,
  item_name     VARCHAR(120) NOT NULL,         -- snapshot at time of order
  unit_price    DECIMAL(10,2) NOT NULL,        -- snapshot at time of order
  quantity      SMALLINT UNSIGNED NOT NULL,
  line_total    DECIMAL(10,2) NOT NULL,
  CONSTRAINT fk_oi_order FOREIGN KEY (order_id)     REFERENCES orders(id)     ON DELETE CASCADE,
  CONSTRAINT fk_oi_item  FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE SET NULL,
  INDEX idx_oi_order (order_id),
  INDEX idx_oi_item (menu_item_id)
) ENGINE=InnoDB;

CREATE TABLE order_status_history (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id    INT UNSIGNED NOT NULL,
  old_status  VARCHAR(30) NULL,
  new_status  VARCHAR(30) NOT NULL,
  changed_by  INT UNSIGNED NULL,               -- NULL = customer/system
  note        VARCHAR(255) NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_osh_order FOREIGN KEY (order_id)   REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_osh_user  FOREIGN KEY (changed_by) REFERENCES users(id)  ON DELETE SET NULL,
  INDEX idx_osh_order (order_id)
) ENGINE=InnoDB;

CREATE TABLE payments (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id            INT UNSIGNED NOT NULL,
  method              ENUM('razorpay','cod') NOT NULL,
  razorpay_order_id   VARCHAR(60) NULL,
  razorpay_payment_id VARCHAR(60) NULL,
  razorpay_signature  VARCHAR(255) NULL,
  amount              DECIMAL(10,2) NOT NULL,
  currency            CHAR(3) NOT NULL DEFAULT 'INR',
  status              ENUM('created','paid','failed','refunded') NOT NULL DEFAULT 'created',
  refund_id           VARCHAR(60) NULL,
  refund_amount       DECIMAL(10,2) NULL,
  raw_response        JSON NULL,
  marked_by           INT UNSIGNED NULL,      -- staff who marked COD paid
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_pay_order FOREIGN KEY (order_id)  REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_pay_user  FOREIGN KEY (marked_by) REFERENCES users(id)  ON DELETE SET NULL,
  UNIQUE KEY uq_rzp_order (razorpay_order_id),
  UNIQUE KEY uq_rzp_payment (razorpay_payment_id),
  INDEX idx_pay_status (status),
  INDEX idx_pay_order (order_id)
) ENGINE=InnoDB;

CREATE TABLE reservations (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference        VARCHAR(20) NOT NULL UNIQUE,      -- RS-20261003-0001
  name             VARCHAR(100) NOT NULL,
  phone            VARCHAR(20)  NOT NULL,
  email            VARCHAR(150) NULL,
  reservation_date DATE NOT NULL,
  reservation_time TIME NOT NULL,
  guests           TINYINT UNSIGNED NOT NULL,
  table_preference VARCHAR(50) NULL,                 -- Courtyard | Indoor | Terrace | Any
  table_id         SMALLINT UNSIGNED NULL,
  special_requests VARCHAR(500) NULL,
  status           ENUM('pending','confirmed','cancelled','completed','no_show') NOT NULL DEFAULT 'pending',
  handled_by       INT UNSIGNED NULL,
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_res_table FOREIGN KEY (table_id)   REFERENCES restaurant_tables(id) ON DELETE SET NULL,
  CONSTRAINT fk_res_user  FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_res_date (reservation_date, reservation_time),
  INDEX idx_res_status (status)
) ENGINE=InnoDB;

-- =====================================================================
--  5. GALLERY, TESTIMONIALS, CONTACTS, NEWSLETTER, ANALYTICS
-- =====================================================================
CREATE TABLE gallery_categories (
  id         SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(60) NOT NULL,
  slug       VARCHAR(70) NOT NULL UNIQUE,
  sort_order SMALLINT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE gallery_images (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id SMALLINT UNSIGNED NULL,
  image       VARCHAR(255) NOT NULL,
  caption     VARCHAR(200) NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  sort_order  SMALLINT NOT NULL DEFAULT 0,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_gal_cat FOREIGN KEY (category_id) REFERENCES gallery_categories(id) ON DELETE SET NULL,
  INDEX idx_gal (category_id, is_active, sort_order)
) ENGINE=InnoDB;

CREATE TABLE testimonials (
  id          SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(100) NOT NULL,
  designation VARCHAR(100) NULL,
  message     VARCHAR(600) NOT NULL,
  rating      TINYINT UNSIGNED NOT NULL DEFAULT 5,
  photo       VARCHAR(255) NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  sort_order  SMALLINT NOT NULL DEFAULT 0,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE contacts (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(100) NOT NULL,
  email      VARCHAR(150) NOT NULL,
  phone      VARCHAR(20)  NULL,
  subject    VARCHAR(150) NULL,
  message    TEXT NOT NULL,
  is_read    TINYINT(1) NOT NULL DEFAULT 0,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_contact_read (is_read, created_at)
) ENGINE=InnoDB;

CREATE TABLE newsletter (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email           VARCHAR(150) NOT NULL UNIQUE,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  ip_address      VARCHAR(45) NULL,
  subscribed_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  unsubscribed_at DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE page_views (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  path        VARCHAR(255) NOT NULL,
  visitor_id  CHAR(32) NOT NULL,             -- hashed IP+UA+date (no raw PII)
  referrer    VARCHAR(255) NULL,
  device      ENUM('desktop','mobile','tablet','other') NOT NULL DEFAULT 'other',
  viewed_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pv_time (viewed_at),
  INDEX idx_pv_path (path(100)),
  INDEX idx_pv_visitor (visitor_id, viewed_at)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
--  SEED DATA
-- =====================================================================

-- ---- Roles ----------------------------------------------------------
INSERT INTO roles (id, slug, name, description) VALUES
(1, 'super_admin', 'Super Admin', 'Full access to every module'),
(2, 'staff',       'Staff',       'Day-to-day operations: orders, tables, reservations');

-- ---- Permissions ----------------------------------------------------
INSERT INTO permissions (slug, module, name) VALUES
('dashboard.view',        'dashboard',    'View dashboard'),
('dashboard.revenue',     'dashboard',    'See revenue figures on dashboard'),
('analytics.view',        'analytics',    'View analytics & reports'),
('analytics.export',      'analytics',    'Export analytics CSV'),
('orders.view',           'orders',       'View orders'),
('orders.update',         'orders',       'Update order status'),
('orders.delete',         'orders',       'Delete orders'),
('tables.view',           'tables',       'View table list'),
('tables.update',         'tables',       'Change table status'),
('tables.manage',         'tables',       'Create / edit / delete tables'),
('payments.view',         'payments',     'View payments'),
('payments.mark_cod',     'payments',     'Mark COD orders as paid'),
('payments.refund',       'payments',     'Issue refunds'),
('payments.config',       'payments',     'Configure payment gateway keys'),
('menu.view',             'menu',         'View menu items'),
('menu.manage',           'menu',         'Create / edit menu & categories'),
('menu.delete',           'menu',         'Delete menu items & categories'),
('homepage.manage',       'homepage',     'Edit homepage sections & slides'),
('gallery.manage',        'gallery',      'Manage gallery'),
('reservations.view',     'reservations', 'View reservations'),
('reservations.manage',   'reservations', 'Approve / cancel / assign table'),
('reservations.delete',   'reservations', 'Delete reservations'),
('pages.manage',          'pages',        'Manage CMS pages'),
('testimonials.manage',   'content',      'Manage testimonials'),
('coupons.manage',        'content',      'Manage coupons & offers'),
('contacts.view',         'content',      'View contact messages'),
('contacts.delete',       'content',      'Delete contact messages'),
('newsletter.view',       'content',      'View & export subscribers'),
('settings.manage',       'settings',     'Manage site settings'),
('users.manage',          'users',        'Manage staff users'),
('logs.view',             'users',        'View activity log');

-- Super Admin gets everything
INSERT INTO role_permissions (role_id, permission_id) SELECT 1, id FROM permissions;

-- Staff gets operational permissions only (no delete, no settings, no revenue)
INSERT INTO role_permissions (role_id, permission_id)
SELECT 2, id FROM permissions WHERE slug IN (
  'dashboard.view','orders.view','orders.update','tables.view','tables.update',
  'payments.mark_cod','menu.view','reservations.view','reservations.manage','contacts.view'
);

-- ---- Users ----------------------------------------------------------
-- Super Admin: admin@villagecourtyard.com / Admin@123  (forced to change on first login)
-- Staff:       staff@villagecourtyard.com / Staff@123
INSERT INTO users (id, role_id, name, email, phone, password_hash, force_password_change) VALUES
(1, 1, 'Super Admin', 'admin@villagecourtyard.com', '+91 98765 43210',
   '$2y$10$Ov5I7AdBOOenk7qUND.H7eKtzumopkAMKgLx0tOCgwKIJYO.25nXC', 1),
(2, 2, 'Ravi Kumar',  'staff@villagecourtyard.com', '+91 98765 43211',
   '$2y$10$.2dWJWZdCFrPumknn28BA.B8nqZWReQv/AJ6syvjC1GIWBIvz5KcW', 0);

-- ---- Settings -------------------------------------------------------
INSERT INTO settings (setting_key, value, type, grp, label, is_public) VALUES
('site_name',            'Village Courtyard', 'text', 'general', 'Site name', 1),
('tagline',              'Rustic flavours, served under the stars', 'text', 'general', 'Tagline', 1),
('logo',                 'uploads/logo/logo-full.png',  'image', 'general', 'Logo', 1),
('logo_white',           'uploads/logo/logo-white.png', 'image', 'general', 'Logo (dark backgrounds)', 1),
('favicon',              'uploads/logo/favicon.png',    'image', 'general', 'Favicon', 1),
('maintenance_mode',     '0', 'boolean', 'general', 'Maintenance mode', 1),
('maintenance_message',  'We are giving our courtyard a fresh coat of paint. Back shortly!', 'textarea', 'general', 'Maintenance message', 1),
('currency_symbol',      '₹', 'text', 'general', 'Currency symbol', 1),

('phone',                '+91 98765 43210', 'text',  'contact', 'Phone', 1),
('whatsapp',             '+91 98765 43210', 'text',  'contact', 'WhatsApp', 1),
('email',                'hello@villagecourtyard.com', 'email', 'contact', 'Email', 1),
('address',              'Plot 42, Saheed Nagar, Bhubaneswar, Odisha 751007', 'textarea', 'contact', 'Address', 1),
('map_embed_url',        'https://www.google.com/maps?q=Saheed+Nagar+Bhubaneswar&output=embed', 'url', 'contact', 'Google Map embed URL', 1),
('opening_hours',        '[{"day":"Monday – Friday","hours":"12:00 PM – 11:00 PM"},{"day":"Saturday – Sunday","hours":"11:00 AM – 11:30 PM"}]', 'json', 'contact', 'Opening hours', 1),

('facebook',             'https://facebook.com/villagecourtyard',  'url', 'social', 'Facebook', 1),
('instagram',            'https://instagram.com/villagecourtyard', 'url', 'social', 'Instagram', 1),
('twitter',              '', 'url', 'social', 'X / Twitter', 1),
('youtube',              '', 'url', 'social', 'YouTube', 1),

('ordering_enabled',     '1',    'boolean', 'order', 'Online ordering enabled', 1),
('gst_percent',          '5',    'number',  'order', 'GST %', 1),
('delivery_charge',      '40',   'number',  'order', 'Delivery charge (₹)', 1),
('free_delivery_above',  '799',  'number',  'order', 'Free delivery above (₹)', 1),
('min_order_amount',     '199',  'number',  'order', 'Minimum order (₹)', 1),
('cod_enabled',          '1',    'boolean', 'order', 'Cash on Delivery enabled', 1),
('reservation_slot_minutes', '30', 'number', 'order', 'Reservation slot length (min)', 1),
('reservations_enabled',     '1',     'boolean', 'reservation', 'Online reservations enabled', 1),
('reservation_open_time',    '12:00', 'text',    'reservation', 'First bookable time (HH:MM)', 1),
('reservation_close_time',   '22:30', 'text',    'reservation', 'Last bookable time (HH:MM)', 1),
('reservation_dining_minutes','120',  'number',  'reservation', 'How long a table is held per booking (min)', 1),
('reservation_max_guests',   '20',    'number',  'reservation', 'Largest party bookable online', 1),
('reservation_max_days_ahead','60',   'number',  'reservation', 'How far ahead guests can book (days)', 1),
('reservation_min_notice_minutes','60','number', 'reservation', 'Minimum notice for same-day bookings (min)', 1),

('razorpay_enabled',     '1', 'boolean', 'payment', 'Razorpay enabled', 1),
('razorpay_key_id',      'rzp_test_XXXXXXXXXXXXXX', 'text',   'payment', 'Razorpay Key ID', 1),
('razorpay_key_secret',  '', 'secret', 'payment', 'Razorpay Key Secret', 0),
('razorpay_webhook_secret', '', 'secret', 'payment', 'Razorpay Webhook Secret', 0),

('meta_title',           'Village Courtyard | Rustic Fine Dining in Bhubaneswar', 'text', 'seo', 'Default meta title', 1),
('meta_description',     'Village Courtyard serves soulful Indian and continental cuisine in a lantern-lit courtyard. Order online or reserve a table.', 'textarea', 'seo', 'Default meta description', 1),
('meta_keywords',        'restaurant, fine dining, Bhubaneswar, Indian food, continental, courtyard dining', 'text', 'seo', 'Meta keywords', 1),
('og_image',             'uploads/slides/hero-1.jpg', 'image', 'seo', 'Social share image', 1),
('google_analytics_id',  '', 'text', 'seo', 'Google Analytics ID (optional)', 1),
('footer_about',         'A rustic courtyard kitchen where village recipes meet modern craft. Every plate is slow-cooked, honest and made to share.', 'textarea', 'general', 'Footer about text', 1),
('copyright_text',       '© {year} Village Courtyard. All rights reserved.', 'text', 'general', 'Copyright text', 1);

-- ---- Homepage sections ---------------------------------------------
INSERT INTO homepage_sections (section_key, title, subtitle, content, image, is_visible, sort_order) VALUES
('hero', NULL, NULL, '{"autoplay":true,"interval":6000}', NULL, 1, 1),
('about', 'A Courtyard Built on Stories', 'Our Story',
  '{"text":"Village Courtyard began with a grandmother’s recipe book and a dream of a place where families gather under open skies. Our chefs bring together slow-cooked village classics and refined continental plates, using seasonal produce from farms around Odisha.","btn_text":"Read Our Story","btn_link":"/about","stats":[{"value":"12+","label":"Years of Craft"},{"value":"80+","label":"Signature Dishes"},{"value":"50k","label":"Happy Guests"}]}',
  'uploads/homepage/about.jpg', 1, 2),
('signature', 'Signature Dishes', 'Chef’s Selection', '{"limit":6,"source":"featured"}', NULL, 1, 3),
('specials', 'Today’s Specials', 'Limited Time',
  '{"banner_text":"Flat 15% off on all orders above ₹999 — use code COURTYARD15","btn_text":"Order Now","btn_link":"/menu","show_items":true,"limit":4}',
  'uploads/homepage/specials.jpg', 1, 4),
('why_us', 'Why Guests Return', 'The Courtyard Promise',
  '{"items":[{"icon":"bi-flower1","title":"Farm-Fresh Produce","text":"Vegetables and spices sourced daily from local farmers."},{"icon":"bi-fire","title":"Slow-Cooked Over Wood","text":"Clay ovens and wood fire for deep, smoky flavour."},{"icon":"bi-stars","title":"Lantern-Lit Courtyard","text":"An open-air setting made for long, unhurried dinners."},{"icon":"bi-truck","title":"Fast, Careful Delivery","text":"Sealed, insulated packaging so food arrives as it left."}]}',
  NULL, 1, 5),
('gallery', 'Moments in the Courtyard', 'Gallery', '{"limit":8}', NULL, 1, 6),
('testimonials', 'Words from Our Guests', 'Testimonials', '{"limit":6}', NULL, 1, 7),
('hours', 'Visit Us', 'Opening Hours', '{"show_map":true}', NULL, 1, 8),
('cta', 'Your Table Under the Lanterns Awaits', 'Reserve or Order',
  '{"text":"Book a courtyard table for tonight or have our kitchen come to you.","btn1_text":"Reserve a Table","btn1_link":"/reservations","btn2_text":"Order Online","btn2_link":"/menu"}',
  'uploads/homepage/cta.jpg', 1, 9);

INSERT INTO hero_slides (image, heading, subheading, btn1_text, btn1_link, btn2_text, btn2_link, sort_order) VALUES
('uploads/slides/hero-1.jpg', 'Welcome to Village Courtyard', 'Rustic flavours, slow-cooked and served under the stars', 'View Menu', '/menu', 'Reserve a Table', '/reservations', 1),
('uploads/slides/hero-2.jpg', 'From Clay Oven to Your Table', 'Wood-fired classics crafted from age-old village recipes', 'Order Online', '/menu', 'Our Story', '/about', 2),
('uploads/slides/hero-3.jpg', 'Celebrate in the Courtyard', 'Private dining and events for every occasion', 'Book Now', '/reservations', 'Gallery', '/gallery', 3);

-- ---- Pages ----------------------------------------------------------
INSERT INTO pages (title, slug, content, meta_title, meta_description, show_in_menu, menu_order, is_published, is_system, created_by) VALUES
('About Us', 'about',
 '<h2>Our Story</h2><p>Village Courtyard was born from a simple idea: bring the warmth of a village home to the heart of the city. What began as a handful of family recipes has grown into a kitchen celebrated for honest, slow-cooked food.</p><h2>Our Philosophy</h2><p>We cook the way our grandparents did: seasonal ingredients, patient cooking, and generous portions meant for sharing.</p><h2>The Courtyard</h2><p>Lanterns, terracotta and an open sky set the stage for long dinners with the people you love.</p>',
 'About Village Courtyard', 'The story, chefs and philosophy behind Village Courtyard.', 1, 1, 1, 1, 1),
('Private Events', 'private-events',
 '<h2>Host With Us</h2><p>From intimate anniversaries to corporate dinners for 80 guests, our courtyard and private dining room can be tailored to your event. Custom menus, décor and live music available on request.</p>',
 'Private Events at Village Courtyard', 'Host birthdays, anniversaries and corporate events at Village Courtyard.', 1, 2, 1, 0, 1),
('Privacy Policy', 'privacy-policy',
 '<h2>Privacy Policy</h2><p>We collect only the information required to process your orders and reservations. Payments are handled securely by Razorpay; we never store card details.</p>',
 'Privacy Policy', 'Village Courtyard privacy policy.', 0, 10, 1, 0, 1),
('Terms & Conditions', 'terms',
 '<h2>Terms &amp; Conditions</h2><p>Orders once confirmed and in preparation cannot be cancelled. Refunds for prepaid orders are processed within 5–7 working days.</p>',
 'Terms & Conditions', 'Village Courtyard terms and conditions.', 0, 11, 1, 0, 1);

-- ---- Categories (6) -------------------------------------------------
INSERT INTO categories (id, name, slug, description, image, sort_order) VALUES
(1, 'Starters',           'starters',     'Small plates to begin the evening',      'uploads/menu/cat-starters.jpg', 1),
(2, 'Tandoor & Grill',    'tandoor-grill','Smoky clay-oven and grill specialties',  'uploads/menu/cat-tandoor.jpg',  2),
(3, 'Village Mains',      'village-mains','Slow-cooked Indian curries and thalis',  'uploads/menu/cat-mains.jpg',    3),
(4, 'Continental',        'continental',  'European classics with a rustic touch',  'uploads/menu/cat-continental.jpg', 4),
(5, 'Desserts',           'desserts',     'Sweet endings, old and new',             'uploads/menu/cat-desserts.jpg', 5),
(6, 'Beverages',          'beverages',    'Coolers, shakes and hot brews',          'uploads/menu/cat-beverages.jpg',6);

-- ---- Menu items (24) ------------------------------------------------
INSERT INTO menu_items (id, category_id, name, slug, description, price, image, food_type, spice_level, prep_minutes, is_featured, is_special, sort_order) VALUES
(1,  1, 'Courtyard Paneer Tikka',   'courtyard-paneer-tikka',  'Cottage cheese marinated in hung curd and mustard oil, charred in the tandoor.', 289.00, 'uploads/menu/paneer-tikka.jpg',  'veg',     2, 20, 1, 0, 1),
(2,  1, 'Crispy Corn Pepper Salt',  'crispy-corn-pepper-salt', 'Golden sweet corn tossed with cracked pepper, spring onion and garlic.',          219.00, 'uploads/menu/crispy-corn.jpg',   'veg',     1, 15, 0, 0, 2),
(3,  1, 'Chicken 65',               'chicken-65',              'Fiery South Indian fried chicken with curry leaves and green chilli.',          299.00, 'uploads/menu/chicken-65.jpg',    'non_veg', 3, 18, 0, 1, 3),
(4,  1, 'Prawn Golden Fry',         'prawn-golden-fry',        'Chilika prawns in a light semolina crust with tartare dip.',                   389.00, 'uploads/menu/prawn-fry.jpg',     'non_veg', 1, 18, 0, 0, 4),
(5,  2, 'Clay Oven Murgh Malai',    'clay-oven-murgh-malai',   'Creamy chicken kebabs with cardamom, cheese and cashew.',                      349.00, 'uploads/menu/murgh-malai.jpg',   'non_veg', 1, 25, 1, 0, 1),
(6,  2, 'Mutton Seekh Kebab',       'mutton-seekh-kebab',      'Hand-minced mutton with fresh herbs, grilled on skewers.',                     399.00, 'uploads/menu/seekh-kebab.jpg',   'non_veg', 2, 25, 0, 0, 2),
(7,  2, 'Tandoori Pomfret',         'tandoori-pomfret',        'Whole pomfret in a carom-ajwain marinade, chargrilled.',                       549.00, 'uploads/menu/tandoori-pomfret.jpg','non_veg',2, 30, 0, 1, 3),
(8,  2, 'Tandoori Mushroom',        'tandoori-mushroom',       'Button mushrooms stuffed with spiced cheese, smoky finish.',                   269.00, 'uploads/menu/tandoori-mushroom.jpg','veg',   1, 20, 0, 0, 4),
(9,  3, 'Village Mutton Curry',     'village-mutton-curry',    'Bone-in mutton slow-cooked in an iron kadhai with whole spices.',              449.00, 'uploads/menu/mutton-curry.jpg',  'non_veg', 2, 35, 1, 0, 1),
(10, 3, 'Butter Chicken',           'butter-chicken',          'Tandoori chicken in a velvety tomato-butter gravy.',                           379.00, 'uploads/menu/butter-chicken.jpg','non_veg', 1, 25, 0, 0, 2),
(11, 3, 'Dal Courtyard',            'dal-courtyard',           'Black lentils simmered overnight on wood fire, finished with white butter.',   259.00, 'uploads/menu/dal-courtyard.jpg', 'veg',     0, 15, 1, 0, 3),
(12, 3, 'Paneer Lababdar',          'paneer-lababdar',         'Paneer in a rich onion-tomato gravy with kasuri methi.',                       319.00, 'uploads/menu/paneer-lababdar.jpg','veg',    1, 20, 0, 0, 4),
(13, 3, 'Odia Machha Besara',       'odia-machha-besara',      'River fish in a tangy mustard gravy, a traditional Odia favourite.',          399.00, 'uploads/menu/machha-besara.jpg', 'non_veg', 2, 25, 0, 1, 5),
(14, 3, 'Courtyard Veg Thali',      'courtyard-veg-thali',     'Two curries, dal, rice, roti, raita, salad and dessert.',                      349.00, 'uploads/menu/veg-thali.jpg',     'veg',     1, 20, 0, 0, 6),
(15, 4, 'Herb Grilled Chicken',     'herb-grilled-chicken',    'Rosemary-garlic chicken breast, mashed potato and red wine jus.',              429.00, 'uploads/menu/grilled-chicken.jpg','non_veg',0, 25, 0, 0, 1),
(16, 4, 'Penne Arrabbiata',         'penne-arrabbiata',        'Penne in a spicy San Marzano tomato sauce with basil.',                        299.00, 'uploads/menu/penne-arrabbiata.jpg','veg',   2, 18, 0, 0, 2),
(17, 4, 'Wild Mushroom Risotto',    'wild-mushroom-risotto',   'Arborio rice, mixed mushrooms, parmesan and truffle oil.',                     389.00, 'uploads/menu/risotto.jpg',       'veg',     0, 25, 1, 0, 3),
(18, 4, 'Fish & Chips',             'fish-and-chips',          'Beer-battered basa, hand-cut fries, mushy peas and tartare.',                  399.00, 'uploads/menu/fish-chips.jpg',    'non_veg', 0, 20, 0, 0, 4),
(19, 5, 'Chhena Poda Cheesecake',   'chhena-poda-cheesecake',  'Odisha’s caramelised cottage-cheese dessert reimagined as a cheesecake.',      219.00, 'uploads/menu/chhena-poda.jpg',   'veg',     0, 10, 1, 0, 1),
(20, 5, 'Gulab Jamun with Rabdi',   'gulab-jamun-rabdi',       'Warm gulab jamun over chilled saffron rabdi.',                                 179.00, 'uploads/menu/gulab-jamun.jpg',   'veg',     0, 10, 0, 0, 2),
(21, 5, 'Chocolate Lava Cake',      'chocolate-lava-cake',     'Molten dark chocolate cake with vanilla bean ice cream.',                      249.00, 'uploads/menu/lava-cake.jpg',     'egg',     0, 15, 0, 1, 3),
(22, 6, 'Kokum Cooler',             'kokum-cooler',            'Tangy kokum with mint, black salt and soda.',                                  129.00, 'uploads/menu/kokum-cooler.jpg',  'veg',     0, 5,  0, 0, 1),
(23, 6, 'Masala Chaas',             'masala-chaas',            'Spiced buttermilk with roasted cumin and coriander.',                           99.00, 'uploads/menu/masala-chaas.jpg',  'veg',     0, 5,  0, 0, 2),
(24, 6, 'Kulhad Chai',              'kulhad-chai',             'Ginger-cardamom tea served in a clay cup.',                                     79.00, 'uploads/menu/kulhad-chai.jpg',   'veg',     0, 5,  0, 0, 3);

-- ---- Restaurant tables (10) ----------------------------------------
INSERT INTO restaurant_tables (id, table_number, capacity, location, status) VALUES
(1,  'T1',  2, 'Courtyard', 'occupied'),
(2,  'T2',  2, 'Courtyard', 'available'),
(3,  'T3',  4, 'Courtyard', 'occupied'),
(4,  'T4',  4, 'Courtyard', 'reserved'),
(5,  'T5',  4, 'Indoor',    'available'),
(6,  'T6',  6, 'Indoor',    'occupied'),
(7,  'T7',  6, 'Indoor',    'available'),
(8,  'T8',  8, 'Terrace',   'reserved'),
(9,  'T9',  4, 'Terrace',   'available'),
(10, 'T10', 10,'Terrace',   'available');

-- ---- Coupons --------------------------------------------------------
INSERT INTO coupons (id, code, description, discount_type, discount_value, min_order_amount, max_discount, usage_limit, valid_from, valid_until) VALUES
(1, 'COURTYARD15', '15% off on orders above ₹999',  'percent', 15.00, 999.00, 300.00, 500, '2026-01-01 00:00:00', '2027-12-31 23:59:59'),
(2, 'WELCOME100',  'Flat ₹100 off your first order', 'flat',   100.00, 499.00, NULL,   1000,'2026-01-01 00:00:00', '2027-12-31 23:59:59'),
(3, 'FESTIVE20',   '20% off festive special',        'percent', 20.00, 1499.00,500.00, 200, '2026-10-01 00:00:00', '2026-11-30 23:59:59');

-- ---- Sample orders (various statuses) ------------------------------
-- Dates are relative to import time so the dashboard & analytics have live data.
INSERT INTO orders (id, order_number, customer_name, customer_phone, customer_email, order_type, table_id, delivery_address, notes,
                    subtotal, discount, coupon_id, tax_percent, tax_amount, delivery_charge, total, payment_method, payment_status, status, is_seen, created_at) VALUES
(1,  'VC-SEED-0001', 'Ananya Mishra',   '9437000001', 'ananya@example.com', 'dine_in',  1,  NULL, NULL,                 638.00,   0.00, NULL, 5, 31.90,  0.00, 669.90, 'cod',      'pending', 'preparing',        1, NOW() - INTERVAL 25 MINUTE),
(2,  'VC-SEED-0002', 'Rahul Patnaik',   '9437000002', 'rahul@example.com',  'dine_in',  3,  NULL, 'Less spicy please',  1047.00,  0.00, NULL, 5, 52.35,  0.00, 1099.35,'razorpay', 'paid',    'served',           1, NOW() - INTERVAL 50 MINUTE),
(3,  'VC-SEED-0003', 'Sneha Das',       '9437000003', NULL,                 'dine_in',  3,  NULL, NULL,                 438.00,   0.00, NULL, 5, 21.90,  0.00, 459.90, 'cod',      'pending', 'confirmed',        1, NOW() - INTERVAL 10 MINUTE),
(4,  'VC-SEED-0004', 'Vikram Sahoo',    '9437000004', 'vikram@example.com', 'dine_in',  6,  NULL, 'Birthday — add a candle', 1696.00, 254.40, 1, 5, 72.08, 0.00, 1513.68,'razorpay','paid', 'preparing', 1, NOW() - INTERVAL 15 MINUTE),
(5,  'VC-SEED-0005', 'Priya Nayak',     '9437000005', 'priya@example.com',  'delivery', NULL, 'Flat 3B, Lotus Residency, Jaydev Vihar, Bhubaneswar 751013', 'Call on arrival', 728.00, 0.00, NULL, 5, 36.40, 40.00, 804.40, 'razorpay', 'paid', 'out_for_delivery', 1, NOW() - INTERVAL 35 MINUTE),
(6,  'VC-SEED-0006', 'Arjun Mohanty',   '9437000006', NULL,                 'takeaway', NULL, NULL, NULL,                 518.00,   0.00, NULL, 5, 25.90,  0.00, 543.90, 'cod',      'pending', 'ready',            1, NOW() - INTERVAL 20 MINUTE),
(7,  'VC-SEED-0007', 'Kavya Rath',      '9437000007', 'kavya@example.com',  'delivery', NULL, 'House 12, Lane 4, Nayapalli, Bhubaneswar 751012', NULL, 1128.00, 100.00, 2, 5, 51.40, 0.00, 1079.40, 'razorpay', 'paid', 'pending', 0, NOW() - INTERVAL 2 MINUTE),
(8,  'VC-SEED-0008', 'Sourav Behera',   '9437000008', 'sourav@example.com', 'delivery', NULL, 'B-204, Infocity Road, Patia, Bhubaneswar 751024', NULL, 897.00, 0.00, NULL, 5, 44.85, 0.00, 941.85, 'razorpay', 'paid', 'completed', 1, NOW() - INTERVAL 1 DAY),
(9,  'VC-SEED-0009', 'Meera Pradhan',   '9437000009', NULL,                 'dine_in',  5,  NULL, NULL,                 1268.00,  0.00, NULL, 5, 63.40,  0.00, 1331.40,'cod',      'paid',    'completed',        1, NOW() - INTERVAL 1 DAY - INTERVAL 3 HOUR),
(10, 'VC-SEED-0010', 'Aditya Jena',     '9437000010', 'aditya@example.com', 'takeaway', NULL, NULL, NULL,                 578.00,   0.00, NULL, 5, 28.90,  0.00, 606.90, 'razorpay', 'paid',    'completed',        1, NOW() - INTERVAL 2 DAY),
(11, 'VC-SEED-0011', 'Isha Panda',      '9437000011', 'isha@example.com',   'delivery', NULL, 'Plot 88, Sailashree Vihar, Bhubaneswar 751021', NULL, 448.00, 0.00, NULL, 5, 22.40, 40.00, 510.40, 'razorpay', 'refunded', 'cancelled', 1, NOW() - INTERVAL 3 DAY),
(12, 'VC-SEED-0012', 'Nikhil Swain',    '9437000012', NULL,                 'dine_in',  7,  NULL, NULL,                 2186.00,  0.00, NULL, 5, 109.30, 0.00, 2295.30,'razorpay', 'paid',    'completed',        1, NOW() - INTERVAL 4 DAY),
(13, 'VC-SEED-0013', 'Tanvi Acharya',   '9437000013', 'tanvi@example.com',  'delivery', NULL, 'Flat 7A, Rasulgarh, Bhubaneswar 751010', NULL, 836.00, 0.00, NULL, 5, 41.80, 0.00, 877.80, 'cod', 'paid', 'completed', 1, NOW() - INTERVAL 5 DAY),
(14, 'VC-SEED-0014', 'Rohan Biswal',    '9437000014', NULL,                 'takeaway', NULL, NULL, NULL,                 349.00,   0.00, NULL, 5, 17.45,  0.00, 366.45, 'cod',      'failed',  'cancelled',        1, NOW() - INTERVAL 6 DAY),
(15, 'VC-SEED-0015', 'Divya Senapati',  '9437000015', 'divya@example.com',  'dine_in',  10, NULL, 'Anniversary dinner', 2894.00, 578.80, 3, 5, 115.76, 0.00, 2430.96,'razorpay','paid', 'completed', 1, NOW() - INTERVAL 8 DAY);

-- Give seed orders real order numbers based on their creation date
UPDATE orders SET order_number = CONCAT('VC-', DATE_FORMAT(created_at, '%Y%m%d'), '-', LPAD(id, 4, '0')) WHERE order_number LIKE 'VC-SEED-%';

INSERT INTO order_items (order_id, menu_item_id, item_name, unit_price, quantity, line_total) VALUES
(1, 1,  'Courtyard Paneer Tikka', 289.00, 1, 289.00), (1, 12, 'Paneer Lababdar', 319.00, 1, 319.00), (1, 24, 'Kulhad Chai', 79.00, 2, 158.00),
(2, 5,  'Clay Oven Murgh Malai',  349.00, 1, 349.00), (2, 9,  'Village Mutton Curry', 449.00, 1, 449.00), (2, 11, 'Dal Courtyard', 259.00, 1, 259.00),
(3, 10, 'Butter Chicken', 379.00, 1, 379.00),
(4, 6,  'Mutton Seekh Kebab', 399.00, 2, 798.00), (4, 10, 'Butter Chicken', 379.00, 1, 379.00), (4, 11, 'Dal Courtyard', 259.00, 2, 518.00),
(5, 15, 'Herb Grilled Chicken', 429.00, 1, 429.00), (5, 16, 'Penne Arrabbiata', 299.00, 1, 299.00),
(6, 11, 'Dal Courtyard', 259.00, 2, 518.00),
(7, 9,  'Village Mutton Curry', 449.00, 2, 898.00), (7, 19, 'Chhena Poda Cheesecake', 219.00, 1, 219.00),
(8, 10, 'Butter Chicken', 379.00, 1, 379.00), (8, 11, 'Dal Courtyard', 259.00, 2, 518.00),
(9, 14, 'Courtyard Veg Thali', 349.00, 2, 698.00), (9, 1, 'Courtyard Paneer Tikka', 289.00, 1, 289.00), (9, 20, 'Gulab Jamun with Rabdi', 179.00, 1, 179.00),
(10, 17, 'Wild Mushroom Risotto', 389.00, 1, 389.00), (10, 20, 'Gulab Jamun with Rabdi', 179.00, 1, 179.00),
(11, 9, 'Village Mutton Curry', 449.00, 1, 449.00),
(12, 7, 'Tandoori Pomfret', 549.00, 2, 1098.00), (12, 13, 'Odia Machha Besara', 399.00, 2, 798.00), (12, 21, 'Chocolate Lava Cake', 249.00, 1, 249.00),
(13, 3, 'Chicken 65', 299.00, 1, 299.00), (13, 10, 'Butter Chicken', 379.00, 1, 379.00), (13, 22, 'Kokum Cooler', 129.00, 1, 129.00),
(14, 14, 'Courtyard Veg Thali', 349.00, 1, 349.00),
(15, 5, 'Clay Oven Murgh Malai', 349.00, 2, 698.00), (15, 9, 'Village Mutton Curry', 449.00, 2, 898.00), (15, 17, 'Wild Mushroom Risotto', 389.00, 2, 778.00), (15, 21, 'Chocolate Lava Cake', 249.00, 2, 498.00);

-- Recalculate every seed order's money fields from its line items so totals always reconcile
UPDATE orders o
JOIN (SELECT order_id, SUM(line_total) AS s FROM order_items GROUP BY order_id) x ON x.order_id = o.id
SET o.subtotal = x.s;

UPDATE orders o LEFT JOIN coupons c ON c.id = o.coupon_id
SET o.discount = CASE
      WHEN c.id IS NULL THEN 0
      WHEN c.discount_type = 'flat' THEN LEAST(c.discount_value, o.subtotal)
      ELSE LEAST(ROUND(o.subtotal * c.discount_value / 100, 2), COALESCE(c.max_discount, o.subtotal))
    END;

UPDATE orders
SET delivery_charge = IF(order_type = 'delivery' AND (subtotal - discount) < 799, 40, 0),
    tax_amount      = ROUND((subtotal - discount) * tax_percent / 100, 2);

UPDATE orders SET total = subtotal - discount + tax_amount + delivery_charge;

UPDATE coupons c SET used_count = (SELECT COUNT(*) FROM orders o WHERE o.coupon_id = c.id AND o.status <> 'cancelled');

-- ---- Status history & payments for seed orders ---------------------
INSERT INTO order_status_history (order_id, old_status, new_status, changed_by, note, created_at)
SELECT id, NULL, 'pending', NULL, 'Order placed', created_at FROM orders;

INSERT INTO order_status_history (order_id, old_status, new_status, changed_by, note, created_at)
SELECT id, 'pending', status, 2, NULL, created_at + INTERVAL 5 MINUTE FROM orders WHERE status <> 'pending';

INSERT INTO payments (order_id, method, razorpay_order_id, razorpay_payment_id, amount, status, refund_id, refund_amount, marked_by, created_at)
SELECT id, payment_method,
       IF(payment_method = 'razorpay', CONCAT('order_seed', LPAD(id, 8, '0')), NULL),
       IF(payment_method = 'razorpay' AND payment_status IN ('paid','refunded'), CONCAT('pay_seed', LPAD(id, 10, '0')), NULL),
       total,
       CASE payment_status WHEN 'paid' THEN 'paid' WHEN 'refunded' THEN 'refunded' WHEN 'failed' THEN 'failed' ELSE 'created' END,
       IF(payment_status = 'refunded', CONCAT('rfnd_seed', LPAD(id, 8, '0')), NULL),
       IF(payment_status = 'refunded', total, NULL),
       IF(payment_method = 'cod' AND payment_status = 'paid', 2, NULL),
       created_at
FROM orders;

-- ---- Reservations ---------------------------------------------------
INSERT INTO reservations (reference, name, phone, email, reservation_date, reservation_time, guests, table_preference, table_id, special_requests, status, handled_by) VALUES
(CONCAT('RS-', DATE_FORMAT(CURDATE(), '%Y%m%d'), '-0001'), 'Sanjay Rout',    '9438000001', 'sanjay@example.com', CURDATE(), '20:00:00', 4, 'Courtyard', 4,    'Window-side if possible', 'confirmed', 1),
(CONCAT('RS-', DATE_FORMAT(CURDATE(), '%Y%m%d'), '-0002'), 'Pooja Mallick',  '9438000002', NULL,                 CURDATE(), '21:00:00', 8, 'Terrace',   8,    'Birthday celebration',    'confirmed', 2),
(CONCAT('RS-', DATE_FORMAT(CURDATE(), '%Y%m%d'), '-0003'), 'Amit Dash',      '9438000003', 'amit@example.com',   CURDATE() + INTERVAL 1 DAY, '19:30:00', 2, 'Any', NULL, NULL, 'pending', NULL),
(CONCAT('RS-', DATE_FORMAT(CURDATE(), '%Y%m%d'), '-0004'), 'Lipsa Parida',   '9438000004', 'lipsa@example.com',  CURDATE() + INTERVAL 2 DAY, '13:00:00', 6, 'Indoor', NULL, 'High chair needed', 'pending', NULL),
(CONCAT('RS-', DATE_FORMAT(CURDATE(), '%Y%m%d'), '-0005'), 'Deepak Samal',   '9438000005', NULL,                 CURDATE() - INTERVAL 1 DAY, '20:30:00', 4, 'Courtyard', 3, NULL, 'completed', 2);

-- ---- Gallery --------------------------------------------------------
INSERT INTO gallery_categories (id, name, slug, sort_order) VALUES
(1, 'Food', 'food', 1), (2, 'Ambience', 'ambience', 2), (3, 'Events', 'events', 3);

INSERT INTO gallery_images (category_id, image, caption, sort_order) VALUES
(1, 'uploads/gallery/food-1.jpg', 'Village mutton curry in an iron kadhai', 1),
(1, 'uploads/gallery/food-2.jpg', 'Fresh from the clay oven', 2),
(1, 'uploads/gallery/food-3.jpg', 'Chhena poda cheesecake', 3),
(1, 'uploads/gallery/food-4.jpg', 'The courtyard thali', 4),
(2, 'uploads/gallery/ambience-1.jpg', 'Lantern-lit courtyard at dusk', 5),
(2, 'uploads/gallery/ambience-2.jpg', 'Terracotta walls and wooden tables', 6),
(2, 'uploads/gallery/ambience-3.jpg', 'The terrace under the stars', 7),
(3, 'uploads/gallery/events-1.jpg', 'A wedding reception in the courtyard', 8),
(3, 'uploads/gallery/events-2.jpg', 'Live folk music night', 9),
(3, 'uploads/gallery/events-3.jpg', 'Corporate dinner on the terrace', 10);

-- ---- Testimonials ---------------------------------------------------
INSERT INTO testimonials (name, designation, message, rating, sort_order) VALUES
('Ritika Mohapatra', 'Food Blogger',      'The village mutton curry tastes exactly like my grandmother made it. The courtyard at night is pure magic.', 5, 1),
('Arvind Menon',     'Regular Guest',     'Elegant without being stuffy. Staff remember our names and the chhena poda cheesecake is unmissable.', 5, 2),
('Sana Qureshi',     'Event Host',        'We hosted our anniversary dinner for 40 guests here. Flawless service, beautiful décor, and everyone is still talking about the food.', 5, 3),
('Manish Agarwal',   'Corporate Client',  'Fast delivery, food arrived hot and perfectly packed. Our go-to for team lunches now.', 4, 4);

-- ---- Contacts & newsletter -----------------------------------------
INSERT INTO contacts (name, email, phone, subject, message, is_read) VALUES
('Nandini Roy',  'nandini@example.com', '9439000001', 'Private dining enquiry', 'Do you have a private room for 25 people on a Saturday evening?', 0),
('Karan Sethi',  'karan@example.com',   NULL,         'Feedback',               'Loved the dal courtyard! Please add it to your takeaway combos.', 1);

INSERT INTO newsletter (email) VALUES ('ananya@example.com'), ('kavya@example.com'), ('foodie.bbsr@example.com');

-- ---- Page views (last 14 days of sample traffic for analytics) -----
INSERT INTO page_views (path, visitor_id, device, viewed_at)
SELECT ELT(1 + (n % 6), '/', '/menu', '/about', '/gallery', '/reservations', '/contact'),
       MD5(CONCAT('seed-visitor-', n % 180)),
       ELT(1 + (n % 3), 'desktop', 'mobile', 'mobile'),
       NOW() - INTERVAL (n % 14) DAY - INTERVAL (n % 11) HOUR
FROM (
  SELECT a.d + b.d * 10 + c.d * 100 AS n
  FROM (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a,
       (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b,
       (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4) c
) nums;

-- ---- First activity log entry --------------------------------------
INSERT INTO activity_logs (user_id, action, entity_type, description) VALUES
(1, 'install', 'system', 'Database installed with seed data');

-- =====================================================================
--  END
-- =====================================================================
