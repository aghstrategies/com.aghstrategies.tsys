-- install sql for iATS Services extension, create a table to hold custom codes

CREATE TABLE `civicrm_tsys_recur` (
  `id` int unsigned NOT NULL AUTO_INCREMENT  COMMENT 'Id',
  `vault_token` varchar(100) NOT NULL COMMENT 'Vault Token returned from TSYS',
  `recur_id` int(10) unsigned DEFAULT '0' COMMENT 'CiviCRM recurring_contribution table id',
  `identifier` varchar(255) DEFAULT 'CARD last 4' COMMENT 'Not used currently could be used to store identifying info for card',
  PRIMARY KEY ( `id` ),
  KEY (`recur_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Table to store vault tokens';
