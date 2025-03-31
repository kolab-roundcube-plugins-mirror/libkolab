CREATE SEQUENCE kolab_folders_seq
    INCREMENT BY 1
    NO MAXVALUE
    NO MINVALUE
    CACHE 1;

CREATE TABLE kolab_folders (
    folder_id integer DEFAULT nextval('kolab_folders_seq'::text) PRIMARY KEY,
    resource varchar(255) NOT NULL,
    "type" varchar(32) NOT NULL,
    synclock integer NOT NULL DEFAULT 0,
    ctag varchar(128) DEFAULT NULL,
    changed timestamp with time zone DEFAULT NULL
);

CREATE INDEX kolab_folders_resource_type_idx ON kolab_folders(resource, "type");

CREATE TABLE kolab_cache_contact (
    folder_id integer NOT NULL
        REFERENCES kolab_folders (folder_id) ON DELETE CASCADE ON UPDATE CASCADE,
    msguid integer NOT NULL,
    uid varchar(512) NOT NULL,
    created timestamp with time zone DEFAULT NULL,
    changed timestamp with time zone DEFAULT NULL,
    data text NOT NULL,
    tags text NOT NULL,
    words text NOT NULL,
    "type" varchar(32) NOT NULL,
    name varchar(255) NOT NULL,
    firstname varchar(255) NOT NULL,
    surname varchar(255) NOT NULL,
    email varchar(255) NOT NULL,
    PRIMARY KEY(folder_id, msguid)
);

CREATE INDEX kolab_cache_contact_type_idx ON kolab_cache_contact(folder_id, "type");
CREATE INDEX kolab_cache_contact_uid2msguid_idx ON kolab_cache_contact(folder_id, uid, msguid);

CREATE TABLE kolab_cache_event (
    folder_id integer NOT NULL
        REFERENCES kolab_folders (folder_id) ON DELETE CASCADE ON UPDATE CASCADE,
    msguid integer NOT NULL,
    uid varchar(512) NOT NULL,
    created timestamp with time zone DEFAULT NULL,
    changed timestamp with time zone DEFAULT NULL,
    data text NOT NULL,
    tags text NOT NULL,
    words text NOT NULL,
    dtstart timestamp with time zone,
    dtend timestamp with time zone,
    PRIMARY KEY(folder_id, msguid)
);

CREATE INDEX kolab_cache_event_uid2msguid_idx ON kolab_cache_event(folder_id, uid, msguid);

CREATE TABLE kolab_cache_task (
    folder_id integer NOT NULL
        REFERENCES kolab_folders (folder_id) ON DELETE CASCADE ON UPDATE CASCADE,
    msguid integer NOT NULL,
    uid varchar(512) NOT NULL,
    created timestamp with time zone DEFAULT NULL,
    changed timestamp with time zone DEFAULT NULL,
    data text NOT NULL,
    tags text NOT NULL,
    words text NOT NULL,
    dtstart timestamp with time zone,
    dtend timestamp with time zone,
    PRIMARY KEY(folder_id, msguid)
);

CREATE INDEX kolab_cache_task_uid2msguid_idx ON kolab_cache_task(folder_id, uid, msguid);

CREATE TABLE kolab_cache_journal (
    folder_id integer NOT NULL
        REFERENCES kolab_folders (folder_id) ON DELETE CASCADE ON UPDATE CASCADE,
    msguid integer NOT NULL,
    uid varchar(512) NOT NULL,
    created timestamp with time zone DEFAULT NULL,
    changed timestamp with time zone DEFAULT NULL,
    data text NOT NULL,
    tags text NOT NULL,
    words text NOT NULL,
    dtstart timestamp with time zone,
    dtend timestamp with time zone,
    PRIMARY KEY(folder_id, msguid)
);

CREATE INDEX kolab_cache_journal_uid2msguid_idx ON kolab_cache_journal(folder_id, uid, msguid);

CREATE TABLE kolab_cache_note (
    folder_id integer NOT NULL
        REFERENCES kolab_folders (folder_id) ON DELETE CASCADE ON UPDATE CASCADE,
    msguid integer NOT NULL,
    uid varchar(512) NOT NULL,
    created timestamp with time zone DEFAULT NULL,
    changed timestamp with time zone DEFAULT NULL,
    data text NOT NULL,
    tags text NOT NULL,
    words text NOT NULL,
    PRIMARY KEY(folder_id, msguid)
);

CREATE INDEX kolab_cache_note_uid2msguid_idx ON kolab_cache_note(folder_id, uid, msguid);

