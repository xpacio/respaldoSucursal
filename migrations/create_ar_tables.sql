-- Migration: create_ar_tables.sql
-- Tablas para el Agente de Respaldo (AR)

-- Tabla principal de clientes AR
CREATE TABLE IF NOT EXISTS ar_clients (
    id SERIAL PRIMARY KEY,
    rbfid VARCHAR(10) NOT NULL UNIQUE,
    enabled BOOLEAN DEFAULT true,
    registered_at TIMESTAMP DEFAULT NOW(),
    last_sync_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_ar_clients_rbfid ON ar_clients(rbfid);

-- Tabla de archivos por cliente
CREATE TABLE IF NOT EXISTS ar_files (
    id SERIAL PRIMARY KEY,
    rbfid VARCHAR(10) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_size BIGINT DEFAULT 0,
    chunk_count INTEGER DEFAULT 0,
    hash_xxh3 VARCHAR(20),
    updated_at TIMESTAMP DEFAULT NOW(),
    created_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(rbfid, file_name)
);

CREATE INDEX IF NOT EXISTS idx_ar_files_rbfid ON ar_files(rbfid);
CREATE INDEX IF NOT EXISTS idx_ar_files_rbfid_name ON ar_files(rbfid, file_name);

-- Tabla de chunks (hashes por archivo)
CREATE TABLE IF NOT EXISTS ar_file_hashes (
    id SERIAL PRIMARY KEY,
    rbfid VARCHAR(10) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    chunk_index INTEGER NOT NULL,
    hash_xxh3 VARCHAR(20) NOT NULL,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(rbfid, file_name, chunk_index)
);

CREATE INDEX IF NOT EXISTS idx_ar_hashes_rbfid_file ON ar_file_hashes(rbfid, file_name);

-- Tabla de sesiones activas (para slots)
CREATE TABLE IF NOT EXISTS ar_sessions (
    id SERIAL PRIMARY KEY,
    rbfid VARCHAR(10) NOT NULL,
    status VARCHAR(20) DEFAULT 'active',
    started_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    completed_at TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_ar_sessions_status ON ar_sessions(status, updated_at);

-- Tabla de historial de sincronizaciones
CREATE TABLE IF NOT EXISTS ar_sync_history (
    id SERIAL PRIMARY KEY,
    rbfid VARCHAR(10) NOT NULL,
    file_name VARCHAR(255),
    chunk_count INTEGER DEFAULT 0,
    bytes_transferred BIGINT DEFAULT 0,
    status VARCHAR(20) DEFAULT 'completed',
    error_message TEXT,
    started_at TIMESTAMP DEFAULT NOW(),
    completed_at TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_ar_sync_history_rbfid ON ar_sync_history(rbfid, started_at);