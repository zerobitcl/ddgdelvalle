-- ══════════════════════════════════════════════════════
--  DDG Del Valle · Asistencia por curso / sesión
--  Motor: MySQL 5.7+ / MariaDB 10.3+
--  Charset: utf8mb4
--
--  Flujo operativo:
--    1) Curso
--    2) Nómina de alumnos del curso
--    3) Sesión de clase (fecha + enlace online)
--    4) Asistencia por alumno en esa sesión
-- ══════════════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS `ddg_otec`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `ddg_otec`;

CREATE TABLE IF NOT EXISTS `asis_cursos` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `codigo`     VARCHAR(64)  NOT NULL COMMENT 'Slug interno único',
  `nombre`     VARCHAR(255) NOT NULL,
  `modalidad`  ENUM('presencial','online','mixta') NOT NULL DEFAULT 'online',
  `activo`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_codigo` (`codigo`),
  KEY `idx_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cursos operativos para toma de asistencia';

CREATE TABLE IF NOT EXISTS `asis_alumnos` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `curso_id`   INT UNSIGNED NOT NULL,
  `rut`        VARCHAR(20)  NOT NULL,
  `nombre`     VARCHAR(255) NOT NULL,
  `email`      VARCHAR(255) NOT NULL DEFAULT '',
  `activo`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_curso_rut` (`curso_id`, `rut`),
  KEY `idx_curso` (`curso_id`),
  CONSTRAINT `fk_alumnos_curso`
    FOREIGN KEY (`curso_id`) REFERENCES `asis_cursos` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Nómina de alumnos por curso';

CREATE TABLE IF NOT EXISTS `asis_sesiones` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `curso_id`       INT UNSIGNED NOT NULL,
  `fecha`          DATE         NOT NULL,
  `hora_inicio`    TIME         NOT NULL DEFAULT '09:00:00',
  `titulo`         VARCHAR(255) NOT NULL DEFAULT 'Clase',
  `enlace_online`  VARCHAR(1000) NOT NULL DEFAULT '' COMMENT 'Meet / Zoom / Teams',
  `notas`          VARCHAR(1000) NOT NULL DEFAULT '',
  `creado_por`     VARCHAR(120) NOT NULL DEFAULT 'relator',
  `cerrada`        TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_curso_fecha` (`curso_id`, `fecha`),
  CONSTRAINT `fk_sesiones_curso`
    FOREIGN KEY (`curso_id`) REFERENCES `asis_cursos` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sesiones de clase (una lista por sesión)';

CREATE TABLE IF NOT EXISTS `asis_registros` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sesion_id`    INT UNSIGNED NOT NULL,
  `alumno_id`    INT UNSIGNED NOT NULL,
  `estado`       ENUM('presente','ausente','justificado','tardanza','sin_marcar')
                               NOT NULL DEFAULT 'sin_marcar',
  `observacion`  VARCHAR(500)  NOT NULL DEFAULT '',
  `marcado_por`  VARCHAR(120)  NOT NULL DEFAULT '',
  `marcado_en`   TIMESTAMP     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sesion_alumno` (`sesion_id`, `alumno_id`),
  KEY `idx_estado` (`estado`),
  CONSTRAINT `fk_reg_sesion`
    FOREIGN KEY (`sesion_id`) REFERENCES `asis_sesiones` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_reg_alumno`
    FOREIGN KEY (`alumno_id`) REFERENCES `asis_alumnos` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Asistencia por alumno en una sesión';

INSERT INTO `asis_cursos` (`codigo`, `nombre`, `modalidad`, `activo`)
VALUES
  ('gastronomia-online', 'Gastronomía Online', 'online', 1),
  ('riego-tecnificado', 'Riego Tecnificado', 'mixta', 1)
ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`);
