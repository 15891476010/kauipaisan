-- MySQL dump 10.13  Distrib 8.0.45, for Linux (x86_64)
--
-- Host: 192.168.2.18    Database: kuaipaisan
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
-- Table structure for table `sites`
--

DROP TABLE IF EXISTS `sites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sites` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `agent_id` bigint unsigned NOT NULL,
  `is_platform_site` tinyint NOT NULL DEFAULT '0',
  `name` varchar(120) NOT NULL,
  `code` varchar(64) DEFAULT NULL,
  `manager_username` varchar(80) DEFAULT NULL,
  `manager_password` varchar(255) DEFAULT NULL,
  `manager_phone` varchar(30) DEFAULT NULL,
  `status` tinyint NOT NULL DEFAULT '1',
  `settings` json DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `last_login_location` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_site_agent_code` (`agent_id`,`code`),
  KEY `idx_site_platform` (`tenant_id`,`is_platform_site`,`deleted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sites`
--

LOCK TABLES `sites` WRITE;
/*!40000 ALTER TABLE `sites` DISABLE KEYS */;
INSERT INTO `sites` VALUES (3,1,1,0,'测试站','ceshi','zhang10867779','$2y$10$POuxBXp.XQUYIl91RvgiSOniRbX2fSRgVBt8RLsFcj1U2NNfVOEs2','',1,NULL,'2026-08-18 18:25:07',NULL,'2026-08-18 19:17:03',NULL,NULL),(4,1,1,0,'站点a','dupa1787049090044720000','sameuser','$2y$10$kkxKcESBN1DnYoqzCuWnU.1gkKxh3qDsTJ/dx1CMUa9Ub3cw1pQOa',NULL,1,NULL,'2026-08-18 18:31:30',NULL,'2026-08-18 19:17:03',NULL,NULL),(5,1,1,0,'站点b','dupb1787049090196344000','sameuser','$2y$10$QR48BBEs2mUDum02lHS4QOZtl6ttwr/n4FZNuMMIlhNV6j3xlSMkW',NULL,1,NULL,'2026-08-18 18:31:30',NULL,'2026-08-18 19:17:03',NULL,NULL),(6,1,1,0,'test-clean',NULL,'test-clean-user','$2y$10$x6aGG/A/mckBRFkT6dvuouAS178tcUrT9scm4wkDlnWsD5AT.bDjm',NULL,1,NULL,'2026-08-18 19:31:18',NULL,'2026-08-18 19:31:18',NULL,NULL),(7,1,1,0,'浏览器验证站点','','browser-check','$2y$10$NNwCir.ICZbk8xuw5YDjZO3Rjjk94sFTyBseNhDEGCg6qkUmslRJi','',1,NULL,'2026-08-18 19:31:50',NULL,'2026-08-18 19:32:07',NULL,NULL),(9,1,1,0,'布局验证',NULL,'layout-check','$2y$10$zlmKxtJNiWRyVPCmuoGh6egQ6w3G6ORzgF/QhmZcfEjKRzSK5e95i','',1,NULL,'2026-08-18 19:58:06',NULL,'2026-08-18 19:58:27',NULL,NULL),(10,1,1,0,'ceshi',NULL,'zhang10867779','$2y$10$gXYq9mqtTwTCc0GT6d4D6eBjd3Vj7rP5cnIHgePZsnEDWH6hsvr12','',1,NULL,'2026-08-18 20:04:20',NULL,'2026-08-18 20:37:05',NULL,NULL),(11,1,1,0,'用户管理验证站点',NULL,'site-manager-test','$2y$10$ca3ROA7ORfMnr1mB58JhUeN2nArC1maGOijBqTuUmF//FW1/1AA1i',NULL,1,NULL,'2026-08-18 20:13:52',NULL,'2026-08-18 20:14:46',NULL,NULL),(12,1,1,0,'下拉验证站点',NULL,'select-manager','$2y$10$i01YBrLeF4eEzYrW0TMre.c99h1QNMASsCa0ZcZ/xMvH2xvYxLMhi',NULL,1,NULL,'2026-08-18 20:19:05',NULL,'2026-08-18 20:19:51',NULL,NULL),(13,1,0,1,'平台自有站点',NULL,'Dj2013','$2y$10$g8hSkqUjy.d4IRvw/dtEp.07OeajUbkZ8aeVq99jOdW8w28kg5tRO','',1,'{\"credit_limit\": 200000, \"max_profit_share_rate\": 80, \"agent_permissions_by_level\": {\"agent\": [\"route.overview\", \"overview\", \"order_details\", \"winning_details\", \"bet_details\", \"refunds\", \"route.ledger\", \"contribution\", \"daily_ledger\", \"monthly_ledger\", \"daily_path\", \"monthly_path\", \"route.reports\", \"reports\", \"monthly_reports\", \"route.results\", \"results\", \"route.subordinates\", \"subordinates\", \"member.create\", \"member.update\", \"route.intercept\", \"interception_details\", \"interception_winning\", \"interception_plate\", \"route.subaccounts\", \"subaccounts\", \"subaccount.create\", \"subaccount.update\", \"subaccount.delete\"], \"director\": [\"route.overview\", \"overview\", \"order_details\", \"winning_details\", \"bet_details\", \"refunds\", \"route.ledger\", \"contribution\", \"daily_ledger\", \"monthly_ledger\", \"daily_path\", \"monthly_path\", \"route.reports\", \"reports\", \"monthly_reports\", \"route.results\", \"results\", \"route.organizations\", \"organization.manage\", \"organization.create\", \"organization.update\", \"organization.delete\", \"route.subaccounts\", \"subaccounts\", \"subaccount.create\", \"subaccount.update\", \"subaccount.delete\"], \"shareholder\": [\"route.overview\", \"overview\", \"order_details\", \"winning_details\", \"bet_details\", \"refunds\", \"route.ledger\", \"contribution\", \"daily_ledger\", \"monthly_ledger\", \"daily_path\", \"monthly_path\", \"route.reports\", \"reports\", \"monthly_reports\", \"route.results\", \"results\", \"route.organizations\", \"organization.manage\", \"organization.create\", \"organization.update\", \"organization.delete\", \"route.subaccounts\", \"subaccounts\", \"subaccount.create\", \"subaccount.update\", \"subaccount.delete\"], \"general_agent\": [\"route.overview\", \"overview\", \"order_details\", \"winning_details\", \"bet_details\", \"refunds\", \"route.ledger\", \"contribution\", \"daily_ledger\", \"monthly_ledger\", \"daily_path\", \"monthly_path\", \"route.reports\", \"reports\", \"monthly_reports\", \"route.results\", \"results\", \"route.organizations\", \"organization.manage\", \"organization.create\", \"organization.update\", \"organization.delete\", \"route.subaccounts\", \"subaccounts\", \"subaccount.create\", \"subaccount.update\", \"subaccount.delete\"], \"small_shareholder\": [\"route.overview\", \"overview\", \"order_details\", \"winning_details\", \"bet_details\", \"refunds\", \"route.ledger\", \"contribution\", \"daily_ledger\", \"monthly_ledger\", \"daily_path\", \"monthly_path\", \"route.reports\", \"reports\", \"monthly_reports\", \"route.results\", \"results\", \"route.organizations\", \"organization.manage\", \"organization.create\", \"organization.update\", \"organization.delete\", \"route.subaccounts\", \"subaccounts\", \"subaccount.create\", \"subaccount.update\", \"subaccount.delete\"]}}','2026-08-18 20:32:39','2026-08-24 23:52:06',NULL,NULL,NULL);
/*!40000 ALTER TABLE `sites` ENABLE KEYS */;
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
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `organization_nodes`
--

LOCK TABLES `organization_nodes` WRITE;
/*!40000 ALTER TABLE `organization_nodes` DISABLE KEYS */;
INSERT INTO `organization_nodes` VALUES (1,1,13,0,'director',1,'/1/','平台自有站点 · 根总监','DIR-13',200000.00,155.52,'[\"*\"]','{}',1,'2026-08-23 09:57:32','2026-08-24 23:55:47',NULL),(2,1,13,1,'shareholder',2,'/1/2/','大股东 1','SH-13-AF8098DD',100000.00,16.94,'[\"overview\", \"order_details\", \"winning_details\", \"bet_details\", \"contribution\", \"daily_ledger\", \"monthly_ledger\", \"daily_path\", \"monthly_path\", \"reports\", \"monthly_reports\", \"results\", \"subordinates\", \"interception_details\", \"interception_winning\", \"interception_plate\", \"rules\", \"settings\", \"logs\", \"subaccounts\", \"organization.manage\"]','[]',1,'2026-08-23 20:36:05','2026-08-23 21:43:11',NULL),(3,1,13,2,'small_shareholder',3,'/1/2/3/','小股东 1','SS-13-3A7E86FF',100000.00,0.32,'[\"overview\", \"order_details\", \"winning_details\", \"bet_details\", \"contribution\", \"daily_ledger\", \"monthly_ledger\", \"daily_path\", \"monthly_path\", \"reports\", \"monthly_reports\", \"results\", \"subordinates\", \"interception_details\", \"interception_winning\", \"interception_plate\", \"rules\", \"settings\", \"logs\", \"subaccounts\", \"organization.manage\"]','[]',1,'2026-08-23 20:43:18','2026-08-23 21:43:11',NULL),(4,1,13,3,'general_agent',4,'/1/2/3/4/','总代理 1','GA-13-7B7A094E',100000.00,0.02,'[\"overview\", \"order_details\", \"winning_details\", \"bet_details\", \"contribution\", \"daily_ledger\", \"monthly_ledger\", \"daily_path\", \"monthly_path\", \"reports\", \"monthly_reports\", \"results\", \"subordinates\", \"interception_details\", \"interception_winning\", \"interception_plate\", \"rules\", \"settings\", \"logs\", \"subaccounts\", \"organization.manage\"]','[]',1,'2026-08-23 20:44:52','2026-08-23 21:43:11',NULL),(5,1,13,4,'agent',5,'/1/2/3/4/5/','代理 1','AG-13-594F6813',100000.00,0.00,'[\"overview\", \"order_details\", \"winning_details\", \"bet_details\", \"contribution\", \"daily_ledger\", \"monthly_ledger\", \"daily_path\", \"monthly_path\", \"reports\", \"monthly_reports\", \"results\", \"subordinates\", \"interception_details\", \"interception_winning\", \"interception_plate\", \"rules\", \"settings\", \"logs\", \"subaccounts\", \"organization.manage\"]','[]',1,'2026-08-23 20:45:49','2026-08-23 20:56:21',NULL),(7,1,13,1,'shareholder',2,'/1/7/','111','SH-13-22848146',100000.00,50000.00,'[\"route.overview\", \"overview\", \"order_details\", \"winning_details\", \"bet_details\", \"refunds\", \"route.ledger\", \"contribution\", \"daily_ledger\", \"monthly_ledger\", \"daily_path\", \"monthly_path\", \"route.reports\", \"reports\", \"monthly_reports\", \"route.results\", \"results\", \"route.organizations\", \"organization.manage\", \"organization.create\", \"organization.update\", \"organization.delete\", \"route.subaccounts\", \"subaccounts\", \"subaccount.create\", \"subaccount.update\", \"subaccount.delete\"]','[]',1,'2026-08-24 23:55:47','2026-08-24 23:58:12',NULL),(8,1,13,7,'small_shareholder',3,'/1/7/8/','2222','SS-13-0D08BA0B',50000.00,0.00,'[\"route.overview\", \"overview\", \"order_details\", \"winning_details\", \"bet_details\", \"refunds\", \"route.ledger\", \"contribution\", \"daily_ledger\", \"monthly_ledger\", \"daily_path\", \"monthly_path\", \"route.reports\", \"reports\", \"monthly_reports\", \"route.results\", \"results\", \"route.organizations\", \"organization.manage\", \"organization.create\", \"organization.update\", \"organization.delete\", \"route.subaccounts\", \"subaccounts\", \"subaccount.create\", \"subaccount.update\", \"subaccount.delete\"]','[]',1,'2026-08-24 23:58:12','2026-08-24 23:59:56',NULL),(9,1,13,8,'general_agent',4,'/1/7/8/9/','333','GA-13-3E555766',50000.00,0.00,'[\"route.overview\", \"overview\", \"order_details\", \"winning_details\", \"bet_details\", \"refunds\", \"route.ledger\", \"contribution\", \"daily_ledger\", \"monthly_ledger\", \"daily_path\", \"monthly_path\", \"route.reports\", \"reports\", \"monthly_reports\", \"route.results\", \"results\", \"route.organizations\", \"organization.manage\", \"organization.create\", \"organization.update\", \"organization.delete\", \"route.subaccounts\", \"subaccounts\", \"subaccount.create\", \"subaccount.update\", \"subaccount.delete\"]','[]',1,'2026-08-24 23:59:56','2026-08-25 00:01:55',NULL),(10,1,13,9,'agent',5,'/1/7/8/9/10/','4444','AG-13-0C3A6D8F',50000.00,50000.00,'[\"route.overview\", \"overview\", \"order_details\", \"winning_details\", \"bet_details\", \"refunds\", \"route.ledger\", \"contribution\", \"daily_ledger\", \"monthly_ledger\", \"daily_path\", \"monthly_path\", \"route.reports\", \"reports\", \"monthly_reports\", \"route.results\", \"results\", \"route.subaccounts\", \"subaccounts\", \"subaccount.create\", \"subaccount.update\", \"subaccount.delete\"]','[]',1,'2026-08-25 00:01:55','2026-08-25 00:01:55',NULL);
/*!40000 ALTER TABLE `organization_nodes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `organization_profit_shares`
--

