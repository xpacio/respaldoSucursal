-- Agregar columna last_heartbeat_at a clients para tracking de heartbeat
ALTER TABLE clients ADD COLUMN IF NOT EXISTS last_heartbeat_at TIMESTAMP;
