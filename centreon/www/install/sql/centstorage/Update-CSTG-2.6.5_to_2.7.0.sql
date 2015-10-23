ALTER TABLE `hoststateevents` ADD COLUMN `in_ack` tinyint(4) DEFAULT '0';
ALTER TABLE `servicestateevents` ADD COLUMN `in_ack` tinyint(4) DEFAULT '0';

-- Ticket #2276
ALTER TABLE config ENGINE=InnoDB;
ALTER TABLE data_stats_daily ENGINE=InnoDB;
ALTER TABLE data_stats_monthly ENGINE=InnoDB;
ALTER TABLE data_stats_yearly ENGINE=InnoDB;
ALTER TABLE index_data ENGINE=InnoDB;
ALTER TABLE instance ENGINE=InnoDB;
ALTER TABLE log_action ENGINE=InnoDB;
ALTER TABLE log_action_modification ENGINE=InnoDB;
ALTER TABLE log_archive_last_status ENGINE=InnoDB;
ALTER TABLE log_archive_service ENGINE=InnoDB;
ALTER TABLE log_snmptt ENGINE=InnoDB;
ALTER TABLE metrics ENGINE=InnoDB;
ALTER TABLE statistics ENGINE=InnoDB;

ALTER TABLE centreon_acl DROP COLUMN host_name;
ALTER TABLE centreon_acl DROP COLUMN service_description;

ALTER TABLE `config` ADD COLUMN `len_storage_downtimes` int(11) DEFAULT NULL,  ADD COLUMN `len_storage_comments` int(11) DEFAULT NULL;

UPDATE logs SET status = NULL WHERE status = 5;