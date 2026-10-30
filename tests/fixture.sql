-- Minimale TYPO3-13-ähnliche Datenbank für den Selbsttest (tests/run.sh)
CREATE TABLE pages (uid int PRIMARY KEY, pid int, title varchar(255), slug varchar(255), doktype int DEFAULT 1,
  hidden tinyint DEFAULT 0, deleted tinyint DEFAULT 0, is_siteroot tinyint DEFAULT 0, extendToSubpages tinyint DEFAULT 0,
  sys_language_uid int DEFAULT 0, l10n_parent int DEFAULT 0, t3ver_wsid int DEFAULT 0, starttime int DEFAULT 0, endtime int DEFAULT 0);
CREATE TABLE tt_content (uid int PRIMARY KEY, pid int, header varchar(255), CType varchar(255), colPos int DEFAULT 0,
  bodytext mediumtext, hidden tinyint DEFAULT 0, deleted tinyint DEFAULT 0, sys_language_uid int DEFAULT 0, t3ver_wsid int DEFAULT 0,
  starttime int DEFAULT 0, endtime int DEFAULT 0, crdate int, tstamp int);
CREATE TABLE sys_template (uid int PRIMARY KEY, pid int, title varchar(255), config text, hidden tinyint DEFAULT 0, deleted tinyint DEFAULT 0);
CREATE TABLE sys_file_storage (uid int PRIMARY KEY, name varchar(255), configuration text, deleted tinyint DEFAULT 0);
CREATE TABLE sys_file (uid int PRIMARY KEY, storage int, identifier varchar(512), name varchar(255), size int, sha1 char(40), missing tinyint DEFAULT 0);
CREATE TABLE sys_file_metadata (uid int PRIMARY KEY, file int, title varchar(255), description text);
CREATE TABLE sys_file_reference (uid int PRIMARY KEY, pid int, uid_local int, uid_foreign int, tablenames varchar(64), fieldname varchar(64),
  deleted tinyint DEFAULT 0, hidden tinyint DEFAULT 0, t3ver_wsid int DEFAULT 0, link varchar(1024) DEFAULT '');
CREATE TABLE sys_refindex (hash varchar(32), tablename varchar(64), recuid int, field varchar(64), softref_key varchar(30),
  ref_table varchar(64), ref_uid int, workspace int DEFAULT 0);
CREATE TABLE sys_history (uid int PRIMARY KEY AUTO_INCREMENT, tstamp int, actiontype int, userid int, recuid int, tablename varchar(64), history_data mediumtext);
CREATE TABLE sys_log (uid int PRIMARY KEY AUTO_INCREMENT, tstamp int, userid int, type int, action int, tablename varchar(64), recuid int, details text, log_data text);
CREATE TABLE sys_filemounts (uid int PRIMARY KEY, pid int, title varchar(255), identifier varchar(255), deleted tinyint DEFAULT 0);
CREATE TABLE be_users (uid int PRIMARY KEY, username varchar(50), realName varchar(80));

INSERT INTO sys_file_storage VALUES (1, 'fileadmin', '<?xml version="1.0"?><T3FlexForms><data><sheet index="sDEF"><language index="lDEF"><field index="basePath"><value index="vDEF">fileadmin/</value></field></language></sheet></data></T3FlexForms>', 0);
INSERT INTO be_users VALUES (1, 'admin', 'Anna Admin'), (2, 'redakteur', 'Rolf Redakteur');

-- Site A (Root 1) mit Unterseiten, Site B (Root 10), Ordner 20 ohne Site
INSERT INTO pages (uid, pid, title, slug, doktype, hidden, deleted, is_siteroot) VALUES
 (1, 0, 'Site A', '/', 1, 0, 0, 1),
 (2, 1, 'Aktuelles', '/aktuelles', 1, 0, 0, 0),
 (3, 1, 'Versteckt', '/versteckt', 1, 1, 0, 0),
 (4, 1, 'Gelöscht', '/geloescht', 1, 0, 1, 0),
 (5, 1, 'Datenordner', '/daten', 254, 0, 0, 0),
 (10, 0, 'Site B', '/', 1, 0, 0, 1),
 (11, 10, 'Produkte', '/produkte', 1, 0, 0, 0),
 (20, 0, 'Altlasten', '/', 254, 0, 0, 0);

