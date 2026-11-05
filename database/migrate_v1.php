<?php

declare(strict_types=1);

namespace Kontor\Shop\Database;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Migration v1 - legt alle Basistabellen des Plugins an (Abschnitt 5.3 des
 * Umsetzungsplans). Nutzt dbDelta(), ist daher idempotent: wiederholtes
 * Aufrufen legt nichts doppelt an und gleicht Schemaaenderungen inkrementell
 * ab. Wird von Kontor\Shop\Core\Lifecycle bei Aktivierung und bei jedem
 * Versionssprung aufgerufen.
 *
 * Keine echten FOREIGN-KEY-Constraints (dbDelta unterstuetzt diese nicht
 * zuverlaessig) - Referenzen werden stattdessen per Index abgesichert und in
 * der jeweiligen Repository-Klasse geprueft.
 */
function migrate_v1(): void
{
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $p               = $wpdb->prefix . 'kontor_shop_';

    $sql = "
CREATE TABLE {$p}log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  level VARCHAR(16) NOT NULL,
  channel VARCHAR(32) NOT NULL,
  reference VARCHAR(16) NULL,
  message VARCHAR(500) NOT NULL,
  context TEXT NULL,
  user_id BIGINT UNSIGNED NULL,
  url VARCHAR(255) NULL,
  PRIMARY KEY  (id),
  KEY created_idx (created_at),
  KEY level_idx (level),
  KEY reference_idx (reference)
) {$charset_collate};

CREATE TABLE {$p}mail_outbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at DATETIME NOT NULL,
  next_attempt_at DATETIME NOT NULL,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  status VARCHAR(16) NOT NULL,
  mailer VARCHAR(32) NOT NULL DEFAULT '',
  event VARCHAR(64) NOT NULL DEFAULT '',
  recipient VARCHAR(190) NOT NULL,
  reference VARCHAR(32) NOT NULL DEFAULT '',
  subject VARCHAR(255) NOT NULL DEFAULT '',
  payload LONGTEXT NOT NULL,
  last_error VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY due_idx (status, next_attempt_at),
  KEY recipient_idx (recipient)
) {$charset_collate};

CREATE TABLE {$p}audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at DATETIME NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  user_login VARCHAR(60) NULL,
  actor VARCHAR(190) NULL,
  action VARCHAR(64) NOT NULL,
  object_type VARCHAR(32) NOT NULL,
  object_id VARCHAR(64) NULL,
  object_label VARCHAR(190) NULL,
  changes MEDIUMTEXT NULL,
  source VARCHAR(16) NOT NULL,
  ip VARCHAR(45) NULL,
  key_id CHAR(8) NOT NULL,
  prev_hash CHAR(64) NOT NULL,
  hash CHAR(64) NOT NULL,
  PRIMARY KEY  (id),
  KEY created_idx (created_at),
  KEY object_idx (object_type,object_id),
  KEY user_idx (user_id),
  KEY action_idx (action)
) {$charset_collate};

CREATE TABLE {$p}shop_types (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(190) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  accent_color VARCHAR(7) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  page_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY slug_idx (slug)
) {$charset_collate};

CREATE TABLE {$p}categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  shop_type_id BIGINT UNSIGNED NULL,
  parent_id BIGINT UNSIGNED NULL,
  name VARCHAR(190) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  accent_color VARCHAR(7) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY slug_idx (slug),
  KEY shop_type_idx (shop_type_id),
  KEY parent_idx (parent_id)
) {$charset_collate};

CREATE TABLE {$p}warehouses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(190) NOT NULL,
  contact_name VARCHAR(190) NOT NULL DEFAULT '',
  address TEXT NULL,
  manager_user_id BIGINT UNSIGNED NULL,
  bank_account_id VARCHAR(32) NULL,
  color VARCHAR(7) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY manager_user_idx (manager_user_id)
) {$charset_collate};

