-- Log de cada envio saliente de WhatsApp (mismo shape que email_logs). No
-- viene del dump original de produccion (2026-08-03); se agrego el
-- 2026-08-13 y ya vive en schema.sql (linea ~2300, con la nota completa).
-- El legacy monolito no lee ni escribe esta tabla.

CREATE TABLE IF NOT EXISTS `whatsapp_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `business_id` bigint unsigned DEFAULT NULL,
  `type` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `to_phone` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('sent','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sent',
  `error` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `whatsapp_logs_business_id_created_at_index` (`business_id`,`created_at`),
  KEY `whatsapp_logs_type_created_at_index` (`type`,`created_at`),
  CONSTRAINT `whatsapp_logs_business_id_foreign` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
