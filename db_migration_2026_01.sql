-- Migration: performance and data quality improvements
-- Run in MySQL 8.x. Execute step by step; stop on errors.

USE pst_project;

-- 1) Add raw columns to preserve original Excel text (split for compatibility)
ALTER TABLE stock_taking
  ADD COLUMN available_stock_raw VARCHAR(100) NULL AFTER available_stock;

ALTER TABLE stock_taking
  ADD COLUMN new_available_stock_raw VARCHAR(100) NULL AFTER new_available_stock;

-- 2) Backfill raw from existing values (safe to re-run)
UPDATE stock_taking
  SET available_stock_raw = available_stock,
      new_available_stock_raw = new_available_stock
  WHERE available_stock_raw IS NULL OR new_available_stock_raw IS NULL;

ALTER TABLE stock_taking
  MODIFY available_stock DECIMAL(18,4) NULL;

ALTER TABLE stock_taking
  MODIFY new_available_stock DECIMAL(18,4) NULL;

-- 4) Enforce NOT NULL on key columns
ALTER TABLE stock_taking
  MODIFY material VARCHAR(50) NOT NULL,
  MODIFY inventory_number VARCHAR(191) NOT NULL;

ALTER TABLE stock_taking
  ADD CONSTRAINT chk_available_nonneg CHECK (available_stock IS NULL OR available_stock >= 0);

ALTER TABLE stock_taking
  ADD CONSTRAINT chk_new_available_nonneg CHECK (new_available_stock IS NULL OR new_available_stock >= 0);

-- 6) Indexes for common queries
CREATE INDEX idx_st_created ON stock_taking (created_at DESC, id);
CREATE INDEX idx_st_area_created ON stock_taking (area, created_at);
CREATE INDEX idx_st_resolved_created ON stock_taking (resolved_at, created_at);

CREATE INDEX idx_pn_resolved_created ON physical_notes (resolved_at, created_at);
CREATE INDEX idx_pn_issue_resolved ON physical_notes (issue_type, resolved_at);
CREATE INDEX idx_pn_stock_fk ON physical_notes (stock_taking_id);

-- 7) Stock movements history (append-only)
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

-- 8) Optional: unify charset/collation (uncomment if desired)
-- ALTER TABLE stock_taking CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
-- ALTER TABLE physical_notes CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

-- Notes:
-- - Partitioning is not applied here because existing unique indexes must include the partition key; do separately if needed.
-- - After running, use numeric columns (available_stock, new_available_stock) for filters and aggregations; use *_raw only for display.