CREATE TABLE {$p}products (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  warehouse_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NULL,
  sku VARCHAR(64) NOT NULL DEFAULT '',
  name VARCHAR(190) NOT NULL,
  slug VARCHAR(190) NOT NULL DEFAULT '',
  gtin VARCHAR(14) NOT NULL DEFAULT '',
  brand VARCHAR(120) NOT NULL DEFAULT '',
  description LONGTEXT NULL,
  price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  member_discount_type VARCHAR(16) NOT NULL DEFAULT 'none',
  member_discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  image_id BIGINT UNSIGNED NULL,
  image_alt VARCHAR(255) NOT NULL DEFAULT '',
  variant_options TEXT NULL,
  unit_amount DECIMAL(10,3) NULL,
  unit_type VARCHAR(8) NOT NULL DEFAULT '',
  tax_rate DECIMAL(5,2) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  archived TINYINT(1) NOT NULL DEFAULT 0,
  discontinued TINYINT(1) NOT NULL DEFAULT 0,
  weight DECIMAL(10,3) NULL,
  min_stock INT NULL,
  content_updated_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY warehouse_idx (warehouse_id),
  KEY category_idx (category_id),
  KEY sku_idx (sku),
  KEY active_idx (active),
  KEY archived_idx (archived),
  UNIQUE KEY slug_idx (slug)
) {$charset_collate};

CREATE TABLE {$p}product_variants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  option1 VARCHAR(100) NOT NULL DEFAULT '',
  option2 VARCHAR(100) NOT NULL DEFAULT '',
  option3 VARCHAR(100) NOT NULL DEFAULT '',
  sku VARCHAR(64) NOT NULL DEFAULT '',
  gtin VARCHAR(14) NOT NULL DEFAULT '',
  price DECIMAL(10,2) NULL,
  weight DECIMAL(10,3) NULL,
  unit_amount DECIMAL(10,3) NULL,
  image_id BIGINT UNSIGNED NULL,
  sort_order INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY product_idx (product_id),
  KEY sku_idx (sku),
  KEY image_idx (image_id)
) {$charset_collate};

CREATE TABLE {$p}product_images (
  product_id BIGINT UNSIGNED NOT NULL,
  attachment_id BIGINT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY  (product_id, attachment_id),
  KEY attachment_idx (attachment_id)
) {$charset_collate};

CREATE TABLE {$p}product_categories (
  product_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY  (product_id, category_id),
  KEY category_idx (category_id)
) {$charset_collate};

CREATE TABLE {$p}product_tombstones (
  product_id BIGINT UNSIGNED NOT NULL,
  slug VARCHAR(190) NOT NULL DEFAULT '',
  removed_at DATETIME NOT NULL,
  PRIMARY KEY  (product_id)
) {$charset_collate};

CREATE TABLE {$p}stock_movements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  type VARCHAR(32) NOT NULL,
  quantity_delta INT NOT NULL,
  order_id BIGINT UNSIGNED NULL,
  note TEXT NULL,
  user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY product_idx (product_id),
  KEY product_variant_idx (product_id,variant_id),
  KEY order_idx (order_id),
  KEY type_idx (type)
) {$charset_collate};

CREATE TABLE {$p}stock_reservations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  quantity INT UNSIGNED NOT NULL,
  session_key VARCHAR(191) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY product_idx (product_id),
  KEY session_key_idx (session_key),
  KEY expires_idx (expires_at)
) {$charset_collate};

CREATE TABLE {$p}orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_number VARCHAR(32) NOT NULL,
  payment_reference VARCHAR(64) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'open',
  is_member TINYINT(1) NOT NULL DEFAULT 0,
  customer_name VARCHAR(190) NOT NULL,
  customer_email VARCHAR(190) NOT NULL,
  customer_phone VARCHAR(64) NULL,
  delivery_address TEXT NULL,
  billing_name VARCHAR(190) NULL,
  billing_address TEXT NULL,
  billing_vat_id VARCHAR(32) NULL,
  self_pickup TINYINT(1) NOT NULL DEFAULT 0,
  shipping_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  items_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  payment_method VARCHAR(32) NOT NULL DEFAULT 'prepayment',
  bank_account_id VARCHAR(32) NULL,
  checkout_token VARCHAR(64) NULL,
  shipping_tax TEXT NULL,
  legal_accepted_at DATETIME NULL,
  paid_at DATETIME NULL,
  paid_by_user_id BIGINT UNSIGNED NULL,
  paid_via VARCHAR(64) NULL,
  paid_by_name VARCHAR(190) NULL,
  paid_with VARCHAR(32) NULL,
  shipped_at DATETIME NULL,
  shipped_by_user_id BIGINT UNSIGNED NULL,
  cancelled_at DATETIME NULL,
  refund_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  refunded_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  discount_code VARCHAR(40) NULL,
  discount_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY order_number_idx (order_number),
  UNIQUE KEY payment_reference_idx (payment_reference),
  KEY status_idx (status),
  KEY checkout_token_idx (checkout_token),
  KEY updated_idx (updated_at)
) {$charset_collate};

