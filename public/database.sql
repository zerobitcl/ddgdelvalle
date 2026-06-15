-- ══════════════════════════════════════════════════════
--  DDG del Valle · Esquema de Base de Datos
--  Motor: MySQL 5.7+ / MariaDB 10.3+
--  Charset: utf8mb4 (soporta español y emojis)
-- ══════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `relatores` (

  -- Identificador
  `id`                   INT UNSIGNED     NOT NULL AUTO_INCREMENT,

  -- Campos del panel (9 requeridos)
  `curso`                VARCHAR(255)     NOT NULL                COMMENT 'Curso o taller que imparte',
  `nombre`               VARCHAR(255)     NOT NULL                COMMENT 'Nombre completo del relator',
  `rut`                  VARCHAR(20)      NOT NULL                COMMENT 'RUT formato 12.345.678-9',
  `carrera`              VARCHAR(255)     NOT NULL DEFAULT ''     COMMENT 'Carrera o profesión',
  `correo`               VARCHAR(255)     NOT NULL DEFAULT ''     COMMENT 'Correo de contacto',
  `vigencia`             ENUM('Activo','Inactivo','Pendiente')
                                          NOT NULL DEFAULT 'Pendiente' COMMENT 'Estado del relator',
  `carpeta`              VARCHAR(1000)    NOT NULL DEFAULT ''     COMMENT 'URL carpeta digital (Drive, etc.)',
  `telefono`             VARCHAR(30)      NOT NULL DEFAULT ''     COMMENT 'Teléfono de contacto',

  -- Datos de transferencia bancaria (campo 8, aplanado)
  `banco`                VARCHAR(150)     NOT NULL DEFAULT ''     COMMENT 'Nombre del banco',
  `tipo_cuenta`          VARCHAR(100)     NOT NULL DEFAULT ''     COMMENT 'Tipo de cuenta bancaria',
  `numero_cuenta`        VARCHAR(100)     NOT NULL DEFAULT ''     COMMENT 'Número de cuenta',
  `correo_transferencia` VARCHAR(255)     NOT NULL DEFAULT ''     COMMENT 'Correo para notificación de pago',

  -- Auditoría
  `created_at`           TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                          ON UPDATE CURRENT_TIMESTAMP,

  -- Restricciones
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rut` (`rut`),
  INDEX `idx_vigencia` (`vigencia`),
  INDEX `idx_nombre`   (`nombre`),
  INDEX `idx_curso`    (`curso`)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Registro de relatores OTEC DDG del Valle';

CREATE TABLE IF NOT EXISTS `inventario_state` (
  `id`         TINYINT UNSIGNED NOT NULL,
  `state_json` LONGTEXT NOT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Estado centralizado de inventario, clases y asignaciones';


-- ══════════════════════════════════════════════════════
--  (OPCIONAL) Datos de prueba para verificar la conexión
--  Eliminar este bloque antes de producción real
-- ══════════════════════════════════════════════════════

INSERT INTO `relatores`
  (`curso`, `nombre`, `rut`, `carrera`, `correo`, `vigencia`, `carpeta`, `telefono`,
   `banco`, `tipo_cuenta`, `numero_cuenta`, `correo_transferencia`)
VALUES
  ('Primeros Auxilios', 'María González Rojas', '12.345.678-9',
   'Técnico en Enfermería', 'm.gonzalez@relatores.cl', 'Activo',
   'https://drive.google.com/drive/folders/mock_gonzalez', '+56 9 8765 4321',
   'Banco de Chile', 'Cuenta Corriente', '00-123-45678-9', 'm.gonzalez@pagos.cl'),

  ('Seguridad Industrial y Prevención de Riesgos', 'Carlos Pérez Muñoz', '9.876.543-2',
   'Ingeniería en Prevención de Riesgos', 'c.perez@relatores.cl', 'Activo',
   'https://drive.google.com/drive/folders/mock_perez', '+56 9 7654 3210',
   'BancoEstado', 'Cuenta RUT', '9876543', 'c.perez@pagos.cl'),

  ('Excel Avanzado para Gestión Empresarial', 'Ana Martínez López', '15.678.901-K',
   'Ingeniería Comercial', 'a.martinez@relatores.cl', 'Pendiente',
   'https://drive.google.com/drive/folders/mock_martinez', '+56 9 6543 2109',
   'Santander', 'Cuenta Vista', '56-789-01234-K', 'a.martinez@pagos.cl'),

  ('Atención al Cliente y Habilidades Blandas', 'Roberto Silva Contreras', '11.234.567-8',
   'Psicología Organizacional', 'r.silva@relatores.cl', 'Activo',
   'https://drive.google.com/drive/folders/mock_silva', '+56 9 5432 1098',
   'Scotiabank', 'Cuenta Corriente', '11-234-56789-0', 'r.silva@pagos.cl'),

  ('Liderazgo y Trabajo en Equipo', 'Patricia Flores Vega', '13.456.789-0',
   'Administración de Empresas', 'p.flores@relatores.cl', 'Inactivo',
   'https://drive.google.com/drive/folders/mock_flores', '+56 9 4321 0987',
   'Itaú', 'Cuenta Corriente', '13-456-78901-2', 'p.flores@pagos.cl');
