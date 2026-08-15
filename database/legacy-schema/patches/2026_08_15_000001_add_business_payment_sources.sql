-- Guarda, por negocio, los payment_source_id de tarjeta/Nequi ya
-- tokenizados para reuso via Nexolu Payments Core ("Fuentes de Pago" de
-- Wompi). No viene del dump original de produccion; ya vive en schema.sql
-- (linea ~385, con la nota completa sobre por que status='removed' es un
-- soft-delete local en vez de depender de un "void" de Wompi, que no
-- funciona para fuentes de pago normales). El legacy monolito no lee ni
-- escribe esta tabla.

CREATE TABLE IF NOT EXISTS `business_payment_sources` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `business_id` bigint unsigned NOT NULL,
  `provider_slug` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'wompi',
  `payment_source_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `business_payment_sources_provider_source_unique` (`provider_slug`,`payment_source_id`),
  KEY `business_payment_sources_business_id_status_index` (`business_id`,`status`),
  CONSTRAINT `business_payment_sources_business_id_foreign` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