CREATE TABLE kolab_cache_file (
    folder_id integer NOT NULL
        REFERENCES kolab_folders (folder_id) ON DELETE CASCADE ON UPDATE CASCADE,
    msguid integer NOT NULL,
    uid varchar(512) NOT NULL,
    created timestamp with time zone DEFAULT NULL,
    changed timestamp with time zone DEFAULT NULL,
    data text NOT NULL,
    tags text NOT NULL,
    words text NOT NULL,
    filename varchar(255) DEFAULT NULL,
    PRIMARY KEY(folder_id, msguid)
);

CREATE INDEX kolab_cache_file_filename_idx ON kolab_cache_file(folder_id, filename);
CREATE INDEX kolab_cache_file_uid2msguid_idx ON kolab_cache_file(folder_id, uid, msguid);

CREATE TABLE kolab_cache_configuration (
    folder_id integer NOT NULL
        REFERENCES kolab_folders (folder_id) ON DELETE CASCADE ON UPDATE CASCADE,
    msguid integer NOT NULL,
    uid varchar(512) NOT NULL,
    created timestamp with time zone DEFAULT NULL,
    changed timestamp with time zone DEFAULT NULL,
    data text NOT NULL,
    tags text NOT NULL,
    words text NOT NULL,
    "type" varchar(32) NOT NULL,
    PRIMARY KEY(folder_id, msguid)
);

CREATE INDEX kolab_cache_configuration_type_idx ON kolab_cache_configuration(folder_id, "type");
CREATE INDEX kolab_cache_configuration_uid2msguid_idx ON kolab_cache_configuration(folder_id, uid, msguid);

CREATE TABLE kolab_cache_freebusy (
    folder_id integer NOT NULL
        REFERENCES kolab_folders (folder_id) ON DELETE CASCADE ON UPDATE CASCADE,
    msguid integer NOT NULL,
    uid varchar(512) NOT NULL,
    created timestamp with time zone DEFAULT NULL,
    changed timestamp with time zone DEFAULT NULL,
    data text NOT NULL,
    tags text NOT NULL,
    words text NOT NULL,
    dtstart timestamp with time zone,
    dtend timestamp with time zone,
    PRIMARY KEY(folder_id, msguid)
);

CREATE INDEX kolab_cache_freebusy_uid2msguid_idx ON kolab_cache_freebusy(folder_id, uid, msguid);

CREATE TABLE kolab_cache_dav_contact (
    folder_id integer NOT NULL
        REFERENCES kolab_folders (folder_id) ON DELETE CASCADE ON UPDATE CASCADE,
    uid varchar(512) NOT NULL,
    etag varchar(128) NOT NULL,
    created timestamp with time zone DEFAULT NULL,
    changed timestamp with time zone DEFAULT NULL,
    data text NOT NULL,
    tags text NOT NULL,
    words text NOT NULL,
    "type" varchar(32) NOT NULL,
    name varchar(255) NOT NULL,
    firstname varchar(255) NOT NULL,
    surname varchar(255) NOT NULL,
    email varchar(255) NOT NULL,
    PRIMARY KEY(folder_id, uid)
);

CREATE INDEX kolab_cache_dav_contact_type_idx ON kolab_cache_dav_contact(folder_id, "type");

CREATE TABLE kolab_cache_dav_event (
    folder_id integer NOT NULL
        REFERENCES kolab_folders (folder_id) ON DELETE CASCADE ON UPDATE CASCADE,
    uid varchar(512) NOT NULL,
    etag varchar(128) NOT NULL,
    created timestamp with time zone DEFAULT NULL,
    changed timestamp with time zone DEFAULT NULL,
    data text NOT NULL,
    tags text NOT NULL,
    words text NOT NULL,
    dtstart timestamp with time zone,
    dtend timestamp with time zone,
    PRIMARY KEY(folder_id, uid)
);

CREATE TABLE kolab_cache_dav_task (
    folder_id integer NOT NULL
        REFERENCES kolab_folders (folder_id) ON DELETE CASCADE ON UPDATE CASCADE,
    uid varchar(512) NOT NULL,
    etag varchar(128) NOT NULL,
    created timestamp with time zone DEFAULT NULL,
    changed timestamp with time zone DEFAULT NULL,
    data text NOT NULL,
    tags text NOT NULL,
    words text NOT NULL,
    dtstart timestamp with time zone,
    dtend timestamp with time zone,
    PRIMARY KEY(folder_id, uid)
);

INSERT INTO "system" (name, "value") VALUES ('libkolab-version', '2023111200');