INSERT INTO tt_content (uid, pid, header, CType, bodytext, hidden, deleted, crdate, tstamp) VALUES
 (100, 2, 'Bild aktiv', 'textmedia', '', 0, 0, 1300000000, 1600000000),
 (101, 3, 'Auf versteckter Seite', 'textmedia', '', 0, 0, 1300000000, 1600000000),
 (102, 4, 'Auf gelöschter Seite', 'textmedia', '', 0, 0, 1300000000, 1600000000),
 (103, 11, 'Gelöschtes Element', 'uploads', '', 0, 1, 1300000000, 1600000000),
 (104, 11, 'RTE mit Links', 'text', '<p><a href="t3://file?uid=6">PDF</a> und <img src="fileadmin/_migrated/pics/hartkodiert.jpg"> und <a href="/fileadmin/_migrated/media/gibtsnicht.pdf">x</a></p>', 0, 0, 1300000000, 1600000000),
 (105, 5, 'Im Ordner', 'textmedia', '', 0, 0, 1300000000, 1600000000),
 (106, 20, 'Ohne Site', 'textmedia', '', 0, 0, 1300000000, 1600000000);

INSERT INTO sys_template VALUES (1, 1, 'Main', 'page.logo.file = fileadmin/_migrated/pics/logo%20alt.png', 0, 0);
INSERT INTO sys_filemounts VALUES (1, 0, 'Alte Bilder', '1:/_migrated/pics/', 0);

INSERT INTO sys_file (uid, storage, identifier, name, size, sha1) VALUES
 (1, 1, '/_migrated/pics/aktiv.jpg', 'aktiv.jpg', 5, ''),
 (2, 1, '/_migrated/pics/versteckt.jpg', 'versteckt.jpg', 5, ''),
 (3, 1, '/_migrated/pics/geloeschte_seite.jpg', 'geloeschte_seite.jpg', 5, ''),
 (4, 1, '/_migrated/content_uploads/geloeschtes_element.pdf', 'geloeschtes_element.pdf', 5, ''),
 (5, 1, '/_migrated/content_uploads/unbenutzt.pdf', 'unbenutzt.pdf', 5, ''),
 (6, 1, '/_migrated/media/rte_link.pdf', 'rte_link.pdf', 5, ''),
 (7, 1, '/_migrated/pics/fehlt.jpg', 'fehlt.jpg', 5, 'ffff'),
 (8, 1, '/_migrated/pics/ordner.jpg', 'ordner.jpg', 5, ''),
 (9, 1, '/_migrated/pics/ohne_site.jpg', 'ohne_site.jpg', 5, ''),
 (10, 1, '/user_upload/kopie.pdf', 'kopie.pdf', 5, 'SHA_UNBENUTZT'),
 (11, 1, '/_migrated/media/logclick.pdf', 'logclick.pdf', 5, '');
UPDATE sys_file SET sha1 = SHA1('unbenutzt-inhalt\n') WHERE uid = 10;

INSERT INTO sys_file_reference (uid, pid, uid_local, uid_foreign, tablenames, fieldname, deleted, hidden) VALUES
 (1000, 2, 1, 100, 'tt_content', 'assets', 0, 0),
 (1001, 3, 2, 101, 'tt_content', 'assets', 0, 0),
 (1002, 4, 3, 102, 'tt_content', 'assets', 0, 0),
 (1003, 11, 4, 103, 'tt_content', 'media', 0, 0),
 (1004, 2, 7, 100, 'tt_content', 'assets', 0, 0),
 (1005, 5, 8, 105, 'tt_content', 'assets', 0, 0),
 (1006, 20, 9, 106, 'tt_content', 'assets', 0, 0),
 (1007, 2, 5, 100, 'tt_content', 'assets', 1, 0);

INSERT INTO sys_refindex (hash, tablename, recuid, field, softref_key, ref_table, ref_uid) VALUES
 ('a', 'tt_content', 104, 'bodytext', 'typolink_tag', 'sys_file', 6),
 ('b', 'sys_file_reference', 1000, 'uid_local', '', 'sys_file', 1);

INSERT INTO sys_history (tstamp, actiontype, userid, recuid, tablename) VALUES
 (1500000000, 1, 2, 100, 'tt_content'), (1600000000, 2, 1, 100, 'tt_content'), (1500000100, 1, 2, 1000, 'sys_file_reference');
INSERT INTO sys_log (tstamp, userid, type, action, tablename, recuid, details, log_data) VALUES
 (1650000000, 1, 2, 3, '', 0, 'File "%s" deleted', '["\\/_migrated\\/pics\\/versteckt.jpg"]');
