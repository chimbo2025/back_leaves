-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 28, 2026 at 10:06 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `dpp_leave`
--

-- --------------------------------------------------------

--
-- Table structure for table `approval_actions`
--

CREATE TABLE `approval_actions` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `assignment_id` bigint(20) UNSIGNED NOT NULL,
  `actor_employee_id` varchar(191) NOT NULL,
  `decision` varchar(191) NOT NULL,
  `comments` text DEFAULT NULL,
  `workflow_rule_used` bigint(20) UNSIGNED DEFAULT NULL,
  `acted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- --------------------------------------------------------

--
-- Table structure for table `approval_roles`
--

CREATE TABLE `approval_roles` (
  `id` varchar(191) NOT NULL,
  `code` varchar(191) NOT NULL,
  `name` varchar(191) NOT NULL,
  `name_sw` varchar(191) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_system_protected` tinyint(1) NOT NULL DEFAULT 0,
  `resolution_type` varchar(191) NOT NULL DEFAULT 'employee_approval_roles',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `approval_signatures`
--

CREATE TABLE `approval_signatures` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `leave_request_id` bigint(20) UNSIGNED NOT NULL,
  `approval_action_id` bigint(20) UNSIGNED DEFAULT NULL,
  `actor_id` varchar(191) NOT NULL,
  `approval_action` enum('approved','rejected','returned') NOT NULL,
  `signature_type` enum('digital','wet_ink_reference') NOT NULL DEFAULT 'digital',
  `signature_value` varchar(191) DEFAULT NULL,
  `signature_hash` varchar(191) NOT NULL,
  `signed_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `entity_type` varchar(191) NOT NULL,
  `entity_id` varchar(191) NOT NULL,
  `action` varchar(191) NOT NULL,
  `actor_user_id` varchar(191) DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `reason` text DEFAULT NULL,
  `prev_hash` char(64) DEFAULT NULL,
  `row_hash` char(64) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `entity_type`, `entity_id`, `action`, `actor_user_id`, `payload`, `reason`, `prev_hash`, `row_hash`, `created_at`) VALUES
(1, 'leave_policy', 'pol1', 'version', 'u3', '{\"note\":\"AnnualLeave policy updated to v4: carry-over 10 days\"}', NULL, NULL, '9eb5bcdd5f96c2c2957f023bdf005d852dcedfc5b7c31efdd427d8b6e931746f', '2026-06-14 21:00:00'),
(2, 'workflow', 'wf_maternity', 'version', 'u3', '{\"note\":\"Maternity workflow: added final-authority step\"}', NULL, '9eb5bcdd5f96c2c2957f023bdf005d852dcedfc5b7c31efdd427d8b6e931746f', '372e3e08cdd641ecd8864106f2a9fd593ca61fdf1f347591650ffe13c296626d', '2026-05-01 21:00:00'),
(3, 'leave_request', 'r6', 'return', 'u3', '{\"ref\":\"LVE-2026-0158\"}', 'Admission letter missing', '372e3e08cdd641ecd8864106f2a9fd593ca61fdf1f347591650ffe13c296626d', '3e78d0bd5c4a1e8ac3e1aea51cb54529ff3b3cc2a1efb4283a2cc9867275e8e3', '2026-08-25 21:00:00'),
(4, 'delegation', 'del1', 'create', 'u1', '{\"delegator\":\"e6\",\"delegate\":\"e7\",\"window\":\"05 Sep \\u2013 10 Oct\"}', NULL, '3e78d0bd5c4a1e8ac3e1aea51cb54529ff3b3cc2a1efb4283a2cc9867275e8e3', '2e94e71e3a4bdb84dd1a025d1dcdac5498b57b7dc834ec415e8161ae5e034bad', '2026-09-04 21:00:00'),
(5, 'leave_request', 'r3', 'approve', 'u5', '{\"ref\":\"LVE-2026-0153\",\"step\":\"DEPT_HEAD\"}', NULL, '2e94e71e3a4bdb84dd1a025d1dcdac5498b57b7dc834ec415e8161ae5e034bad', '9f2c25954e779b178ba22579cb6104b7179310f8980ccf9e908367c470ea9ff9', '2026-09-03 21:00:00'),
(6, 'leave_request', 'r7', 'reject', 'u6', '{\"ref\":\"LVE-2026-0160\"}', 'overlap with Records cover', '9f2c25954e779b178ba22579cb6104b7179310f8980ccf9e908367c470ea9ff9', 'a9c080f951ad260aec8e70ae3660266b8854c284f28c81a45ccdb67f09191a14', '2026-09-07 21:00:00'),
(7, 'case', 'case_r3', 'handover', 'u5', '{\"ref\":\"LVE-2026-0153\",\"to\":\"FINAL_AUTHORITY\"}', NULL, 'a9c080f951ad260aec8e70ae3660266b8854c284f28c81a45ccdb67f09191a14', '8b1375e2164866070f881774cef17890c4b4564d380866780f1c6698769feb4a', '2026-09-07 21:00:00'),
(8, 'case', 'case_r4', 'handover', 'u6', '{\"ref\":\"LVE-2026-0155\",\"to\":\"HR\"}', NULL, '8b1375e2164866070f881774cef17890c4b4564d380866780f1c6698769feb4a', 'e26ca016d7cb08b314187d5d239723bf999c046a35463c17c9bede775483834d', '2026-09-02 21:00:00'),
(9, 'config', 'zones', 'create', 'u3', '{\"note\":\"Created Pemba coordination office zone record\"}', NULL, 'e26ca016d7cb08b314187d5d239723bf999c046a35463c17c9bede775483834d', '198a997882f5d21973676eefed165773c5ab1c7b0c458bc43eb252536363da3c', '2026-04-19 21:00:00'),
(10, 'leave_request', 'r10', 'create', 'u1', '{\"ref\":\"LVE-2026-0167\",\"days\":12}', NULL, '198a997882f5d21973676eefed165773c5ab1c7b0c458bc43eb252536363da3c', '8ff3af6c9116215b68998589c1f17edd2900869518e5a06237b950ecdcf9eb47', '2026-09-04 21:00:00'),
(11, 'leave_request', 'r11', 'approve', 'u1', '{\"ref\":\"LVE-2026-0161\",\"step\":\"HR\"}', NULL, '8ff3af6c9116215b68998589c1f17edd2900869518e5a06237b950ecdcf9eb47', 'bcab111f0a23f2bb7f833864e2a77baf3eabbef93670e92fedf94dc34571adef', '2026-07-27 21:00:00'),
(12, 'delegation', 'del3', 'create', 'u3', '{\"delegator\":\"e3\",\"delegate\":\"e2\",\"window\":\"14\\u201324 Dec\"}', NULL, 'bcab111f0a23f2bb7f833864e2a77baf3eabbef93670e92fedf94dc34571adef', 'b71a66d51b0334901bcbb0026d169c9439ad631a605dc5c45f94fd03fb9dfffc', '2026-09-07 21:00:00'),
(13, 'case', 'CR-2026-0160', 'create', 'u1', '{\"ref\":\"CR-2026-0160\",\"court\":\"High Court of Zanzibar\"}', NULL, 'b71a66d51b0334901bcbb0026d169c9439ad631a605dc5c45f94fd03fb9dfffc', 'a6bfeefdb9c7a214325091ce36e11ada5fa6127b6958c6d6e04751f908551b2c', '2026-07-31 21:00:00'),
(14, 'employee', 'e10', 'update', 'u3', '{\"note\":\"Contact email refreshed\"}', NULL, 'a6bfeefdb9c7a214325091ce36e11ada5fa6127b6958c6d6e04751f908551b2c', 'bce06973bb3f7a847c308037e5b8d13cfb1eb1140453e4090f3e137d27933f6f', '2026-09-05 21:00:00'),
(15, 'document', 'birth_certificate_e7.pdf', 'create', 'u7', '{\"request\":\"LVE-2026-0163\",\"type\":\"Birth certificate\"}', NULL, 'bce06973bb3f7a847c308037e5b8d13cfb1eb1140453e4090f3e137d27933f6f', '8efbaf04d6772298fe2bf77c0f38194dde846d0098e3375dad8d6230fe7fe3f5', '2026-09-07 21:00:00'),
(16, 'role', 'SELF_SERVICE', 'archive', 'u1', '{\"name\":\"Self-Service\",\"was_system\":true}', NULL, '8efbaf04d6772298fe2bf77c0f38194dde846d0098e3375dad8d6230fe7fe3f5', 'e034fbeaebd6690911788ceec7cbf07ffc1272dce2ea63a63162dfeed878a92e', '2026-10-28 08:53:42'),
(17, 'role', 'BREAK_GLASS', 'archive', 'u1', '{\"name\":\"Break-Glass Authority\",\"was_system\":true}', NULL, 'e034fbeaebd6690911788ceec7cbf07ffc1272dce2ea63a63162dfeed878a92e', '740bf84bbaf31fba699672de79f0c459fac69ad58de4bb61fc70806e43d46641', '2026-10-28 08:53:55'),
(18, 'workflow', 'reassign_steps', 'update', 'u1', '{\"from_role\":\"SUPERVISOR\",\"to_role\":\"FINAL_AUTHORITY\",\"steps\":6,\"workflow_defs\":[\"wf_annual\",\"wf_emergency\",\"wf_lwp\",\"wf_paternity\",\"wf_sick\",\"wf_study\"]}', NULL, '740bf84bbaf31fba699672de79f0c459fac69ad58de4bb61fc70806e43d46641', 'd1649789292b809089346f3ca708701db77d719ca74e3fb5ca49c98b8292ee14', '2026-10-28 08:54:29'),
(19, 'role', 'SUPERVISOR', 'archive', 'u1', '{\"name\":\"Immediate Supervisor\",\"was_system\":true}', NULL, 'd1649789292b809089346f3ca708701db77d719ca74e3fb5ca49c98b8292ee14', 'b1083213425198febe7c4ce7fb195e9271b662c3ded80ea65a2fd8eeb8b9c9e5', '2026-10-28 08:54:30'),
(20, 'role', 'FINAL_AUTHORITY', 'update', 'u1', '{\"name\":\"Final Authority \\u2014 DPP\",\"description\":\"Final sign-off. Holds every catalogued permission explicitly, so the RBAC matrix shows exactly what the role can do. Organisation-wide reach comes from its four view_cross_zone codes.\"}', NULL, 'b1083213425198febe7c4ce7fb195e9271b662c3ded80ea65a2fd8eeb8b9c9e5', 'f216d97c90e60f3ad63d98616dd3fc1061ff97ddd0ee39302df85446179f6887', '2026-10-28 11:13:03'),
(21, 'role', 'HR', 'update', 'u3', '{\"name\":\"Human Resources\",\"description\":\"Central HR. Cross-zone read for requests, staff and reports; administers staff, roles and leave policy.\"}', NULL, 'f216d97c90e60f3ad63d98616dd3fc1061ff97ddd0ee39302df85446179f6887', 'ba416cf3f0a7204144d97786f77e0c689947eccca7c87aa1541f8efac5fbecc0', '2026-10-28 11:44:24'),
(22, 'role', 'HR', 'update', 'u3', '{\"name\":\"Human Resources\",\"description\":\"Central HR. Cross-zone read for requests, staff and reports; administers staff, roles and leave policy.\"}', NULL, 'ba416cf3f0a7204144d97786f77e0c689947eccca7c87aa1541f8efac5fbecc0', 'dd553f19591dffa28da99dce2ae02137ca5668d51fbb2ad996a13bfc709ffb1f', '2026-10-28 11:44:42');

-- --------------------------------------------------------

--
-- Table structure for table `balance_transactions`
--

CREATE TABLE `balance_transactions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` varchar(191) NOT NULL,
  `leave_type_id` varchar(191) NOT NULL,
  `leave_cycle_start` date DEFAULT NULL,
  `leave_cycle_end` date DEFAULT NULL,
  `transaction_type` varchar(191) NOT NULL,
  `amount` decimal(8,2) NOT NULL,
  `direction` varchar(191) NOT NULL,
  `source_type` varchar(191) DEFAULT NULL,
  `source_id` varchar(191) DEFAULT NULL,
  `leave_request_id` bigint(20) UNSIGNED DEFAULT NULL,
  `leave_waiver_id` bigint(20) UNSIGNED DEFAULT NULL,
  `policy_id` varchar(191) DEFAULT NULL,
  `reason_code` varchar(191) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `idempotency_key` varchar(191) NOT NULL,
  `reversed_transaction_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_by` varchar(191) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cache`
--

