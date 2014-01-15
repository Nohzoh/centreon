ALTER TABLE `options` ADD COLUMN `group` VARCHAR(255) NOT NULL DEFAULT 'default' FIRST;

-- Ticket #5106
CREATE TABLE `hooks` (
	`hook_id` int(11) NOT NULL AUTO_INCREMENT,
	`hook_name` varchar(255),
	`hook_description` varchar(255),
	`hook_type` tinyint(1) DEFAULT 0,
	PRIMARY KEY(`hook_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `module_hooks` (
	`module_id` int(11) NOT NULL,
	`hook_id` int(11) NOT NULL,
	`module_hook_name` varchar(255) NOT NULL,
	`module_hook_description` varchar(255) NOT NULL,
	KEY `module_id` (`module_id`),
	KEY `hook_id` (`hook_id`),
	CONSTRAINT `fk_module_id` FOREIGN KEY (`module_id`) REFERENCES `modules_informations` (`id`) ON DELETE CASCADE,
	CONSTRAINT `fk_hook_id` FOREIGN KEY (`hook_id`) REFERENCES `hooks` (`hook_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
--
