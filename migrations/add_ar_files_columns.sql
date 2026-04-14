-- Add missing columns to ar_files
ALTER TABLE ar_files ADD COLUMN IF NOT EXISTS last_mtime bigint DEFAULT 0;
ALTER TABLE ar_files ADD COLUMN IF NOT EXISTS last_size bigint DEFAULT 0;