CREATE TABLE `cache` (
  `key` varchar(191) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `cache`
--

INSERT INTO `cache` (`key`, `value`, `expiration`) VALUES
('laravel-cache-perms:u1', 'a:93:{i:0;s:13:\"requests.view\";i:1;s:15:\"requests.create\";i:2;s:13:\"policies.view\";i:3;s:14:\"documents.view\";i:4;s:20:\"leave_plans.view_own\";i:5;s:22:\"leave_plans.create_own\";i:6;s:22:\"leave_plans.select_own\";i:7;s:24:\"leave_waivers.create_own\";i:8;s:22:\"leave_waivers.view_own\";i:9;s:24:\"leave_returns.report_own\";i:10;s:22:\"leave_returns.view_own\";i:11;s:23:\"leave_payments.view_own\";i:12;s:24:\"leave_documents.view_own\";i:13;s:28:\"leave_documents.download_own\";i:14;s:22:\"notifications.view_own\";i:15;s:32:\"notifications.manage_preferences\";i:16;s:16:\"reports.view_own\";i:17;s:16:\"requests.approve\";i:18;s:16:\"delegations.view\";i:19;s:18:\"delegations.create\";i:20;s:18:\"delegations.update\";i:21;s:12:\"reports.view\";i:22;s:23:\"reports.view_department\";i:23;s:27:\"leave_plans.view_department\";i:24;s:30:\"leave_plans.confirm_department\";i:25;s:29:\"leave_waivers.view_department\";i:26;s:20:\"leave_waivers.review\";i:27;s:20:\"leave_waivers.reject\";i:28;s:29:\"leave_returns.view_department\";i:29;s:20:\"leave_returns.review\";i:30;s:20:\"leave_returns.excuse\";i:31;s:29:\"leave_returns.apply_deduction\";i:32;s:19:\"leave_returns.close\";i:33;s:21:\"requests.approve_zone\";i:34;s:17:\"reports.view_zone\";i:35;s:21:\"leave_plans.view_zone\";i:36;s:24:\"leave_plans.confirm_zone\";i:37;s:23:\"leave_waivers.view_zone\";i:38;s:23:\"leave_returns.view_zone\";i:39;s:24:\"requests.view_cross_zone\";i:40;s:15:\"policies.create\";i:41;s:20:\"policies.edit_policy\";i:42;s:16:\"documents.create\";i:43;s:16:\"documents.delete\";i:44;s:32:\"documents.view_medical_documents\";i:45;s:21:\"staff.view_cross_zone\";i:46;s:27:\"reports.view_gender_reports\";i:47;s:23:\"reports.view_cross_zone\";i:48;s:25:\"reports.view_organisation\";i:49;s:14:\"reports.export\";i:50;s:18:\"reports.view_audit\";i:51;s:22:\"reports.view_financial\";i:52;s:22:\"reports.view_sensitive\";i:53;s:10:\"admin.view\";i:54;s:12:\"admin.create\";i:55;s:12:\"admin.update\";i:56;s:12:\"admin.delete\";i:57;s:27:\"leave_plans.view_cross_zone\";i:58;s:21:\"leave_waivers.approve\";i:59;s:29:\"leave_waivers.view_cross_zone\";i:60;s:29:\"leave_returns.view_cross_zone\";i:61;s:30:\"leave_payments.view_department\";i:62;s:24:\"leave_payments.view_zone\";i:63;s:30:\"leave_payments.view_cross_zone\";i:64;s:21:\"leave_payments.create\";i:65;s:21:\"leave_payments.review\";i:66;s:22:\"leave_payments.approve\";i:67;s:21:\"leave_payments.reject\";i:68;s:21:\"leave_payments.cancel\";i:69;s:27:\"leave_documents.download_hr\";i:70;s:24:\"leave_documents.generate\";i:71;s:22:\"leave_documents.revoke\";i:72;s:28:\"notifications.view_sensitive\";i:73;s:23:\"report_exports.download\";i:74;s:21:\"report_exports.cancel\";i:75;s:22:\"leave_payments.receive\";i:76;s:22:\"leave_payments.process\";i:77;s:15:\"audit_logs.view\";i:78;s:23:\"approval_workflows.view\";i:79;s:14:\"staff.view_own\";i:80;s:25:\"approval_workflows.create\";i:81;s:25:\"approval_workflows.update\";i:82;s:19:\"case_handovers.view\";i:83;s:21:\"case_handovers.create\";i:84;s:21:\"case_handovers.accept\";i:85;s:21:\"case_handovers.reject\";i:86;s:23:\"case_handovers.complete\";i:87;s:17:\"requests.view_own\";i:88;s:17:\"requests.edit_own\";i:89;s:15:\"requests.submit\";i:90;s:17:\"requests.withdraw\";i:91;s:15:\"requests.reject\";i:92;s:15:\"requests.return\";}', 1793217922),
('laravel-cache-perms:u3', 'a:91:{i:0;s:13:\"requests.view\";i:1;s:15:\"requests.create\";i:2;s:13:\"policies.view\";i:3;s:14:\"documents.view\";i:4;s:20:\"leave_plans.view_own\";i:5;s:22:\"leave_plans.create_own\";i:6;s:22:\"leave_plans.select_own\";i:7;s:24:\"leave_waivers.create_own\";i:8;s:22:\"leave_waivers.view_own\";i:9;s:24:\"leave_returns.report_own\";i:10;s:22:\"leave_returns.view_own\";i:11;s:23:\"leave_payments.view_own\";i:12;s:24:\"leave_documents.view_own\";i:13;s:28:\"leave_documents.download_own\";i:14;s:22:\"notifications.view_own\";i:15;s:32:\"notifications.manage_preferences\";i:16;s:16:\"reports.view_own\";i:17;s:16:\"requests.approve\";i:18;s:16:\"delegations.view\";i:19;s:18:\"delegations.create\";i:20;s:18:\"delegations.update\";i:21;s:12:\"reports.view\";i:22;s:23:\"reports.view_department\";i:23;s:27:\"leave_plans.view_department\";i:24;s:30:\"leave_plans.confirm_department\";i:25;s:29:\"leave_waivers.view_department\";i:26;s:20:\"leave_waivers.review\";i:27;s:20:\"leave_waivers.reject\";i:28;s:29:\"leave_returns.view_department\";i:29;s:20:\"leave_returns.review\";i:30;s:20:\"leave_returns.excuse\";i:31;s:29:\"leave_returns.apply_deduction\";i:32;s:19:\"leave_returns.close\";i:33;s:21:\"requests.approve_zone\";i:34;s:17:\"reports.view_zone\";i:35;s:21:\"leave_plans.view_zone\";i:36;s:24:\"leave_plans.confirm_zone\";i:37;s:23:\"leave_waivers.view_zone\";i:38;s:23:\"leave_returns.view_zone\";i:39;s:24:\"requests.view_cross_zone\";i:40;s:15:\"policies.create\";i:41;s:20:\"policies.edit_policy\";i:42;s:16:\"documents.create\";i:43;s:16:\"documents.delete\";i:44;s:32:\"documents.view_medical_documents\";i:45;s:21:\"staff.view_cross_zone\";i:46;s:27:\"reports.view_gender_reports\";i:47;s:23:\"reports.view_cross_zone\";i:48;s:25:\"reports.view_organisation\";i:49;s:14:\"reports.export\";i:50;s:18:\"reports.view_audit\";i:51;s:22:\"reports.view_financial\";i:52;s:22:\"reports.view_sensitive\";i:53;s:10:\"admin.view\";i:54;s:12:\"admin.create\";i:55;s:12:\"admin.update\";i:56;s:12:\"admin.delete\";i:57;s:27:\"leave_plans.view_cross_zone\";i:58;s:21:\"leave_waivers.approve\";i:59;s:29:\"leave_waivers.view_cross_zone\";i:60;s:29:\"leave_returns.view_cross_zone\";i:61;s:30:\"leave_payments.view_department\";i:62;s:24:\"leave_payments.view_zone\";i:63;s:30:\"leave_payments.view_cross_zone\";i:64;s:21:\"leave_payments.create\";i:65;s:21:\"leave_payments.review\";i:66;s:22:\"leave_payments.approve\";i:67;s:21:\"leave_payments.reject\";i:68;s:21:\"leave_payments.cancel\";i:69;s:27:\"leave_documents.download_hr\";i:70;s:24:\"leave_documents.generate\";i:71;s:22:\"leave_documents.revoke\";i:72;s:28:\"notifications.view_sensitive\";i:73;s:23:\"report_exports.download\";i:74;s:21:\"report_exports.cancel\";i:75;s:15:\"audit_logs.view\";i:76;s:23:\"approval_workflows.view\";i:77;s:14:\"staff.view_own\";i:78;s:25:\"approval_workflows.create\";i:79;s:25:\"approval_workflows.update\";i:80;s:19:\"case_handovers.view\";i:81;s:21:\"case_handovers.create\";i:82;s:21:\"case_handovers.accept\";i:83;s:21:\"case_handovers.reject\";i:84;s:23:\"case_handovers.complete\";i:85;s:17:\"requests.view_own\";i:86;s:17:\"requests.edit_own\";i:87;s:15:\"requests.submit\";i:88;s:17:\"requests.withdraw\";i:89;s:15:\"requests.reject\";i:90;s:15:\"requests.return\";}', 1793203230);

-- --------------------------------------------------------

--
-- Table structure for table `cache_locks`
--

CREATE TABLE `cache_locks` (
  `key` varchar(191) NOT NULL,
  `owner` varchar(191) NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cases`
--

CREATE TABLE `cases` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `ref` varchar(191) NOT NULL,
  `kind` varchar(191) NOT NULL DEFAULT 'docket',
  `request_id` bigint(20) UNSIGNED DEFAULT NULL,
  `assigned_prosecutor_id` varchar(191) NOT NULL,
  `next_hearing_date` date DEFAULT NULL,
  `status` varchar(191) NOT NULL DEFAULT 'active',
  `owner_id` varchar(191) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `cases`
--

INSERT INTO `cases` (`id`, `ref`, `kind`, `request_id`, `assigned_prosecutor_id`, `next_hearing_date`, `status`, `owner_id`, `note`, `created_at`, `updated_at`) VALUES
(1, 'LVE-2026-0147', 'docket', 1, 'e7', NULL, 'active', 'e3', 'Docket follows the request through its approval chain.', '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(2, 'LVE-2026-0150', 'docket', 2, 'e8', NULL, 'active', 'e3', 'Docket follows the request through its approval chain.', '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(3, 'LVE-2026-0153', 'docket', 3, 'e9', '2026-10-11', 'active', 'e1', 'Docket follows the request through its approval chain.', '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(4, 'LVE-2026-0155', 'docket', 4, 'e6', '2026-11-12', 'active', 'e3', 'Docket follows the request through its approval chain.', '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(5, 'LVE-2026-0156', 'docket', 5, 'e12', NULL, 'active', 'e3', 'Docket follows the request through its approval chain.', '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(6, 'LVE-2026-0158', 'docket', 6, 'e10', NULL, 'active', 'e3', 'Docket follows the request through its approval chain.', '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(7, 'LVE-2026-0160', 'docket', 7, 'e11', NULL, 'closed', 'e11', 'Case closed on rejection.', '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(8, 'LVE-2026-0162', 'docket', 8, 'e8', NULL, 'active', 'e4', 'Docket follows the request through its approval chain.', '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(9, 'LVE-2026-0163', 'docket', 9, 'e7', NULL, 'active', 'e6', 'Docket follows the request through its approval chain.', '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(10, 'LVE-2026-0167', 'docket', 10, 'e1', NULL, 'active', 'e3', 'Docket follows the request through its approval chain.', '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(11, 'LVE-2026-0161', 'docket', 11, 'e3', NULL, 'active', 'e1', 'Docket follows the request through its approval chain.', '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(12, 'LVE-2026-0164', 'docket', 12, 'e7', NULL, 'active', 'e6', 'Docket follows the request through its approval chain.', '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(13, 'LVE-2026-0159', 'docket', 13, 'e4', NULL, 'active', 'e1', 'Docket follows the request through its approval chain.', '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(14, 'CR-2026-0098', 'court_case', NULL, 'e2', '2026-10-08', 'active', 'e2', NULL, '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(15, 'CR-2026-0121', 'court_case', NULL, 'e8', '2026-11-03', 'active', 'e8', NULL, '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(16, 'CR-2026-0128', 'court_case', NULL, 'e7', '2026-10-14', 'active', 'e7', NULL, '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(17, 'CR-2026-0141', 'court_case', NULL, 'e6', '2026-09-25', 'active', 'e6', NULL, '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(18, 'CR-2026-0142', 'court_case', NULL, 'e9', '2026-10-11', 'active', 'e9', NULL, '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(19, 'CR-2026-0155', 'court_case', NULL, 'e7', '2026-11-20', 'active', 'e7', NULL, '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(20, 'CR-2026-0160', 'court_case', NULL, 'e1', '2026-11-05', 'active', 'e1', NULL, '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(21, 'CR-2026-0161', 'court_case', NULL, 'e3', '2026-12-02', 'closed', 'e3', 'Matter concluded by consent.', '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(22, 'CR-2026-0163', 'court_case', NULL, 'e1', '2026-10-18', 'active', 'e1', NULL, '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(23, 'CR-2026-0166', 'court_case', NULL, 'e10', '2026-10-22', 'active', 'e10', NULL, '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(24, 'CR-2026-0149', 'court_case', NULL, 'e11', '2026-09-30', 'closed', 'e11', 'Matter concluded by consent.', '2026-10-26 11:46:20', '2026-10-26 11:46:20');

-- --------------------------------------------------------

--
-- Table structure for table `case_handovers`
--

CREATE TABLE `case_handovers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `request_id` bigint(20) UNSIGNED DEFAULT NULL,
  `case_id` bigint(20) UNSIGNED NOT NULL,
  `from_employee_id` varchar(191) NOT NULL,
  `to_employee_id` varchar(191) NOT NULL,
  `status` varchar(191) NOT NULL DEFAULT 'proposed',
  `proposed_at` timestamp NULL DEFAULT NULL,
  `responded_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` varchar(191) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `case_handovers`
--

INSERT INTO `case_handovers` (`id`, `request_id`, `case_id`, `from_employee_id`, `to_employee_id`, `status`, `proposed_at`, `responded_at`, `reviewed_by`, `reviewed_at`, `notes`, `created_at`, `updated_at`) VALUES
(1, 3, 3, 'e3', 'e1', 'proposed', '2026-09-07 21:00:00', NULL, NULL, NULL, 'Approval progressed to Final Authority — DPP.', '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(2, 4, 4, 'e2', 'e3', 'proposed', '2026-09-02 21:00:00', NULL, NULL, NULL, 'Approval progressed to Human Resources.', '2026-10-26 11:46:20', '2026-10-26 11:46:20');

-- --------------------------------------------------------

--
-- Table structure for table `delegations`
--

CREATE TABLE `delegations` (
  `id` varchar(191) NOT NULL,
  `delegator_employee_id` varchar(191) NOT NULL,
  `delegate_employee_id` varchar(191) NOT NULL,
  `role_id` varchar(191) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `authorized_by` varchar(191) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `approval_role_id` varchar(191) DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `delegations`
--

INSERT INTO `delegations` (`id`, `delegator_employee_id`, `delegate_employee_id`, `role_id`, `start_date`, `end_date`, `authorized_by`, `active`, `created_at`, `updated_at`, `approval_role_id`) VALUES
('del1', 'e6', 'e7', 'DEPT_HEAD', '2026-09-05', '2030-12-31', 'e1', 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18', NULL),
('del2', 'e5', 'e9', 'ZONAL_HEAD', '2026-08-01', '2026-09-20', 'e1', 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18', NULL),
('del3', 'e3', 'e2', 'HR', '2031-12-14', '2031-12-24', 'e1', 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18', NULL),
('del4', 'e4', 'e8', 'ZONAL_HEAD', '2026-09-01', '2030-12-31', 'e1', 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18', NULL),
('del5', 'e6', 'e2', 'DEPT_HEAD', '2026-07-01', '2026-08-15', 'e1', 0, '2026-10-26 11:46:18', '2026-10-26 11:46:18', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` varchar(191) NOT NULL,
  `name` varchar(191) NOT NULL,
  `zone_id` varchar(191) DEFAULT NULL,
  `parent_department_id` varchar(191) DEFAULT NULL,
  `unit_type` varchar(191) NOT NULL DEFAULT 'department',
  `reports_directly_to_leadership` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `name`, `zone_id`, `parent_department_id`, `unit_type`, `reports_directly_to_leadership`, `created_at`, `updated_at`) VALUES
('d1', 'Administration & Human Resources', 'z1', NULL, 'division', 1, '2026-10-26 11:46:08', '2026-10-26 11:46:08'),
('d2', 'Prosecutions', 'z1', NULL, 'division', 1, '2026-10-26 11:46:08', '2026-10-26 11:46:08'),
('d3', 'Legal Research & Advisory', 'z1', 'd2', 'unit', 0, '2026-10-26 11:46:08', '2026-10-26 11:46:08'),
('d4', 'Regional Prosecutions — Unguja North', 'z2', 'd2', 'region', 0, '2026-10-26 11:46:08', '2026-10-26 11:46:08'),
('d5', 'Regional Prosecutions — Pemba', 'z4', 'd2', 'region', 0, '2026-10-26 11:46:08', '2026-10-26 11:46:08'),
('d6', 'ICT', 'z1', 'd1', 'unit', 0, '2026-10-26 11:46:08', '2026-10-26 11:46:08'),
('d7', 'Records & Archives', 'z1', 'd1', 'unit', 0, '2026-10-26 11:46:08', '2026-10-26 11:46:08'),
('d8', 'Finance', 'z1', 'd1', 'unit', 0, '2026-10-26 11:46:08', '2026-10-26 11:46:08');

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `id` varchar(191) NOT NULL,
  `payroll_no` varchar(40) DEFAULT NULL,
  `user_id` varchar(191) DEFAULT NULL,
  `full_name` varchar(191) NOT NULL,
  `initials` varchar(191) DEFAULT NULL,
  `position_id` varchar(191) NOT NULL,
  `department_id` varchar(191) NOT NULL,
  `zone_id` varchar(191) NOT NULL,
  `gender` varchar(10) DEFAULT NULL,
  `email` varchar(191) DEFAULT NULL,
  `hire_date` date NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`id`, `payroll_no`, `user_id`, `full_name`, `initials`, `position_id`, `department_id`, `zone_id`, `gender`, `email`, `hire_date`, `is_active`, `created_at`, `updated_at`) VALUES
('e1', 'DPP-1001', 'u1', 'Ali Mwinyi Khamis', 'AK', 'p1', 'd2', 'z1', 'M', 'ali.khamis@dpp.go.tz', '2010-01-04', 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
('e10', 'DPP-1010', 'u10', 'Baraka Said Maulid', 'BM', 'p10', 'd6', 'z1', 'M', 'baraka.maulid@dpp.go.tz', '2019-04-08', 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
('e11', 'DPP-1011', 'u11', 'Khadija Suleiman Omar', 'KO', 'p11', 'd7', 'z1', 'F', 'khadija.omar@dpp.go.tz', '2020-07-14', 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
('e12', 'DPP-1012', 'u12', 'Haji Mwinyi Abdullah', 'HA', 'p12', 'd1', 'z1', 'M', 'haji.abdullah@dpp.go.tz', '2021-10-02', 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
('e2', 'DPP-1002', 'u2', 'Salma Ali Juma', 'SJ', 'p2', 'd2', 'z1', 'F', 'salma.juma@dpp.go.tz', '2012-06-18', 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
('e3', 'DPP-1003', 'u3', 'Fatma Abdalla Hassan', 'FH', 'p3', 'd1', 'z1', 'F', 'fatma.hassan@dpp.go.tz', '2011-03-01', 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
('e4', 'DPP-1004', 'u4', 'Rashid Haji Rashid', 'RR', 'p4', 'd4', 'z2', 'M', 'rashid.rashid@dpp.go.tz', '2013-09-15', 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
('e5', 'DPP-1005', 'u5', 'Juma Mohammed Ali', 'JA', 'p4', 'd5', 'z4', 'M', 'juma.ali@dpp.go.tz', '2012-11-05', 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
('e6', 'DPP-1006', 'u6', 'Zaytuni Omar Juma', 'ZJ', 'p5', 'd2', 'z1', 'F', 'zaytuni.juma@dpp.go.tz', '2014-02-20', 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
('e7', 'DPP-1007', 'u7', 'Asha Hassan Haji', 'AH', 'p8', 'd2', 'z1', 'F', 'asha.haji@dpp.go.tz', '2018-05-10', 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
('e8', 'DPP-1008', 'u8', 'Mohammed Ali Salum', 'MS', 'p7', 'd4', 'z2', 'M', 'mohammed.salum@dpp.go.tz', '2016-08-22', 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
('e9', 'DPP-1009', 'u9', 'Rehema Mzuri Hamad', 'RH', 'p7', 'd5', 'z4', 'F', 'rehema.hamad@dpp.go.tz', '2017-01-30', 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18');

-- --------------------------------------------------------

--
-- Table structure for table `employee_approval_roles`
--

CREATE TABLE `employee_approval_roles` (
  `id` varchar(191) NOT NULL,
  `employee_id` varchar(191) NOT NULL,
  `approval_role_id` varchar(191) NOT NULL,
  `organizational_unit_id` varchar(191) DEFAULT NULL,
  `zone_id` varchar(191) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 1,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `source` varchar(191) NOT NULL DEFAULT 'manual_grant',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employee_roles`
--

CREATE TABLE `employee_roles` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` varchar(191) NOT NULL,
  `role_id` varchar(191) NOT NULL,
  `department_id` varchar(191) DEFAULT NULL,
  `scope_zone_id` varchar(191) DEFAULT NULL,
  `source` varchar(191) NOT NULL DEFAULT 'position_default',
  `granted_by` varchar(191) DEFAULT NULL,
  `effective_from` date DEFAULT NULL,
  `effective_to` date DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `employee_roles`
--

INSERT INTO `employee_roles` (`id`, `employee_id`, `role_id`, `department_id`, `scope_zone_id`, `source`, `granted_by`, `effective_from`, `effective_to`, `active`, `created_at`, `updated_at`) VALUES
(1, 'e1', 'FINAL_AUTHORITY', NULL, NULL, 'position_default', 'u1', '2020-01-01', NULL, 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(2, 'e3', 'HR', NULL, NULL, 'position_default', 'u1', '2020-01-01', NULL, 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(3, 'e3', 'DEPT_HEAD', 'd1', NULL, 'head_of_department', 'u1', '2020-01-01', NULL, 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(4, 'e3', 'DEPT_HEAD', 'd6', NULL, 'head_of_department', 'u1', '2020-01-01', NULL, 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(5, 'e3', 'DEPT_HEAD', 'd7', NULL, 'head_of_department', 'u1', '2020-01-01', NULL, 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(6, 'e3', 'DEPT_HEAD', 'd8', NULL, 'head_of_department', 'u1', '2020-01-01', NULL, 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(7, 'e2', 'DEPT_HEAD', 'd3', NULL, 'head_of_department', 'u1', '2020-01-01', NULL, 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(8, 'e4', 'DEPT_HEAD', 'd4', NULL, 'head_of_department', 'u1', '2020-01-01', NULL, 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(9, 'e4', 'ZONAL_HEAD', NULL, 'z2', 'zonal_head', 'u1', '2020-01-01', NULL, 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(10, 'e5', 'DEPT_HEAD', 'd5', NULL, 'head_of_department', 'u1', '2020-01-01', NULL, 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(11, 'e5', 'ZONAL_HEAD', NULL, 'z4', 'zonal_head', 'u1', '2020-01-01', NULL, 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(12, 'e6', 'DEPT_HEAD', 'd2', NULL, 'head_of_department', 'u1', '2020-01-01', NULL, 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(13, 'e6', 'SUPERVISOR', NULL, NULL, 'position_default', 'u1', '2020-01-01', NULL, 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(14, 'e7', 'SUPERVISOR', NULL, NULL, 'position_default', 'u1', '2020-01-01', NULL, 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(15, 'e1', 'SELF_SERVICE', NULL, NULL, 'position_default', 'u1', '2020-01-01', NULL, 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(16, 'e10', 'SELF_SERVICE', NULL, NULL, 'position_default', 'u1', '2020-01-01', NULL, 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(17, 'e11', 'SELF_SERVICE', NULL, NULL, 'position_default', 'u1', '2020-01-01', NULL, 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(18, 'e12', 'SELF_SERVICE', NULL, NULL, 'position_default', 'u1', '2020-01-01', NULL, 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(19, 'e2', 'SELF_SERVICE', NULL, NULL, 'position_default', 'u1', '2020-01-01', NULL, 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(20, 'e3', 'SELF_SERVICE', NULL, NULL, 'position_default', 'u1', '2020-01-01', NULL, 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(21, 'e4', 'SELF_SERVICE', NULL, NULL, 'position_default', 'u1', '2020-01-01', NULL, 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(22, 'e5', 'SELF_SERVICE', NULL, NULL, 'position_default', 'u1', '2020-01-01', NULL, 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(23, 'e6', 'SELF_SERVICE', NULL, NULL, 'position_default', 'u1', '2020-01-01', NULL, 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(24, 'e7', 'SELF_SERVICE', NULL, NULL, 'position_default', 'u1', '2020-01-01', NULL, 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(25, 'e8', 'SELF_SERVICE', NULL, NULL, 'position_default', 'u1', '2020-01-01', NULL, 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(26, 'e9', 'SELF_SERVICE', NULL, NULL, 'position_default', 'u1', '2020-01-01', NULL, 1, '2026-10-26 11:46:18', '2026-10-26 11:46:18');

-- --------------------------------------------------------

--
-- Table structure for table `failed_jobs`
--

CREATE TABLE `failed_jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(191) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `jobs`
--

CREATE TABLE `jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `queue` varchar(191) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) UNSIGNED NOT NULL,
  `reserved_at` int(10) UNSIGNED DEFAULT NULL,
  `available_at` int(10) UNSIGNED NOT NULL,
  `created_at` int(10) UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `job_batches`
--

CREATE TABLE `job_batches` (
  `id` varchar(191) NOT NULL,
  `name` varchar(191) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_balances`
--

CREATE TABLE `leave_balances` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` varchar(191) NOT NULL,
  `leave_type_id` varchar(191) NOT NULL,
  `year` int(10) UNSIGNED NOT NULL,
  `accrued_days` decimal(6,2) NOT NULL,
  `carried_over_days` decimal(6,2) NOT NULL DEFAULT 0.00,
  `used_days` decimal(6,2) NOT NULL DEFAULT 0.00,
  `expiry_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_documents`
--

CREATE TABLE `leave_documents` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `request_id` bigint(20) UNSIGNED NOT NULL,
  `file_ref` varchar(191) NOT NULL,
  `document_type` varchar(191) DEFAULT NULL,
  `name` varchar(191) DEFAULT NULL,
  `size` varchar(191) DEFAULT NULL,
  `uploaded_by_user_id` varchar(191) DEFAULT NULL,
  `uploaded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `leave_documents`
--

INSERT INTO `leave_documents` (`id`, `request_id`, `file_ref`, `document_type`, `name`, `size`, `uploaded_by_user_id`, `uploaded_at`, `created_at`, `updated_at`) VALUES
(1, 2, 'documents/medical_certificate_e8.pdf', 'Medical certificate', 'medical_certificate_e8.pdf', '146 KB', 'u8', '2026-09-09 21:00:00', '2026-10-26 11:46:19', '2026-10-26 11:46:19'),
(2, 3, 'documents/antenatal_record_e9.pdf', 'Antenatal record', 'antenatal_record_e9.pdf', '1.2 MB', 'u9', '2026-09-01 21:00:00', '2026-10-26 11:46:19', '2026-10-26 11:46:19'),
(3, 11, 'documents/acting_cover_note_e3.pdf', 'Cover note', 'acting_cover_note_e3.pdf', '118 KB', 'u3', '2026-07-19 21:00:00', '2026-10-26 11:46:19', '2026-10-26 11:46:19'),
(4, 4, 'documents/acting_cover_note_e6.pdf', 'Acting cover plan', 'acting_cover_note_e6.pdf', '96 KB', 'u6', '2026-08-29 21:00:00', '2026-10-26 11:46:19', '2026-10-26 11:46:19'),
(5, 9, 'documents/birth_certificate_e7.pdf', 'Birth certificate', 'birth_certificate_e7.pdf', '512 KB', 'u7', '2026-09-07 21:00:00', '2026-10-26 11:46:19', '2026-10-26 11:46:19'),
(6, 13, 'documents/medical_certificate_e4.pdf', 'Medical certificate', 'medical_certificate_e4.pdf', '132 KB', 'u4', '2026-09-10 21:00:00', '2026-10-26 11:46:19', '2026-10-26 11:46:19');

-- --------------------------------------------------------

--
-- Table structure for table `leave_document_requirements`
--

CREATE TABLE `leave_document_requirements` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `leave_policy_id` varchar(191) DEFAULT NULL,
  `leave_type_id` varchar(191) NOT NULL,
  `document_type` varchar(191) NOT NULL,
  `required` tinyint(1) NOT NULL DEFAULT 1,
  `condition_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`condition_json`)),
  `verification_role` varchar(191) DEFAULT NULL,
  `sensitivity` varchar(191) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_payment_cases`
--

CREATE TABLE `leave_payment_cases` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` varchar(191) NOT NULL,
  `leave_request_id` bigint(20) UNSIGNED DEFAULT NULL,
  `leave_type_id` varchar(191) NOT NULL,
  `policy_id` varchar(191) DEFAULT NULL,
  `payment_type` varchar(191) NOT NULL DEFAULT 'leave_encashment',
  `status` varchar(191) NOT NULL DEFAULT 'pending',
  `salary_basis` varchar(191) DEFAULT NULL,
  `salary_amount` decimal(14,2) DEFAULT NULL,
  `allowances_included` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`allowances_included`)),
  `allowances_excluded` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`allowances_excluded`)),
  `eligible_amount` decimal(14,2) DEFAULT NULL,
  `calculated_amount` decimal(14,2) DEFAULT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'TZS',
  `calculation_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`calculation_snapshot`)),
  `eligibility_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`eligibility_snapshot`)),
  `created_by` varchar(191) DEFAULT NULL,
  `submitted_by` varchar(191) DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `hr_reviewed_by` varchar(191) DEFAULT NULL,
  `hr_reviewed_at` timestamp NULL DEFAULT NULL,
  `sent_to_accounting_by` varchar(191) DEFAULT NULL,
  `sent_to_accounting_at` timestamp NULL DEFAULT NULL,
  `accounting_notified_at` timestamp NULL DEFAULT NULL,
  `processing_at` timestamp NULL DEFAULT NULL,
  `processed_by` varchar(191) DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `rejected_by` varchar(191) DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `cancelled_by` varchar(191) DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `idempotency_key` varchar(191) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_plans`
--

CREATE TABLE `leave_plans` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` varchar(191) NOT NULL,
  `leave_type_id` varchar(191) NOT NULL,
  `leave_policy_id` varchar(191) DEFAULT NULL,
  `cycle_start` date DEFAULT NULL,
  `cycle_end` date DEFAULT NULL,
  `status` varchar(191) NOT NULL DEFAULT 'draft',
  `selected_option` varchar(191) DEFAULT NULL,
  `confirmed_start_date` date DEFAULT NULL,
  `confirmed_end_date` date DEFAULT NULL,
  `confirmed_by` varchar(191) DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `leave_request_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_plan_options`
--

CREATE TABLE `leave_plan_options` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `leave_plan_id` bigint(20) UNSIGNED NOT NULL,
  `option_number` int(10) UNSIGNED NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `days` int(10) UNSIGNED NOT NULL,
  `score` decimal(5,2) DEFAULT NULL,
  `score_breakdown_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`score_breakdown_json`)),
  `availability_status` varchar(191) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_policies`
--

CREATE TABLE `leave_policies` (
  `id` varchar(191) NOT NULL,
  `leave_type_id` varchar(191) NOT NULL,
  `version` int(10) UNSIGNED NOT NULL,
  `max_days_per_year` decimal(5,1) DEFAULT NULL,
  `max_days_per_request` decimal(5,1) DEFAULT NULL,
  `min_tenure_months` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `min_notice_days` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `requires_documents` tinyint(1) NOT NULL DEFAULT 0,
  `required_docs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`required_docs`)),
  `carry_over_max` decimal(5,1) NOT NULL DEFAULT 0.0,
  `carry_over_expiry_month` tinyint(3) UNSIGNED DEFAULT NULL,
  `allow_half_day` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `status` varchar(191) NOT NULL DEFAULT 'active',
  `source_document` varchar(191) DEFAULT NULL,
  `regulation_reference` varchar(191) DEFAULT NULL,
  `is_inherited` tinyint(1) NOT NULL DEFAULT 0,
  `parent_policy_id` varchar(191) DEFAULT NULL,
  `priority` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `effective_from` date DEFAULT NULL,
  `effective_to` date DEFAULT NULL,
  `created_by` varchar(191) DEFAULT NULL,
  `approved_by` varchar(191) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `leave_policies`
--

INSERT INTO `leave_policies` (`id`, `leave_type_id`, `version`, `max_days_per_year`, `max_days_per_request`, `min_tenure_months`, `min_notice_days`, `requires_documents`, `required_docs`, `carry_over_max`, `carry_over_expiry_month`, `allow_half_day`, `notes`, `status`, `source_document`, `regulation_reference`, `is_inherited`, `parent_policy_id`, `priority`, `effective_from`, `effective_to`, `created_by`, `approved_by`, `created_at`, `updated_at`) VALUES
('pol1', 'lt1', 4, 30.0, 30.0, 12, 14, 0, '[]', 10.0, 3, 1, 'Current annual policy v4 — 30 days, carry-over raised to 10. >20 days requires final authority.', 'active', NULL, NULL, 0, NULL, 0, '2026-06-16', NULL, 'u3', 'u1', '2026-10-26 11:46:13', '2026-10-26 11:46:13'),
('pol1_v1', 'lt1', 1, 24.0, 24.0, 12, 14, 0, '[]', 5.0, NULL, 1, 'Original annual policy — carry-over capped at 5 days.', 'active', NULL, NULL, 0, NULL, 0, '2023-04-01', '2024-03-31', 'u3', 'u1', '2026-10-26 11:46:13', '2026-10-26 11:46:13'),
('pol1_v3', 'lt1', 3, 27.0, 27.0, 12, 14, 0, '[]', 8.0, 3, 1, 'Annual policy v3 — 27 days, carry-over raised to 8.', 'active', NULL, NULL, 0, NULL, 0, '2024-04-01', '2026-06-15', 'u3', 'u1', '2026-10-26 11:46:13', '2026-10-26 11:46:13'),
('pol2', 'lt2', 3, 14.0, 14.0, 0, 1, 1, '[\"Medical certificate\"]', 0.0, NULL, 1, 'Current sick policy v3 — 14 days. Medical certificate mandatory beyond 3 days.', 'active', NULL, NULL, 0, NULL, 0, '2026-03-02', NULL, 'u3', 'u1', '2026-10-26 11:46:13', '2026-10-26 11:46:13'),
('pol2_v1', 'lt2', 1, 10.0, 10.0, 0, 1, 1, '[\"Medical certificate\"]', 0.0, NULL, 1, 'Original sick policy — 10 days.', 'active', NULL, NULL, 0, NULL, 0, '2024-01-01', '2025-08-01', 'u3', 'u1', '2026-10-26 11:46:13', '2026-10-26 11:46:13'),
('pol2_v2', 'lt2', 2, 12.0, 12.0, 0, 1, 1, '[\"Medical certificate\"]', 0.0, NULL, 1, 'Sick leave v2 — 12 days, extended for seasonal malaria.', 'active', NULL, NULL, 0, NULL, 0, '2025-08-02', '2026-03-01', 'u3', 'u1', '2026-10-26 11:46:13', '2026-10-26 11:46:13'),
('pol3', 'lt3', 2, 84.0, 84.0, 6, 7, 1, '[\"Antenatal record\",\"Birth certificate (post-delivery)\"]', 0.0, NULL, 0, 'Current maternity policy v2 — final authority approval required, acting cover mandatory.', 'active', NULL, NULL, 0, NULL, 0, '2025-10-02', NULL, 'u3', 'u1', '2026-10-26 11:46:13', '2026-10-26 11:46:13'),
('pol3_v1', 'lt3', 1, 84.0, 84.0, 6, 7, 1, '[\"Antenatal record\",\"Birth certificate (post-delivery)\"]', 0.0, NULL, 0, 'Original maternity policy — 84 days, 6 months tenure.', 'active', NULL, NULL, 0, NULL, 0, '2023-01-01', '2025-10-01', 'u3', 'u1', '2026-10-26 11:46:13', '2026-10-26 11:46:13'),
('pol4', 'lt4', 2, 7.0, 7.0, 0, 1, 1, '[\"Birth certificate\"]', 0.0, NULL, 0, 'Medical/birth documentation held privately by HR only.', 'active', NULL, NULL, 0, NULL, 0, '2024-01-01', NULL, 'u3', 'u1', '2026-10-26 11:46:13', '2026-10-26 11:46:13'),
('pol5', 'lt5', 2, 5.0, 5.0, 0, 0, 0, '[]', 0.0, NULL, 0, 'Zero notice period. Supporting explanation recorded at submission.', 'active', NULL, NULL, 0, NULL, 0, '2024-01-01', NULL, 'u3', 'u1', '2026-10-26 11:46:13', '2026-10-26 11:46:13'),
('pol6', 'lt6', 1, 120.0, 120.0, 24, 45, 1, '[\"Admission \\/ enrolment letter\",\"Study plan\"]', 0.0, NULL, 0, 'Pemba requests add a Zonal Head step. Final authority approval always required.', 'active', NULL, NULL, 0, NULL, 0, '2024-01-01', NULL, 'u3', 'u1', '2026-10-26 11:46:13', '2026-10-26 11:46:13'),
('pol7', 'lt7', 1, 60.0, 60.0, 0, 14, 0, '[]', 0.0, NULL, 0, 'Not applicable during probation. Final authority approval required.', 'active', NULL, NULL, 0, NULL, 0, '2024-01-01', NULL, 'u3', 'u1', '2026-10-26 11:46:13', '2026-10-26 11:46:13');

-- --------------------------------------------------------

--
-- Table structure for table `leave_reasons`
--

CREATE TABLE `leave_reasons` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `leave_type_id` varchar(191) NOT NULL,
  `code` varchar(191) NOT NULL,
  `name` varchar(191) NOT NULL,
  `description` text DEFAULT NULL,
  `maximum_days` int(10) UNSIGNED DEFAULT NULL,
  `period_type` varchar(191) DEFAULT NULL,
  `requires_document` tinyint(1) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_requests`
--

CREATE TABLE `leave_requests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `ref` varchar(191) NOT NULL,
  `employee_id` varchar(191) NOT NULL,
  `leave_type_id` varchar(191) NOT NULL,
  `policy_id` varchar(191) DEFAULT NULL,
  `policy_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`policy_snapshot`)),
  `required_docs_provided` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`required_docs_provided`)),
  `medical_required` tinyint(1) DEFAULT NULL,
  `eligibility_checked_at` timestamp NULL DEFAULT NULL,
  `eligibility_blockers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`eligibility_blockers`)),
  `eligibility_ok` tinyint(1) DEFAULT NULL,
  `policy_version` varchar(191) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `days` int(10) UNSIGNED DEFAULT NULL,
  `is_half_day` tinyint(1) NOT NULL DEFAULT 0,
  `is_emergency_exception` tinyint(1) NOT NULL DEFAULT 0,
  `exception_reason` text DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `status` varchar(191) NOT NULL DEFAULT 'draft',
  `current_owner_id` varchar(191) DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `leave_requests`
--

INSERT INTO `leave_requests` (`id`, `ref`, `employee_id`, `leave_type_id`, `policy_id`, `policy_snapshot`, `required_docs_provided`, `medical_required`, `eligibility_checked_at`, `eligibility_blockers`, `eligibility_ok`, `policy_version`, `start_date`, `end_date`, `days`, `is_half_day`, `is_emergency_exception`, `exception_reason`, `reason`, `status`, `current_owner_id`, `submitted_at`, `decided_at`, `created_at`, `updated_at`) VALUES
(1, 'LVE-2026-0147', 'e7', 'lt1', 'pol1', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-05', '2026-10-16', 10, 0, 0, NULL, 'Family visit to Unguja North; planned well in advance.', 'approved', NULL, '2026-08-19 21:00:00', '2026-08-27 21:00:00', '2026-08-19 21:00:00', '2026-08-27 21:00:00'),
(2, 'LVE-2026-0150', 'e8', 'lt2', 'pol2', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-14', '2026-09-18', 5, 0, 0, NULL, 'Malaria treatment and recovery.', 'approved', NULL, '2026-09-09 21:00:00', '2026-09-11 21:00:00', '2026-09-09 21:00:00', '2026-09-11 21:00:00'),
(3, 'LVE-2026-0153', 'e9', 'lt3', 'pol3', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-01', '2026-12-23', 84, 0, 0, NULL, 'Statutory maternity leave; antenatal record attached.', 'under_review', NULL, '2026-09-01 21:00:00', NULL, '2026-09-01 21:00:00', '2026-09-01 21:00:00'),
(4, 'LVE-2026-0155', 'e6', 'lt1', 'pol1', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-02', '2026-11-23', 22, 0, 0, NULL, 'Accrued annual leave over two years.', 'under_review', NULL, '2026-08-29 21:00:00', NULL, '2026-08-29 21:00:00', '2026-08-29 21:00:00'),
(5, 'LVE-2026-0156', 'e12', 'lt5', 'pol5', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-20', '2026-09-21', 2, 0, 1, 'Urgent family matter — bereavement support.', 'Urgent family matter — bereavement support.', 'approved', NULL, '2026-09-11 21:00:00', '2026-09-12 21:00:00', '2026-09-11 21:00:00', '2026-09-12 21:00:00'),
(6, 'LVE-2026-0158', 'e10', 'lt6', 'pol6', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-01', '2026-11-30', 61, 0, 0, NULL, 'MSc in Cybersecurity — University of Dar es Salaam, first year.', 'returned_for_correction', NULL, '2026-08-14 21:00:00', NULL, '2026-08-14 21:00:00', '2026-08-14 21:00:00'),
(7, 'LVE-2026-0160', 'e11', 'lt1', 'pol1', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-28', '2026-10-02', 5, 0, 0, NULL, 'Annual leave; already on file for Records rotation.', 'rejected', NULL, '2026-09-04 21:00:00', '2026-09-07 21:00:00', '2026-09-04 21:00:00', '2026-09-07 21:00:00'),
(8, 'LVE-2026-0162', 'e8', 'lt1', 'pol1', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-09', '2026-11-20', 12, 0, 0, NULL, 'Annual leave, deferred from 2025.', 'submitted', NULL, '2026-09-05 21:00:00', NULL, '2026-09-05 21:00:00', '2026-09-05 21:00:00'),
(9, 'LVE-2026-0163', 'e7', 'lt4', 'pol4', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-19', '2026-10-25', 7, 0, 0, NULL, 'Birth of second child.', 'submitted', NULL, '2026-09-07 21:00:00', NULL, '2026-09-07 21:00:00', '2026-09-07 21:00:00'),
(10, 'LVE-2026-0167', 'e1', 'lt1', 'pol1', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-16', '2026-11-27', 12, 0, 0, NULL, 'Annual leave for the DPP; executive cover arranged with the Deputy from day one.', 'under_review', NULL, '2026-09-04 21:00:00', NULL, '2026-09-04 21:00:00', '2026-09-04 21:00:00'),
(11, 'LVE-2026-0161', 'e3', 'lt1', 'pol1', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-08-03', '2026-08-12', 10, 0, 0, NULL, 'Annual leave; HR cover left with Baraka.', 'approved', NULL, '2026-07-19 21:00:00', '2026-07-27 21:00:00', '2026-07-19 21:00:00', '2026-07-27 21:00:00'),
(12, 'LVE-2026-0164', 'e7', 'lt2', 'pol2', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-28', '2026-10-01', 4, 0, 0, NULL, 'Malaria treatment and clinic follow-up.', 'submitted', NULL, '2026-09-11 21:00:00', NULL, '2026-09-11 21:00:00', '2026-09-11 21:00:00'),
(13, 'LVE-2026-0159', 'e4', 'lt2', 'pol2', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-22', '2026-09-25', 4, 0, 0, NULL, 'Malaria outpatient treatment and rest.', 'submitted', NULL, '2026-09-10 21:00:00', NULL, '2026-09-10 21:00:00', '2026-09-10 21:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `leave_request_dates`
--

CREATE TABLE `leave_request_dates` (
  `request_id` bigint(20) UNSIGNED NOT NULL,
  `the_date` date NOT NULL,
  `day_fraction` decimal(3,2) NOT NULL DEFAULT 1.00,
  PRIMARY KEY (`request_id`,`the_date`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `leave_request_dates`
--

INSERT INTO `leave_request_dates` (`request_id`, `the_date`, `day_fraction`) VALUES
(1, '2026-10-05', 1.00),
(1, '2026-10-06', 1.00),
(1, '2026-10-07', 1.00),
(1, '2026-10-08', 1.00),
(1, '2026-10-09', 1.00),
(1, '2026-10-10', 1.00),
(1, '2026-10-11', 1.00),
(1, '2026-10-12', 1.00),
(1, '2026-10-13', 1.00),
(1, '2026-10-14', 1.00),
(1, '2026-10-15', 1.00),
(1, '2026-10-16', 1.00),
(2, '2026-09-14', 1.00),
(2, '2026-09-15', 1.00),
(2, '2026-09-16', 1.00),
(2, '2026-09-17', 1.00),
(2, '2026-09-18', 1.00),
(3, '2026-10-01', 1.00),
(3, '2026-10-02', 1.00),
(3, '2026-10-03', 1.00),
(3, '2026-10-04', 1.00),
(3, '2026-10-05', 1.00),
(3, '2026-10-06', 1.00),
(3, '2026-10-07', 1.00),
(3, '2026-10-08', 1.00),
(3, '2026-10-09', 1.00),
(3, '2026-10-10', 1.00),
(3, '2026-10-11', 1.00),
(3, '2026-10-12', 1.00),
(3, '2026-10-13', 1.00),
(3, '2026-10-14', 1.00),
(3, '2026-10-15', 1.00),
(3, '2026-10-16', 1.00),
(3, '2026-10-17', 1.00),
(3, '2026-10-18', 1.00),
(3, '2026-10-19', 1.00),
(3, '2026-10-20', 1.00),
(3, '2026-10-21', 1.00),
(3, '2026-10-22', 1.00),
(3, '2026-10-23', 1.00),
(3, '2026-10-24', 1.00),
(3, '2026-10-25', 1.00),
(3, '2026-10-26', 1.00),
(3, '2026-10-27', 1.00),
(3, '2026-10-28', 1.00),
(3, '2026-10-29', 1.00),
(3, '2026-10-30', 1.00),
(3, '2026-10-31', 1.00),
(3, '2026-11-01', 1.00),
(3, '2026-11-02', 1.00),
(3, '2026-11-03', 1.00),
(3, '2026-11-04', 1.00),
(3, '2026-11-05', 1.00),
(3, '2026-11-06', 1.00),
(3, '2026-11-07', 1.00),
(3, '2026-11-08', 1.00),
(3, '2026-11-09', 1.00),
(3, '2026-11-10', 1.00),
(3, '2026-11-11', 1.00),
(3, '2026-11-12', 1.00),
(3, '2026-11-13', 1.00),
(3, '2026-11-14', 1.00),
(3, '2026-11-15', 1.00),
(3, '2026-11-16', 1.00),
(3, '2026-11-17', 1.00),
(3, '2026-11-18', 1.00),
(3, '2026-11-19', 1.00),
(3, '2026-11-20', 1.00),
(3, '2026-11-21', 1.00),
(3, '2026-11-22', 1.00),
(3, '2026-11-23', 1.00),
(3, '2026-11-24', 1.00),
(3, '2026-11-25', 1.00),
(3, '2026-11-26', 1.00),
(3, '2026-11-27', 1.00),
(3, '2026-11-28', 1.00),
(3, '2026-11-29', 1.00),
(3, '2026-11-30', 1.00),
(3, '2026-12-01', 1.00),
(3, '2026-12-02', 1.00),
(3, '2026-12-03', 1.00),
(3, '2026-12-04', 1.00),
(3, '2026-12-05', 1.00),
(3, '2026-12-06', 1.00),
(3, '2026-12-07', 1.00),
(3, '2026-12-08', 1.00),
(3, '2026-12-09', 1.00),
(3, '2026-12-10', 1.00),
(3, '2026-12-11', 1.00),
(3, '2026-12-12', 1.00),
(3, '2026-12-13', 1.00),
(3, '2026-12-14', 1.00),
(3, '2026-12-15', 1.00),
(3, '2026-12-16', 1.00),
(3, '2026-12-17', 1.00),
(3, '2026-12-18', 1.00),
(3, '2026-12-19', 1.00),
(3, '2026-12-20', 1.00),
(3, '2026-12-21', 1.00),
(3, '2026-12-22', 1.00),
(3, '2026-12-23', 1.00),
(4, '2026-11-02', 1.00),
(4, '2026-11-03', 1.00),
(4, '2026-11-04', 1.00),
(4, '2026-11-05', 1.00),
(4, '2026-11-06', 1.00),
(4, '2026-11-07', 1.00),
(4, '2026-11-08', 1.00),
(4, '2026-11-09', 1.00),
(4, '2026-11-10', 1.00),
(4, '2026-11-11', 1.00),
(4, '2026-11-12', 1.00),
(4, '2026-11-13', 1.00),
(4, '2026-11-14', 1.00),
(4, '2026-11-15', 1.00),
(4, '2026-11-16', 1.00),
(4, '2026-11-17', 1.00),
(4, '2026-11-18', 1.00),
(4, '2026-11-19', 1.00),
(4, '2026-11-20', 1.00),
(4, '2026-11-21', 1.00),
(4, '2026-11-22', 1.00),
(4, '2026-11-23', 1.00),
(5, '2026-09-20', 1.00),
(5, '2026-09-21', 1.00),
(6, '2026-10-01', 1.00),
(6, '2026-10-02', 1.00),
(6, '2026-10-03', 1.00),
(6, '2026-10-04', 1.00),
(6, '2026-10-05', 1.00),
(6, '2026-10-06', 1.00),
(6, '2026-10-07', 1.00),
(6, '2026-10-08', 1.00),
(6, '2026-10-09', 1.00),
(6, '2026-10-10', 1.00),
(6, '2026-10-11', 1.00),
(6, '2026-10-12', 1.00),
(6, '2026-10-13', 1.00),
(6, '2026-10-14', 1.00),
(6, '2026-10-15', 1.00),
(6, '2026-10-16', 1.00),
(6, '2026-10-17', 1.00),
(6, '2026-10-18', 1.00),
(6, '2026-10-19', 1.00),
(6, '2026-10-20', 1.00),
(6, '2026-10-21', 1.00),
(6, '2026-10-22', 1.00),
(6, '2026-10-23', 1.00),
(6, '2026-10-24', 1.00),
(6, '2026-10-25', 1.00),
(6, '2026-10-26', 1.00),
(6, '2026-10-27', 1.00),
(6, '2026-10-28', 1.00),
(6, '2026-10-29', 1.00),
(6, '2026-10-30', 1.00),
(6, '2026-10-31', 1.00),
(6, '2026-11-01', 1.00),
(6, '2026-11-02', 1.00),
(6, '2026-11-03', 1.00),
(6, '2026-11-04', 1.00),
(6, '2026-11-05', 1.00),
(6, '2026-11-06', 1.00),
(6, '2026-11-07', 1.00),
(6, '2026-11-08', 1.00),
(6, '2026-11-09', 1.00),
(6, '2026-11-10', 1.00),
(6, '2026-11-11', 1.00),
(6, '2026-11-12', 1.00),
(6, '2026-11-13', 1.00),
(6, '2026-11-14', 1.00),
(6, '2026-11-15', 1.00),
(6, '2026-11-16', 1.00),
(6, '2026-11-17', 1.00),
(6, '2026-11-18', 1.00),
(6, '2026-11-19', 1.00),
(6, '2026-11-20', 1.00),
(6, '2026-11-21', 1.00),
(6, '2026-11-22', 1.00),
(6, '2026-11-23', 1.00),
(6, '2026-11-24', 1.00),
(6, '2026-11-25', 1.00),
(6, '2026-11-26', 1.00),
(6, '2026-11-27', 1.00),
(6, '2026-11-28', 1.00),
(6, '2026-11-29', 1.00),
(6, '2026-11-30', 1.00),
(7, '2026-09-28', 1.00),
(7, '2026-09-29', 1.00),
(7, '2026-09-30', 1.00),
(7, '2026-10-01', 1.00),
(7, '2026-10-02', 1.00),
(8, '2026-11-09', 1.00),
(8, '2026-11-10', 1.00),
(8, '2026-11-11', 1.00),
(8, '2026-11-12', 1.00),
(8, '2026-11-13', 1.00),
(8, '2026-11-14', 1.00),
(8, '2026-11-15', 1.00),
(8, '2026-11-16', 1.00),
(8, '2026-11-17', 1.00),
(8, '2026-11-18', 1.00),
(8, '2026-11-19', 1.00),
(8, '2026-11-20', 1.00),
(9, '2026-10-19', 1.00),
(9, '2026-10-20', 1.00),
(9, '2026-10-21', 1.00),
(9, '2026-10-22', 1.00),
(9, '2026-10-23', 1.00),
(9, '2026-10-24', 1.00),
(9, '2026-10-25', 1.00),
(10, '2026-11-16', 1.00),
(10, '2026-11-17', 1.00),
(10, '2026-11-18', 1.00),
(10, '2026-11-19', 1.00),
(10, '2026-11-20', 1.00),
(10, '2026-11-21', 1.00),
(10, '2026-11-22', 1.00),
(10, '2026-11-23', 1.00),
(10, '2026-11-24', 1.00),
(10, '2026-11-25', 1.00),
(10, '2026-11-26', 1.00),
(10, '2026-11-27', 1.00),
(11, '2026-08-03', 1.00),
(11, '2026-08-04', 1.00),
(11, '2026-08-05', 1.00),
(11, '2026-08-06', 1.00),
(11, '2026-08-07', 1.00),
(11, '2026-08-08', 1.00),
(11, '2026-08-09', 1.00),
(11, '2026-08-10', 1.00),
(11, '2026-08-11', 1.00),
(11, '2026-08-12', 1.00),
(12, '2026-09-28', 1.00),
(12, '2026-09-29', 1.00),
(12, '2026-09-30', 1.00),
(12, '2026-10-01', 1.00),
(13, '2026-09-22', 1.00),
(13, '2026-09-23', 1.00),
(13, '2026-09-24', 1.00),
(13, '2026-09-25', 1.00);

-- --------------------------------------------------------

--
-- Table structure for table `leave_returns`
--

CREATE TABLE `leave_returns` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `leave_request_id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` varchar(191) NOT NULL,
  `expected_return_date` date NOT NULL,
  `actual_return_date` date DEFAULT NULL,
  `status` varchar(191) NOT NULL DEFAULT 'pending',
  `reported_by` varchar(191) DEFAULT NULL,
  `reported_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` varchar(191) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `decision_reason` text DEFAULT NULL,
  `explanation` text DEFAULT NULL,
  `explanation_status` varchar(191) DEFAULT NULL,
  `supporting_document_id` bigint(20) UNSIGNED DEFAULT NULL,
  `late_days` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `excused_days` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `unauthorised_days` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `deducted_days` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `policy_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`policy_snapshot`)),
  `closed_by` varchar(191) DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `closure_reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_rules`
--

CREATE TABLE `leave_rules` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `leave_policy_id` varchar(191) NOT NULL,
  `rule_key` varchar(191) NOT NULL,
  `operator` varchar(191) NOT NULL,
  `value_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`value_json`)),
  `condition_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`condition_json`)),
  `priority` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `is_inherited` tinyint(1) NOT NULL DEFAULT 0,
  `inherited_from_rule_id` bigint(20) UNSIGNED DEFAULT NULL,
  `overridden` tinyint(1) NOT NULL DEFAULT 0,
  `source_reference` varchar(191) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_types`
--

CREATE TABLE `leave_types` (
  `id` varchar(191) NOT NULL,
  `code` varchar(191) NOT NULL,
  `name` varchar(191) NOT NULL,
  `swahili` varchar(191) DEFAULT NULL,
  `short` varchar(191) DEFAULT NULL,
  `is_paid_default` tinyint(1) NOT NULL DEFAULT 1,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `leave_types`
--

INSERT INTO `leave_types` (`id`, `code`, `name`, `swahili`, `short`, `is_paid_default`, `description`, `created_at`, `updated_at`) VALUES
('lt1', 'ANNUAL', 'Annual Leave', 'Likizo ya Mwaka', 'Mwaka', 1, 'Annual recreational leave accrued per service year.', '2026-10-26 11:46:08', '2026-10-26 11:46:08'),
('lt2', 'SICK', 'Sick Leave', 'Likizo ya Ugonjwa', 'Ugonjwa', 1, 'Leave for certified illness or injury.', '2026-10-26 11:46:08', '2026-10-26 11:46:08'),
('lt3', 'MATERNITY', 'Maternity Leave', 'Likizo ya Uzazi', 'Uzazi', 1, 'Statutory leave for mothers before and after delivery.', '2026-10-26 11:46:08', '2026-10-26 11:46:08'),
('lt4', 'PATERNITY', 'Paternity Leave', 'Likizo ya Baba', 'Baba', 1, 'Short leave for fathers following the birth of a child.', '2026-10-26 11:46:08', '2026-10-26 11:46:08'),
('lt5', 'EMERGENCY', 'Emergency Leave', 'Likizo ya Dharura', 'Dharura', 1, 'Urgent personal or family emergencies.', '2026-10-26 11:46:08', '2026-10-26 11:46:08'),
('lt6', 'STUDY', 'Study Leave', 'Likizo ya Masomo', 'Masomo', 1, 'Leave to pursue approved academic or professional training.', '2026-10-26 11:46:08', '2026-10-26 11:46:08'),
('lt7', 'WITHOUT_PAY', 'Leave Without Pay', 'Likizo Bila Malipo', 'Bila Malipo', 0, 'Unpaid leave approved for personal reasons.', '2026-10-26 11:46:08', '2026-10-26 11:46:08');

-- --------------------------------------------------------

--
-- Table structure for table `leave_waivers`
--

CREATE TABLE `leave_waivers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` varchar(191) NOT NULL,
  `leave_type_id` varchar(191) NOT NULL,
  `leave_policy_id` varchar(191) DEFAULT NULL,
  `leave_cycle_start` date DEFAULT NULL,
  `leave_cycle_end` date DEFAULT NULL,
  `requested_action` varchar(191) NOT NULL,
  `reason` text DEFAULT NULL,
  `requested_by` varchar(191) DEFAULT NULL,
  `status` varchar(191) NOT NULL DEFAULT 'draft',
  `reviewed_by` varchar(191) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `decision_comment` text DEFAULT NULL,
  `effect_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`effect_json`)),
  `policy_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`policy_snapshot`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int(10) UNSIGNED NOT NULL,
  `migration` varchar(191) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `migrations`
--

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES
(1, '0001_01_01_000000_create_users_table', 1),
(2, '0001_01_01_000001_create_cache_table', 1),
(3, '0001_01_01_000002_create_jobs_table', 1),
(4, '2026_09_09_185728_create_personal_access_tokens_table', 1),
(5, '2026_09_09_210001_create_zones_table', 1),
(6, '2026_09_09_210002_create_departments_table', 1),
(7, '2026_09_09_210003_create_positions_table', 1),
(8, '2026_09_09_210004_create_employees_table', 1),
(9, '2026_09_09_210005_create_reporting_lines_table', 1),
(10, '2026_09_09_210006_create_roles_table', 1),
(11, '2026_09_09_210007_create_permissions_table', 1),
(12, '2026_09_09_210008_create_role_permissions_table', 1),
(13, '2026_09_09_210009_create_position_default_roles_table', 1),
(14, '2026_09_09_210010_create_employee_roles_table', 1),
(15, '2026_09_09_210011_create_leave_types_table', 1),
(16, '2026_09_09_210012_create_leave_policies_table', 1),
(17, '2026_09_09_210013_create_leave_balances_table', 1),
(18, '2026_09_09_210014_create_leave_requests_table', 1),
(19, '2026_09_09_210015_create_leave_request_dates_table', 1),
(20, '2026_09_09_210016_create_leave_documents_table', 1),
(21, '2026_09_09_210017_create_workflow_definitions_table', 1),
(22, '2026_09_09_210018_create_workflow_steps_table', 1),
(23, '2026_09_09_210019_create_workflow_assignments_table', 1),
(24, '2026_09_09_210020_create_approval_actions_table', 1),
(25, '2026_09_09_210021_create_delegations_table', 1),
(26, '2026_09_09_210022_create_cases_table', 1),
(27, '2026_09_09_210023_create_case_handovers_table', 1),
(28, '2026_09_09_210024_create_notifications_table', 1),
(29, '2026_09_09_210025_create_audit_logs_table', 1),
(30, '2026_09_11_000001_add_payroll_no_to_employees_table', 2),
(31, '2026_09_11_000002_make_leave_request_return_date_nullable', 2),
(32, '2026_09_14_110000_extend_permission_catalog_all_modules', 3),
(33, '2026_10_02_090000_add_cross_zone_permissions', 4),
(34, '2026_10_02_100000_allow_role_deletion', 4),
(35, '2026_10_02_110000_add_description_to_roles_table', 5),
(37, '2026_10_03_120000_remove_unenforced_permission_codes', 6),
(39, '2026_10_04_090000_enumerate_final_authority_and_add_break_glass', 7),
(48, '2026_10_04_100000_restore_pruned_codes', 8),
(49, '2026_10_05_090000_self_service_role', 8),
(52, '2026_10_06_100000_permission_lifecycle', 9),
(53, '2026_10_08_001316_add_policy_lifecycle_and_provenance_to_leave_policies', 10),
(54, '2026_10_08_001429_create_leave_rules_table', 10),
(55, '2026_10_08_001536_create_leave_reasons_table', 10),
(56, '2026_10_08_001559_create_leave_document_requirements_table', 10),
(57, '2026_10_08_001619_extend_leave_requests_with_eligibility_snapshot', 10),
(58, '2026_10_10_141349_create_leave_waivers_table', 10),
(59, '2026_10_10_141626_create_leave_plans_table', 10),
(60, '2026_10_10_142159_create_leave_plan_options_table', 10),
(61, '2026_10_10_150000_add_phase2_leave_planning_permissions', 10),
(62, '2026_10_11_090000_create_balance_transactions_table', 10),
(63, '2026_10_11_090100_add_leave_waiver_scope_permissions', 10),
(64, '2026_10_11_090200_create_leave_returns_table', 10),
(65, '2026_10_11_090300_add_leave_returns_permissions', 10),
(66, '2026_10_11_100000_create_notification_deliveries_table', 10),
(67, '2026_10_11_100100_create_notification_preferences_table', 10),
(68, '2026_10_11_100200_add_notifications_permissions', 10),
(69, '2026_10_11_110000_create_leave_payment_cases_table', 10),
(70, '2026_10_11_110100_add_leave_payments_permissions', 10),
(71, '2026_10_11_120000_create_approval_signatures_table', 10),
(72, '2026_10_11_120100_create_official_leave_letters_table', 11),
(73, '2026_10_11_120200_add_leave_documents_permissions', 11),
(74, '2026_10_11_130000_create_report_exports_table', 11),
(75, '2026_10_11_140000_add_performance_indexes', 11),
(86, '2026_10_28_000001_add_email_to_users_table', 12),
(87, '2026_10_12_090000_normalize_permission_catalog', 13),
(88, '2026_10_12_100000_authority_planned_code_grants', 13),
(89, '2026_10_16_120000_remove_verify_public_permission', 13),
(90, '2026_10_18_100000_purge_never_submitted_withdrawn', 13),
(91, '2026_10_18_110000_add_is_active_to_workflow_definitions', 13),
(92, '2026_10_26_000001_create_approval_roles_table', 13),
(93, '2026_10_26_000002_add_approval_role_id_to_workflow_steps', 13),
(94, '2026_10_26_000003_create_employee_approval_roles_table', 13),
(95, '2026_10_26_000004_add_approval_role_id_to_delegations', 13);

-- --------------------------------------------------------

--
-- Table structure for table `modules`
--

CREATE TABLE `modules` (
  `id` varchar(64) NOT NULL,
  `label` varchar(191) NOT NULL,
  `description` varchar(191) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `modules`
--

INSERT INTO `modules` (`id`, `label`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
('admin', 'Administration', 'Employees, roles, departments, zones and reference data.', 1, NULL, NULL),
('approval_workflows', 'Approval Workflows', 'Configuration of approval chains: who approves what, in which order, with which SLA.', 1, '2026-10-28 11:47:18', '2026-10-28 11:47:18'),
('audit_logs', 'Audit Logs', 'Audit log search and chain verification.', 1, NULL, NULL),
('case_handovers', 'Case Handovers', 'Dockets attached to leave requests and ownership handovers between holders.', 1, NULL, NULL),
('delegations', 'Delegations', 'Acting cover granted to a colleague for a bounded window.', 1, NULL, NULL),
('departments', 'Departments', 'Department management', 1, '2026-10-28 11:47:18', '2026-10-28 11:47:18'),
('divisions', 'Divisions', 'Division management', 1, '2026-10-28 11:47:18', '2026-10-28 11:47:18'),
('documents', 'Documents', 'Supporting and medical documentation attached to a request.', 1, NULL, NULL),
('employees', 'Employees', 'Employee management', 1, '2026-10-28 11:47:18', '2026-10-28 11:47:18'),
('handovers', 'Handovers', 'Handover management', 1, '2026-10-28 11:47:18', '2026-10-28 11:47:18'),
('leave_documents', 'Leave Documents', 'Official leave letters, digital signatures, and QR verification.', 1, NULL, NULL),
('leave_payments', 'Leave Payments', 'Leave encashment and leave-purchase payment cases: eligibility, HR review and Accounting settlement.', 1, NULL, NULL),
('leave_plans', 'Leave Planning', 'Almanac leave planning: date proposals, options and confirmation.', 1, NULL, NULL),
('leave_returns', 'Return to Work', 'Return-to-work reporting and late-return review and deductions.', 1, NULL, NULL),
('leave_waivers', 'Leave Waivers', 'Leave waiver, deferral and rescheduling requests.', 1, NULL, NULL),
('notifications', 'Notifications', 'Read your own notifications, manage delivery preferences, and receive sensitive leave detail.', 1, NULL, NULL),
('policies', 'Leave Policies', 'Policy authoring and the rules a request is measured against.', 1, NULL, NULL),
('positions', 'Positions', 'Position management', 1, '2026-10-28 11:47:18', '2026-10-28 11:47:18'),
('report_exports', 'Report Exports', 'Report export management and history.', 1, NULL, NULL),
('reports', 'Reports', 'Aggregate and per-zone reporting surfaces.', 1, NULL, NULL),
('requests', 'Leave Requests', 'Submitting, approving and acting on leave requests.', 1, NULL, NULL),
('staff', 'Staff', 'Staff directory visibility.', 1, NULL, NULL),
('units', 'Units', 'Independent unit management', 1, '2026-10-28 11:47:18', '2026-10-28 11:47:18'),
('workflows', 'Workflows', 'Workflow engine', 1, '2026-10-28 11:47:18', '2026-10-28 11:47:18');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `recipient_employee_id` varchar(191) NOT NULL,
  `channel` varchar(191) NOT NULL DEFAULT 'in_system',
  `event_type` varchar(191) NOT NULL,
  `related_entity_type` varchar(191) DEFAULT NULL,
  `related_entity_id` bigint(20) UNSIGNED DEFAULT NULL,
  `message` text NOT NULL,
  `is_privacy_safe` tinyint(1) NOT NULL DEFAULT 1,
  `sent_at` timestamp NULL DEFAULT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `recipient_employee_id`, `channel`, `event_type`, `related_entity_type`, `related_entity_id`, `message`, `is_privacy_safe`, `sent_at`, `read_at`, `created_at`, `updated_at`) VALUES
(1, 'e9', 'in_system', 'submitted', NULL, NULL, 'Your maternity leave request LVE-2026-0153 was submitted for approval.', 1, '2026-09-02 06:14:00', NULL, '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(2, 'e1', 'in_system', 'upcoming', NULL, NULL, 'Rehema\'s maternity request awaits your final-authority review (due within SLA).', 1, '2026-09-08 05:02:00', NULL, '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(3, 'e10', 'in_system', 'returned_for_correction', NULL, NULL, 'Study leave LVE-2026-0158 was returned for correction — admission letter missing.', 1, '2026-08-26 11:41:00', NULL, '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(4, 'e7', 'in_system', 'approved', NULL, NULL, 'Annual leave LVE-2026-0147 fully approved (5 Oct – 16 Oct).', 1, '2026-08-28 08:20:00', '2026-08-29 05:00:00', '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(5, 'e8', 'in_system', 'escalated', NULL, NULL, 'Sick leave LVE-2026-0150 was escalated; your zonal head did not respond in SLA time.', 1, '2026-09-11 04:45:00', NULL, '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(6, 'e3', 'in_system', 'delegation', NULL, NULL, 'Zaytuni delegated department-head approval authority to Asha until 10 Oct.', 1, '2026-09-05 07:00:00', NULL, '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(7, 'e7', 'in_system', 'upcoming', NULL, NULL, 'Your paternity leave begins 19 Oct. Ensure birth certificate is uploaded.', 1, '2026-09-09 03:30:00', NULL, '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(8, 'e1', 'in_system', 'handover', NULL, NULL, 'Rehema\'s maternity docket awaits your acceptance — proposed by HR on 8 Sep.', 1, '2026-09-08 06:30:00', NULL, '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(9, 'e3', 'in_system', 'submitted', NULL, NULL, 'A new annual leave request LVE-2026-0167 was submitted by the DPP.', 1, '2026-09-05 12:10:00', NULL, '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(10, 'e3', 'in_system', 'delegation', NULL, NULL, 'You delegated HR approval authority to Salma Ali Juma for 14–24 Dec.', 1, '2026-09-08 08:00:00', NULL, '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(11, 'e3', 'in_system', 'deadline_upcoming', NULL, NULL, 'LVE-2026-0167 annual leave is approaching its SLA due date.', 1, '2026-09-10 05:00:00', NULL, '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(12, 'e6', 'in_system', 'submitted', NULL, NULL, 'Sick leave LVE-2026-0164 from Asha awaits your recommendation.', 1, '2026-09-12 07:20:00', NULL, '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(13, 'e10', 'in_system', 'deadline_upcoming', NULL, NULL, 'Study leave LVE-2026-0158 must be resubmitted with the admission letter before 30 Sep.', 1, '2026-09-09 04:45:00', NULL, '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(14, 'e11', 'in_system', 'rejected', NULL, NULL, 'Your annual leave LVE-2026-0160 was declined — Records cover overlaps.', 1, '2026-09-08 13:00:00', NULL, '2026-10-26 11:46:20', '2026-10-26 11:46:20'),
(15, 'e8', 'in_system', 'upcoming', NULL, NULL, 'Sick leave LVE-2026-0150 approved; return to duty on 19 Sep.', 1, '2026-09-13 05:30:00', NULL, '2026-10-26 11:46:20', '2026-10-26 11:46:20');

-- --------------------------------------------------------

--
-- Table structure for table `notification_deliveries`
--

CREATE TABLE `notification_deliveries` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `notification_id` bigint(20) UNSIGNED NOT NULL,
  `channel` varchar(191) NOT NULL,
  `recipient` varchar(191) NOT NULL,
  `status` varchar(191) NOT NULL DEFAULT 'queued',
  `provider_message_id` varchar(191) DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `failed_at` timestamp NULL DEFAULT NULL,
  `failure_reason` text DEFAULT NULL,
  `attempts` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `idempotency_key` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification_preferences`
--

CREATE TABLE `notification_preferences` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` varchar(191) NOT NULL,
  `event_type` varchar(191) NOT NULL,
  `email_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `database_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `language` varchar(191) NOT NULL DEFAULT 'en',
  `timing_config_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`timing_config_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `official_leave_letters`
--

CREATE TABLE `official_leave_letters` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `leave_request_id` bigint(20) UNSIGNED NOT NULL,
  `document_type` enum('approval_letter','rejection_letter','cancellation_letter') NOT NULL DEFAULT 'approval_letter',
  `document_version` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `template_version` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `policy_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`policy_snapshot`)),
  `approval_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`approval_snapshot`)),
  `content_hash` varchar(64) NOT NULL,
  `storage_path` varchar(191) NOT NULL,
  `storage_disk` varchar(20) NOT NULL DEFAULT 'local',
  `generated_by` varchar(191) NOT NULL,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `revoked_at` timestamp NULL DEFAULT NULL,
  `revoked_by` varchar(191) DEFAULT NULL,
  `revocation_reason` text DEFAULT NULL,
  `qr_token` varchar(64) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `email` varchar(191) NOT NULL,
  `token` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(191) NOT NULL,
  `module` varchar(191) NOT NULL DEFAULT 'requests',
  `action` varchar(191) NOT NULL DEFAULT 'view',
  `label` varchar(191) DEFAULT NULL,
  `hint` varchar(191) DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'implemented',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `enforcement_reference` varchar(191) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`id`, `code`, `module`, `action`, `label`, `hint`, `status`, `active`, `enforcement_reference`, `created_at`, `updated_at`) VALUES
(1, 'requests.view', 'requests', 'view', 'view', 'View audit log entries.', 'implemented', 1, 'routes/api.php: GET api/requests (permission:requests.view) + app/Policies/LeaveRequestPolicy.php::view', '2026-10-26 11:46:13', '2026-10-26 11:46:18'),
(2, 'requests.create', 'requests', 'create', 'create', 'requests create', 'implemented', 1, 'routes/api.php: POST api/requests (permission:requests.create)', '2026-10-26 11:46:13', '2026-10-26 11:46:18'),
(7, 'policies.view', 'policies', 'view', 'view', 'View audit log entries.', 'implemented', 1, 'routes/api.php: GET api/workflows + GET api/workflows/{id} (permission:policies.view)', '2026-10-26 11:46:13', '2026-10-26 11:46:18'),
(8, 'documents.view', 'documents', 'view', 'view', 'View audit log entries.', 'implemented', 1, 'routes/api.php: GET api/requests/{id}/documents (permission:documents.view) + app/Policies/LeaveDocumentPolicy.php::view', '2026-10-26 11:46:13', '2026-10-26 11:46:18'),
(16, 'leave_plans.view_own', 'leave_plans', 'view_own', 'view_own', 'View your own leave reports.', 'implemented', 1, 'app/Http/Controllers/Api/LeavePlanController.php::index (can leave_plans view_own)', '2026-10-26 11:46:13', '2026-10-26 11:46:18'),
(17, 'leave_plans.create_own', 'leave_plans', 'create_own', 'create_own', 'leave_plans create_own', 'implemented', 1, 'routes/api.php: POST api/leave-plans and POST api/leave-plans/{plan}/propose (permission:leave_plans.create_own)', '2026-10-26 11:46:13', '2026-10-26 11:46:18'),
(18, 'leave_plans.select_own', 'leave_plans', 'select_own', 'select_own', 'leave_plans select_own', 'implemented', 1, 'routes/api.php: POST api/leave-plans/{plan}/select and POST api/leave-plans/{plan}/convert (permission:leave_plans.select_own)', '2026-10-26 11:46:13', '2026-10-26 11:46:18'),
(19, 'leave_waivers.create_own', 'leave_waivers', 'create_own', 'create_own', 'leave_waivers create_own', 'implemented', 1, 'routes/api.php: POST api/leave-waivers (permission:leave_waivers.create_own)', '2026-10-26 11:46:13', '2026-10-26 11:46:18'),
(20, 'leave_waivers.view_own', 'leave_waivers', 'view_own', 'view_own', 'View your own leave reports.', 'implemented', 1, 'app/Policies/LeaveWaiverPolicy.php::viewAny (can leave_waivers view_own)', '2026-10-26 11:46:13', '2026-10-26 11:46:18'),
(21, 'leave_returns.report_own', 'leave_returns', 'report_own', 'report_own', 'leave_returns report_own', 'implemented', 1, 'routes/api.php: POST api/leave-requests/{id}/return and POST api/leave-returns/{id}/explanation (permission:leave_returns.report_own) + app/Policies/LeaveReturnPolicy.php::report', '2026-10-26 11:46:13', '2026-10-26 11:46:18'),
(22, 'leave_returns.view_own', 'leave_returns', 'view_own', 'view_own', 'View your own leave reports.', 'implemented', 1, 'app/Policies/LeaveReturnPolicy.php::viewAny (can leave_returns view_own)', '2026-10-26 11:46:13', '2026-10-26 11:46:18'),
(23, 'leave_payments.view_own', 'leave_payments', 'view_own', 'view_own', 'View your own leave reports.', 'implemented', 1, 'app/Policies/LeavePaymentCasePolicy.php::viewAny (can leave_payments view_own)', '2026-10-26 11:46:13', '2026-10-26 11:46:18'),
(24, 'leave_documents.view_own', 'leave_documents', 'view_own', 'view_own', 'View your own leave reports.', 'implemented', 1, 'app/Policies/OfficialLeaveLetterPolicy.php::viewAny (can leave_documents view_own)', '2026-10-26 11:46:13', '2026-10-26 11:46:18'),
(25, 'leave_documents.download_own', 'leave_documents', 'download_own', 'download_own', 'leave_documents download_own', 'implemented', 1, 'app/Policies/OfficialLeaveLetterPolicy.php::downloadOwn (can leave_documents download_own)', '2026-10-26 11:46:13', '2026-10-26 11:46:18'),
(26, 'notifications.view_own', 'notifications', 'view_own', 'view_own', 'View your own leave reports.', 'implemented', 1, 'routes/api.php: GET api/notifications, GET api/notifications/unread-count, POST api/notifications/{id}/read (permission:notifications.view_own)', '2026-10-26 11:46:13', '2026-10-26 11:46:18'),
(27, 'notifications.manage_preferences', 'notifications', 'manage_preferences', 'manage_preferences', 'notifications manage_preferences', 'implemented', 1, 'routes/api.php: GET and PATCH api/notifications/preferences (permission:notifications.manage_preferences) + app/Http/Controllers/Api/NotificationPreferenceController.php', '2026-10-26 11:46:13', '2026-10-26 11:46:18'),
(28, 'reports.view_own', 'reports', 'view_own', 'view_own', 'View your own leave reports.', 'implemented', 1, 'app/Services/ReportAuthorizationService.php::canViewReports + app/Services/ReportQueryService.php (scope own)', '2026-10-26 11:46:13', '2026-10-26 11:46:18'),
(29, 'requests.approve', 'requests', 'approve', 'approve', 'requests approve', 'implemented', 1, 'app/Services/LeaveRequestService.php::decide for approved decisions (can requests approve) + components/leave/leave-sidebar.tsx', '2026-10-26 11:46:14', '2026-10-26 11:46:18'),
(32, 'delegations.view', 'delegations', 'view', 'view', 'View audit log entries.', 'implemented', 1, 'routes/api.php: GET api/delegations (permission:delegations.view)', '2026-10-26 11:46:14', '2026-10-26 11:46:18'),
(33, 'delegations.create', 'delegations', 'create', 'create', 'delegations create', 'implemented', 1, 'routes/api.php: POST api/delegations (permission:delegations.create)', '2026-10-26 11:46:14', '2026-10-26 11:46:18'),
(34, 'delegations.update', 'delegations', 'update', 'update', 'delegations update', 'implemented', 1, 'routes/api.php: POST api/delegations/{id}/revoke (permission:delegations.update) + app/Http/Controllers/Api/DelegationController.php::revoke', '2026-10-26 11:46:14', '2026-10-26 11:46:18'),
(35, 'reports.view', 'reports', 'view', 'view', 'View audit log entries.', 'implemented', 1, 'routes/api.php: GET api/reports/* (permission:reports.view)', '2026-10-26 11:46:14', '2026-10-26 11:46:18'),
(36, 'reports.view_department', 'reports', 'view_department', 'view_department', 'View leave reports for your department.', 'implemented', 1, 'app/Services/ReportAuthorizationService.php::canViewReports + app/Services/ReportQueryService.php (scope department)', '2026-10-26 11:46:14', '2026-10-26 11:46:18'),
(37, 'leave_plans.view_department', 'leave_plans', 'view_department', 'view_department', 'View leave reports for your department.', 'implemented', 1, 'app/Http/Controllers/Api/LeavePlanController.php::index and calendar (can leave_plans view_department)', '2026-10-26 11:46:14', '2026-10-26 11:46:18'),
(38, 'leave_plans.confirm_department', 'leave_plans', 'confirm_department', 'confirm_department', 'leave_plans confirm_department', 'implemented', 1, 'app/Http/Controllers/Api/LeavePlanController.php::confirm (can leave_plans confirm_department)', '2026-10-26 11:46:14', '2026-10-26 11:46:18'),
(39, 'leave_waivers.view_department', 'leave_waivers', 'view_department', 'view_department', 'View leave reports for your department.', 'implemented', 1, 'app/Policies/LeaveWaiverPolicy.php::view (can leave_waivers view_department)', '2026-10-26 11:46:14', '2026-10-26 11:46:18'),
(40, 'leave_waivers.review', 'leave_waivers', 'review', 'review', 'leave_waivers review', 'implemented', 1, 'routes/api.php: POST api/leave-waivers/{waiver}/review (permission:leave_waivers.review) + app/Policies/LeaveWaiverPolicy.php::review', '2026-10-26 11:46:14', '2026-10-26 11:46:18'),
(41, 'leave_waivers.reject', 'leave_waivers', 'reject', 'reject', 'leave_waivers reject', 'implemented', 1, 'routes/api.php: POST api/leave-waivers/{waiver}/reject (permission:leave_waivers.reject) + app/Policies/LeaveWaiverPolicy.php::reject', '2026-10-26 11:46:14', '2026-10-26 11:46:18'),
(42, 'leave_returns.view_department', 'leave_returns', 'view_department', 'view_department', 'View leave reports for your department.', 'implemented', 1, 'app/Policies/LeaveReturnPolicy.php::view (can leave_returns view_department)', '2026-10-26 11:46:14', '2026-10-26 11:46:18'),
(43, 'leave_returns.review', 'leave_returns', 'review', 'review', 'leave_returns review', 'implemented', 1, 'routes/api.php: POST api/leave-returns/{id}/review (permission:leave_returns.review) + app/Policies/LeaveReturnPolicy.php::review', '2026-10-26 11:46:14', '2026-10-26 11:46:18'),
(44, 'leave_returns.excuse', 'leave_returns', 'excuse', 'excuse', 'leave_returns excuse', 'implemented', 1, 'app/Services/LateReturnService.php::review (can leave_returns excuse)', '2026-10-26 11:46:14', '2026-10-26 11:46:18'),
(45, 'leave_returns.apply_deduction', 'leave_returns', 'apply_deduction', 'apply_deduction', 'leave_returns apply_deduction', 'implemented', 1, 'app/Services/LateReturnService.php::review (can leave_returns apply_deduction)', '2026-10-26 11:46:14', '2026-10-26 11:46:18'),
(46, 'leave_returns.close', 'leave_returns', 'close', 'close', 'leave_returns close', 'implemented', 1, 'routes/api.php: POST api/leave-returns/{id}/close (permission:leave_returns.close) + app/Policies/LeaveReturnPolicy.php::close', '2026-10-26 11:46:14', '2026-10-26 11:46:18'),
(47, 'requests.approve_zone', 'requests', 'approve_zone', 'approve_zone', 'Approve leave for staff outside your own zone (regional oversight).', 'implemented', 1, 'capability only; consumed by components/leave/leave-sidebar.tsx, authority is workflow assignment ownership', '2026-10-26 11:46:14', '2026-10-26 11:46:18'),
(48, 'reports.view_zone', 'reports', 'view_zone', 'view_zone', 'View leave reports for your zone.', 'implemented', 1, 'app/Services/ReportAuthorizationService.php::canViewReports + app/Services/ReportQueryService.php (scope zone)', '2026-10-26 11:46:15', '2026-10-26 11:46:18'),
(49, 'leave_plans.view_zone', 'leave_plans', 'view_zone', 'view_zone', 'View leave reports for your zone.', 'implemented', 1, 'app/Http/Controllers/Api/LeavePlanController.php::index and calendar (can leave_plans view_zone)', '2026-10-26 11:46:15', '2026-10-26 11:46:18'),
(50, 'leave_plans.confirm_zone', 'leave_plans', 'confirm_zone', 'confirm_zone', 'leave_plans confirm_zone', 'implemented', 1, 'app/Http/Controllers/Api/LeavePlanController.php::confirm (can leave_plans confirm_zone)', '2026-10-26 11:46:15', '2026-10-26 11:46:18'),
(51, 'leave_waivers.view_zone', 'leave_waivers', 'view_zone', 'view_zone', 'View leave reports for your zone.', 'implemented', 1, 'app/Policies/LeaveWaiverPolicy.php::view (can leave_waivers view_zone)', '2026-10-26 11:46:15', '2026-10-26 11:46:18'),
(52, 'leave_returns.view_zone', 'leave_returns', 'view_zone', 'view_zone', 'View leave reports for your zone.', 'implemented', 1, 'app/Policies/LeaveReturnPolicy.php::view (can leave_returns view_zone)', '2026-10-26 11:46:15', '2026-10-26 11:46:18'),
(53, 'requests.view_cross_zone', 'requests', 'view_cross_zone', 'view_cross_zone', 'View staff and leave records across ALL zones/departments. Central offices only; regional roles stay zone-scoped.', 'implemented', 1, 'app/Services/AccessScopeService.php::canViewAllZones via CROSS_ZONE_ACTION', '2026-10-26 11:46:15', '2026-10-26 11:46:18'),
(54, 'policies.create', 'policies', 'create', 'create', 'policies create', 'implemented', 1, 'routes/api.php: POST api/policies (permission:policies.create)', '2026-10-26 11:46:15', '2026-10-26 11:46:18'),
(55, 'policies.edit_policy', 'policies', 'edit_policy', 'edit_policy', 'Revise leave-type policies and approval workflows.', 'implemented', 1, 'routes/api.php: POST and PATCH api/workflows (permission:policies.edit_policy) + app/Http/Controllers/Api/WorkflowController.php', '2026-10-26 11:46:15', '2026-10-26 11:46:18'),
(56, 'documents.create', 'documents', 'create', 'create', 'documents create', 'implemented', 1, 'routes/api.php: POST api/requests/{id}/documents (permission:documents.create)', '2026-10-26 11:46:15', '2026-10-26 11:46:18'),
(57, 'documents.delete', 'documents', 'delete', 'delete', 'documents delete', 'implemented', 1, 'routes/api.php: DELETE api/requests/{id}/documents/{document} (permission:documents.delete) + app/Policies/LeaveDocumentPolicy.php::delete', '2026-10-26 11:46:15', '2026-10-26 11:46:18'),
(58, 'documents.view_medical_documents', 'documents', 'view_medical_documents', 'view_medical_documents', 'Reveal restricted medical & birth records (HR privacy gate).', 'implemented', 1, 'app/Policies/LeaveDocumentPolicy.php::view', '2026-10-26 11:46:15', '2026-10-26 11:46:18'),
(59, 'staff.view_cross_zone', 'staff', 'view_cross_zone', 'view_cross_zone', 'View staff and leave records across ALL zones/departments. Central offices only; regional roles stay zone-scoped.', 'implemented', 1, 'app/Services/AccessScopeService.php::canViewAllZones via CROSS_ZONE_ACTION', '2026-10-26 11:46:15', '2026-10-26 11:46:18'),
(60, 'reports.view_gender_reports', 'reports', 'view_gender_reports', 'view_gender_reports', 'Unlock gender-split reporting (restricted personnel data).', 'implemented', 1, 'app/Http/Controllers/Api/ReportController.php::gender (permission:reports.view_gender_reports)', '2026-10-26 11:46:15', '2026-10-26 11:46:18'),
(61, 'reports.view_cross_zone', 'reports', 'view_cross_zone', 'view_cross_zone', 'View staff and leave records across ALL zones/departments. Central offices only; regional roles stay zone-scoped.', 'implemented', 1, 'app/Services/AccessScopeService.php::canViewAllZones via CROSS_ZONE_ACTION (module reports)', '2026-10-26 11:46:15', '2026-10-26 11:46:18'),
(62, 'reports.view_organisation', 'reports', 'view_organisation', 'view_organisation', 'View leave reports across the entire organisation.', 'implemented', 1, 'app/Services/ReportAuthorizationService.php::canViewReports + app/Services/ReportQueryService.php (cross-zone)', '2026-10-26 11:46:15', '2026-10-26 11:46:18'),
(63, 'reports.export', 'reports', 'export', 'export', 'Export leave reports to CSV/PDF.', 'implemented', 1, 'routes/api.php: POST api/reports/exports (permission:reports.export) + app/Http/Controllers/Api/ReportController.php::createExport', '2026-10-26 11:46:15', '2026-10-26 11:46:18'),
(64, 'reports.view_audit', 'reports', 'view_audit', 'view_audit', 'View audit log reports.', 'implemented', 1, 'routes/api.php: GET api/reports/audit (permission:reports.view_audit) + app/Http/Controllers/Api/ReportController.php::auditSearch', '2026-10-26 11:46:15', '2026-10-26 11:46:18'),
(65, 'reports.view_financial', 'reports', 'view_financial', 'view_financial', 'View financial leave reports (payments, balances).', 'implemented', 1, 'app/Services/ReportAuthorizationService.php::canViewReports (financial gate) + app/Services/ReportQueryService.php', '2026-10-26 11:46:15', '2026-10-26 11:46:18'),
(66, 'reports.view_sensitive', 'reports', 'view_sensitive', 'view_sensitive', 'View sensitive leave reports (medical, full history).', 'implemented', 1, 'app/Services/ReportAuthorizationService.php::canViewReports (sensitive gate) + app/Services/ReportQueryService.php', '2026-10-26 11:46:15', '2026-10-26 11:46:18'),
(67, 'admin.view', 'admin', 'view', 'view', 'View audit log entries.', 'implemented', 1, 'routes/api.php: GET api/roles, GET api/roles/permissions, GET api/audit, GET api/audit/verify (permission:admin.view)', '2026-10-26 11:46:15', '2026-10-26 11:46:18'),
(68, 'admin.create', 'admin', 'create', 'create', 'admin create', 'implemented', 1, 'routes/api.php: POST role, employee, zone and department writes', '2026-10-26 11:46:15', '2026-10-26 11:46:18'),
(69, 'admin.update', 'admin', 'update', 'update', 'admin update', 'implemented', 1, 'routes/api.php: PATCH role, employee, zone and department writes', '2026-10-26 11:46:15', '2026-10-26 11:46:18'),
(70, 'admin.delete', 'admin', 'delete', 'delete', 'admin delete', 'implemented', 1, 'routes/api.php: DELETE role, zone and department writes', '2026-10-26 11:46:15', '2026-10-26 11:46:18'),
(73, 'leave_plans.view_cross_zone', 'leave_plans', 'view_cross_zone', 'view_cross_zone', 'View staff and leave records across ALL zones/departments. Central offices only; regional roles stay zone-scoped.', 'implemented', 1, 'app/Services/AccessScopeService.php::canViewAllZones via CROSS_ZONE_ACTION (module leave_plans)', '2026-10-26 11:46:15', '2026-10-26 11:46:18'),
(74, 'leave_waivers.approve', 'leave_waivers', 'approve', 'approve', 'leave_waivers approve', 'implemented', 1, 'routes/api.php: POST api/leave-waivers/{waiver}/approve (permission:leave_waivers.approve) + app/Policies/LeaveWaiverPolicy.php::approve', '2026-10-26 11:46:15', '2026-10-26 11:46:18'),
(75, 'leave_waivers.view_cross_zone', 'leave_waivers', 'view_cross_zone', 'view_cross_zone', 'View staff and leave records across ALL zones/departments. Central offices only; regional roles stay zone-scoped.', 'implemented', 1, 'app/Services/AccessScopeService.php::canViewAllZones via CROSS_ZONE_ACTION (module leave_waivers) + app/Policies/LeaveWaiverPolicy.php::view', '2026-10-26 11:46:15', '2026-10-26 11:46:18'),
(76, 'leave_returns.view_cross_zone', 'leave_returns', 'view_cross_zone', 'view_cross_zone', 'View staff and leave records across ALL zones/departments. Central offices only; regional roles stay zone-scoped.', 'implemented', 1, 'app/Services/AccessScopeService.php::canViewAllZones via CROSS_ZONE_ACTION (module leave_returns) + app/Policies/LeaveReturnPolicy.php::view', '2026-10-26 11:46:16', '2026-10-26 11:46:18'),
(77, 'leave_payments.view_department', 'leave_payments', 'view_department', 'view_department', 'View leave reports for your department.', 'implemented', 1, 'app/Policies/LeavePaymentCasePolicy.php::view (can leave_payments view_department)', '2026-10-26 11:46:16', '2026-10-26 11:46:18'),
(78, 'leave_payments.view_zone', 'leave_payments', 'view_zone', 'view_zone', 'View leave reports for your zone.', 'implemented', 1, 'app/Policies/LeavePaymentCasePolicy.php::view (can leave_payments view_zone)', '2026-10-26 11:46:16', '2026-10-26 11:46:18'),
(79, 'leave_payments.view_cross_zone', 'leave_payments', 'view_cross_zone', 'view_cross_zone', 'View staff and leave records across ALL zones/departments. Central offices only; regional roles stay zone-scoped.', 'implemented', 1, 'app/Services/AccessScopeService.php::canViewAllZones via CROSS_ZONE_ACTION (module leave_payments) + app/Policies/LeavePaymentCasePolicy.php::view', '2026-10-26 11:46:16', '2026-10-26 11:46:18'),
(80, 'leave_payments.create', 'leave_payments', 'create', 'create', 'leave_payments create', 'implemented', 1, 'routes/api.php: POST api/leave-payments and POST api/leave-payments/{id}/evaluate, /submit (permission:leave_payments.create) + app/Http/Controllers/Api/LeavePaymentController.php::store', '2026-10-26 11:46:16', '2026-10-26 11:46:18'),
(81, 'leave_payments.review', 'leave_payments', 'review', 'review', 'leave_payments review', 'implemented', 1, 'routes/api.php: POST api/leave-payments/{id}/send-to-accounting (permission:leave_payments.review) + app/Policies/LeavePaymentCasePolicy.php::sendToAccounting', '2026-10-26 11:46:16', '2026-10-26 11:46:18'),
(82, 'leave_payments.approve', 'leave_payments', 'approve', 'approve', 'leave_payments approve', 'implemented', 1, 'routes/api.php: POST api/leave-payments/{id}/approve (permission:leave_payments.approve) + app/Policies/LeavePaymentCasePolicy.php::approve', '2026-10-26 11:46:16', '2026-10-26 11:46:18'),
(83, 'leave_payments.reject', 'leave_payments', 'reject', 'reject', 'leave_payments reject', 'implemented', 1, 'routes/api.php: POST api/leave-payments/{id}/reject (permission:leave_payments.reject) + app/Policies/LeavePaymentCasePolicy.php::reject', '2026-10-26 11:46:16', '2026-10-26 11:46:18'),
(84, 'leave_payments.cancel', 'leave_payments', 'cancel', 'cancel', 'Cancel pending report exports.', 'implemented', 1, 'routes/api.php: POST api/leave-payments/{id}/cancel (permission:leave_payments.cancel) + app/Policies/LeavePaymentCasePolicy.php::cancel', '2026-10-26 11:46:16', '2026-10-26 11:46:18'),
(85, 'leave_documents.download_hr', 'leave_documents', 'download_hr', 'download_hr', 'leave_documents download_hr', 'implemented', 1, 'app/Policies/OfficialLeaveLetterPolicy.php::downloadHr (can leave_documents download_hr) + app/Http/Controllers/Api/OfficialLetterController.php::temporaryUrl', '2026-10-26 11:46:16', '2026-10-26 11:46:18'),
(86, 'leave_documents.generate', 'leave_documents', 'generate', 'generate', 'leave_documents generate', 'implemented', 1, 'routes/api.php: POST api/leave-requests/{id}/letters/generate (permission:leave_documents.generate) + app/Http/Controllers/Api/OfficialLetterController.php::generateApproval', '2026-10-26 11:46:16', '2026-10-26 11:46:18'),
(87, 'leave_documents.revoke', 'leave_documents', 'revoke', 'revoke', 'leave_documents revoke', 'implemented', 1, 'routes/api.php: POST api/leave-requests/{id}/letters/{letter}/revoke (permission:leave_documents.revoke) + app/Policies/OfficialLeaveLetterPolicy.php::revoke', '2026-10-26 11:46:16', '2026-10-26 11:46:18'),
(88, 'notifications.view_sensitive', 'notifications', 'view_sensitive', 'view_sensitive', 'View sensitive leave reports (medical, full history).', 'implemented', 1, 'app/Services/LeaveNotificationService.php::message (can notifications view_sensitive gates leave type/reason detail)', '2026-10-26 11:46:16', '2026-10-26 11:46:18'),
(89, 'report_exports.download', 'report_exports', 'download', 'download', 'Download generated report exports.', 'implemented', 1, 'routes/api.php: GET api/reports/exports/{id}/download (permission:report_exports.download) + app/Http/Controllers/Api/ReportController.php::downloadExport', '2026-10-26 11:46:16', '2026-10-26 11:46:18'),
(90, 'report_exports.cancel', 'report_exports', 'cancel', 'cancel', 'Cancel pending report exports.', 'implemented', 1, 'routes/api.php: POST api/reports/exports/{id}/cancel (permission:report_exports.cancel) + app/Http/Controllers/Api/ReportController.php::cancelExport', '2026-10-26 11:46:16', '2026-10-26 11:46:18'),
(104, 'leave_payments.receive', 'leave_payments', 'receive', 'receive', 'leave_payments receive', 'implemented', 1, 'routes/api.php: POST api/leave-payments/{id}/receive (permission:leave_payments.receive) + app/Policies/LeavePaymentCasePolicy.php::receive', '2026-10-26 11:46:17', '2026-10-26 11:46:18'),
(105, 'leave_payments.process', 'leave_payments', 'process', 'process', 'leave_payments process', 'implemented', 1, 'routes/api.php: POST api/leave-payments/{id}/paid and /reject-payment (permission:leave_payments.process) + app/Policies/LeavePaymentCasePolicy.php::process', '2026-10-26 11:46:17', '2026-10-26 11:46:18'),
(106, 'audit_logs.view', 'audit_logs', 'view', 'view', 'View audit log entries.', 'implemented', 1, 'routes/api.php: GET api/reports/audit (permission:audit_logs.view) + app/Http/Controllers/Api/ReportController.php::auditSearch + app/Services/AuditReportService.php', '2026-10-26 11:46:17', '2026-10-26 11:46:18'),
(108, 'approval_workflows.view', 'approval_workflows', 'view', 'Approval Workflows View', 'View approval workflow definitions.', 'implemented', 1, NULL, '2026-10-28 11:24:04', '2026-10-28 12:51:53'),
(109, 'workflows.view', 'workflows', 'view', 'Workflows View', NULL, 'active', 1, NULL, '2026-10-28 11:24:04', '2026-10-28 11:24:04'),
(110, 'staff.view_own', 'staff', 'view_own', 'Staff Own View', 'View the staff directory.', 'implemented', 1, NULL, '2026-10-28 11:24:04', '2026-10-28 12:51:53'),
(111, 'staff.view_all', 'staff', 'view_all', 'Staff View All', NULL, 'active', 1, NULL, '2026-10-28 11:24:04', '2026-10-28 11:24:04'),
(112, 'employees.view', 'employees', 'view', 'Employees View', NULL, 'active', 1, NULL, '2026-10-28 11:24:04', '2026-10-28 11:24:04'),
(113, 'handovers.view', 'handovers', 'view', 'Handovers View', NULL, 'active', 1, NULL, '2026-10-28 11:24:04', '2026-10-28 11:24:04'),
(114, 'departments.view', 'departments', 'view', 'Departments View', NULL, 'active', 1, NULL, '2026-10-28 11:24:04', '2026-10-28 11:24:04'),
(115, 'positions.view', 'positions', 'view', 'Positions View', NULL, 'active', 1, NULL, '2026-10-28 11:24:04', '2026-10-28 11:24:04'),
(116, 'divisions.view', 'divisions', 'view', 'Divisions View', NULL, 'active', 1, NULL, '2026-10-28 11:24:04', '2026-10-28 11:24:04'),
(117, 'units.view', 'units', 'view', 'Units View', NULL, 'active', 1, NULL, '2026-10-28 11:24:04', '2026-10-28 11:24:04'),
(118, 'divisions.create', 'divisions', 'create', 'Divisions Create', NULL, 'active', 1, NULL, '2026-10-28 11:24:04', '2026-10-28 11:24:04'),
(119, 'units.create', 'units', 'create', 'Units Create', NULL, 'active', 1, NULL, '2026-10-28 11:24:04', '2026-10-28 11:24:04'),
(120, 'divisions.update', 'divisions', 'update', 'Divisions Update', NULL, 'active', 1, NULL, '2026-10-28 11:24:04', '2026-10-28 11:24:04'),
(121, 'units.update', 'units', 'update', 'Units Update', NULL, 'active', 1, NULL, '2026-10-28 11:24:04', '2026-10-28 11:24:04'),
(122, 'approval_workflows.create', 'approval_workflows', 'create', 'create', 'Create approval workflow definitions.', 'implemented', 1, NULL, '2026-10-28 12:51:53', '2026-10-28 12:51:53'),
(123, 'approval_workflows.update', 'approval_workflows', 'update', 'update', 'Edit approval workflow definitions.', 'implemented', 1, NULL, '2026-10-28 12:51:53', '2026-10-28 12:51:53'),
(124, 'approval_workflows.delete', 'approval_workflows', 'delete', 'delete', 'Delete approval workflow definitions (reserved).', 'planned', 0, NULL, '2026-10-28 12:51:53', '2026-10-28 12:51:53'),
(125, 'approval_workflows.activate', 'approval_workflows', 'activate', 'activate', 'Activate a workflow version (reserved).', 'planned', 0, NULL, '2026-10-28 12:51:53', '2026-10-28 12:51:53'),
(126, 'approval_workflows.archive', 'approval_workflows', 'archive', 'archive', 'Archive a workflow version (reserved).', 'planned', 0, NULL, '2026-10-28 12:51:53', '2026-10-28 12:51:53'),
(127, 'approval_workflows.test', 'approval_workflows', 'test', 'test', 'Dry-run a workflow definition (reserved).', 'planned', 0, NULL, '2026-10-28 12:51:53', '2026-10-28 12:51:53'),
(128, 'case_handovers.view', 'case_handovers', 'view', 'view', 'View dockets and handovers you may see.', 'implemented', 1, NULL, '2026-10-28 12:51:53', '2026-10-28 12:51:53'),
(129, 'case_handovers.create', 'case_handovers', 'create', 'create', 'Propose a handover of a docket.', 'implemented', 1, NULL, '2026-10-28 12:51:53', '2026-10-28 12:51:53'),
(130, 'case_handovers.accept', 'case_handovers', 'accept', 'accept', 'Accept a handover proposed to you.', 'implemented', 1, NULL, '2026-10-28 12:51:53', '2026-10-28 12:51:53'),
(131, 'case_handovers.reject', 'case_handovers', 'reject', 'reject', 'Reject a handover proposed to you.', 'implemented', 1, NULL, '2026-10-28 12:51:53', '2026-10-28 12:51:53'),
(132, 'case_handovers.complete', 'case_handovers', 'complete', 'complete', 'Sign off handover completion.', 'implemented', 1, NULL, '2026-10-28 12:51:53', '2026-10-28 12:51:53'),
(133, 'case_handovers.cancel', 'case_handovers', 'cancel', 'cancel', 'Cancel a proposed handover (reserved).', 'planned', 0, NULL, '2026-10-28 12:51:53', '2026-10-28 12:51:53'),
(134, 'requests.view_own', 'requests', 'view_own', 'view_own', 'View your own leave requests.', 'implemented', 1, NULL, '2026-10-28 12:51:53', '2026-10-28 12:51:53'),
(135, 'requests.edit_own', 'requests', 'edit_own', 'edit_own', 'Edit your own draft requests.', 'implemented', 1, NULL, '2026-10-28 12:51:53', '2026-10-28 12:51:53'),
(136, 'requests.submit', 'requests', 'submit', 'submit', 'Submit and resubmit your own requests.', 'implemented', 1, NULL, '2026-10-28 12:51:53', '2026-10-28 12:51:53'),
(137, 'requests.withdraw', 'requests', 'withdraw', 'withdraw', 'Withdraw your own requests.', 'implemented', 1, NULL, '2026-10-28 12:51:53', '2026-10-28 12:51:53'),
(138, 'requests.reject', 'requests', 'reject', 'reject', 'Reject a step assigned to you.', 'implemented', 1, NULL, '2026-10-28 12:51:53', '2026-10-28 12:51:53'),
(139, 'requests.return', 'requests', 'return', 'return', 'Return a step assigned to you for correction.', 'implemented', 1, NULL, '2026-10-28 12:51:53', '2026-10-28 12:51:53'),
(140, 'requests.view_department', 'requests', 'view_department', 'view_department', 'Department-scoped request visibility (reserved).', 'planned', 0, NULL, '2026-10-28 12:51:53', '2026-10-28 12:51:53'),
(141, 'requests.view_zone', 'requests', 'view_zone', 'view_zone', 'Zone-scoped request visibility (reserved).', 'planned', 0, NULL, '2026-10-28 12:51:53', '2026-10-28 12:51:53'),
(142, 'requests.cancel', 'requests', 'cancel', 'cancel', 'Cancel a request (reserved).', 'planned', 0, NULL, '2026-10-28 12:51:53', '2026-10-28 12:51:53'),
(143, 'policies.update', 'policies', 'update', 'update', 'Edit a policy in place (reserved; authoring is versioned).', 'planned', 0, NULL, '2026-10-28 12:51:53', '2026-10-28 12:51:53'),
(144, 'policies.archive', 'policies', 'archive', 'archive', 'Archive a policy version (reserved).', 'planned', 0, NULL, '2026-10-28 12:51:53', '2026-10-28 12:51:53'),
(145, 'policies.publish', 'policies', 'publish', 'publish', 'Publish a policy version (reserved).', 'planned', 0, NULL, '2026-10-28 12:51:53', '2026-10-28 12:51:53'),
(146, 'staff.view_department', 'staff', 'view_department', 'view_department', 'Department-scoped staff visibility (reserved).', 'planned', 0, NULL, '2026-10-28 12:51:53', '2026-10-28 12:51:53'),
(147, 'staff.view_zone', 'staff', 'view_zone', 'view_zone', 'Zone-scoped staff visibility (reserved).', 'planned', 0, NULL, '2026-10-28 12:51:53', '2026-10-28 12:51:53');

-- --------------------------------------------------------

--
-- Table structure for table `personal_access_tokens`
--

CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tokenable_type` varchar(191) NOT NULL,
  `tokenable_id` varchar(191) NOT NULL,
  `name` text NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `personal_access_tokens`
--

INSERT INTO `personal_access_tokens` (`id`, `tokenable_type`, `tokenable_id`, `name`, `token`, `abilities`, `last_used_at`, `expires_at`, `created_at`, `updated_at`) VALUES
(13, 'App\\Models\\User', 'u3', 'api', '7d0fe193d36c72ffc4e95efe307be45701e236410bf8909cbeb45d61384811f8', '[\"*\"]', '2026-10-28 12:59:43', NULL, '2026-10-28 12:59:30', '2026-10-28 12:59:43'),
(14, 'App\\Models\\User', 'u1', 'api', '25a0ddd35c25147ffeb10ad938952366c4a13b9487e50b5f8bc9954df9f6f87f', '[\"*\"]', '2026-10-28 17:05:17', NULL, '2026-10-28 16:56:03', '2026-10-28 17:05:17');

-- --------------------------------------------------------

--
-- Table structure for table `positions`
--

CREATE TABLE `positions` (
  `id` varchar(191) NOT NULL,
  `title` varchar(191) NOT NULL,
  `department_id` varchar(191) NOT NULL,
  `zone_id` varchar(191) DEFAULT NULL,
  `tier` tinyint(3) UNSIGNED DEFAULT NULL,
  `is_final_authority` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `positions`
--

INSERT INTO `positions` (`id`, `title`, `department_id`, `zone_id`, `tier`, `is_final_authority`, `created_at`, `updated_at`) VALUES
('p1', 'Director of Public Prosecutions', 'd2', NULL, 5, 1, '2026-10-26 11:46:08', '2026-10-26 11:46:08'),
('p10', 'ICT Officer', 'd6', NULL, 1, 0, '2026-10-26 11:46:08', '2026-10-26 11:46:08'),
('p11', 'Records Officer', 'd7', NULL, 1, 0, '2026-10-26 11:46:08', '2026-10-26 11:46:08'),
('p12', 'Executive Assistant', 'd1', NULL, 1, 0, '2026-10-26 11:46:08', '2026-10-26 11:46:08'),
('p2', 'Deputy Director of Public Prosecutions', 'd2', NULL, 4, 0, '2026-10-26 11:46:08', '2026-10-26 11:46:08'),
('p3', 'Director, Administration & Human Resources', 'd1', NULL, 4, 0, '2026-10-26 11:46:08', '2026-10-26 11:46:08'),
('p4', 'Zonal / Regional Head', 'd4', NULL, 4, 0, '2026-10-26 11:46:08', '2026-10-26 11:46:08'),
('p5', 'Head of Prosecutions', 'd2', NULL, 3, 0, '2026-10-26 11:46:08', '2026-10-26 11:46:08'),
('p6', 'Senior State Attorney', 'd2', NULL, 2, 0, '2026-10-26 11:46:08', '2026-10-26 11:46:08'),
('p7', 'State Attorney I', 'd2', NULL, 1, 0, '2026-10-26 11:46:08', '2026-10-26 11:46:08'),
('p8', 'State Attorney II', 'd2', NULL, 1, 0, '2026-10-26 11:46:08', '2026-10-26 11:46:08'),
('p9', 'Human Resources Officer', 'd1', NULL, 1, 0, '2026-10-26 11:46:08', '2026-10-26 11:46:08');

-- --------------------------------------------------------

--
-- Table structure for table `position_default_roles`
--

CREATE TABLE `position_default_roles` (
  `position_id` varchar(191) NOT NULL,
  `role_id` varchar(191) NOT NULL,
  PRIMARY KEY (`position_id`,`role_id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `position_default_roles`
--

INSERT INTO `position_default_roles` (`position_id`, `role_id`) VALUES
('p1', 'FINAL_AUTHORITY'),
('p3', 'HR'),
('p5', 'SUPERVISOR'),
('p8', 'SUPERVISOR');

-- --------------------------------------------------------

--
-- Table structure for table `reporting_lines`
--

CREATE TABLE `reporting_lines` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `employee_id` varchar(191) NOT NULL,
  `supervisor_employee_id` varchar(191) NOT NULL,
  `relationship_type` varchar(191) NOT NULL DEFAULT 'primary',
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `reporting_lines`
--

INSERT INTO `reporting_lines` (`id`, `employee_id`, `supervisor_employee_id`, `relationship_type`, `effective_from`, `effective_to`, `created_at`, `updated_at`) VALUES
(1, 'e7', 'e6', 'primary', '2020-01-01', NULL, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(2, 'e6', 'e2', 'primary', '2020-01-01', NULL, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(3, 'e8', 'e4', 'primary', '2020-01-01', NULL, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(4, 'e9', 'e5', 'primary', '2020-01-01', NULL, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(5, 'e2', 'e1', 'primary', '2020-01-01', NULL, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(6, 'e3', 'e1', 'primary', '2020-01-01', NULL, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(7, 'e4', 'e1', 'primary', '2020-01-01', NULL, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(8, 'e5', 'e1', 'primary', '2020-01-01', NULL, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(9, 'e10', 'e3', 'primary', '2020-01-01', NULL, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(10, 'e11', 'e3', 'primary', '2020-01-01', NULL, '2026-10-26 11:46:18', '2026-10-26 11:46:18'),
(11, 'e12', 'e3', 'primary', '2020-01-01', NULL, '2026-10-26 11:46:18', '2026-10-26 11:46:18');

-- --------------------------------------------------------

--
-- Table structure for table `report_exports`
--

CREATE TABLE `report_exports` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `report_type` varchar(191) NOT NULL,
  `requested_by` varchar(191) NOT NULL,
  `filters_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`filters_json`)),
  `scope_snapshot_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`scope_snapshot_json`)),
  `format` enum('csv','pdf') NOT NULL,
  `status` enum('queued','processing','completed','failed','expired','cancelled') NOT NULL DEFAULT 'queued',
  `file_path` varchar(191) DEFAULT NULL,
  `file_disk` varchar(20) NOT NULL DEFAULT 'local',
  `file_hash` varchar(64) DEFAULT NULL,
  `row_count` int(10) UNSIGNED DEFAULT NULL,
  `column_count` int(10) UNSIGNED DEFAULT NULL,
  `generated_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `failure_reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` varchar(191) NOT NULL,
  `name` varchar(191) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `approval_tier` tinyint(3) UNSIGNED DEFAULT NULL,
  `is_final_authority` tinyint(1) NOT NULL DEFAULT 0,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `name`, `description`, `approval_tier`, `is_final_authority`, `is_system`, `active`, `created_at`, `updated_at`) VALUES
('ACCOUNTING', 'Finance / Accounting', 'Finance office. Receives approved payment cases and settles them. Holds leave_payments.view_cross_zone, receive and process. Never approves or rejects.', 4, 0, 1, 1, '2026-10-26 11:46:13', '2026-10-26 11:46:13'),
('BREAK_GLASS', 'Break-Glass Authority', 'Emergency recovery access, identical in reach to the Final Authority. Every grant is capped at 30 days so it cannot quietly become a standing privilege.', 5, 0, 1, 0, '2026-10-26 11:46:13', '2026-10-28 08:53:55'),
('DEPT_HEAD', 'Department Head', 'Heads one department. Scope comes from the department grant; can approve, return and reject without any zone reach.', 2, 0, 1, 1, '2026-10-26 11:46:13', '2026-10-26 11:46:13'),
('FINAL_AUTHORITY', 'Final Authority — DPP', 'Final sign-off. Holds every catalogued permission explicitly, so the RBAC matrix shows exactly what the role can do. Organisation-wide reach comes from its four view_cross_zone codes.', 5, 1, 1, 1, '2026-10-26 11:46:13', '2026-10-26 11:46:13'),
('HR', 'Human Resources', 'Central HR. Cross-zone read for requests, staff and reports; administers staff, roles and leave policy.', 4, 0, 1, 1, '2026-10-26 11:46:13', '2026-10-26 11:46:13'),
('SELF_SERVICE', 'Self-Service', 'Baseline for every active employee: read and file their own leave, read leave policy, see their own documents. No approval rights, no cross-zone reach.', 0, 0, 1, 0, '2026-10-26 11:46:13', '2026-10-28 08:53:42'),
('SUPERVISOR', 'Immediate Supervisor', 'Line manager for day-to-day leave approval within their own reporting line.', 1, 0, 1, 0, '2026-10-26 11:46:13', '2026-10-28 08:54:30'),
('ZONAL_HEAD', 'Zone / Regional Head', 'Heads one zone. Scope comes from the zone grant; can approve within that zone only.', 3, 0, 1, 1, '2026-10-26 11:46:13', '2026-10-26 11:46:13');

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `role_id` varchar(191) NOT NULL,
  `permission_id` bigint(20) UNSIGNED NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `role_permissions`
--

INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
('ACCOUNTING', 79),
('ACCOUNTING', 104),
('ACCOUNTING', 105),
('ACCOUNTING', 108),
('ACCOUNTING', 109),
('ACCOUNTING', 110),
('ACCOUNTING', 111),
('ACCOUNTING', 112),
('ACCOUNTING', 113),
('ACCOUNTING', 114),
('ACCOUNTING', 115),
('ACCOUNTING', 116),
('ACCOUNTING', 117),
('ACCOUNTING', 118),
('ACCOUNTING', 119),
('ACCOUNTING', 120),
('ACCOUNTING', 121),
('BREAK_GLASS', 1),
('BREAK_GLASS', 2),
('BREAK_GLASS', 7),
('BREAK_GLASS', 8),
('BREAK_GLASS', 16),
('BREAK_GLASS', 17),
('BREAK_GLASS', 18),
('BREAK_GLASS', 19),
('BREAK_GLASS', 20),
('BREAK_GLASS', 21),
('BREAK_GLASS', 22),
('BREAK_GLASS', 23),
('BREAK_GLASS', 24),
('BREAK_GLASS', 25),
('BREAK_GLASS', 26),
('BREAK_GLASS', 27),
('BREAK_GLASS', 28),
('BREAK_GLASS', 29),
('BREAK_GLASS', 32),
('BREAK_GLASS', 33),
('BREAK_GLASS', 34),
('BREAK_GLASS', 35),
('BREAK_GLASS', 36),
('BREAK_GLASS', 37),
('BREAK_GLASS', 38),
('BREAK_GLASS', 39),
('BREAK_GLASS', 40),
('BREAK_GLASS', 41),
('BREAK_GLASS', 42),
('BREAK_GLASS', 43),
('BREAK_GLASS', 44),
('BREAK_GLASS', 45),
('BREAK_GLASS', 46),
('BREAK_GLASS', 47),
('BREAK_GLASS', 48),
('BREAK_GLASS', 49),
('BREAK_GLASS', 50),
('BREAK_GLASS', 51),
('BREAK_GLASS', 52),
('BREAK_GLASS', 53),
('BREAK_GLASS', 54),
('BREAK_GLASS', 55),
('BREAK_GLASS', 56),
('BREAK_GLASS', 57),
('BREAK_GLASS', 58),
('BREAK_GLASS', 59),
('BREAK_GLASS', 60),
('BREAK_GLASS', 61),
('BREAK_GLASS', 62),
('BREAK_GLASS', 63),
('BREAK_GLASS', 64),
('BREAK_GLASS', 65),
('BREAK_GLASS', 66),
('BREAK_GLASS', 67),
('BREAK_GLASS', 68),
('BREAK_GLASS', 69),
('BREAK_GLASS', 70),
('BREAK_GLASS', 73),
('BREAK_GLASS', 74),
('BREAK_GLASS', 75),
('BREAK_GLASS', 76),
('BREAK_GLASS', 77),
('BREAK_GLASS', 78),
('BREAK_GLASS', 79),
('BREAK_GLASS', 80),
('BREAK_GLASS', 81),
('BREAK_GLASS', 82),
('BREAK_GLASS', 83),
('BREAK_GLASS', 84),
('BREAK_GLASS', 85),
('BREAK_GLASS', 86),
('BREAK_GLASS', 87),
('BREAK_GLASS', 88),
('BREAK_GLASS', 89),
('BREAK_GLASS', 90),
('BREAK_GLASS', 104),
('BREAK_GLASS', 105),
('BREAK_GLASS', 106),
('BREAK_GLASS', 108),
('BREAK_GLASS', 109),
('BREAK_GLASS', 110),
('BREAK_GLASS', 111),
('BREAK_GLASS', 112),
('BREAK_GLASS', 113),
('BREAK_GLASS', 114),
('BREAK_GLASS', 115),
('BREAK_GLASS', 116),
('BREAK_GLASS', 117),
('BREAK_GLASS', 118),
('BREAK_GLASS', 119),
('BREAK_GLASS', 120),
('BREAK_GLASS', 121),
('BREAK_GLASS', 122),
('BREAK_GLASS', 123),
('BREAK_GLASS', 124),
('BREAK_GLASS', 125),
('BREAK_GLASS', 126),
('BREAK_GLASS', 127),
('BREAK_GLASS', 128),
('BREAK_GLASS', 129),
('BREAK_GLASS', 130),
('BREAK_GLASS', 131),
('BREAK_GLASS', 132),
('BREAK_GLASS', 133),
('BREAK_GLASS', 134),
('BREAK_GLASS', 135),
('BREAK_GLASS', 136),
('BREAK_GLASS', 137),
('BREAK_GLASS', 138),
('BREAK_GLASS', 139),
('BREAK_GLASS', 140),
('BREAK_GLASS', 141),
('BREAK_GLASS', 142),
('BREAK_GLASS', 143),
('BREAK_GLASS', 144),
('BREAK_GLASS', 145),
('BREAK_GLASS', 146),
('BREAK_GLASS', 147),
('DEPT_HEAD', 1),
('DEPT_HEAD', 24),
('DEPT_HEAD', 25),
('DEPT_HEAD', 29),
('DEPT_HEAD', 32),
('DEPT_HEAD', 33),
('DEPT_HEAD', 34),
('DEPT_HEAD', 35),
('DEPT_HEAD', 36),
('DEPT_HEAD', 37),
('DEPT_HEAD', 38),
('DEPT_HEAD', 39),
('DEPT_HEAD', 40),
('DEPT_HEAD', 41),
('DEPT_HEAD', 42),
('DEPT_HEAD', 43),
('DEPT_HEAD', 44),
('DEPT_HEAD', 45),
('DEPT_HEAD', 46),
('DEPT_HEAD', 108),
('DEPT_HEAD', 109),
('DEPT_HEAD', 110),
('DEPT_HEAD', 111),
('DEPT_HEAD', 112),
('DEPT_HEAD', 113),
('DEPT_HEAD', 114),
('DEPT_HEAD', 115),
('DEPT_HEAD', 116),
('DEPT_HEAD', 117),
('DEPT_HEAD', 118),
('DEPT_HEAD', 119),
('DEPT_HEAD', 120),
('DEPT_HEAD', 121),
('DEPT_HEAD', 128),
('DEPT_HEAD', 129),
('DEPT_HEAD', 130),
('DEPT_HEAD', 131),
('DEPT_HEAD', 132),
('DEPT_HEAD', 134),
('DEPT_HEAD', 135),
('DEPT_HEAD', 136),
('DEPT_HEAD', 137),
('DEPT_HEAD', 138),
('DEPT_HEAD', 139),
('FINAL_AUTHORITY', 1),
('FINAL_AUTHORITY', 2),
('FINAL_AUTHORITY', 7),
('FINAL_AUTHORITY', 8),
('FINAL_AUTHORITY', 16),
('FINAL_AUTHORITY', 17),
('FINAL_AUTHORITY', 18),
('FINAL_AUTHORITY', 19),
('FINAL_AUTHORITY', 20),
('FINAL_AUTHORITY', 21),
('FINAL_AUTHORITY', 22),
('FINAL_AUTHORITY', 23),
('FINAL_AUTHORITY', 24),
('FINAL_AUTHORITY', 25),
('FINAL_AUTHORITY', 26),
('FINAL_AUTHORITY', 27),
('FINAL_AUTHORITY', 28),
('FINAL_AUTHORITY', 29),
('FINAL_AUTHORITY', 32),
('FINAL_AUTHORITY', 33),
('FINAL_AUTHORITY', 34),
('FINAL_AUTHORITY', 35),
('FINAL_AUTHORITY', 36),
('FINAL_AUTHORITY', 37),
('FINAL_AUTHORITY', 38),
('FINAL_AUTHORITY', 39),
('FINAL_AUTHORITY', 40),
('FINAL_AUTHORITY', 41),
('FINAL_AUTHORITY', 42),
('FINAL_AUTHORITY', 43),
('FINAL_AUTHORITY', 44),
('FINAL_AUTHORITY', 45),
('FINAL_AUTHORITY', 46),
('FINAL_AUTHORITY', 47),
('FINAL_AUTHORITY', 48),
('FINAL_AUTHORITY', 49),
('FINAL_AUTHORITY', 50),
('FINAL_AUTHORITY', 51),
('FINAL_AUTHORITY', 52),
('FINAL_AUTHORITY', 53),
('FINAL_AUTHORITY', 54),
('FINAL_AUTHORITY', 55),
('FINAL_AUTHORITY', 56),
('FINAL_AUTHORITY', 57),
('FINAL_AUTHORITY', 58),
('FINAL_AUTHORITY', 59),
('FINAL_AUTHORITY', 60),
('FINAL_AUTHORITY', 61),
('FINAL_AUTHORITY', 62),
('FINAL_AUTHORITY', 63),
('FINAL_AUTHORITY', 64),
('FINAL_AUTHORITY', 65),
('FINAL_AUTHORITY', 66),
('FINAL_AUTHORITY', 67),
('FINAL_AUTHORITY', 68),
('FINAL_AUTHORITY', 69),
('FINAL_AUTHORITY', 70),
('FINAL_AUTHORITY', 73),
('FINAL_AUTHORITY', 74),
('FINAL_AUTHORITY', 75),
('FINAL_AUTHORITY', 76),
('FINAL_AUTHORITY', 77),
('FINAL_AUTHORITY', 78),
('FINAL_AUTHORITY', 79),
('FINAL_AUTHORITY', 80),
('FINAL_AUTHORITY', 81),
('FINAL_AUTHORITY', 82),
('FINAL_AUTHORITY', 83),
('FINAL_AUTHORITY', 84),
('FINAL_AUTHORITY', 85),
('FINAL_AUTHORITY', 86),
('FINAL_AUTHORITY', 87),
('FINAL_AUTHORITY', 88),
('FINAL_AUTHORITY', 89),
('FINAL_AUTHORITY', 90),
('FINAL_AUTHORITY', 104),
('FINAL_AUTHORITY', 105),
('FINAL_AUTHORITY', 106),
('FINAL_AUTHORITY', 108),
('FINAL_AUTHORITY', 109),
('FINAL_AUTHORITY', 110),
('FINAL_AUTHORITY', 111),
('FINAL_AUTHORITY', 112),
('FINAL_AUTHORITY', 113),
('FINAL_AUTHORITY', 114),
('FINAL_AUTHORITY', 115),
('FINAL_AUTHORITY', 116),
('FINAL_AUTHORITY', 117),
('FINAL_AUTHORITY', 118),
('FINAL_AUTHORITY', 119),
('FINAL_AUTHORITY', 120),
('FINAL_AUTHORITY', 121),
('FINAL_AUTHORITY', 122),
('FINAL_AUTHORITY', 123),
('FINAL_AUTHORITY', 124),
('FINAL_AUTHORITY', 125),
('FINAL_AUTHORITY', 126),
('FINAL_AUTHORITY', 127),
('FINAL_AUTHORITY', 128),
('FINAL_AUTHORITY', 129),
('FINAL_AUTHORITY', 130),
('FINAL_AUTHORITY', 131),
('FINAL_AUTHORITY', 132),
('FINAL_AUTHORITY', 133),
('FINAL_AUTHORITY', 134),
('FINAL_AUTHORITY', 135),
('FINAL_AUTHORITY', 136),
('FINAL_AUTHORITY', 137),
('FINAL_AUTHORITY', 138),
('FINAL_AUTHORITY', 139),
('FINAL_AUTHORITY', 140),
('FINAL_AUTHORITY', 141),
('FINAL_AUTHORITY', 142),
('FINAL_AUTHORITY', 143),
('FINAL_AUTHORITY', 144),
('FINAL_AUTHORITY', 145),
('FINAL_AUTHORITY', 146),
('FINAL_AUTHORITY', 147),
('HR', 1),
('HR', 2),
('HR', 7),
('HR', 8),
('HR', 16),
('HR', 17),
('HR', 18),
('HR', 19),
('HR', 20),
('HR', 21),
('HR', 22),
('HR', 23),
('HR', 24),
('HR', 25),
('HR', 26),
('HR', 27),
('HR', 28),
('HR', 29),
('HR', 32),
('HR', 33),
('HR', 34),
('HR', 35),
('HR', 36),
('HR', 37),
('HR', 38),
('HR', 39),
('HR', 40),
('HR', 41),
('HR', 42),
('HR', 43),
('HR', 44),
('HR', 45),
('HR', 46),
('HR', 47),
('HR', 48),
('HR', 49),
('HR', 50),
('HR', 51),
('HR', 52),
('HR', 53),
('HR', 54),
('HR', 55),
('HR', 56),
('HR', 57),
('HR', 58),
('HR', 59),
('HR', 60),
('HR', 61),
('HR', 62),
('HR', 63),
('HR', 64),
('HR', 65),
('HR', 66),
('HR', 67),
('HR', 68),
('HR', 69),
('HR', 70),
('HR', 73),
('HR', 74),
('HR', 75),
('HR', 76),
('HR', 77),
('HR', 78),
('HR', 79),
('HR', 80),
('HR', 81),
('HR', 82),
('HR', 83),
('HR', 84),
('HR', 85),
('HR', 86),
('HR', 87),
('HR', 88),
('HR', 89),
('HR', 90),
('HR', 106),
('HR', 108),
('HR', 109),
('HR', 110),
('HR', 111),
('HR', 112),
('HR', 113),
('HR', 114),
('HR', 115),
('HR', 116),
('HR', 117),
('HR', 118),
('HR', 119),
('HR', 120),
('HR', 121),
('HR', 122),
('HR', 123),
('HR', 128),
('HR', 129),
('HR', 130),
('HR', 131),
('HR', 132),
('HR', 134),
('HR', 135),
('HR', 136),
('HR', 137),
('HR', 138),
('HR', 139),
('SELF_SERVICE', 1),
('SELF_SERVICE', 2),
('SELF_SERVICE', 7),
('SELF_SERVICE', 8),
('SELF_SERVICE', 16),
('SELF_SERVICE', 17),
('SELF_SERVICE', 18),
('SELF_SERVICE', 19),
('SELF_SERVICE', 20),
('SELF_SERVICE', 21),
('SELF_SERVICE', 22),
('SELF_SERVICE', 23),
('SELF_SERVICE', 24),
('SELF_SERVICE', 25),
('SELF_SERVICE', 26),
('SELF_SERVICE', 27),
('SELF_SERVICE', 28),
('SELF_SERVICE', 108),
('SELF_SERVICE', 110),
('SELF_SERVICE', 128),
('SELF_SERVICE', 129),
('SELF_SERVICE', 130),
('SELF_SERVICE', 131),
('SELF_SERVICE', 132),
('SELF_SERVICE', 134),
('SELF_SERVICE', 135),
('SELF_SERVICE', 136),
('SELF_SERVICE', 137),
('SUPERVISOR', 1),
('SUPERVISOR', 2),
('SUPERVISOR', 7),
('SUPERVISOR', 8),
('SUPERVISOR', 24),
('SUPERVISOR', 25),
('SUPERVISOR', 29),
('SUPERVISOR', 32),
('SUPERVISOR', 33),
('SUPERVISOR', 34),
('SUPERVISOR', 35),
('SUPERVISOR', 108),
('SUPERVISOR', 109),
('SUPERVISOR', 110),
('SUPERVISOR', 111),
('SUPERVISOR', 112),
('SUPERVISOR', 113),
('SUPERVISOR', 114),
('SUPERVISOR', 115),
('SUPERVISOR', 116),
('SUPERVISOR', 117),
('SUPERVISOR', 118),
('SUPERVISOR', 119),
('SUPERVISOR', 120),
('SUPERVISOR', 121),
('SUPERVISOR', 128),
('SUPERVISOR', 129),
('SUPERVISOR', 130),
('SUPERVISOR', 131),
('SUPERVISOR', 132),
('SUPERVISOR', 134),
('SUPERVISOR', 135),
('SUPERVISOR', 136),
('SUPERVISOR', 137),
('SUPERVISOR', 138),
('SUPERVISOR', 139),
('ZONAL_HEAD', 1),
('ZONAL_HEAD', 24),
('ZONAL_HEAD', 25),
('ZONAL_HEAD', 29),
('ZONAL_HEAD', 32),
('ZONAL_HEAD', 33),
('ZONAL_HEAD', 34),
('ZONAL_HEAD', 35),
('ZONAL_HEAD', 40),
('ZONAL_HEAD', 41),
('ZONAL_HEAD', 43),
('ZONAL_HEAD', 44),
('ZONAL_HEAD', 45),
('ZONAL_HEAD', 46),
('ZONAL_HEAD', 47),
('ZONAL_HEAD', 48),
('ZONAL_HEAD', 49),
('ZONAL_HEAD', 50),
('ZONAL_HEAD', 51),
('ZONAL_HEAD', 52),
('ZONAL_HEAD', 108),
('ZONAL_HEAD', 109),
('ZONAL_HEAD', 110),
('ZONAL_HEAD', 111),
('ZONAL_HEAD', 112),
('ZONAL_HEAD', 113),
('ZONAL_HEAD', 114),
('ZONAL_HEAD', 115),
('ZONAL_HEAD', 116),
('ZONAL_HEAD', 117),
('ZONAL_HEAD', 118),
('ZONAL_HEAD', 119),
('ZONAL_HEAD', 120),
('ZONAL_HEAD', 121),
('ZONAL_HEAD', 128),
('ZONAL_HEAD', 129),
('ZONAL_HEAD', 130),
('ZONAL_HEAD', 131),
('ZONAL_HEAD', 132),
('ZONAL_HEAD', 134),
('ZONAL_HEAD', 135),
('ZONAL_HEAD', 136),
('ZONAL_HEAD', 137),
('ZONAL_HEAD', 138),
('ZONAL_HEAD', 139);

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `id` varchar(191) NOT NULL,
  `user_id` varchar(191) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sessions`
--

INSERT INTO `sessions` (`id`, `user_id`, `ip_address`, `user_agent`, `payload`, `last_activity`) VALUES
('0NN0R3g4Cnobaw0hhERK6gmGv0UqM7pb8152mUmK', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT; Windows NT 10.0; en-US) WindowsPowerShell/5.1.19041.6456', 'YTozOntzOjY6Il90b2tlbiI7czo0MDoiaUNEZnhnT01rZ011ZVg5UUh5aTFraEFJMFQ5c0lXWFlDNmxCeEtnTCI7czo5OiJfcHJldmlvdXMiO2E6Mjp7czozOiJ1cmwiO3M6MjE6Imh0dHA6Ly8xMjcuMC4wLjE6ODAwMCI7czo1OiJyb3V0ZSI7Tjt9czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319fQ==', 1792041001),
('E8n8u3VGhDvPhC3fImpwDHkxITe00qrGms2F2rop', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT; Windows NT 10.0; en-US) WindowsPowerShell/5.1.19041.6456', 'YTozOntzOjY6Il90b2tlbiI7czo0MDoidXFJbFhHMmtqVTVqTndtVDY5VUZMbjJMWE1WSWRpQ1l1eWRzV1NnTCI7czo5OiJfcHJldmlvdXMiO2E6Mjp7czozOiJ1cmwiO3M6MjE6Imh0dHA6Ly8xMjcuMC4wLjE6ODAwMCI7czo1OiJyb3V0ZSI7Tjt9czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319fQ==', 1793111177),
('uPN1dHvfNIhta5ZTKNny0x6pe2SnmJPwLFu5B5fX', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT; Windows NT 10.0; en-US) WindowsPowerShell/5.1.19041.6456', 'YTozOntzOjY6Il90b2tlbiI7czo0MDoid0drZG04RTV3bVBHQ3pDTVU4Y2xQSmlJWVhYU1ZWMlJGdlgzQURWUyI7czo5OiJfcHJldmlvdXMiO2E6Mjp7czozOiJ1cmwiO3M6MjE6Imh0dHA6Ly9sb2NhbGhvc3Q6ODAwMCI7czo1OiJyb3V0ZSI7Tjt9czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319fQ==', 1789147841);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` varchar(191) NOT NULL,
  `username` varchar(191) NOT NULL,
  `email` varchar(191) DEFAULT NULL,
  `password` varchar(191) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password`, `is_active`, `remember_token`, `created_at`, `updated_at`) VALUES
('u1', 'u1', 'ali.mwinyi@dpp.zn', '$2y$12$4Tqfjebc1wKu3kBndLI4l.QCC4nimkD/35dIElbJpCf8MQnyCMkXu', 1, NULL, '2026-10-26 11:46:09', '2026-10-28 09:43:07'),
('u10', 'u10', 'baraka.maulid@dpp.zn', '$2y$12$hjrqhVV6pRU.BCmnqA3FVenBxw5lbPRxJUpkQYlITPdi1WyH.fb.S', 1, NULL, '2026-10-26 11:46:12', '2026-10-28 09:43:07'),
('u11', 'u11', 'khadija.omar@dpp.zn', '$2y$12$lRFwnYRO/ZcpjGVVRQFrbezw5BjXfYYURAW.L8kB./NSxhLHOXWoW', 1, NULL, '2026-10-26 11:46:12', '2026-10-28 09:43:07'),
('u12', 'u12', 'haji.abdullah@dpp.zn', '$2y$12$u15.v3M0QlvVt1zA0W2yPuQwobXndg0lb641eJ1Q36ujsbyD/AH0m', 1, NULL, '2026-10-26 11:46:13', '2026-10-28 09:43:07'),
('u2', 'u2', 'salma.juma@dpp.zn', '$2y$12$ZQ0KnLnsIOJG9aNkTLwseu8GTkGphJVCVUh9VJL9rA5tkyTWNSU1S', 1, NULL, '2026-10-26 11:46:09', '2026-10-28 09:43:07'),
('u3', 'u3', 'fatma.hassan@dpp.zn', '$2y$12$g3n3GLoaWzQZ.bAowrGW3.Xp3pJOkIRSs/k1lgMT7j0kwgDIiNDB.', 1, NULL, '2026-10-26 11:46:09', '2026-10-28 09:43:07'),
('u4', 'u4', 'rashid.haji@dpp.zn', '$2y$12$YPwJinPb1h5eopasm2TceO2iPiAaizrEBHzXkWAxQVsIxq9x6BA0m', 1, NULL, '2026-10-26 11:46:10', '2026-10-28 09:43:07'),
('u5', 'u5', 'juma.mohammed@dpp.zn', '$2y$12$96BsoRtNqjCaJ1S9vwxb4eDeYZVtKtftmmwEbJ91bITEaCHR/A8wK', 1, NULL, '2026-10-26 11:46:10', '2026-10-28 09:43:07'),
('u6', 'u6', 'zaytuni.omar@dpp.zn', '$2y$12$egh.fri0ew8ol3IMI6qZXeRzQ2j6h.cY8gFwA27fg2gtxNCjyxewq', 1, NULL, '2026-10-26 11:46:10', '2026-10-28 09:43:07'),
('u7', 'u7', 'asha.hassan@dpp.zn', '$2y$12$dT9vYVpsMKfenGgIq9ok4eVnBB6XIjwsTxAz.3T7CqJIiMOEXf20G', 1, NULL, '2026-10-26 11:46:11', '2026-10-28 09:43:07'),
('u8', 'u8', 'mohammed.salim@dpp.zn', '$2y$12$smlIMy8lIDO0q2/DGQNWWOrEgZumiaDTUTJ1QJ7MqItDsgA.jIZUq', 1, NULL, '2026-10-26 11:46:11', '2026-10-28 09:43:07'),
('u9', 'u9', 'rehema.mzuri@dpp.zn', '$2y$12$/MXnfm2e3HypQxGi27N4OOh95JwBOx4/zhjXge8fVhhrVCrs7FwC6', 1, NULL, '2026-10-26 11:46:12', '2026-10-28 09:43:07');

-- --------------------------------------------------------

--
-- Table structure for table `workflow_assignments`
--

CREATE TABLE `workflow_assignments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `request_id` bigint(20) UNSIGNED NOT NULL,
  `step_id` bigint(20) UNSIGNED DEFAULT NULL,
  `assigned_employee_id` varchar(191) NOT NULL,
  `assigned_role_id` varchar(191) NOT NULL,
  `status` varchar(191) NOT NULL DEFAULT 'pending',
  `due_at` timestamp NULL DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `workflow_definitions`
--

CREATE TABLE `workflow_definitions` (
  `id` varchar(191) NOT NULL,
  `leave_type_id` varchar(191) NOT NULL,
  `version` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `min_duration_days` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `zone_id` varchar(191) DEFAULT NULL,
  `department_id` varchar(191) DEFAULT NULL,
  `effective_from` date DEFAULT NULL,
  `effective_to` date DEFAULT NULL,
  `created_by` varchar(191) DEFAULT NULL,
  `approved_by` varchar(191) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `workflow_definitions`
--

INSERT INTO `workflow_definitions` (`id`, `leave_type_id`, `version`, `min_duration_days`, `zone_id`, `department_id`, `effective_from`, `effective_to`, `created_by`, `approved_by`, `notes`, `created_at`, `updated_at`, `is_active`) VALUES
('wf_annual', 'lt1', 1, 0, NULL, NULL, '2024-01-01', NULL, 'u3', 'u1', NULL, '2026-10-26 11:46:18', '2026-10-26 11:46:18', 1),
('wf_emergency', 'lt5', 1, 0, NULL, NULL, '2024-01-01', NULL, 'u3', 'u1', NULL, '2026-10-26 11:46:18', '2026-10-26 11:46:18', 1),
('wf_lwp', 'lt7', 1, 0, NULL, NULL, '2024-01-01', NULL, 'u3', 'u1', NULL, '2026-10-26 11:46:18', '2026-10-26 11:46:18', 1),
('wf_maternity', 'lt3', 1, 0, NULL, NULL, '2024-01-01', NULL, 'u3', 'u1', NULL, '2026-10-26 11:46:18', '2026-10-26 11:46:18', 1),
('wf_paternity', 'lt4', 1, 0, NULL, NULL, '2024-01-01', NULL, 'u3', 'u1', NULL, '2026-10-26 11:46:18', '2026-10-26 11:46:18', 1),
('wf_sick', 'lt2', 1, 0, NULL, NULL, '2024-01-01', NULL, 'u3', 'u1', NULL, '2026-10-26 11:46:18', '2026-10-26 11:46:18', 1),
('wf_study', 'lt6', 1, 0, NULL, NULL, '2024-01-01', NULL, 'u3', 'u1', NULL, '2026-10-26 11:46:18', '2026-10-26 11:46:18', 1);

-- --------------------------------------------------------

--
-- Table structure for table `workflow_steps`
--

CREATE TABLE `workflow_steps` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `workflow_def_id` varchar(191) NOT NULL,
  `step_order` int(10) UNSIGNED NOT NULL,
  `role_required` varchar(191) NOT NULL,
  `sla_days` int(10) UNSIGNED NOT NULL DEFAULT 3,
  `is_mandatory` tinyint(1) NOT NULL DEFAULT 1,
  `escalation_after_sla` tinyint(1) NOT NULL DEFAULT 0,
  `note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `approval_role_id` varchar(191) DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `workflow_steps`
--

INSERT INTO `workflow_steps` (`id`, `workflow_def_id`, `step_order`, `role_required`, `sla_days`, `is_mandatory`, `escalation_after_sla`, `note`, `created_at`, `updated_at`, `approval_role_id`) VALUES
(1, 'wf_annual', 1, 'FINAL_AUTHORITY', 2, 1, 0, 'Verifies work coverage and minimum staffing.', '2026-10-26 11:46:18', '2026-10-26 11:46:18', NULL),
(2, 'wf_annual', 2, 'ZONAL_HEAD', 3, 1, 0, 'Regional sign-off for non-HQ zones; final authority only when >20 days.', '2026-10-26 11:46:18', '2026-10-26 11:46:18', NULL),
(3, 'wf_annual', 3, 'HR', 3, 1, 0, 'Checks balance, eligibility and policy compliance.', '2026-10-26 11:46:18', '2026-10-26 11:46:18', NULL),
(4, 'wf_sick', 1, 'FINAL_AUTHORITY', 1, 1, 0, 'Recommends on continuity and coverage.', '2026-10-26 11:46:18', '2026-10-26 11:46:18', NULL),
(5, 'wf_sick', 2, 'HR', 2, 1, 0, 'Verifies medical certificate and balance.', '2026-10-26 11:46:18', '2026-10-26 11:46:18', NULL),
(6, 'wf_maternity', 1, 'DEPT_HEAD', 2, 1, 0, 'Confirms acting cover for the position.', '2026-10-26 11:46:18', '2026-10-26 11:46:18', NULL),
(7, 'wf_maternity', 2, 'HR', 3, 1, 0, 'Verifies antenatal documentation and statutory days.', '2026-10-26 11:46:18', '2026-10-26 11:46:18', NULL),
(8, 'wf_maternity', 3, 'FINAL_AUTHORITY', 3, 1, 0, 'Statutory sign-off.', '2026-10-26 11:46:18', '2026-10-26 11:46:18', NULL),
(9, 'wf_paternity', 1, 'FINAL_AUTHORITY', 1, 1, 0, 'Confirms short-term cover.', '2026-10-26 11:46:18', '2026-10-26 11:46:18', NULL),
(10, 'wf_paternity', 2, 'HR', 2, 1, 0, 'Verifies birth certificate; records privately.', '2026-10-26 11:46:18', '2026-10-26 11:46:18', NULL),
(11, 'wf_emergency', 1, 'FINAL_AUTHORITY', 1, 1, 0, 'Acknowledges emergency reasons.', '2026-10-26 11:46:18', '2026-10-26 11:46:18', NULL),
(12, 'wf_emergency', 2, 'HR', 2, 1, 0, 'Validates balance and records exception.', '2026-10-26 11:46:18', '2026-10-26 11:46:18', NULL),
(13, 'wf_study', 1, 'FINAL_AUTHORITY', 3, 1, 0, 'Assesses operational impact.', '2026-10-26 11:46:18', '2026-10-26 11:46:18', NULL),
(14, 'wf_study', 2, 'DEPT_HEAD', 3, 1, 0, 'Endorses training relevance.', '2026-10-26 11:46:18', '2026-10-26 11:46:18', NULL),
(15, 'wf_study', 3, 'ZONAL_HEAD', 3, 1, 0, 'Pemba coordination review where applicable.', '2026-10-26 11:46:18', '2026-10-26 11:46:18', NULL),
(16, 'wf_study', 4, 'HR', 3, 1, 0, 'Checks tenure, documents and bond conditions.', '2026-10-26 11:46:18', '2026-10-26 11:46:18', NULL),
(17, 'wf_study', 5, 'FINAL_AUTHORITY', 3, 1, 0, 'Final sign-off.', '2026-10-26 11:46:18', '2026-10-26 11:46:18', NULL),
(18, 'wf_lwp', 1, 'FINAL_AUTHORITY', 2, 1, 0, 'Confirms operational impact.', '2026-10-26 11:46:18', '2026-10-26 11:46:18', NULL),
(19, 'wf_lwp', 2, 'DEPT_HEAD', 3, 1, 0, 'Recommends on absence length.', '2026-10-26 11:46:18', '2026-10-26 11:46:18', NULL),
(20, 'wf_lwp', 3, 'HR', 3, 1, 0, 'Checks probation and policy compliance.', '2026-10-26 11:46:18', '2026-10-26 11:46:18', NULL),
(21, 'wf_lwp', 4, 'FINAL_AUTHORITY', 3, 1, 0, 'Final sign-off.', '2026-10-26 11:46:18', '2026-10-26 11:46:18', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `zones`
--

CREATE TABLE `zones` (
  `id` varchar(191) NOT NULL,
  `name` varchar(191) NOT NULL,
  `short` varchar(191) DEFAULT NULL,
  `is_hq` tinyint(1) NOT NULL DEFAULT 0,
  `is_pemba` tinyint(1) NOT NULL DEFAULT 0,
  `is_coordination` tinyint(1) NOT NULL DEFAULT 0,
  `parent_zone_id` varchar(191) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `zones`
--

INSERT INTO `zones` (`id`, `name`, `short`, `is_hq`, `is_pemba`, `is_coordination`, `parent_zone_id`, `created_at`, `updated_at`) VALUES
('z1', 'Unguja Island — Zanzibar Town', 'Unguja (HQ)', 1, 0, 0, NULL, '2026-10-26 11:46:08', '2026-10-26 11:46:08'),
('z2', 'Unguja North', 'Unguja Kaskazini', 0, 0, 0, NULL, '2026-10-26 11:46:08', '2026-10-26 11:46:08'),
('z3', 'Unguja South', 'Unguja Kusini', 0, 0, 0, NULL, '2026-10-26 11:46:08', '2026-10-26 11:46:08'),
('z4', 'Pemba — Wete Coordination Office', 'Pemba', 0, 1, 1, NULL, '2026-10-26 11:46:08', '2026-10-26 11:46:08');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `approval_actions`
--
ALTER TABLE `approval_actions`
  ADD KEY `approval_actions_workflow_rule_used_foreign` (`workflow_rule_used`),
  ADD KEY `approval_actions_assignment_id_index` (`assignment_id`),
  ADD KEY `approval_actions_actor_employee_id_index` (`actor_employee_id`),
  ADD KEY `approval_actions_acted_at_index` (`acted_at`),
  ADD KEY `approval_actions_assignment_id_acted_at_index` (`assignment_id`,`acted_at`);

--
-- Indexes for table `approval_roles`
--
ALTER TABLE `approval_roles`
  ADD UNIQUE KEY `approval_roles_code_unique` (`code`),
  ADD KEY `approval_roles_code_is_active_index` (`code`,`is_active`);

--
-- Indexes for table `approval_signatures`
--
ALTER TABLE `approval_signatures`
  ADD KEY `approval_signatures_leave_request_id_actor_id_index` (`leave_request_id`,`actor_id`),
  ADD KEY `approval_signatures_approval_action_id_index` (`approval_action_id`),
  ADD KEY `approval_signatures_signature_hash_index` (`signature_hash`),
  ADD KEY `approval_signatures_leave_request_id_index` (`leave_request_id`),
  ADD KEY `approval_signatures_actor_id_index` (`actor_id`),
  ADD KEY `approval_signatures_leave_request_id_approval_action_index` (`leave_request_id`,`approval_action`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD KEY `audit_logs_actor_user_id_foreign` (`actor_user_id`);

--
-- Indexes for table `balance_transactions`
--
ALTER TABLE `balance_transactions`
  ADD UNIQUE KEY `balance_transactions_idempotency_key_unique` (`idempotency_key`),
  ADD KEY `balance_transactions_leave_type_id_foreign` (`leave_type_id`),
  ADD KEY `balance_transactions_leave_request_id_foreign` (`leave_request_id`),
  ADD KEY `balance_transactions_leave_waiver_id_foreign` (`leave_waiver_id`),
  ADD KEY `balance_transactions_policy_id_foreign` (`policy_id`),
  ADD KEY `balance_transactions_reversed_transaction_id_foreign` (`reversed_transaction_id`),
  ADD KEY `balance_transactions_created_by_foreign` (`created_by`),
  ADD KEY `balance_transactions_employee_id_leave_type_id_index` (`employee_id`,`leave_type_id`),
  ADD KEY `balance_transactions_transaction_type_index` (`transaction_type`);

--
-- Indexes for table `cache`
--
ALTER TABLE `cache`
  ADD KEY `cache_expiration_index` (`expiration`);

--
-- Indexes for table `cache_locks`
--
ALTER TABLE `cache_locks`
  ADD KEY `cache_locks_expiration_index` (`expiration`);

--
-- Indexes for table `cases`
--
ALTER TABLE `cases`
  ADD UNIQUE KEY `cases_ref_unique` (`ref`),
  ADD KEY `cases_request_id_foreign` (`request_id`),
  ADD KEY `cases_assigned_prosecutor_id_foreign` (`assigned_prosecutor_id`),
  ADD KEY `cases_owner_id_foreign` (`owner_id`);

--
-- Indexes for table `case_handovers`
--
ALTER TABLE `case_handovers`
  ADD KEY `case_handovers_request_id_foreign` (`request_id`),
  ADD KEY `case_handovers_case_id_foreign` (`case_id`),
  ADD KEY `case_handovers_from_employee_id_foreign` (`from_employee_id`),
  ADD KEY `case_handovers_to_employee_id_foreign` (`to_employee_id`),
  ADD KEY `case_handovers_reviewed_by_foreign` (`reviewed_by`);

--
-- Indexes for table `delegations`
--
ALTER TABLE `delegations`
  ADD KEY `delegations_delegator_employee_id_foreign` (`delegator_employee_id`),
  ADD KEY `delegations_delegate_employee_id_foreign` (`delegate_employee_id`),
  ADD KEY `delegations_authorized_by_foreign` (`authorized_by`),
  ADD KEY `delegations_role_id_foreign` (`role_id`),
  ADD KEY `delegations_approval_role_id_foreign` (`approval_role_id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD KEY `departments_zone_id_foreign` (`zone_id`),
  ADD KEY `departments_parent_department_id_foreign` (`parent_department_id`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD UNIQUE KEY `employees_payroll_no_unique` (`payroll_no`),
  ADD KEY `employees_user_id_foreign` (`user_id`),
  ADD KEY `employees_position_id_foreign` (`position_id`),
  ADD KEY `employees_department_id_foreign` (`department_id`),
  ADD KEY `employees_zone_id_foreign` (`zone_id`);

--
-- Indexes for table `employee_approval_roles`
--
ALTER TABLE `employee_approval_roles`
  ADD KEY `employee_approval_roles_approval_role_id_foreign` (`approval_role_id`),
  ADD KEY `employee_approval_roles_organizational_unit_id_foreign` (`organizational_unit_id`),
  ADD KEY `employee_approval_roles_employee_id_approval_role_id_index` (`employee_id`,`approval_role_id`);

--
-- Indexes for table `employee_roles`
--
ALTER TABLE `employee_roles`
  ADD UNIQUE KEY `employee_roles_identity` (`employee_id`,`role_id`,`department_id`,`scope_zone_id`),
  ADD KEY `employee_roles_role_id_foreign` (`role_id`),
  ADD KEY `employee_roles_department_id_foreign` (`department_id`),
  ADD KEY `employee_roles_scope_zone_id_foreign` (`scope_zone_id`);

--
-- Indexes for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  ADD UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`);

--
-- Indexes for table `jobs`
--
ALTER TABLE `jobs`
  ADD KEY `jobs_queue_index` (`queue`);

--
-- Indexes for table `job_batches`
--
ALTER TABLE `job_batches`

--
-- Indexes for table `leave_balances`
--
ALTER TABLE `leave_balances`
  ADD UNIQUE KEY `leave_balances_employee_id_leave_type_id_year_unique` (`employee_id`,`leave_type_id`,`year`),
  ADD KEY `leave_balances_leave_type_id_foreign` (`leave_type_id`);

--
-- Indexes for table `leave_documents`
--
ALTER TABLE `leave_documents`
  ADD KEY `leave_documents_request_id_foreign` (`request_id`),
  ADD KEY `leave_documents_uploaded_by_user_id_foreign` (`uploaded_by_user_id`);

--
-- Indexes for table `leave_document_requirements`
--
ALTER TABLE `leave_document_requirements`
  ADD KEY `leave_document_requirements_leave_policy_id_index` (`leave_policy_id`),
  ADD KEY `leave_document_requirements_leave_type_id_index` (`leave_type_id`);

--
-- Indexes for table `leave_payment_cases`
--
ALTER TABLE `leave_payment_cases`
  ADD UNIQUE KEY `leave_payment_cases_leave_request_id_payment_type_unique` (`leave_request_id`,`payment_type`),
  ADD UNIQUE KEY `leave_payment_cases_idempotency_key_unique` (`idempotency_key`),
  ADD KEY `leave_payment_cases_policy_id_foreign` (`policy_id`),
  ADD KEY `leave_payment_cases_created_by_foreign` (`created_by`),
  ADD KEY `leave_payment_cases_submitted_by_foreign` (`submitted_by`),
  ADD KEY `leave_payment_cases_hr_reviewed_by_foreign` (`hr_reviewed_by`),
  ADD KEY `leave_payment_cases_sent_to_accounting_by_foreign` (`sent_to_accounting_by`),
  ADD KEY `leave_payment_cases_processed_by_foreign` (`processed_by`),
  ADD KEY `leave_payment_cases_rejected_by_foreign` (`rejected_by`),
  ADD KEY `leave_payment_cases_cancelled_by_foreign` (`cancelled_by`),
  ADD KEY `leave_payment_cases_employee_id_status_index` (`employee_id`,`status`),
  ADD KEY `leave_payment_cases_status_index` (`status`),
  ADD KEY `leave_payment_cases_leave_type_id_index` (`leave_type_id`),
  ADD KEY `leave_payment_cases_leave_request_id_payment_type_index` (`leave_request_id`,`payment_type`);

--
-- Indexes for table `leave_plans`
--
ALTER TABLE `leave_plans`
  ADD KEY `leave_plans_employee_id_foreign` (`employee_id`),
  ADD KEY `leave_plans_leave_type_id_foreign` (`leave_type_id`),
  ADD KEY `leave_plans_leave_policy_id_foreign` (`leave_policy_id`),
  ADD KEY `leave_plans_confirmed_by_foreign` (`confirmed_by`),
  ADD KEY `leave_plans_leave_request_id_foreign` (`leave_request_id`);

--
-- Indexes for table `leave_plan_options`
--
ALTER TABLE `leave_plan_options`
  ADD KEY `leave_plan_options_leave_plan_id_foreign` (`leave_plan_id`);

--
-- Indexes for table `leave_policies`
--
ALTER TABLE `leave_policies`
  ADD UNIQUE KEY `leave_policies_leave_type_id_version_unique` (`leave_type_id`,`version`),
  ADD KEY `leave_policies_created_by_foreign` (`created_by`),
  ADD KEY `leave_policies_approved_by_foreign` (`approved_by`),
  ADD KEY `leave_policies_type_status_dates_idx` (`leave_type_id`,`status`,`effective_from`,`effective_to`);

--
-- Indexes for table `leave_reasons`
--
ALTER TABLE `leave_reasons`
  ADD UNIQUE KEY `leave_reasons_code_unique` (`code`),
  ADD KEY `leave_reasons_leave_type_id_index` (`leave_type_id`);

--
-- Indexes for table `leave_requests`
--
ALTER TABLE `leave_requests`
  ADD UNIQUE KEY `leave_requests_ref_unique` (`ref`),
  ADD KEY `leave_requests_leave_type_id_foreign` (`leave_type_id`),
  ADD KEY `leave_requests_policy_id_foreign` (`policy_id`),
  ADD KEY `leave_requests_employee_id_index` (`employee_id`),
  ADD KEY `leave_requests_start_date_index` (`start_date`),
  ADD KEY `leave_requests_end_date_index` (`end_date`),
  ADD KEY `leave_requests_current_owner_id_index` (`current_owner_id`),
  ADD KEY `leave_requests_status_employee_id_index` (`status`,`employee_id`),
  ADD KEY `leave_requests_status_start_date_end_date_index` (`status`,`start_date`,`end_date`);

--
-- Indexes for table `leave_request_dates`
--
ALTER TABLE `leave_request_dates`

--
-- Indexes for table `leave_returns`
--
ALTER TABLE `leave_returns`
  ADD UNIQUE KEY `leave_returns_leave_request_id_unique` (`leave_request_id`),
  ADD KEY `leave_returns_reported_by_foreign` (`reported_by`),
  ADD KEY `leave_returns_reviewed_by_foreign` (`reviewed_by`),
  ADD KEY `leave_returns_supporting_document_id_foreign` (`supporting_document_id`),
  ADD KEY `leave_returns_closed_by_foreign` (`closed_by`),
  ADD KEY `leave_returns_employee_id_status_index` (`employee_id`,`status`),
  ADD KEY `leave_returns_leave_request_id_index` (`leave_request_id`),
  ADD KEY `leave_returns_employee_id_index` (`employee_id`),
  ADD KEY `leave_returns_status_index` (`status`),
  ADD KEY `leave_returns_expected_return_date_index` (`expected_return_date`);

--
-- Indexes for table `leave_rules`
--
ALTER TABLE `leave_rules`
  ADD KEY `leave_rules_leave_policy_id_index` (`leave_policy_id`),
  ADD KEY `leave_rules_inherited_from_rule_id_index` (`inherited_from_rule_id`);

--
-- Indexes for table `leave_types`
--
ALTER TABLE `leave_types`
  ADD UNIQUE KEY `leave_types_code_unique` (`code`);

--
-- Indexes for table `leave_waivers`
--
ALTER TABLE `leave_waivers`
  ADD KEY `leave_waivers_employee_id_foreign` (`employee_id`),
  ADD KEY `leave_waivers_leave_type_id_foreign` (`leave_type_id`),
  ADD KEY `leave_waivers_leave_policy_id_foreign` (`leave_policy_id`),
  ADD KEY `leave_waivers_requested_by_foreign` (`requested_by`),
  ADD KEY `leave_waivers_reviewed_by_foreign` (`reviewed_by`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`

--
-- Indexes for table `modules`
--
ALTER TABLE `modules`

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD KEY `notifications_recipient_employee_id_foreign` (`recipient_employee_id`);

--
-- Indexes for table `notification_deliveries`
--
ALTER TABLE `notification_deliveries`
  ADD UNIQUE KEY `notification_deliveries_idempotency_key_unique` (`idempotency_key`),
  ADD KEY `notification_deliveries_notification_id_channel_index` (`notification_id`,`channel`);

--
-- Indexes for table `notification_preferences`
--
ALTER TABLE `notification_preferences`
  ADD UNIQUE KEY `notification_preferences_user_id_event_type_unique` (`user_id`,`event_type`);

--
-- Indexes for table `official_leave_letters`
--
ALTER TABLE `official_leave_letters`
  ADD UNIQUE KEY `official_leave_letters_qr_token_unique` (`qr_token`),
  ADD UNIQUE KEY `oll_req_type_version_unique` (`leave_request_id`,`document_type`,`document_version`),
  ADD KEY `official_leave_letters_qr_token_index` (`qr_token`),
  ADD KEY `official_leave_letters_revoked_at_index` (`revoked_at`),
  ADD KEY `official_leave_letters_leave_request_id_index` (`leave_request_id`),
  ADD KEY `official_leave_letters_leave_request_id_document_type_index` (`leave_request_id`,`document_type`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD UNIQUE KEY `permissions_code_unique` (`code`),
  ADD KEY `permissions_status_active_index` (`status`,`active`);

--
-- Indexes for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  ADD UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  ADD KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  ADD KEY `personal_access_tokens_tokenable_id_index` (`tokenable_id`),
  ADD KEY `personal_access_tokens_expires_at_index` (`expires_at`);

--
-- Indexes for table `positions`
--
ALTER TABLE `positions`
  ADD KEY `positions_department_id_foreign` (`department_id`),
  ADD KEY `positions_zone_id_foreign` (`zone_id`);

--
-- Indexes for table `position_default_roles`
--
ALTER TABLE `position_default_roles`
  ADD KEY `position_default_roles_role_id_foreign` (`role_id`);

--
-- Indexes for table `reporting_lines`
--
ALTER TABLE `reporting_lines`
  ADD KEY `reporting_lines_employee_id_foreign` (`employee_id`),
  ADD KEY `reporting_lines_supervisor_employee_id_foreign` (`supervisor_employee_id`);

--
-- Indexes for table `report_exports`
--
ALTER TABLE `report_exports`
  ADD KEY `report_exports_requested_by_status_index` (`requested_by`,`status`),
  ADD KEY `report_exports_status_index` (`status`),
  ADD KEY `report_exports_expires_at_index` (`expires_at`),
  ADD KEY `report_exports_report_type_created_at_index` (`report_type`,`created_at`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD KEY `role_permissions_permission_id_foreign` (`permission_id`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD KEY `sessions_user_id_index` (`user_id`),
  ADD KEY `sessions_last_activity_index` (`last_activity`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD UNIQUE KEY `users_username_unique` (`username`);

--
-- Indexes for table `workflow_assignments`
--
ALTER TABLE `workflow_assignments`
  ADD KEY `workflow_assignments_step_id_foreign` (`step_id`),
  ADD KEY `workflow_assignments_assigned_role_id_foreign` (`assigned_role_id`),
  ADD KEY `workflow_assignments_request_id_index` (`request_id`),
  ADD KEY `workflow_assignments_assigned_employee_id_index` (`assigned_employee_id`),
  ADD KEY `workflow_assignments_status_index` (`status`),
  ADD KEY `workflow_assignments_due_at_index` (`due_at`),
  ADD KEY `workflow_assignments_assigned_employee_id_status_index` (`assigned_employee_id`,`status`),
  ADD KEY `workflow_assignments_request_id_status_index` (`request_id`,`status`);

--
-- Indexes for table `workflow_definitions`
--
ALTER TABLE `workflow_definitions`
  ADD UNIQUE KEY `workflow_definitions_leave_type_id_version_unique` (`leave_type_id`,`version`),
  ADD KEY `workflow_definitions_zone_id_foreign` (`zone_id`),
  ADD KEY `workflow_definitions_department_id_foreign` (`department_id`);

--
-- Indexes for table `workflow_steps`
--
ALTER TABLE `workflow_steps`
  ADD UNIQUE KEY `workflow_steps_workflow_def_id_step_order_unique` (`workflow_def_id`,`step_order`),
  ADD KEY `workflow_steps_role_required_foreign` (`role_required`),
  ADD KEY `workflow_steps_approval_role_id_foreign` (`approval_role_id`);

--
-- Indexes for table `zones`
--
ALTER TABLE `zones`

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `approval_actions`
--
ALTER TABLE `approval_actions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `approval_signatures`
--
ALTER TABLE `approval_signatures`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `balance_transactions`
--
ALTER TABLE `balance_transactions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cases`
--
ALTER TABLE `cases`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `case_handovers`
--
ALTER TABLE `case_handovers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `employee_roles`
--
ALTER TABLE `employee_roles`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `jobs`
--
ALTER TABLE `jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `leave_balances`
--
ALTER TABLE `leave_balances`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leave_documents`
--
ALTER TABLE `leave_documents`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `leave_document_requirements`
--
ALTER TABLE `leave_document_requirements`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leave_payment_cases`
--
ALTER TABLE `leave_payment_cases`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leave_plans`
--
ALTER TABLE `leave_plans`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leave_plan_options`
--
ALTER TABLE `leave_plan_options`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leave_reasons`
--
ALTER TABLE `leave_reasons`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leave_requests`
--
ALTER TABLE `leave_requests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `leave_returns`
--
ALTER TABLE `leave_returns`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leave_rules`
--
ALTER TABLE `leave_rules`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leave_waivers`
--
ALTER TABLE `leave_waivers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=96;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `notification_deliveries`
--
ALTER TABLE `notification_deliveries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification_preferences`
--
ALTER TABLE `notification_preferences`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `official_leave_letters`
--
ALTER TABLE `official_leave_letters`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=148;

--
-- AUTO_INCREMENT for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `reporting_lines`
--
ALTER TABLE `reporting_lines`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `report_exports`
--
ALTER TABLE `report_exports`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `workflow_assignments`
--
ALTER TABLE `workflow_assignments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `workflow_steps`
--
ALTER TABLE `workflow_steps`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `approval_actions`
--
ALTER TABLE `approval_actions`
  ADD CONSTRAINT `approval_actions_actor_employee_id_foreign` FOREIGN KEY (`actor_employee_id`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `approval_actions_assignment_id_foreign` FOREIGN KEY (`assignment_id`) REFERENCES `workflow_assignments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `approval_actions_workflow_rule_used_foreign` FOREIGN KEY (`workflow_rule_used`) REFERENCES `workflow_steps` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `approval_signatures`
--
ALTER TABLE `approval_signatures`
  ADD CONSTRAINT `approval_signatures_approval_action_id_foreign` FOREIGN KEY (`approval_action_id`) REFERENCES `approval_actions` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `approval_signatures_leave_request_id_foreign` FOREIGN KEY (`leave_request_id`) REFERENCES `leave_requests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `balance_transactions`
--
ALTER TABLE `balance_transactions`
  ADD CONSTRAINT `balance_transactions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `balance_transactions_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `balance_transactions_leave_request_id_foreign` FOREIGN KEY (`leave_request_id`) REFERENCES `leave_requests` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `balance_transactions_leave_type_id_foreign` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`),
  ADD CONSTRAINT `balance_transactions_leave_waiver_id_foreign` FOREIGN KEY (`leave_waiver_id`) REFERENCES `leave_waivers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `balance_transactions_policy_id_foreign` FOREIGN KEY (`policy_id`) REFERENCES `leave_policies` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `balance_transactions_reversed_transaction_id_foreign` FOREIGN KEY (`reversed_transaction_id`) REFERENCES `balance_transactions` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `cases`
--
ALTER TABLE `cases`
  ADD CONSTRAINT `cases_assigned_prosecutor_id_foreign` FOREIGN KEY (`assigned_prosecutor_id`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `cases_owner_id_foreign` FOREIGN KEY (`owner_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `cases_request_id_foreign` FOREIGN KEY (`request_id`) REFERENCES `leave_requests` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `case_handovers`
--
ALTER TABLE `case_handovers`
  ADD CONSTRAINT `case_handovers_case_id_foreign` FOREIGN KEY (`case_id`) REFERENCES `cases` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `case_handovers_from_employee_id_foreign` FOREIGN KEY (`from_employee_id`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `case_handovers_request_id_foreign` FOREIGN KEY (`request_id`) REFERENCES `leave_requests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `case_handovers_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `case_handovers_to_employee_id_foreign` FOREIGN KEY (`to_employee_id`) REFERENCES `employees` (`id`);

--
-- Constraints for table `delegations`
--
ALTER TABLE `delegations`
  ADD CONSTRAINT `delegations_approval_role_id_foreign` FOREIGN KEY (`approval_role_id`) REFERENCES `approval_roles` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `delegations_authorized_by_foreign` FOREIGN KEY (`authorized_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `delegations_delegate_employee_id_foreign` FOREIGN KEY (`delegate_employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `delegations_delegator_employee_id_foreign` FOREIGN KEY (`delegator_employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `delegations_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `departments`
--
ALTER TABLE `departments`
  ADD CONSTRAINT `departments_parent_department_id_foreign` FOREIGN KEY (`parent_department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `departments_zone_id_foreign` FOREIGN KEY (`zone_id`) REFERENCES `zones` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `employees`
--
ALTER TABLE `employees`
  ADD CONSTRAINT `employees_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
  ADD CONSTRAINT `employees_position_id_foreign` FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`),
  ADD CONSTRAINT `employees_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `employees_zone_id_foreign` FOREIGN KEY (`zone_id`) REFERENCES `zones` (`id`);

--
-- Constraints for table `employee_approval_roles`
--
ALTER TABLE `employee_approval_roles`
  ADD CONSTRAINT `employee_approval_roles_approval_role_id_foreign` FOREIGN KEY (`approval_role_id`) REFERENCES `approval_roles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `employee_approval_roles_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `employee_approval_roles_organizational_unit_id_foreign` FOREIGN KEY (`organizational_unit_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `employee_roles`
--
ALTER TABLE `employee_roles`
  ADD CONSTRAINT `employee_roles_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `employee_roles_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `employee_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `employee_roles_scope_zone_id_foreign` FOREIGN KEY (`scope_zone_id`) REFERENCES `zones` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `leave_balances`
--
ALTER TABLE `leave_balances`
  ADD CONSTRAINT `leave_balances_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `leave_balances_leave_type_id_foreign` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `leave_documents`
--
ALTER TABLE `leave_documents`
  ADD CONSTRAINT `leave_documents_request_id_foreign` FOREIGN KEY (`request_id`) REFERENCES `leave_requests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `leave_documents_uploaded_by_user_id_foreign` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `leave_payment_cases`
--
ALTER TABLE `leave_payment_cases`
  ADD CONSTRAINT `leave_payment_cases_cancelled_by_foreign` FOREIGN KEY (`cancelled_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leave_payment_cases_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leave_payment_cases_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `leave_payment_cases_hr_reviewed_by_foreign` FOREIGN KEY (`hr_reviewed_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leave_payment_cases_leave_request_id_foreign` FOREIGN KEY (`leave_request_id`) REFERENCES `leave_requests` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leave_payment_cases_leave_type_id_foreign` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `leave_payment_cases_policy_id_foreign` FOREIGN KEY (`policy_id`) REFERENCES `leave_policies` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leave_payment_cases_processed_by_foreign` FOREIGN KEY (`processed_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leave_payment_cases_rejected_by_foreign` FOREIGN KEY (`rejected_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leave_payment_cases_sent_to_accounting_by_foreign` FOREIGN KEY (`sent_to_accounting_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leave_payment_cases_submitted_by_foreign` FOREIGN KEY (`submitted_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `leave_plans`
--
ALTER TABLE `leave_plans`
  ADD CONSTRAINT `leave_plans_confirmed_by_foreign` FOREIGN KEY (`confirmed_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leave_plans_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `leave_plans_leave_policy_id_foreign` FOREIGN KEY (`leave_policy_id`) REFERENCES `leave_policies` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leave_plans_leave_request_id_foreign` FOREIGN KEY (`leave_request_id`) REFERENCES `leave_requests` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leave_plans_leave_type_id_foreign` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`);

--
-- Constraints for table `leave_plan_options`
--
ALTER TABLE `leave_plan_options`
  ADD CONSTRAINT `leave_plan_options_leave_plan_id_foreign` FOREIGN KEY (`leave_plan_id`) REFERENCES `leave_plans` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `leave_policies`
--
ALTER TABLE `leave_policies`
  ADD CONSTRAINT `leave_policies_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leave_policies_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leave_policies_leave_type_id_foreign` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `leave_requests`
--
ALTER TABLE `leave_requests`
  ADD CONSTRAINT `leave_requests_current_owner_id_foreign` FOREIGN KEY (`current_owner_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leave_requests_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `leave_requests_leave_type_id_foreign` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`),
  ADD CONSTRAINT `leave_requests_policy_id_foreign` FOREIGN KEY (`policy_id`) REFERENCES `leave_policies` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `leave_request_dates`
--
ALTER TABLE `leave_request_dates`
  ADD CONSTRAINT `leave_request_dates_request_id_foreign` FOREIGN KEY (`request_id`) REFERENCES `leave_requests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `leave_returns`
--
ALTER TABLE `leave_returns`
  ADD CONSTRAINT `leave_returns_closed_by_foreign` FOREIGN KEY (`closed_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leave_returns_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `leave_returns_leave_request_id_foreign` FOREIGN KEY (`leave_request_id`) REFERENCES `leave_requests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `leave_returns_reported_by_foreign` FOREIGN KEY (`reported_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leave_returns_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leave_returns_supporting_document_id_foreign` FOREIGN KEY (`supporting_document_id`) REFERENCES `leave_documents` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `leave_waivers`
--
ALTER TABLE `leave_waivers`
  ADD CONSTRAINT `leave_waivers_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `leave_waivers_leave_policy_id_foreign` FOREIGN KEY (`leave_policy_id`) REFERENCES `leave_policies` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leave_waivers_leave_type_id_foreign` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`),
  ADD CONSTRAINT `leave_waivers_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leave_waivers_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_recipient_employee_id_foreign` FOREIGN KEY (`recipient_employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notification_deliveries`
--
ALTER TABLE `notification_deliveries`
  ADD CONSTRAINT `notification_deliveries_notification_id_foreign` FOREIGN KEY (`notification_id`) REFERENCES `notifications` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notification_preferences`
--
ALTER TABLE `notification_preferences`
  ADD CONSTRAINT `notification_preferences_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `official_leave_letters`
--
ALTER TABLE `official_leave_letters`
  ADD CONSTRAINT `official_leave_letters_leave_request_id_foreign` FOREIGN KEY (`leave_request_id`) REFERENCES `leave_requests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `positions`
--
ALTER TABLE `positions`
  ADD CONSTRAINT `positions_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `positions_zone_id_foreign` FOREIGN KEY (`zone_id`) REFERENCES `zones` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `position_default_roles`
--
ALTER TABLE `position_default_roles`
  ADD CONSTRAINT `position_default_roles_position_id_foreign` FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `position_default_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `reporting_lines`
--
ALTER TABLE `reporting_lines`
  ADD CONSTRAINT `reporting_lines_employee_id_foreign` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reporting_lines_supervisor_employee_id_foreign` FOREIGN KEY (`supervisor_employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `role_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `role_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `workflow_assignments`
--
ALTER TABLE `workflow_assignments`
  ADD CONSTRAINT `workflow_assignments_assigned_employee_id_foreign` FOREIGN KEY (`assigned_employee_id`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `workflow_assignments_assigned_role_id_foreign` FOREIGN KEY (`assigned_role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `workflow_assignments_request_id_foreign` FOREIGN KEY (`request_id`) REFERENCES `leave_requests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `workflow_assignments_step_id_foreign` FOREIGN KEY (`step_id`) REFERENCES `workflow_steps` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `workflow_definitions`
--
ALTER TABLE `workflow_definitions`
  ADD CONSTRAINT `workflow_definitions_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `workflow_definitions_leave_type_id_foreign` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `workflow_definitions_zone_id_foreign` FOREIGN KEY (`zone_id`) REFERENCES `zones` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `workflow_steps`
--
ALTER TABLE `workflow_steps`
  ADD CONSTRAINT `workflow_steps_approval_role_id_foreign` FOREIGN KEY (`approval_role_id`) REFERENCES `approval_roles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `workflow_steps_role_required_foreign` FOREIGN KEY (`role_required`) REFERENCES `roles` (`id`),
  ADD CONSTRAINT `workflow_steps_workflow_def_id_foreign` FOREIGN KEY (`workflow_def_id`) REFERENCES `workflow_definitions` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