DROP TABLE IF EXISTS `organization_profit_shares`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `organization_profit_shares` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL,
  `site_id` bigint unsigned NOT NULL,
  `parent_organization_id` bigint unsigned NOT NULL,
  `child_organization_id` bigint unsigned NOT NULL,
  `max_share_rate` decimal(8,4) NOT NULL DEFAULT '0.0000',
  `share_rate` decimal(8,4) NOT NULL DEFAULT '0.0000',
  `status` tinyint NOT NULL DEFAULT '1',
  `effective_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_profit_share_edge` (`child_organization_id`),
  KEY `idx_profit_share_parent` (`site_id`,`parent_organization_id`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `organization_profit_shares`
--

LOCK TABLES `organization_profit_shares` WRITE;
/*!40000 ALTER TABLE `organization_profit_shares` DISABLE KEYS */;
INSERT INTO `organization_profit_shares` VALUES (1,1,13,0,1,80.0000,80.0000,1,NULL,'2026-08-23 09:57:32','2026-08-23 09:57:32'),(2,1,13,1,2,80.0000,10.0000,1,NULL,'2026-08-23 20:36:05','2026-08-23 20:36:05'),(3,1,13,2,3,80.0000,2.0000,1,NULL,'2026-08-23 20:43:18','2026-08-23 20:43:18'),(4,1,13,3,4,80.0000,5.0000,1,NULL,'2026-08-23 20:44:52','2026-08-23 20:44:52'),(5,1,13,4,5,80.0000,10.0000,1,NULL,'2026-08-23 20:45:49','2026-08-23 20:45:49'),(6,1,13,1,7,80.0000,0.0000,1,NULL,'2026-08-24 23:55:47','2026-08-24 23:55:47'),(7,1,13,7,8,80.0000,0.0000,1,NULL,'2026-08-24 23:58:12','2026-08-24 23:58:12'),(8,1,13,8,9,80.0000,0.0000,1,NULL,'2026-08-24 23:59:56','2026-08-24 23:59:56'),(9,1,13,9,10,80.0000,0.0000,1,NULL,'2026-08-25 00:01:55','2026-08-25 00:01:55');
/*!40000 ALTER TABLE `organization_profit_shares` ENABLE KEYS */;
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
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `platform_credit_accounts`
--

LOCK TABLES `platform_credit_accounts` WRITE;
/*!40000 ALTER TABLE `platform_credit_accounts` DISABLE KEYS */;
INSERT INTO `platform_credit_accounts` VALUES (1,1,10000000.00,9800043.20,'2026-08-21 21:49:35','2026-08-24 23:52:06');
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

-- Dump completed on 2026-08-24 16:14:41
