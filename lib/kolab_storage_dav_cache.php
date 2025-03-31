<?php

/**
 * Kolab storage cache class providing a local caching layer for Kolab groupware objects.
 *
 * @author Aleksander Machniak <machniak@apheleia-it.ch>
 *
 * Copyright (C) 2012-2022, Apheleia IT AG <contact@apheleia-it.ch>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

class kolab_storage_dav_cache extends kolab_storage_cache
{
    /**
     * Factory constructor
     */
    public static function factory(kolab_storage_folder $storage_folder)
    {
        $subclass = 'kolab_storage_dav_cache_' . $storage_folder->type;
        if (class_exists($subclass)) {
            return new $subclass($storage_folder);
        }

        rcube::raise_error(
            ['code' => 900, 'message' => "No {$subclass} class found for folder '{$storage_folder->name}'"],
            true
        );

        return new kolab_storage_dav_cache($storage_folder);
    }

    /**
     * Connect cache with a storage folder
     *
     * @param kolab_storage_folder $storage_folder The storage folder instance to connect with
     */
    public function set_folder(kolab_storage_folder $storage_folder)
    {
        $this->folder = $storage_folder;

        if (!$this->folder->valid) {
            $this->ready = false;
            return;
        }

        // compose fully qualified ressource uri for this instance
        $this->resource_uri = $this->folder->get_resource_uri();
        $this->cache_table = $this->db->table_name('kolab_cache_dav_' . $this->folder->type);
        $this->ready = true;
    }

    /**
     * Synchronize local cache data with remote
     */
    public function synchronize()
    {
        // only sync once per request cycle
        if ($this->synched) {
            return;
        }

        $this->sync_start = time();

        // read cached folder metadata
        $this->_read_folder_data();

        $ctag = $this->folder->get_ctag();

        // check cache status ($this->metadata is set in _read_folder_data())
        if (
            empty($this->metadata['ctag'])
            || empty($this->metadata['changed'])
            || $this->metadata['ctag'] !== $ctag
        ) {
            // lock synchronization for this folder and wait if already locked
            $this->_sync_lock();

            $result = $this->synchronize_worker();

            // update ctag value (will be written to database in _sync_unlock())
            if ($result) {
                $this->metadata['ctag']    = $ctag;
                $this->metadata['changed'] = date(self::DB_DATE_FORMAT, time());
            }

            // remove lock
            $this->_sync_unlock();
        }

        $this->synched = time();
    }

    /**
     * Perform cache synchronization
     */
    protected function synchronize_worker()
    {
        // get effective time limit we have for synchronization (~70% of the execution time)
        $time_limit = $this->_max_sync_lock_time() * 0.7;

        if (time() - $this->sync_start > $time_limit) {
            return false;
        }

        // TODO: Implement synchronization with use of WebDAV-Sync (RFC 6578)

        // Get the objects from the DAV server
        $dav_index = $this->folder->dav->getIndex($this->folder->href, $this->folder->get_dav_type());

        if (!is_array($dav_index)) {
            rcube::raise_error([
                    'code' => 900,
                    'message' => "Failed to sync the kolab cache for {$this->folder->href}",
                ], true);
            return false;
        }

        // WARNING: For now we assume object's href is <calendar-href>/<uid>.ics,
        //          which would mean there are no duplicates (objects with the same uid).
        //          With DAV protocol we can't get UID without fetching the whole object.
        //          Also the folder_id + uid is a unique index in the database.
        //          In the future we maybe should store the href in database.

        // Determine objects to fetch or delete
        $new_index    = [];
        $update_index = [];
        $old_index    = $this->folder_index(); // uid -> etag
        $chunk_size   = 20; // max numer of objects per DAV request

        foreach ($dav_index as $object) {
            $uid = $object['uid'];
            if (isset($old_index[$uid])) {
                $old_etag = $old_index[$uid];
                $old_index[$uid] = null;

                if ($old_etag === $object['etag']) {
                    // the object didn't change
                    continue;
                }

                $update_index[$uid] = $object['href'];
            } else {
                $new_index[$uid] = $object['href'];
            }
        }

        $i = 0;

        // Fetch new objects and store in DB
        if (!empty($new_index)) {
            foreach (array_chunk($new_index, $chunk_size, true) as $chunk) {
                $objects = $this->folder->dav->getData($this->folder->href, $this->folder->get_dav_type(), $chunk);

                if (!is_array($objects)) {
                    rcube::raise_error([
                            'code' => 900,
                            'message' => "Failed to sync the kolab cache for {$this->folder->href}",
                        ], true);
                    return false;
                }

                foreach ($objects as $dav_object) {
                    if ($object = $this->folder->from_dav($dav_object)) {
                        $object['_raw'] = $dav_object['data'];
                        $this->_extended_insert(0, $object);
                        unset($object['_raw']);
                    }
                }

                $this->_extended_insert(1, null);

                // check time limit and abort sync if running too long
                if (++$i % 25 == 0 && time() - $this->sync_start > $time_limit) {
                    return false;
                }
            }
        }

        // Fetch updated objects and store in DB
        if (!empty($update_index)) {
            foreach (array_chunk($update_index, $chunk_size, true) as $chunk) {
                $objects = $this->folder->dav->getData($this->folder->href, $this->folder->get_dav_type(), $chunk);

                if (!is_array($objects)) {
                    rcube::raise_error([
                            'code' => 900,
                            'message' => "Failed to sync the kolab cache for {$this->folder->href}",
                        ], true);
                    return false;
                }

                foreach ($objects as $dav_object) {
                    if ($object = $this->folder->from_dav($dav_object)) {
                        $object['_raw'] = $dav_object['data'];
                        $this->save($object, $object['uid']);
                        unset($object['_raw']);
                    }
                }

                // check time limit and abort sync if running too long
                if (++$i % 25 == 0 && time() - $this->sync_start > $time_limit) {
                    return false;
                }
            }
        }

        // Remove deleted objects
        $old_index = array_filter($old_index);
        if (!empty($old_index)) {
            $quoted_uids = implode(',', array_map([$this->db, 'quote'], $old_index));
            $this->db->query(
                "DELETE FROM `{$this->cache_table}` WHERE `folder_id` = ? AND `uid` IN ($quoted_uids)",
                $this->folder_id
            );
        }

        return true;
    }

    /**
     * Return current folder index (uid -> etag)
     */
    public function folder_index()
    {
        $this->_read_folder_data();

        // read cache index
        $sql_result = $this->db->query(
            "SELECT `uid`, `etag` FROM `{$this->cache_table}` WHERE `folder_id` = ?",
            $this->folder_id
        );

        $index = [];

        while ($sql_arr = $this->db->fetch_assoc($sql_result)) {
            $index[$sql_arr['uid']] = $sql_arr['etag'];
        }

        return $index;
    }

    /**
     * Read a single entry from cache or from server directly
     *
     * @param string $uid    Object UID
     * @param string $type   Object type to read
     * @param string $unused Unused (kept for compat. with the parent class)
     *
     * @return null|array An array of objects, NULL if not found
     */
    public function get($uid, $type = null, $unused = null)
    {
        if ($this->ready) {
            $this->_read_folder_data();

            $sql_result = $this->db->query(
                "SELECT * FROM `{$this->cache_table}` WHERE `folder_id` = ? AND `uid` = ?",
                $this->folder_id,
                $uid
            );

            if ($sql_arr = $this->db->fetch_assoc($sql_result)) {
                $object = $this->_unserialize($sql_arr);
            }
        }

        // fetch from DAV if not present in cache
        if (empty($object)) {
            if ($object = $this->folder->read_object($uid, $type ?: '*')) {
                $this->save($object);
            }
        }

        return $object ?: null;
    }

    /**
     * Read multiple entries from the server directly
     *
     * @param array $uids Object UIDs
     *
     * @return false|array An array of objects, False on error
     */
    public function multiget($uids)
    {
        return $this->folder->read_objects($uids);
    }

    /**
     * Insert/Update a cache entry
     *
     * @param string      $uid    Object UID
     * @param array|false $object Hash array with object properties to save or false to delete the cache entry
     * @param string      $unused Unused (kept for compat. with the parent class)
     */
    public function set($uid, $object, $unused = null)
    {
        // remove old entry
        if ($this->ready) {
            $this->_read_folder_data();

            $this->db->query(
                "DELETE FROM `{$this->cache_table}` WHERE `folder_id` = ? AND `uid` = ?",
                $this->folder_id,
                $uid
            );
        }

        if ($object) {
            $this->save($object);
        }
    }

    /**
     * Insert (or update) a cache entry
     *
     * @param mixed  $object Hash array with object properties to save or false to delete the cache entry
     * @param string $olduid Optional old message UID (for update)
     */
    public function save($object, $olduid = null, $unused = null)
    {
        // write to cache
        if ($this->ready) {
            $this->_read_folder_data();

            $sql_data              = $this->_serialize($object);
            $sql_data['folder_id'] = $this->folder_id;
            $sql_data['uid']       = rcube_charset::clean($object['uid']);
            $sql_data['etag']      = rcube_charset::clean($object['etag']);

            $args = [];
            $cols = ['folder_id', 'uid', 'etag', 'changed', 'data', 'tags', 'words'];
            $cols = array_merge($cols, $this->extra_cols);

            foreach ($cols as $idx => $col) {
                $cols[$idx] = $this->db->quote_identifier($col);
                $args[]     = $sql_data[$col];
            }

            if ($olduid) {
                foreach ($cols as $idx => $col) {
                    $cols[$idx] = "$col = ?";
                }

                $query = "UPDATE `{$this->cache_table}` SET " . implode(', ', $cols)
                    . " WHERE `folder_id` = ? AND `uid` = ?";
                $args[] = $this->folder_id;
                $args[] = $olduid;
            } else {
                $query = "INSERT INTO `{$this->cache_table}` (`created`, " . implode(', ', $cols)
                    . ") VALUES (" . $this->db->now() . str_repeat(', ?', count($cols)) . ")";
            }

            $result = $this->db->query($query, $args);

            if (!$this->db->affected_rows($result)) {
                rcube::raise_error([
                    'code' => 900,
                    'message' => "Failed to write to kolab cache",
                ], true);
            }
        }
    }

    /**
     * Move an existing cache entry to a new resource
     *
     * @param string $uid Entry's UID
     */
    public function move($uid, $target, $unused1 = null, $unused2 = null)
    {
        // TODO
    }

    /**
     * Update resource URI for existing folder
     *
     * @param string $new_folder Target DAV folder to move it to
     */
    public function rename($new_folder)
    {
        // TODO
    }

    /**
     * Select Kolab objects filtered by the given query
     *
     * @param array $query Pseudo-SQL query as list of filter parameter triplets
     *                     triplet: ['<colname>', '<comparator>', '<value>']
     * @param bool  $uids  Set true to only return UIDs instead of complete objects
     * @param bool  $fast  Use fast mode to fetch only minimal set of information
     *                     (no xml fetching and parsing, etc.)
     *
     * @return array|null|kolab_storage_dataset List of Kolab data objects (each represented as hash array) or UIDs
     */
    public function select($query = [], $uids = false, $fast = false)
    {
        $result = $uids ? [] : new kolab_storage_dataset($this);

        $this->_read_folder_data();

        $sql_query = "SELECT " . ($uids ? "`uid`" : '*')
            . " FROM `{$this->cache_table}` WHERE `folder_id` = ?"
            . self::sql_where($query)
            . (!empty($this->order_by) ? " ORDER BY " . $this->order_by : '');

        $sql_result = $this->limit ?
            $this->db->limitquery($sql_query, $this->limit[1], $this->limit[0], $this->folder_id) :
            $this->db->query($sql_query, $this->folder_id);

        if ($this->db->is_error($sql_result)) {
            if ($uids) {
                return null;
            }

            $result->set_error(true);
            return $result;
        }

        while ($sql_arr = $this->db->fetch_assoc($sql_result)) {
            if (!$uids && ($object = $this->_unserialize($sql_arr, true, $fast))) {
                $result[] = $object;
            } else {
                $result[] = $sql_arr['uid'];
            }
        }

        return $result;
    }

    /**
     * Get number of objects mathing the given query
     *
     * @param array $query Pseudo-SQL query as list of filter parameter triplets
     *
     * @return int|null The number of objects of the given type, Null on error
     */
    public function count($query = [])
    {
        // read from local cache DB (assume it to be synchronized)
        $this->_read_folder_data();

        $sql_result = $this->db->query(
            "SELECT COUNT(*) AS `numrows` FROM `{$this->cache_table}` " .
            "WHERE `folder_id` = ?" . self::sql_where($query),
            $this->folder_id
        );

        if ($this->db->is_error($sql_result)) {
            return null;
        }

        $sql_arr = $this->db->fetch_assoc($sql_result);
        $count   = intval($sql_arr['numrows']);

        return $count;
    }

    /**
     * Getter for a single Kolab object identified by its UID
     *
     * @param string $uid Object UID
     *
     * @return array|null The Kolab object represented as hash array
     */
    public function get_by_uid($uid)
    {
        $old_limit = $this->limit;

        // set limit to skip count query
        $this->limit = [1, 0];

        $list = $this->select([['uid', '=', $uid]]);

        // set the limit back to defined value
        $this->limit = $old_limit;

        if (!empty($list) && !empty($list[0])) {
            return $list[0];
        }

        return null;
    }

    /**
     * Write records into cache using extended inserts to reduce the number of queries to be executed
     *
     * @param int    $force  Set to 0 to commit buffered insert, 1 to force an insert
     * @param ?array $object Kolab object to cache
     */
    protected function _extended_insert($force, $object)
    {
        static $buffer = '';

        $line = '';
        $cols = ['folder_id', 'uid', 'etag', 'created', 'changed', 'data', 'tags', 'words'];
        if ($this->extra_cols) {
            $cols = array_merge($cols, $this->extra_cols);
        }

        if ($object) {
            $sql_data = $this->_serialize($object);

            // Skip multi-folder insert for all databases but MySQL and Postgres
            if (!preg_match('/^(mysql|postgres)/', $this->db->db_provider)) {
                $extra_args = [];
                $params = [
                    $this->folder_id,
                    rcube_charset::clean($object['uid']),
                    rcube_charset::clean($object['etag']),
                    $sql_data['changed'],
                    $sql_data['data'],
                    $sql_data['tags'],
                    $sql_data['words'],
                ];

                foreach ($this->extra_cols as $col) {
                    $params[] = $sql_data[$col];
                    $extra_args[] = '?';
                }

                $cols = implode(', ', array_map(function ($n) { return "`{$n}`"; }, $cols));
                $extra_args = count($extra_args) ? ', ' . implode(', ', $extra_args) : '';

                $result = $this->db->query(
                    "INSERT INTO `{$this->cache_table}` ($cols)"
                    . " VALUES (?, ?, ?, " . $this->db->now() . ", ?, ?, ?, ?$extra_args)",
                    $params
                );

                if (!$this->db->affected_rows($result)) {
                    rcube::raise_error([
                        'code' => 900, 'message' => "Failed to write to kolab cache",
                    ], true);
                }

                return;
            }

            $values = [
                $this->db->quote($this->folder_id),
                $this->db->quote(rcube_charset::clean($object['uid'])),
                $this->db->quote(rcube_charset::clean($object['etag'])),
                !empty($sql_data['created']) ? $this->db->quote($sql_data['created']) : $this->db->now(),
                $this->db->quote($sql_data['changed']),
                $this->db->quote($sql_data['data']),
                $this->db->quote($sql_data['tags']),
                $this->db->quote($sql_data['words']),
            ];
            foreach ($this->extra_cols as $col) {
                $values[] = $this->db->quote($sql_data[$col]);
            }
            $line = '(' . implode(',', $values) . ')';
        }

        if ($buffer && ($force || (strlen($buffer) + strlen($line) > $this->max_sql_packet()))) {
            $columns = implode(', ', array_map(function ($n) { return "`{$n}`"; }, $cols));

            if ($this->db->db_provider == 'postgres') {
                $update = "ON CONFLICT (folder_id, uid) DO UPDATE SET "
                    . implode(', ', array_map(function ($i) { return "`{$i}` = EXCLUDED.`{$i}`"; }, array_slice($cols, 2)));
            } else {
                $update = "ON DUPLICATE KEY UPDATE "
                    . implode(', ', array_map(function ($i) { return "`{$i}` = VALUES(`{$i}`)"; }, array_slice($cols, 2)));
            }

            $result = $this->db->query("INSERT INTO `{$this->cache_table}` ($columns) VALUES $buffer $update");

            if (!$this->db->affected_rows($result)) {
                rcube::raise_error(['code' => 900, 'message' => "Failed to write to kolab cache"], true);
            }

            $buffer = '';
        }

        $buffer .= ($buffer ? ',' : '') . $line;
    }

    /**
     * Helper method to convert the given Kolab object into a dataset to be written to cache
     */
    protected function _serialize($object)
    {
        static $threshold;

        if ($threshold === null) {
            $rcube     = rcube::get_instance();
            $threshold = parse_bytes(rcube::get_instance()->config->get('dav_cache_threshold', 0));
        }

        $data     = [];
        $sql_data = ['created' => date(self::DB_DATE_FORMAT), 'changed' => null, 'tags' => '', 'words' => ''];

        if (!empty($object['changed'])) {
            $sql_data['changed'] = self::_convert_datetime($object['changed']);
        }
        if (!empty($object['created'])) {
            $sql_data['created'] = self::_convert_datetime($object['created']);
        }

        // Store only minimal set of object properties
        foreach ($this->data_props as $prop) {
            if (isset($object[$prop])) {
                $data[$prop] = $object[$prop];
                if ($data[$prop] instanceof DateTimeInterface) {
                    $data[$prop] = [
                        'cl' => 'DateTime',
                        'dt' => $data[$prop]->format('Y-m-d H:i:s'),
                        'tz' => $data[$prop]->getTimezone()->getName(),
                    ];
                }
            }
        }

        if (!empty($object['_raw']) && $threshold > 0 && strlen($object['_raw']) <= $threshold) {
            $data['_raw'] = $object['_raw'];
        }

        $sql_data['data'] = json_encode(rcube_charset::clean($data));

        return $sql_data;
    }

    /**
     * Helper method to turn stored cache data into a valid storage object
     */
    protected function _unserialize($sql_arr, $noread = false, $fast_mode = false)
    {
        $init = function (&$object) use ($sql_arr) {
            if ($sql_arr['created'] && empty($object['created'])) {
                $object['created'] = new DateTime($sql_arr['created'], $this->server_timezone);
            }

            if ($sql_arr['changed'] && empty($object['changed'])) {
                $object['changed'] = new DateTime($sql_arr['changed'], $this->server_timezone);
            }

            $object['_type'] = !empty($sql_arr['type']) ? $sql_arr['type'] : $this->folder->type;
            $object['uid']   = $sql_arr['uid'];
            $object['etag']  = $sql_arr['etag'];
        };

        if (!empty($sql_arr['data']) && ($object = json_decode($sql_arr['data'], true))) {
            foreach ($this->data_props as $prop) {
                if (isset($object[$prop]) && is_array($object[$prop])
                    && isset($object[$prop]['cl']) && $object[$prop]['cl'] == 'DateTime'
                ) {
                    $object[$prop] = new DateTime($object[$prop]['dt'], new DateTimeZone($object[$prop]['tz']));
                } elseif (!isset($object[$prop]) && isset($sql_arr[$prop])) {
                    $object[$prop] = $sql_arr[$prop];
                }
            }

            $init($object);
        }

        if (!empty($fast_mode) && !empty($object)) {
            unset($object['_raw']);
        } elseif ($noread) {
            // We have the raw content already, parse it
            if (!empty($object['_raw'])) {
                $object['data'] = $object['_raw'];
                if ($object = $this->folder->from_dav($object)) {
                    $init($object);
                    return $object;
                }
            }

            return null;
        } else {
            // Fetch a complete object from the server
            $object = $this->folder->read_object($sql_arr['uid'], '*');
        }

        return $object;
    }

    /**
     * Read this folder's ID and cache metadata
     */
    protected function _read_folder_data()
    {
        // already done
        if (!empty($this->folder_id) || !$this->ready) {
            return;
        }

        // Different than in Kolab XML-based storage, in *DAV folders can
        // contain different types of data, e.g. Calendar can store events and tasks.
        // Therefore we both `resource` and `type` in WHERE.

        $sql_arr = $this->db->fetch_assoc($this->db->query(
            "SELECT `folder_id`, `synclock`, `ctag`, `changed` FROM `{$this->folders_table}`"
                . " WHERE `resource` = ? AND `type` = ?",
            $this->resource_uri,
            $this->folder->type
        ));

        if ($sql_arr) {
            $this->folder_id = $sql_arr['folder_id'];
            $this->metadata  = $sql_arr;
        } else {
            $this->db->query("INSERT INTO `{$this->folders_table}` (`resource`, `type`)"
                . " VALUES (?, ?)", $this->resource_uri, $this->folder->type);

            $this->folder_id = $this->db->insert_id('kolab_folders');
            $this->metadata  = [];
        }
    }
}
