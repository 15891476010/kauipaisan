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
-- Table structure for table `site_users`
--

DROP TABLE IF EXISTS `site_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `site_users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `site_id` bigint unsigned NOT NULL,
  `organization_id` bigint unsigned DEFAULT NULL,
  `username` varchar(80) NOT NULL,
  `display_name` varchar(120) NOT NULL,
  `remark` varchar(255) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `balance` decimal(18,2) NOT NULL DEFAULT '0.00',
  `credit_balance` decimal(18,2) NOT NULL DEFAULT '0.00',
  `used_balance` decimal(18,2) NOT NULL DEFAULT '0.00',
  `interception_rate` decimal(8,4) DEFAULT '0.0000',
  `password` varchar(255) NOT NULL,
  `must_change_password` tinyint(1) DEFAULT '0',
  `status` tinyint NOT NULL DEFAULT '1',
  `account_state` varchar(20) DEFAULT 'enabled',
  `last_login_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `last_login_location` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_site_user_username` (`site_id`,`username`),
  KEY `idx_site_user_deleted` (`site_id`,`deleted_at`),
  KEY `idx_site_user_organization` (`site_id`,`organization_id`)
) ENGINE=InnoDB AUTO_INCREMENT=43 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `site_users`
--

LOCK TABLES `site_users` WRITE;
/*!40000 ALTER TABLE `site_users` DISABLE KEYS */;
INSERT INTO `site_users` VALUES (36,1,15,42,'user1','user1','',NULL,8809.00,5244.00,4429.00,20.0000,'$2y$10$ykgzOkxemYS9alHUGqhtIORvV95/pQ86pFq/yLAxdDNR6jk0v2Pi6',0,1,'enabled','2026-09-02 18:08:10',NULL,'2026-08-30 05:50:40','2026-09-02 17:35:49','2409:895e:b66a:20d:18d1:6f03:66bf:3804','公网地址'),(37,1,15,42,'user2','user2','',NULL,10.00,49624.00,0.00,0.0000,'$2y$10$Kvga6PsSi9cpZI39CgeHHu5egWJMzSxTcl7FVa3gHxIF5qUASOL8y',0,1,'enabled','2026-09-02 08:09:44',NULL,'2026-08-31 01:30:24','2026-09-02 17:35:49','2409:895e:ac70:73e:e418:bff:fe9b:ec50','公网地址'),(40,1,15,44,'ch135','ch135',NULL,NULL,233301.00,0.03,0.00,0.0000,'$2y$10$CcoqH.SutfYWgcKOD3KYf.v7tyXsnUFDbLejO.CHo5wSa7uLWXYxq',0,1,'enabled','2026-09-01 18:44:27',NULL,'2026-09-01 18:43:30','2026-09-02 09:44:04','240e:39c:e16:1030:78a0:fe7:ff91:ebb3','公网地址'),(41,1,15,44,'ch136','ch136',NULL,NULL,666699.00,0.43,0.00,0.0000,'$2y$10$fBasvPJOH6LLSLqI12W4ie1SFEbYtOGowpUPC5S1Bmp7kOE5.XhxC',0,1,'enabled',NULL,NULL,'2026-09-01 21:12:07','2026-09-02 09:44:04',NULL,NULL),(42,1,15,49,'ch137','ch137',NULL,NULL,1500000.00,19065.89,0.00,0.0000,'$2y$10$cI8yI2fVJfDhsiiqZh7oqOrL583Rt5lI/pNHVQF7KYQNqTsyNhtpK',0,1,'enabled',NULL,NULL,'2026-09-01 21:18:09','2026-09-02 09:44:04',NULL,NULL);
/*!40000 ALTER TABLE `site_users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `organization_nodes`
--

DROP TABLE IF EXISTS `organization_nodes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `organization_nodes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `site_id` bigint unsigned NOT NULL,
  `parent_id` bigint unsigned NOT NULL DEFAULT '0',
  `level` varchar(24) NOT NULL,
  `depth` tinyint unsigned NOT NULL DEFAULT '1',
  `path` varchar(800) NOT NULL DEFAULT '',
  `name` varchar(120) NOT NULL,
  `code` varchar(64) NOT NULL,
  `credit_limit` decimal(18,2) NOT NULL DEFAULT '0.00',
  `balance` decimal(18,2) DEFAULT '0.00',
  `permissions` json DEFAULT NULL,
  `settings` json DEFAULT NULL,
  `status` tinyint NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_org_site_code` (`site_id`,`code`),
  KEY `idx_org_parent` (`site_id`,`parent_id`,`status`,`deleted_at`),
  KEY `idx_org_level` (`site_id`,`level`,`status`,`deleted_at`),
  KEY `idx_org_path` (`site_id`,`path`(191))
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `organization_nodes`
--

LOCK TABLES `organization_nodes` WRITE;
/*!40000 ALTER TABLE `organization_nodes` DISABLE KEYS */;
INSERT INTO `organization_nodes` VALUES (38,1,15,0,'director',1,'/38/','总监1','DIR-15-6CF19048',9900000.00,3798683.01,'[\"route.overview\", \"overview\", \"order_details\", \"winning_details\", \"bet_details\", \"refunds\", \"route.ledger\", \"contribution\", \"daily_ledger\", \"monthly_ledger\", \"daily_path\", \"monthly_path\", \"route.reports\", \"reports\", \"monthly_reports\", \"route.results\", \"results\", \"route.subordinates\", \"subordinates\", \"organization.manage\", \"organization.create\", \"organization.update\", \"organization.delete\", \"member.create\", \"member.update\", \"route.subaccounts\", \"subaccounts\", \"subaccount.create\", \"subaccount.update\", \"subaccount.delete\"]','[]',1,'2026-08-30 05:46:26','2026-09-02 17:35:49',NULL),(39,1,15,38,'shareholder',2,'/38/39/','大股东1','SH-15-E3611497',100000.00,1932.34,'[\"route.overview\", \"overview\", \"order_details\", \"winning_details\", \"bet_details\", \"refunds\", \"route.ledger\", \"contribution\", \"daily_ledger\", \"monthly_ledger\", \"daily_path\", \"monthly_path\", \"route.reports\", \"reports\", \"monthly_reports\", \"route.results\", \"results\", \"route.subordinates\", \"subordinates\", \"organization.manage\", \"organization.create\", \"organization.update\", \"organization.delete\", \"member.create\", \"member.update\", \"route.intercept\", \"interception_details\", \"interception_winning\", \"interception_plate\", \"route.logs\", \"logs\", \"route.rules\", \"rules\", \"route.settings\", \"settings\", \"settings.update\", \"route.subaccounts\", \"subaccounts\", \"subaccount.create\", \"subaccount.update\", \"subaccount.delete\"]','[]',1,'2026-08-30 05:47:45','2026-09-01 21:21:47',NULL),(40,1,15,39,'small_shareholder',3,'/38/39/40/','小股东1','SS-15-9B9CB230',100000.00,6461.70,'[\"route.overview\", \"overview\", \"order_details\", \"winning_details\", \"bet_details\", \"refunds\", \"route.ledger\", \"contribution\", \"daily_ledger\", \"monthly_ledger\", \"daily_path\", \"monthly_path\", \"route.reports\", \"reports\", \"monthly_reports\", \"route.results\", \"results\", \"route.subordinates\", \"subordinates\", \"organization.manage\", \"organization.create\", \"organization.update\", \"organization.delete\", \"member.create\", \"member.update\", \"route.intercept\", \"interception_details\", \"interception_winning\", \"interception_plate\", \"route.logs\", \"logs\", \"route.rules\", \"rules\", \"route.settings\", \"settings\", \"settings.update\", \"route.subaccounts\", \"subaccounts\", \"subaccount.create\", \"subaccount.update\", \"subaccount.delete\"]','[]',1,'2026-08-30 05:48:38','2026-09-01 21:21:47',NULL),(41,1,15,40,'general_agent',4,'/38/39/40/41/','总代理1','GA-15-EE9AD8BF',100000.00,29108.23,'[\"route.overview\", \"overview\", \"order_details\", \"winning_details\", \"bet_details\", \"refunds\", \"route.ledger\", \"contribution\", \"daily_ledger\", \"monthly_ledger\", \"daily_path\", \"monthly_path\", \"route.reports\", \"reports\", \"monthly_reports\", \"route.results\", \"results\", \"route.subordinates\", \"subordinates\", \"organization.manage\", \"organization.create\", \"organization.update\", \"organization.delete\", \"member.create\", \"member.update\", \"route.intercept\", \"interception_details\", \"interception_winning\", \"interception_plate\", \"route.logs\", \"logs\", \"route.rules\", \"rules\", \"route.settings\", \"settings\", \"settings.update\", \"route.subaccounts\", \"subaccounts\", \"subaccount.create\", \"subaccount.update\", \"subaccount.delete\"]','[]',1,'2026-08-30 05:49:21','2026-09-01 21:21:47',NULL),(42,1,15,41,'agent',5,'/38/39/40/41/42/','代理1','AG-15-02E606A4',100000.00,9046.32,'[\"route.overview\", \"overview\", \"order_details\", \"winning_details\", \"bet_details\", \"refunds\", \"route.ledger\", \"contribution\", \"daily_ledger\", \"monthly_ledger\", \"daily_path\", \"monthly_path\", \"route.reports\", \"reports\", \"monthly_reports\", \"route.results\", \"results\", \"route.subordinates\", \"subordinates\", \"organization.manage\", \"organization.create\", \"organization.update\", \"organization.delete\", \"member.create\", \"member.update\", \"route.intercept\", \"interception_details\", \"interception_winning\", \"interception_plate\", \"route.logs\", \"logs\", \"route.rules\", \"rules\", \"route.settings\", \"settings\", \"settings.update\", \"route.subaccounts\", \"subaccounts\", \"subaccount.create\", \"subaccount.update\", \"subaccount.delete\"]','[]',1,'2026-08-30 05:50:00','2026-09-01 21:21:47',NULL),(44,1,15,38,'agent',2,'/38/44/','代理 2','AG-15-53A7BDB5',1000000.00,100000.00,'[\"route.overview\", \"overview\", \"order_details\", \"winning_details\", \"bet_details\", \"refunds\", \"route.ledger\", \"contribution\", \"daily_ledger\", \"monthly_ledger\", \"daily_path\", \"monthly_path\", \"route.reports\", \"reports\", \"monthly_reports\", \"route.results\", \"results\", \"route.subordinates\", \"subordinates\", \"organization.manage\", \"organization.create\", \"organization.update\", \"organization.delete\", \"member.create\", \"member.update\", \"route.intercept\", \"interception_details\", \"interception_winning\", \"interception_plate\", \"route.logs\", \"logs\", \"route.rules\", \"rules\", \"route.settings\", \"settings\", \"settings.update\", \"route.subaccounts\", \"subaccounts\", \"subaccount.create\", \"subaccount.update\", \"subaccount.delete\"]','{\"board_codes\": [\"A\"]}',1,'2026-09-01 18:41:19','2026-09-02 17:35:49',NULL),(45,1,15,38,'shareholder',2,'/38/45/','大股东 2','SH-15-AE7F8754',5000000.00,3499850.00,'[\"route.overview\", \"overview\", \"order_details\", \"winning_details\", \"bet_details\", \"refunds\", \"route.ledger\", \"contribution\", \"daily_ledger\", \"monthly_ledger\", \"daily_path\", \"monthly_path\", \"route.reports\", \"reports\", \"monthly_reports\", \"route.results\", \"results\", \"route.subordinates\", \"subordinates\", \"organization.manage\", \"organization.create\", \"organization.update\", \"organization.delete\", \"member.create\", \"member.update\", \"route.intercept\", \"interception_details\", \"interception_winning\", \"interception_plate\", \"route.logs\", \"logs\", \"route.rules\", \"rules\", \"route.settings\", \"settings\", \"settings.update\", \"route.subaccounts\", \"subaccounts\", \"subaccount.create\", \"subaccount.update\", \"subaccount.delete\"]','{\"board_codes\": [\"A\"]}',1,'2026-09-01 20:56:57','2026-09-02 17:35:16',NULL),(46,1,15,45,'agent',3,'/38/45/46/','daili3','AG-15-AF929DF0',150.00,150.00,'[\"route.overview\", \"overview\", \"order_details\", \"winning_details\", \"bet_details\", \"refunds\", \"route.ledger\", \"contribution\", \"daily_ledger\", \"monthly_ledger\", \"daily_path\", \"monthly_path\", \"route.reports\", \"reports\", \"monthly_reports\", \"route.results\", \"results\", \"route.subordinates\", \"subordinates\", \"organization.manage\", \"organization.create\", \"organization.update\", \"organization.delete\", \"member.create\", \"member.update\", \"route.intercept\", \"interception_details\", \"interception_winning\", \"interception_plate\", \"route.logs\", \"logs\", \"route.rules\", \"rules\", \"route.settings\", \"settings\", \"settings.update\", \"route.subaccounts\", \"subaccounts\", \"subaccount.create\", \"subaccount.update\", \"subaccount.delete\"]','{\"board_codes\": [\"A\"]}',1,'2026-09-01 20:59:15','2026-09-01 20:59:15',NULL),(47,1,15,45,'small_shareholder',3,'/38/45/47/','小股东2','SS-15-F4DEE4F2',1500000.00,0.00,'[\"route.overview\", \"overview\", \"order_details\", \"winning_details\", \"bet_details\", \"refunds\", \"route.ledger\", \"contribution\", \"daily_ledger\", \"monthly_ledger\", \"daily_path\", \"monthly_path\", \"route.reports\", \"reports\", \"monthly_reports\", \"route.results\", \"results\", \"route.subordinates\", \"subordinates\", \"organization.manage\", \"organization.create\", \"organization.update\", \"organization.delete\", \"member.create\", \"member.update\", \"route.intercept\", \"interception_details\", \"interception_winning\", \"interception_plate\", \"route.logs\", \"logs\", \"route.rules\", \"rules\", \"route.settings\", \"settings\", \"settings.update\", \"route.subaccounts\", \"subaccounts\", \"subaccount.create\", \"subaccount.update\", \"subaccount.delete\"]','{\"board_codes\": [\"A\"]}',1,'2026-09-01 21:02:19','2026-09-01 21:03:46',NULL),(48,1,15,47,'general_agent',4,'/38/45/47/48/','总代理 4','GA-15-7EE5D858',1500000.00,0.00,'[\"route.overview\", \"overview\", \"order_details\", \"winning_details\", \"bet_details\", \"refunds\", \"route.ledger\", \"contribution\", \"daily_ledger\", \"monthly_ledger\", \"daily_path\", \"monthly_path\", \"route.reports\", \"reports\", \"monthly_reports\", \"route.results\", \"results\", \"route.subordinates\", \"subordinates\", \"organization.manage\", \"organization.create\", \"organization.update\", \"organization.delete\", \"member.create\", \"member.update\", \"route.intercept\", \"interception_details\", \"interception_winning\", \"interception_plate\", \"route.logs\", \"logs\", \"route.rules\", \"rules\", \"route.settings\", \"settings\", \"settings.update\", \"route.subaccounts\", \"subaccounts\", \"subaccount.create\", \"subaccount.update\", \"subaccount.delete\"]','{\"board_codes\": [\"A\"]}',1,'2026-09-01 21:03:46','2026-09-02 17:35:16',NULL),(49,1,15,48,'agent',5,'/38/45/47/48/49/','daili5','AG-15-46829F89',1500000.00,0.00,'[\"route.overview\", \"overview\", \"order_details\", \"winning_details\", \"bet_details\", \"refunds\", \"route.ledger\", \"contribution\", \"daily_ledger\", \"monthly_ledger\", \"daily_path\", \"monthly_path\", \"route.reports\", \"reports\", \"monthly_reports\", \"route.results\", \"results\", \"route.subordinates\", \"subordinates\", \"organization.manage\", \"organization.create\", \"organization.update\", \"organization.delete\", \"member.create\", \"member.update\", \"route.intercept\", \"interception_details\", \"interception_winning\", \"interception_plate\", \"route.logs\", \"logs\", \"route.rules\", \"rules\", \"route.settings\", \"settings\", \"settings.update\", \"route.subaccounts\", \"subaccounts\", \"subaccount.create\", \"subaccount.update\", \"subaccount.delete\"]','{\"board_codes\": [\"A\"]}',1,'2026-09-01 21:15:22','2026-09-02 17:35:16',NULL);
/*!40000 ALTER TABLE `organization_nodes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `site_credit_accounts`
--

DROP TABLE IF EXISTS `site_credit_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `site_credit_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `site_id` bigint unsigned NOT NULL,
  `total_score` decimal(18,2) NOT NULL DEFAULT '0.00',
  `balance` decimal(18,2) NOT NULL DEFAULT '0.00',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_site_credit_site` (`site_id`),
  KEY `idx_site_credit_tenant` (`tenant_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `site_credit_accounts`
--

LOCK TABLES `site_credit_accounts` WRITE;
/*!40000 ALTER TABLE `site_credit_accounts` DISABLE KEYS */;
INSERT INTO `site_credit_accounts` VALUES (5,1,15,9900000.00,0.00,'2026-08-30 03:03:00','2026-09-01 20:55:20');
/*!40000 ALTER TABLE `site_credit_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `platform_credit_accounts`
--

DROP TABLE IF EXISTS `platform_credit_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `platform_credit_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `total_score` decimal(18,2) DEFAULT '0.00',
  `balance` decimal(18,2) NOT NULL DEFAULT '0.00',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_platform_credit_tenant` (`tenant_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `platform_credit_accounts`
--

LOCK TABLES `platform_credit_accounts` WRITE;
/*!40000 ALTER TABLE `platform_credit_accounts` DISABLE KEYS */;
INSERT INTO `platform_credit_accounts` VALUES (6,1,99000000.00,89100000.00,'2026-08-30 03:03:38','2026-09-01 20:55:04');
/*!40000 ALTER TABLE `platform_credit_accounts` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-02 10:38:04
