-- changing table format and dropping foreign keys is needed for some versions of MySQL
ALTER TABLE `kolab_cache_contact` DROP FOREIGN KEY `fk_kolab_cache_contact_folder`;
ALTER TABLE `kolab_cache_event` DROP FOREIGN KEY`fk_kolab_cache_event_folder`;
ALTER TABLE `kolab_cache_task` DROP FOREIGN KEY`fk_kolab_cache_task_folder`;
ALTER TABLE `kolab_cache_journal` DROP FOREIGN KEY`fk_kolab_cache_journal_folder`;
ALTER TABLE `kolab_cache_note` DROP FOREIGN KEY`fk_kolab_cache_note_folder`;
ALTER TABLE `kolab_cache_file` DROP FOREIGN KEY`fk_kolab_cache_file_folder`;
ALTER TABLE `kolab_cache_configuration` DROP FOREIGN KEY`fk_kolab_cache_configuration_folder`;
ALTER TABLE `kolab_cache_freebusy` DROP FOREIGN KEY`fk_kolab_cache_freebusy_folder`;

ALTER TABLE `kolab_folders` ROW_FORMAT=DYNAMIC;
ALTER TABLE `kolab_cache_contact` ROW_FORMAT=DYNAMIC;
ALTER TABLE `kolab_cache_event` ROW_FORMAT=DYNAMIC;
ALTER TABLE `kolab_cache_task` ROW_FORMAT=DYNAMIC;
ALTER TABLE `kolab_cache_journal` ROW_FORMAT=DYNAMIC;
ALTER TABLE `kolab_cache_note` ROW_FORMAT=DYNAMIC;
ALTER TABLE `kolab_cache_file` ROW_FORMAT=DYNAMIC;
ALTER TABLE `kolab_cache_configuration` ROW_FORMAT=DYNAMIC;
ALTER TABLE `kolab_cache_freebusy` ROW_FORMAT=DYNAMIC;

ALTER TABLE `kolab_folders` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `kolab_cache_contact` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `kolab_cache_event` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `kolab_cache_task` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `kolab_cache_journal` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `kolab_cache_note` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `kolab_cache_file` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `kolab_cache_configuration` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE `kolab_cache_freebusy` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `kolab_cache_contact` ADD CONSTRAINT `fk_kolab_cache_contact_folder` FOREIGN KEY (`folder_id`)
    REFERENCES `kolab_folders`(`folder_id`) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE `kolab_cache_event` ADD CONSTRAINT `fk_kolab_cache_event_folder` FOREIGN KEY (`folder_id`)
    REFERENCES `kolab_folders`(`folder_id`) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE `kolab_cache_task` ADD CONSTRAINT `fk_kolab_cache_task_folder` FOREIGN KEY (`folder_id`)
    REFERENCES `kolab_folders`(`folder_id`) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE `kolab_cache_journal` ADD CONSTRAINT `fk_kolab_cache_journal_folder` FOREIGN KEY (`folder_id`)
    REFERENCES `kolab_folders`(`folder_id`) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE `kolab_cache_note` ADD CONSTRAINT `fk_kolab_cache_note_folder` FOREIGN KEY (`folder_id`)
    REFERENCES `kolab_folders`(`folder_id`) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE `kolab_cache_file` ADD CONSTRAINT `fk_kolab_cache_file_folder` FOREIGN KEY (`folder_id`)
    REFERENCES `kolab_folders`(`folder_id`) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE `kolab_cache_configuration` ADD CONSTRAINT `fk_kolab_cache_configuration_folder` FOREIGN KEY (`folder_id`)
    REFERENCES `kolab_folders`(`folder_id`) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE `kolab_cache_freebusy` ADD CONSTRAINT `fk_kolab_cache_freebusy_folder` FOREIGN KEY (`folder_id`)
    REFERENCES `kolab_folders`(`folder_id`) ON DELETE CASCADE ON UPDATE CASCADE;
