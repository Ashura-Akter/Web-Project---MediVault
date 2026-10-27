-- ============================================================
-- MediVault v14 — Conflicts + Support Replies
-- Run this ONCE against the existing medivault database.
-- ============================================================

CREATE TABLE IF NOT EXISTS `medicine_conflicts` (
  `conflict_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `medicine_id` int(10) UNSIGNED NOT NULL,
  `conflicting_medicine_id` int(10) UNSIGNED NOT NULL,
  `severity` enum('MILD','MODERATE','SEVERE','CONTRAINDICATED') NOT NULL DEFAULT 'MODERATE',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`conflict_id`),
  UNIQUE KEY `uq_conflict_pair` (`medicine_id`,`conflicting_medicine_id`),
  KEY `idx_conflict_medicine` (`medicine_id`),
  KEY `idx_conflict_other` (`conflicting_medicine_id`),
  CONSTRAINT `fk_mc_medicine` FOREIGN KEY (`medicine_id`) REFERENCES `medicines` (`medicine_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_mc_other`    FOREIGN KEY (`conflicting_medicine_id`) REFERENCES `medicines` (`medicine_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `support_replies` (
  `reply_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `original_notification_id` int(10) UNSIGNED NOT NULL,
  `reply_notification_id` int(10) UNSIGNED DEFAULT NULL,
  `patient_id` int(10) UNSIGNED NOT NULL,
  `admin_id` int(10) UNSIGNED NOT NULL,
  `reply_message` varchar(600) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`reply_id`),
  KEY `idx_sr_original` (`original_notification_id`),
  KEY `idx_sr_patient` (`patient_id`),
  KEY `idx_sr_admin` (`admin_id`),
  CONSTRAINT `fk_sr_original` FOREIGN KEY (`original_notification_id`) REFERENCES `notifications` (`notification_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sr_patient`  FOREIGN KEY (`patient_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sr_admin`    FOREIGN KEY (`admin_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============ SAMPLE CONFLICT DATA (42 entries) ============
INSERT IGNORE INTO `medicine_conflicts` (`medicine_id`, `conflicting_medicine_id`, `severity`, `notes`) VALUES
(11, 91, 'SEVERE', 'Diclofenac + Warfarin greatly increases bleeding risk'),
(14, 91, 'SEVERE', 'Naproxen + Warfarin greatly increases bleeding risk'),
(17, 91, 'SEVERE', 'Ibuprofen + Warfarin greatly increases bleeding risk'),
(20, 91, 'MODERATE', 'Aceclofenac + Warfarin increases bleeding risk'),
(81, 91, 'SEVERE', 'Aspirin + Warfarin greatly increases bleeding risk'),
(39, 90, 'MODERATE', 'Omeprazole reduces Clopidogrel antiplatelet effect'),
(40, 90, 'MODERATE', 'Omeprazole reduces Clopidogrel antiplatelet effect'),
(41, 90, 'MODERATE', 'Omeprazole reduces Clopidogrel antiplatelet effect'),
(134, 135, 'SEVERE', 'Two benzodiazepines cause dangerous sedation'),
(134, 136, 'SEVERE', 'Two benzodiazepines cause dangerous sedation'),
(134, 137, 'SEVERE', 'Two benzodiazepines cause dangerous sedation'),
(135, 136, 'SEVERE', 'Two benzodiazepines cause dangerous sedation'),
(135, 137, 'SEVERE', 'Two benzodiazepines cause dangerous sedation'),
(136, 137, 'SEVERE', 'Two benzodiazepines cause dangerous sedation'),
(19, 138, 'SEVERE', 'Tramadol + Escitalopram: serotonin syndrome risk'),
(19, 139, 'SEVERE', 'Tramadol + Sertraline: serotonin syndrome risk'),
(19, 140, 'SEVERE', 'Tramadol + Amitriptyline: serotonin syndrome risk'),
(19, 141, 'SEVERE', 'Tramadol + Fluoxetine: serotonin syndrome risk'),
(82, 85, 'MILD', 'Amlodipine + Atenolol: additive BP lowering'),
(83, 85, 'MILD', 'Amlodipine + Atenolol: additive BP lowering'),
(11, 147, 'MODERATE', 'Diclofenac + Prednisolone: ulcer and bleeding risk'),
(14, 147, 'MODERATE', 'Naproxen + Prednisolone: ulcer and bleeding risk'),
(17, 147, 'MODERATE', 'Ibuprofen + Prednisolone: ulcer and bleeding risk'),
(20, 147, 'MODERATE', 'Aceclofenac + Prednisolone: ulcer and bleeding risk'),
(36, 146, 'CONTRAINDICATED', 'Co-trimoxazole + Methotrexate: bone marrow suppression'),
(17, 146, 'MODERATE', 'Ibuprofen + Methotrexate: increased toxicity'),
(11, 146, 'MODERATE', 'Diclofenac + Methotrexate: increased toxicity'),
(92, 96, 'MODERATE', 'Furosemide + Metformin: lactic acidosis risk'),
(84, 91, 'MODERATE', 'Losartan + Warfarin: altered INR'),
(82, 84, 'MILD', 'Amlodipine + Losartan: additive BP lowering'),
(87, 89, 'MILD', 'Two statins: increased muscle toxicity'),
(88, 89, 'MILD', 'Two statins: increased muscle toxicity'),
(63, 85, 'MODERATE', 'Salbutamol inhaler + Atenolol: beta-blocker antagonises bronchodilator'),
(61, 95, 'MODERATE', 'Salbutamol syrup + Propranolol: bronchospasm risk'),
(82, 95, 'MODERATE', 'Amlodipine + Propranolol: additive BP lowering'),
(12, 17, 'MODERATE', 'Topical Diclofenac + oral Ibuprofen: additive NSAID effect'),
(98, 102, 'MODERATE', 'Glimepiride + Insulin: hypoglycemia risk'),
(99, 102, 'MODERATE', 'Gliclazide + Insulin: hypoglycemia risk'),
(11, 149, 'MODERATE', 'Diclofenac + Dexamethasone injection: ulcer risk'),
(17, 148, 'MODERATE', 'Ibuprofen + Dexamethasone: ulcer risk'),
(142, 143, 'MODERATE', 'Gabapentin + Phenobarbital: additive CNS depression'),
(142, 144, 'MODERATE', 'Gabapentin + Carbamazepine: additive CNS depression');