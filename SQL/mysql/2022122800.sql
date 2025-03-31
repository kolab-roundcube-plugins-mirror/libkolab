DROP TABLE IF EXISTS `kolab_cache_dav_task`;

CREATE TABLE `kolab_cache_dav_task` (
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
  CONSTRAINT `fk_kolab_cache_dav_task_folder` FOREIGN KEY (`folder_id`)
    REFERENCES `kolab_folders`(`folder_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  PRIMARY KEY(`folder_id`,`uid`)
) ROW_FORMAT=DYNAMIC ENGINE=INNODB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