CREATE TABLE {$p}order_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  warehouse_id BIGINT UNSIGNED NOT NULL,
  product_name VARCHAR(190) NOT NULL,
  variant_label VARCHAR(190) NOT NULL DEFAULT '',
  quantity INT UNSIGNED NOT NULL,
  list_price DECIMAL(10,2) NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  line_total DECIMAL(10,2) NOT NULL,
  discount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  tax_rate DECIMAL(5,2) NULL,
  shipment_id BIGINT UNSIGNED NULL,
  cancelled_at DATETIME NULL,
  cancel_note VARCHAR(255) NULL,
  returned_qty INT UNSIGNED NOT NULL DEFAULT 0,
  returned_at DATETIME NULL,
  PRIMARY KEY  (id),
  KEY order_idx (order_id),
  KEY product_idx (product_id),
  KEY warehouse_idx (warehouse_id),
  KEY shipment_idx (shipment_id)
) {$charset_collate};

CREATE TABLE {$p}shipments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  warehouse_id BIGINT UNSIGNED NULL,
  type VARCHAR(16) NOT NULL DEFAULT 'shipped',
  carrier VARCHAR(32) NULL,
  tracking_number VARCHAR(190) NULL,
  note TEXT NULL,
  user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY order_idx (order_id)
) {$charset_collate};

CREATE TABLE {$p}order_status_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  from_status VARCHAR(32) NULL,
  to_status VARCHAR(32) NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  actor VARCHAR(190) NULL,
  note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY order_idx (order_id)
) {$charset_collate};

CREATE TABLE {$p}stock_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  product_name VARCHAR(190) NOT NULL DEFAULT '',
  variant_label VARCHAR(190) NOT NULL DEFAULT '',
  email VARCHAR(190) NOT NULL,
  name VARCHAR(190) NOT NULL DEFAULT '',
  quantity INT UNSIGNED NOT NULL DEFAULT 0,
  message TEXT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'open',
  token CHAR(32) NOT NULL,
  source VARCHAR(16) NOT NULL DEFAULT 'shop',
  admin_note TEXT NULL,
  handled_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  confirmed_at DATETIME NULL,
  notified_at DATETIME NULL,
  closed_at DATETIME NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token_idx (token),
  KEY product_status_idx (product_id,status),
  KEY status_idx (status),
  KEY email_idx (email)
) {$charset_collate};

CREATE TABLE {$p}coupons (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(40) NOT NULL,
  campaign VARCHAR(60) NOT NULL DEFAULT '',
  type VARCHAR(16) NOT NULL DEFAULT 'percent',
  value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  min_order DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  valid_from DATE NULL,
  valid_until DATE NULL,
  max_uses INT UNSIGNED NULL,
  once_per_customer TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  note TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY code_idx (code),
  KEY campaign_idx (campaign)
) {$charset_collate};

CREATE TABLE {$p}coupon_uses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  coupon_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  checkout_token VARCHAR(64) NOT NULL DEFAULT '',
  customer_email VARCHAR(190) NOT NULL DEFAULT '',
  amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  released_at DATETIME NULL,
  PRIMARY KEY  (id),
  KEY coupon_idx (coupon_id),
  KEY order_idx (order_id),
  KEY email_idx (customer_email)
) {$charset_collate};

CREATE TABLE {$p}invoices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'invoice',
  series VARCHAR(20) NOT NULL,
  seq INT UNSIGNED NOT NULL,
  number VARCHAR(40) NOT NULL,
  issued_at DATETIME NOT NULL,
  total DECIMAL(12,2) NOT NULL,
  refers_to BIGINT UNSIGNED NULL,
  data LONGTEXT NOT NULL,
  file VARCHAR(255) NOT NULL DEFAULT '',
  created_by BIGINT UNSIGNED NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY series_seq_idx (series,seq),
  UNIQUE KEY number_idx (number),
  KEY order_idx (order_id)
) {$charset_collate};
CREATE TABLE {$p}api_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id VARCHAR(32) NOT NULL,
  idempotency_key VARCHAR(64) NOT NULL,
  request_hash CHAR(64) NOT NULL,
  response_code SMALLINT UNSIGNED NOT NULL,
  response_body LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY client_key_idx (client_id,idempotency_key),
  KEY created_idx (created_at)
) {$charset_collate};
";

    dbDelta($sql);
}
