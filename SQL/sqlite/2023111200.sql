PRAGMA foreign_keys=OFF;

ALTER TABLE kolab_folders RENAME TO kolab_folders_old;

CREATE TABLE kolab_folders (
  folder_id INTEGER NOT NULL PRIMARY KEY,
  resource VARCHAR(255) NOT NULL,
  type VARCHAR(32) NOT NULL,
  synclock INTEGER NOT NULL DEFAULT '0',
  ctag VARCHAR(128) DEFAULT NULL,
  changed DATETIME DEFAULT NULL
);

INSERT INTO kolab_folders (folder_id, resource, type, synclock, ctag, changed)
    SELECT folder_id, resource, type, synclock, ctag, changed FROM kolab_folders_old;

CREATE INDEX ix_resource_type ON kolab_folders(resource, type);

DROP TABLE kolab_folders_old;
