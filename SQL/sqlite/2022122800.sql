CREATE TABLE kolab_cache_dav_contact (
  folder_id INTEGER NOT NULL,
  uid VARCHAR(512) NOT NULL,
  etag VARCHAR(128) NOT NULL,
  created DATETIME DEFAULT NULL,
  changed DATETIME DEFAULT NULL,
  data TEXT NOT NULL,
  tags TEXT NOT NULL,
  words TEXT NOT NULL,
  type VARCHAR(32) NOT NULL,
  name VARCHAR(255) NOT NULL,
  firstname VARCHAR(255) NOT NULL,
  surname VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL,
  PRIMARY KEY(folder_id, uid)
);

CREATE INDEX ix_contact_type ON kolab_cache_dav_contact(folder_id, type);

CREATE TABLE kolab_cache_dav_event (
  folder_id INTEGER NOT NULL,
  uid VARCHAR(512) NOT NULL,
  etag VARCHAR(128) NOT NULL,
  created DATETIME DEFAULT NULL,
  changed DATETIME DEFAULT NULL,
  data TEXT NOT NULL,
  tags TEXT NOT NULL,
  words TEXT NOT NULL,
  dtstart DATETIME,
  dtend DATETIME,
  PRIMARY KEY(folder_id, uid)
);

CREATE TABLE kolab_cache_dav_task (
  folder_id INTEGER NOT NULL,
  uid VARCHAR(512) NOT NULL,
  etag VARCHAR(128) NOT NULL,
  created DATETIME DEFAULT NULL,
  changed DATETIME DEFAULT NULL,
  data TEXT NOT NULL,
  tags TEXT NOT NULL,
  words TEXT NOT NULL,
  dtstart DATETIME,
  dtend DATETIME,
  PRIMARY KEY(folder_id, uid)
);
