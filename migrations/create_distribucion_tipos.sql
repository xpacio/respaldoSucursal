-- Normalizar files y dst_template en tabla distribucion_tipos
-- Fecha: 2026-03-28

-- 1. Crear tabla de tipos
CREATE TABLE IF NOT EXISTS distribucion_tipos (
    tipo text PRIMARY KEY,
    files text NOT NULL,
    dst_template text NOT NULL
);

-- 2. Migrar datos existentes
INSERT INTO distribucion_tipos (tipo, files, dst_template)
SELECT DISTINCT tipo, files, dst_template FROM distribucion
ON CONFLICT (tipo) DO NOTHING;

-- 3. Agregar FK en distribucion
ALTER TABLE distribucion
    ADD CONSTRAINT distribucion_tipo_fkey
    FOREIGN KEY (tipo) REFERENCES distribucion_tipos(tipo);

-- 4. Quitar columnas redundantes
ALTER TABLE distribucion DROP COLUMN files;
ALTER TABLE distribucion DROP COLUMN dst_template;
