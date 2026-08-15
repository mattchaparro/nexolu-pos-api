-- Catalogo normalizado de medios de pago del POS + su pivote por negocio.
-- No vienen del dump original de produccion; ya viven en schema.sql
-- (lineas ~408 y ~1317, con la nota completa sobre por que no se llaman
-- "payment_methods" a secas). El legacy monolito no lee ni escribe estas
-- tablas. pos_payment_methods va primero: business_pos_payment_methods
-- tiene un FK hacia ella.

CREATE TABLE IF NOT EXISTS `pos_payment_methods` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pos_payment_methods_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `business_pos_payment_methods` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `business_id` bigint unsigned NOT NULL,
  `pos_payment_method_id` bigint unsigned NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bppm_business_payment_unique` (`business_id`,`pos_payment_method_id`),
  KEY `bppm_pos_payment_method_id_index` (`pos_payment_method_id`),
  CONSTRAINT `bppm_business_id_foreign` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bppm_pos_payment_method_id_foreign` FOREIGN KEY (`pos_payment_method_id`) REFERENCES `pos_payment_methods` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
