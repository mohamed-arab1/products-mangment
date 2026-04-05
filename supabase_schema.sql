-- ============================================================
-- Laravel Migrations SQL for Supabase (PostgreSQL)
-- Run this in: Supabase Dashboard → SQL Editor
-- ============================================================

-- migrations tracking table
CREATE TABLE IF NOT EXISTS migrations (
    id SERIAL PRIMARY KEY,
    migration VARCHAR(255) NOT NULL,
    batch INTEGER NOT NULL
);

-- users, password_reset_tokens, sessions
CREATE TYPE user_role AS ENUM ('admin', 'seller');

CREATE TABLE IF NOT EXISTS users (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    email_verified_at TIMESTAMP NULL,
    password VARCHAR(255) NOT NULL,
    role user_role NOT NULL DEFAULT 'seller',
    remember_token VARCHAR(100) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    email VARCHAR(255) PRIMARY KEY,
    token VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS sessions (
    id VARCHAR(255) PRIMARY KEY,
    user_id BIGINT NULL REFERENCES users(id),
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    payload TEXT NOT NULL,
    last_activity INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS sessions_user_id_index ON sessions(user_id);
CREATE INDEX IF NOT EXISTS sessions_last_activity_index ON sessions(last_activity);

-- cache
CREATE TABLE IF NOT EXISTS cache (
    key VARCHAR(255) PRIMARY KEY,
    value TEXT NOT NULL,
    expiration INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS cache_locks (
    key VARCHAR(255) PRIMARY KEY,
    owner VARCHAR(255) NOT NULL,
    expiration INTEGER NOT NULL
);

-- jobs
CREATE TABLE IF NOT EXISTS jobs (
    id BIGSERIAL PRIMARY KEY,
    queue VARCHAR(255) NOT NULL,
    payload TEXT NOT NULL,
    attempts SMALLINT NOT NULL,
    reserved_at INTEGER NULL,
    available_at INTEGER NOT NULL,
    created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS jobs_queue_index ON jobs(queue);

CREATE TABLE IF NOT EXISTS job_batches (
    id VARCHAR(255) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    total_jobs INTEGER NOT NULL,
    pending_jobs INTEGER NOT NULL,
    failed_jobs INTEGER NOT NULL,
    failed_job_ids TEXT NOT NULL,
    options TEXT NULL,
    cancelled_at INTEGER NULL,
    created_at INTEGER NOT NULL,
    finished_at INTEGER NULL
);

CREATE TABLE IF NOT EXISTS failed_jobs (
    id BIGSERIAL PRIMARY KEY,
    uuid VARCHAR(255) NOT NULL UNIQUE,
    connection TEXT NOT NULL,
    queue TEXT NOT NULL,
    payload TEXT NOT NULL,
    exception TEXT NOT NULL,
    failed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- personal_access_tokens (Sanctum)
CREATE TABLE IF NOT EXISTS personal_access_tokens (
    id BIGSERIAL PRIMARY KEY,
    tokenable_type VARCHAR(255) NOT NULL,
    tokenable_id BIGINT NOT NULL,
    name TEXT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    abilities TEXT NULL,
    last_used_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);
CREATE INDEX IF NOT EXISTS personal_access_tokens_tokenable_type_tokenable_id_index ON personal_access_tokens(tokenable_type, tokenable_id);
CREATE INDEX IF NOT EXISTS personal_access_tokens_expires_at_index ON personal_access_tokens(expires_at);

-- invoices
CREATE TABLE IF NOT EXISTS invoices (
    id BIGSERIAL PRIMARY KEY,
    seller_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    invoice_number VARCHAR(255) NOT NULL UNIQUE,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    sale_date DATE NOT NULL,
    notes TEXT NULL,
    -- buyer fields
    buyer_name VARCHAR(255) NULL,
    buyer_phone VARCHAR(255) NULL,
    buyer_email VARCHAR(255) NULL,
    buyer_address TEXT NULL,
    -- advanced fields
    discount_amount DECIMAL(12,2) NULL DEFAULT 0,
    tax_amount DECIMAL(12,2) NULL DEFAULT 0,
    subtotal DECIMAL(12,2) NULL DEFAULT 0,
    status VARCHAR(50) NULL DEFAULT 'draft',
    payment_method VARCHAR(50) NULL,
    due_date DATE NULL,
    loyalty_points_earned INTEGER NOT NULL DEFAULT 0,
    loyalty_points_redeemed INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);

-- invoice_items
CREATE TABLE IF NOT EXISTS invoice_items (
    id BIGSERIAL PRIMARY KEY,
    invoice_id BIGINT NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
    product_id BIGINT NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(12,2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    -- advanced fields
    discount_percent DECIMAL(5,2) NULL DEFAULT 0,
    notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);

-- targets
CREATE TABLE IF NOT EXISTS targets (
    id BIGSERIAL PRIMARY KEY,
    seller_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    target_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    achieved_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    month INTEGER NOT NULL,
    year INTEGER NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);

-- notifications
CREATE TABLE IF NOT EXISTS notifications (
    id CHAR(36) PRIMARY KEY,
    type VARCHAR(255) NOT NULL,
    notifiable_type VARCHAR(255) NOT NULL,
    notifiable_id BIGINT NOT NULL,
    data TEXT NOT NULL,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);
CREATE INDEX IF NOT EXISTS notifications_notifiable_type_notifiable_id_index ON notifications(notifiable_type, notifiable_id);

-- products
CREATE TABLE IF NOT EXISTS products (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    default_price DECIMAL(12,2) NULL,
    code VARCHAR(255) NULL,
    stock_quantity INTEGER NOT NULL DEFAULT 0,
    purchase_price DECIMAL(12,2) NULL,
    production_date DATE NULL,
    shelf_life_days INTEGER NULL,
    shelf_life_value INTEGER NULL,
    shelf_life_unit VARCHAR(16) NULL,
    expiry_date DATE NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);

-- Add product_id FK to invoice_items
ALTER TABLE invoice_items ADD COLUMN IF NOT EXISTS product_id_fk BIGINT NULL REFERENCES products(id) ON DELETE SET NULL;

-- payments
CREATE TABLE IF NOT EXISTS payments (
    id BIGSERIAL PRIMARY KEY,
    invoice_id BIGINT NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    method VARCHAR(50) NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'pending',
    reference VARCHAR(255) NULL,
    paid_at TIMESTAMP NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);

-- conversations
CREATE TABLE IF NOT EXISTS conversations (
    id BIGSERIAL PRIMARY KEY,
    customer_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    seller_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    last_message_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    UNIQUE(customer_id, seller_id)
);

-- messages
CREATE TABLE IF NOT EXISTS messages (
    id BIGSERIAL PRIMARY KEY,
    conversation_id BIGINT NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
    sender_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    body TEXT NOT NULL,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);

-- product_batches
CREATE TABLE IF NOT EXISTS product_batches (
    id BIGSERIAL PRIMARY KEY,
    product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
    production_date DATE NULL,
    shelf_life_value INTEGER NULL,
    shelf_life_unit VARCHAR(16) NULL,
    expiry_date DATE NULL,
    purchase_price DECIMAL(12,2) NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);
CREATE INDEX IF NOT EXISTS product_batches_product_id_expiry_date_index ON product_batches(product_id, expiry_date);

-- Insert migration records so Laravel knows they're done
INSERT INTO migrations (migration, batch) VALUES
('0001_01_01_000000_create_users_table', 1),
('0001_01_01_000001_create_cache_table', 1),
('0001_01_01_000002_create_jobs_table', 1),
('2025_02_26_000001_create_invoices_table', 1),
('2025_02_26_000002_create_invoice_items_table', 1),
('2025_02_26_000003_create_targets_table', 1),
('2025_02_26_000004_create_notifications_table', 1),
('2025_02_26_164700_add_role_to_users_table', 1),
('2025_02_26_170000_create_products_table', 1),
('2025_02_26_170001_add_product_id_to_invoice_items_table', 1),
('2025_02_26_180000_add_code_to_products_table', 1),
('2026_02_26_161830_create_personal_access_tokens_table', 1),
('2026_03_02_000000_add_buyer_fields_to_invoices_table', 1),
('2026_03_02_000001_add_stock_to_products_table', 1),
('2026_03_02_000002_add_buyer_address_to_invoices_table', 1),
('2026_03_17_000005_add_advanced_fields_to_invoices_and_items_table', 1),
('2026_03_17_000006_create_payments_table', 1),
('2026_04_01_000007_add_credit_and_loyalty_fields_to_invoices_table', 1),
('2026_04_01_000008_create_conversations_table', 1),
('2026_04_01_000009_create_messages_table', 1),
('2026_04_01_000010_create_whatsapp_messages_table', 1),
('2026_04_01_120000_add_inventory_and_pricing_fields_to_products_table', 1),
('2026_04_01_140000_add_shelf_life_unit_to_products_table', 1),
('2026_04_02_000000_drop_whatsapp_messages_table', 1),
('2026_04_02_100000_create_product_batches_table', 1)
ON CONFLICT DO NOTHING;
