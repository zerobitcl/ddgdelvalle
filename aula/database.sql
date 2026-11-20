-- ══════════════════════════════════════════════════════
--  DDG Del Valle · Aula Virtual
--  Motor: MySQL 5.7+ / MariaDB 10.3+
--  Charset: utf8mb4
--
--  Flujo:
--    1) Admin carga usuarios (alumnos / profesores)
--    2) Admin crea cursos y asigna profesores
--    3) Profesor inscribe alumnos ya cargados
--    4) Cada inscripción queda en el log
--    5) Profe/admin publica link Meet/Zoom de la clase
-- ══════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `aula_usuarios` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rut`           VARCHAR(20)  NOT NULL,
  `nombre`        VARCHAR(255) NOT NULL,
  `email`         VARCHAR(255) NOT NULL DEFAULT '',
  `password_hash` VARCHAR(255) NOT NULL,
  `rol`           ENUM('admin','profesor','alumno') NOT NULL DEFAULT 'alumno',
  `activo`        TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rut` (`rut`),
  KEY `idx_rol` (`rol`),
  KEY `idx_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cuentas del aula virtual (admin, profesor, alumno)';

CREATE TABLE IF NOT EXISTS `aula_cursos` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `codigo`        VARCHAR(64)  NOT NULL,
  `nombre`        VARCHAR(255) NOT NULL,
  `descripcion`   VARCHAR(1000) NOT NULL DEFAULT '',
  `modalidad`     ENUM('presencial','online','mixta') NOT NULL DEFAULT 'online',
  `enlace_clase`  VARCHAR(1000) NOT NULL DEFAULT '' COMMENT 'Meet / Zoom / Teams vigente',
  `aula_url`      VARCHAR(500)  NOT NULL DEFAULT '' COMMENT 'URL de materiales (ej. modulo-alumno.php)',
  `video_url`     VARCHAR(1500) NOT NULL DEFAULT '' COMMENT 'URL clase grabada (OneDrive / Stream)',
  `video_titulo`  VARCHAR(255)  NOT NULL DEFAULT '' COMMENT 'Título visible de la clase grabada',
  `portada`       VARCHAR(500)  NOT NULL DEFAULT '',
  `relator`       VARCHAR(255)  NOT NULL DEFAULT '',
  `programa_pdf`  VARCHAR(1500) NOT NULL DEFAULT '' COMMENT 'Solo vista previa en el aula',
  `bajada`        VARCHAR(500)  NOT NULL DEFAULT '',
  `sincrono`      TINYINT(1)   NOT NULL DEFAULT 0,
  `valoracion`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `activo`        TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_codigo` (`codigo`),
  KEY `idx_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Catálogo de cursos del aula virtual';

CREATE TABLE IF NOT EXISTS `aula_inscripciones` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `curso_id`      INT UNSIGNED NOT NULL,
  `usuario_id`    INT UNSIGNED NOT NULL,
  `rol_en_curso`  ENUM('profesor','alumno') NOT NULL DEFAULT 'alumno',
  `inscrito_por`  INT UNSIGNED NULL DEFAULT NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_curso_usuario` (`curso_id`, `usuario_id`),
  KEY `idx_usuario` (`usuario_id`),
  KEY `idx_rol_curso` (`curso_id`, `rol_en_curso`),
  CONSTRAINT `fk_aula_ins_curso`
    FOREIGN KEY (`curso_id`) REFERENCES `aula_cursos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aula_ins_usuario`
    FOREIGN KEY (`usuario_id`) REFERENCES `aula_usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aula_ins_por`
    FOREIGN KEY (`inscrito_por`) REFERENCES `aula_usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Inscripciones (profesor o alumno) a un curso';

CREATE TABLE IF NOT EXISTS `aula_inscripciones_log` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `curso_id`       INT UNSIGNED NOT NULL,
  `usuario_id`     INT UNSIGNED NOT NULL,
  `accion`         ENUM('inscribir','desinscribir') NOT NULL,
  `rol_en_curso`   ENUM('profesor','alumno') NOT NULL,
  `realizado_por`  INT UNSIGNED NULL DEFAULT NULL,
  `detalle`        VARCHAR(500) NOT NULL DEFAULT '',
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_log_curso` (`curso_id`),
  KEY `idx_log_fecha` (`created_at`),
  CONSTRAINT `fk_aula_log_curso`
    FOREIGN KEY (`curso_id`) REFERENCES `aula_cursos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aula_log_usuario`
    FOREIGN KEY (`usuario_id`) REFERENCES `aula_usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aula_log_por`
    FOREIGN KEY (`realizado_por`) REFERENCES `aula_usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Historial de cada inscripción / baja';

CREATE TABLE IF NOT EXISTS `aula_modulos` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `curso_id`   INT UNSIGNED NOT NULL,
  `titulo`     VARCHAR(255) NOT NULL,
  `tipo`       ENUM('video','archivo','enlace') NOT NULL DEFAULT 'video',
  `url`        VARCHAR(1500) NOT NULL DEFAULT '',
  `orden`      INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mod_curso` (`curso_id`, `orden`),
  CONSTRAINT `fk_aula_mod_curso` FOREIGN KEY (`curso_id`) REFERENCES `aula_cursos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aula_progreso` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario_id`    INT UNSIGNED NOT NULL,
  `modulo_id`     INT UNSIGNED NOT NULL,
  `completado_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prog` (`usuario_id`, `modulo_id`),
  CONSTRAINT `fk_aula_prog_user` FOREIGN KEY (`usuario_id`) REFERENCES `aula_usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aula_prog_mod` FOREIGN KEY (`modulo_id`) REFERENCES `aula_modulos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aula_foro` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `curso_id`   INT UNSIGNED NOT NULL,
  `usuario_id` INT UNSIGNED NOT NULL,
  `mensaje`    VARCHAR(1000) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_foro_curso` (`curso_id`, `created_at`),
  CONSTRAINT `fk_aula_foro_curso` FOREIGN KEY (`curso_id`) REFERENCES `aula_cursos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aula_foro_user` FOREIGN KEY (`usuario_id`) REFERENCES `aula_usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aula_suscripciones` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario_id`     INT UNSIGNED NOT NULL,
  `tipo`           ENUM('mensual','diario') NOT NULL DEFAULT 'mensual',
  `meses`          INT UNSIGNED NOT NULL DEFAULT 0,
  `detalle`        VARCHAR(500) NOT NULL DEFAULT '',
  `vigente_desde`  DATE NULL DEFAULT NULL,
  `vigente_hasta`  DATE NULL DEFAULT NULL,
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sus_user` (`usuario_id`),
  CONSTRAINT `fk_aula_sus_user` FOREIGN KEY (`usuario_id`) REFERENCES `aula_usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aula_certificados` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario_id` INT UNSIGNED NOT NULL,
  `curso_id`   INT UNSIGNED NOT NULL,
  `emitido_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cert` (`usuario_id`, `curso_id`),
  CONSTRAINT `fk_aula_cert_user` FOREIGN KEY (`usuario_id`) REFERENCES `aula_usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aula_cert_curso` FOREIGN KEY (`curso_id`) REFERENCES `aula_cursos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aula_solicitudes` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario_id` INT UNSIGNED NOT NULL,
  `mensaje`    VARCHAR(1000) NOT NULL,
  `estado`     ENUM('pendiente','revisada') NOT NULL DEFAULT 'pendiente',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sol_user` (`usuario_id`, `created_at`),
  CONSTRAINT `fk_aula_sol_user` FOREIGN KEY (`usuario_id`) REFERENCES `aula_usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `aula_accesos` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario_id`    INT UNSIGNED NOT NULL,
  `curso_id`      INT UNSIGNED NOT NULL,
  `ultimo_acceso` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `veces`         INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_acceso` (`usuario_id`, `curso_id`),
  CONSTRAINT `fk_aula_acc_user` FOREIGN KEY (`usuario_id`) REFERENCES `aula_usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_aula_acc_curso` FOREIGN KEY (`curso_id`) REFERENCES `aula_cursos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
