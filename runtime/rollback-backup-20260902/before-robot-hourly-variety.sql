-- MySQL dump 10.13  Distrib 8.0.45, for Linux (x86_64)
--
-- Host: localhost    Database: kuaipaisan
-- ------------------------------------------------------
-- Server version	8.0.45

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `robot_accounts`
--

DROP TABLE IF EXISTS `robot_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `robot_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `site_id` bigint unsigned NOT NULL,
  `organization_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `name` varchar(120) NOT NULL,
  `username` varchar(80) NOT NULL,
  `plain_password` varchar(255) NOT NULL,
  `min_amount` decimal(14,2) NOT NULL DEFAULT '1.00',
  `max_amount` decimal(14,2) NOT NULL DEFAULT '100.00',
  `amount_precision` tinyint unsigned NOT NULL DEFAULT '0',
  `start_at` datetime NOT NULL,
  `next_run_at` datetime DEFAULT NULL,
  `last_bet_at` datetime DEFAULT NULL,
  `interval_min` int unsigned NOT NULL DEFAULT '3',
  `interval_max` int unsigned NOT NULL DEFAULT '5',
  `weight_fu` decimal(8,2) NOT NULL DEFAULT '1.00',
  `weight_ti` decimal(8,2) NOT NULL DEFAULT '1.00',
  `weight_futi` decimal(8,2) NOT NULL DEFAULT '1.00',
  `lottery_configs` json DEFAULT NULL,
  `skip_windows` json DEFAULT NULL,
  `win_weight` decimal(5,2) NOT NULL DEFAULT '50.00',
  `monthly_rules` json DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'stopped',
  `converted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_robot_site_username` (`site_id`,`username`),
  UNIQUE KEY `uk_robot_user` (`user_id`),
  KEY `idx_robot_schedule` (`status`,`next_run_at`),
  KEY `idx_robot_scope` (`tenant_id`,`site_id`,`organization_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `robot_accounts`
--

LOCK TABLES `robot_accounts` WRITE;
/*!40000 ALTER TABLE `robot_accounts` DISABLE KEYS */;
INSERT INTO `robot_accounts` VALUES (1,1,15,44,40,'ch135','ch135','ch113355',1.00,1000.00,0,'2024-04-16 10:42:00',NULL,NULL,3,5,1.00,1.00,1.00,'[{\"enabled\": true, \"lottery_id\": 2}, {\"enabled\": true, \"lottery_id\": 1}]',NULL,50.00,NULL,'stopped',NULL,'2026-09-01 18:43:30','2026-09-02 17:35:53'),(2,1,15,44,41,'ch136','ch136','ch113366',1.00,3000.00,0,'2024-04-02 13:11:00',NULL,NULL,3,5,1.00,1.00,1.00,'[{\"enabled\": true, \"lottery_id\": 2}, {\"enabled\": true, \"lottery_id\": 1}]',NULL,50.00,NULL,'stopped',NULL,'2026-09-01 21:12:07','2026-09-02 17:35:45'),(3,1,15,49,42,'ch137','ch137','ch113377',1.00,1000.00,0,'2026-06-02 13:17:00',NULL,NULL,3,5,3.00,1.00,2.00,'[{\"enabled\": true, \"lottery_id\": 2}, {\"enabled\": true, \"lottery_id\": 1}]','[{\"end\": \"16:04\", \"start\": \"00:00\"}, {\"end\": \"16:59\", \"start\": \"16:32\"}]',50.00,'[{\"month\": \"2026-06\", \"max_amount\": \"100000.00\", \"win_weight\": \"80.00\"}, {\"month\": \"2026-07\", \"max_amount\": \"50000.00\", \"win_weight\": \"70.00\"}, {\"month\": \"2026-08\", \"max_amount\": \"100000.00\", \"win_weight\": \"10.00\"}, {\"month\": \"2026-09\", \"max_amount\": \"100000.00\", \"win_weight\": \"10.00\"}]','stopped',NULL,'2026-09-01 21:18:09','2026-09-02 20:49:37');
/*!40000 ALTER TABLE `robot_accounts` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-02 12:55:19
