-- Agregar columna nombre a distribucion
ALTER TABLE distribucion ADD COLUMN nombre VARCHAR(100) NOT NULL DEFAULT '';

-- Agregar restricción única (nombre, tipo, plaza)
ALTER TABLE distribucion ADD UNIQUE (nombre, tipo, plaza);
