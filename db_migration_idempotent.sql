-- Idempotent migration for pst_project (MySQL 8.x)
-- Safe to run multiple times; uses INFORMATION_SCHEMA checks.

USE pst_project;

-- 1) Add raw columns if missing
SET @cnt := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'stock_taking' AND column_name = 'available_stock_raw');
SET @sql := IF(@cnt = 0, 'ALTER TABLE stock_taking ADD COLUMN available_stock_raw VARCHAR(100) NULL AFTER available_stock', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cnt := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'stock_taking' AND column_name = 'new_available_stock_raw');
SET @sql := IF(@cnt = 0, 'ALTER TABLE stock_taking ADD COLUMN new_available_stock_raw VARCHAR(100) NULL AFTER new_available_stock', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) Backfill raw columns where NULL
UPDATE stock_taking
  SET available_stock_raw = available_stock
WHERE available_stock_raw IS NULL;

UPDATE stock_taking
  SET new_available_stock_raw = new_available_stock
WHERE new_available_stock_raw IS NULL;

-- 3) Ensure numeric types for stock columns
SET @need := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'stock_taking' AND column_name = 'available_stock' AND (data_type <> 'decimal' OR numeric_precision <> 18 OR numeric_scale <> 4));
SET @sql := IF(@need > 0, 'ALTER TABLE stock_taking MODIFY available_stock DECIMAL(18,4) NULL', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @need := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'stock_taking' AND column_name = 'new_available_stock' AND (data_type <> 'decimal' OR numeric_precision <> 18 OR numeric_scale <> 4));
SET @sql := IF(@need > 0, 'ALTER TABLE stock_taking MODIFY new_available_stock DECIMAL(18,4) NULL', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4) Enforce NOT NULL on key columns
SET @need := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'stock_taking' AND column_name = 'material' AND is_nullable = 'YES');
SET @sql := IF(@need > 0, 'ALTER TABLE stock_taking MODIFY material VARCHAR(50) NOT NULL', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @need := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'stock_taking' AND column_name = 'inventory_number' AND is_nullable = 'YES');
SET @sql := IF(@need > 0, 'ALTER TABLE stock_taking MODIFY inventory_number VARCHAR(191) NOT NULL', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5) Add non-negative check constraints if missing
SET @cnt := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'stock_taking' AND constraint_type = 'CHECK' AND constraint_name = 'chk_available_nonneg');
SET @sql := IF(@cnt = 0, 'ALTER TABLE stock_taking ADD CONSTRAINT chk_available_nonneg CHECK (available_stock IS NULL OR available_stock >= 0)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cnt := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'stock_taking' AND constraint_type = 'CHECK' AND constraint_name = 'chk_new_available_nonneg');
SET @sql := IF(@cnt = 0, 'ALTER TABLE stock_taking ADD CONSTRAINT chk_new_available_nonneg CHECK (new_available_stock IS NULL OR new_available_stock >= 0)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6) Indexes for common queries
SET @cnt := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'stock_taking' AND index_name = 'idx_st_created');
SET @sql := IF(@cnt = 0, 'CREATE INDEX idx_st_created ON stock_taking (created_at DESC, id)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cnt := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'stock_taking' AND index_name = 'idx_st_area_created');
SET @sql := IF(@cnt = 0, 'CREATE INDEX idx_st_area_created ON stock_taking (area, created_at)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cnt := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'stock_taking' AND index_name = 'idx_st_resolved_created');
SET @sql := IF(@cnt = 0, 'CREATE INDEX idx_st_resolved_created ON stock_taking (resolved_at, created_at)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cnt := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'physical_notes' AND index_name = 'idx_pn_resolved_created');
SET @sql := IF(@cnt = 0, 'CREATE INDEX idx_pn_resolved_created ON physical_notes (resolved_at, created_at)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cnt := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'physical_notes' AND index_name = 'idx_pn_issue_resolved');
SET @sql := IF(@cnt = 0, 'CREATE INDEX idx_pn_issue_resolved ON physical_notes (issue_type, resolved_at)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @cnt := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'physical_notes' AND index_name = 'idx_pn_stock_fk');
SET @sql := IF(@cnt = 0, 'CREATE INDEX idx_pn_stock_fk ON physical_notes (stock_taking_id)', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7) Stock movements history table
CREATE TABLE IF NOT EXISTS stock_movements (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  stock_taking_id INT NOT NULL,
  movement_type ENUM('count','adjust','resolve','other') NOT NULL,
  qty_before DECIMAL(18,4) NULL,
  qty_after  DECIMAL(18,4) NULL,
  note TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sm_created (created_at, id),
  INDEX idx_sm_stock (stock_taking_id, created_at),
  CONSTRAINT fk_sm_stock FOREIGN KEY (stock_taking_id) REFERENCES stock_taking(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Note: Partitioning not applied automatically because unique index (material, inventory_number) does not include partition key (created_at).
-- To enable yearly partitions, either drop/adjust unique index or include created_at in the unique key, then add partitions with ALTER TABLE ... PARTITION BY RANGE (YEAR(created_at)).
