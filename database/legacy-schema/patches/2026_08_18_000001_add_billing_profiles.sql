-- billing_profiles: tabla nueva, agregada a schema.sql sin su patch
-- correspondiente (encontrado al correr la suite completa - los entornos ya
-- provisionados, incluidos los deploys via deploy.sh, se quedaban sin esta
-- tabla). Definicion identica a la de schema.sql.
CREATE TABLE IF NOT EXISTS `billing_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `business_id` bigint unsigned NOT NULL,
  `document_type` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `document_number` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `full_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `billing_profiles_business_id_unique` (`business_id`),
  CONSTRAINT `billing_profiles_business_id_foreign` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
