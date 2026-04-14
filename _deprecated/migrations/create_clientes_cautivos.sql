-- Tabla para respaldar clientes cautivos (permite regeneración después de reset)
CREATE TABLE IF NOT EXISTS clientes_cautivos (
    id SERIAL PRIMARY KEY,
    rbfid VARCHAR(5) NOT NULL,
    emp VARCHAR(3) NOT NULL,
    plaza VARCHAR(5) NOT NULL,
    created_at TIMESTAMP DEFAULT NOW(),
    CONSTRAINT clientes_cautivos_rbfid_unique UNIQUE(rbfid)
);

-- Comentarios para documentación
COMMENT ON TABLE clientes_cautivos IS 'Tabla de respaldo de clientes para regeneración después de reset del sistema';
COMMENT ON COLUMN clientes_cautivos.rbfid IS 'Identificador del cliente (5 caracteres)';
COMMENT ON COLUMN clientes_cautivos.emp IS 'Código de empresa (3 caracteres)';
COMMENT ON COLUMN clientes_cautivos.plaza IS 'Código de plaza (5 caracteres)';
