DROP TABLE IF EXISTS `kolab_cache_dav_contact`;

CREATE TABLE `kolab_cache_dav_contact` (
  `folder_id` BIGINT UNSIGNED NOT NULL,
  `uid` VARCHAR(512) NOT NULL,
  `etag` VARCHAR(128) DEFAULT NULL,
  `created` DATETIME DEFAULT NULL,
  `changed` DATETIME DEFAULT NULL,
  `data` LONGTEXT NOT NULL,
  `tags` TEXT NOT NULL,
  `words` TEXT NOT NULL,
  `type` VARCHAR(32) CHARACTER SET ascii NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `firstname` VARCHAR(255) NOT NULL,
  `surname` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  CONSTRAINT `fk_kolab_cache_dav_contact_folder` FOREIGN KEY (`folder_id`)
    REFERENCES `kolab_folders`(`folder_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  PRIMARY KEY(`folder_id`,`uid`),
  INDEX `contact_type` (`folder_id`,`type`)
) ROW_FORMAT=DYNAMIC ENGINE=INNODB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `kolab_cache_dav_event`;

CREATE TABLE `kolab_cache_dav_event` (
  `folder_id` BIGINT UNSIGNED NOT NULL,
  `uid` VARCHAR(512) NOT NULL,
  `etag` VARCHAR(128) DEFAULT NULL,
  `created` DATETIME DEFAULT NULL,
  `changed` DATETIME DEFAULT NULL,
  `data` LONGTEXT NOT NULL,
  `tags` TEXT NOT NULL,
  `words` TEXT NOT NULL,
  `dtstart` DATETIME,
  `dtend` DATETIME,
  CONSTRAINT `fk_kolab_cache_dav_event_folder` FOREIGN KEY (`folder_id`)
    REFERENCES `kolab_folders`(`folder_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  PRIMARY KEY(`folder_id`,`uid`)
) ROW_FORMAT=DYNAMIC ENGINE=INNODB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
