<?php

/**
 * A utility class to manage ActiveSync subscriptions (for both IMAP and DAV folders).
 *
 * @author Aleksander Machniak <machniak@apheleia-it.ch>
 *
 * Copyright (C) Apheleia IT AG <contact@apheleia-it.ch>
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

class kolab_subscriptions
{
    public const DEVICE_FIELDS = ['devicetype', 'acsversion', 'useragent', 'friendlyname', 'os', 'oslanguage', 'phonenumber', 'is_broken'];

    /** @var ?kolab_storage_dav DAV storage handler */
    private $dav = null;

    private $rc;
    private $folder_meta;
    private $folders_list;
    private $folders_type;
    private $icache = [];
    private $protected_folders = [];

    /**
     * Object constructor
     *
     * @param string $dav_url DAV server location. Enables DAV mode.
     */
    public function __construct($dav_url = null)
    {
        $this->rc = rcube::get_instance();
        $this->protected_folders = $this->rc->config->get('activesync_force_subscriptions', []);

        if ($dav_url) {
            $this->dav = new kolab_storage_dav($dav_url);
        }
    }

    /**
     * List known devices
     *
     * @return array<string,array> Device list as hash array
     */
    public function list_devices()
    {
        $db = $this->rc->get_dbh();
        $table = $db->table_name('syncroton_device');
        $list = [];

        $query = $db->query(
            "SELECT `id`, `deviceid`, " . $db->array2list(self::DEVICE_FIELDS, 'ident')
            . " FROM {$table} WHERE `owner_id` = ?",
            $this->rc->user->ID,
        );

        while ($record = $db->fetch_assoc($query)) {
            $list[$record['deviceid']] = $record;
        }

        return $list;
    }

    /**
     * Get list of all folders available for user
     *
     * @param string $type Folder type
     *
     * @return array<array> List of folders (0 - path, 1 - displayname, 2 - optional folder object)
     */
    public function list_folders($type)
    {
        if ($this->folders_list !== null && $this->folders_type == $type) {
            return $this->folders_list;
        }

        if ($this->dav) {
            if ($type == 'note') {
                $result = [];
            } elseif ($type == 'mail') {
                $storage = $this->rc->get_storage();
                $result = $storage->list_folders();
                $result = array_map([$this, 'imap_folder_prop'], $result);
            } else {
                $result = $this->dav->get_folders($type);
                $result = array_map([$this, 'dav_folder_prop'], $result);
            }
        } else {
            $result = kolab_storage::list_folders('', '*', $type, false);
            $result = array_map([$this, 'imap_folder_prop'], $result);
        }

        $this->folders_list = $result;
        $this->folders_type = $type;

        return $result;
    }

    /**
     * Getter for folders subscription flag on protected folders
     *
     * @return string|false false if not set, otherwise the flag.
     */
    private function get_forced_flag($folder, $devicetype)
    {
        $forcedFolders = $this->protected_folders[strtolower($devicetype)] ?? [];

        foreach ($forcedFolders as $idx => $flag) {
            if (preg_match("|^{$idx}\$|", $folder)) {
                return $flag;
            }
        }
        return false;
    }

    /**
     * Check if a folder is protected by a forced subscription setting
     *
     * @param string $folder     Folder
     * @param string $devicetype Device type
     *
     * @return bool True if protected
     */
    public function is_protected($folder, $devicetype)
    {
        return $this->get_forced_flag($folder, $devicetype) !== false;
    }

    /**
     * Get folder subscriptions
     *
     * @param string $deviceid Device IMEI identifier
     * @param string $type     Folder type
     *
     * @return array<string,array> Folder subscription flags (0 - flag, 1 - display name, 2 - optional folder object)
     */
    public function list_subscriptions($deviceid, $type)
    {
        if ($this->dav && $type == 'note') {
            return [];
        }

        $result = $this->get_subscriptions($deviceid, $type);

        $devicetype = $this->imei_to_type($deviceid);
        $folders = $this->list_folders($type);
        // Verify if subscribed folders still exist
        if (!empty($result)) {
            foreach ($result as $idx => $flag) {
                reset($folders);
                foreach ($folders as $folder) {
                    if ($folder[0] === (string) $idx) {
                        // Folder exists, copy the properties
                        $folder[0] = $flag;
                        $result[$idx] = $folder;
                        continue 2;
                    }
                }

                $update = true;
                unset($result[$idx]);
            }

            // Update subscriptions if any folder was removed from the list
            if (!empty($update)) {
                $data = array_map(function ($v) { return $v[0]; }, $result);
                $this->update_subscriptions($deviceid, $type, $data);
            }
        }

        // Insert existing folders that match the forced folders set, that aren't already in the result set
        // TODO: Because of the regex support in protected_folders we end up doing a lot of comparisons (count(folders) * count(protected_folders)),
        // and can't use a map instead.
        foreach ($folders as $folder) {
            $folderPath = $folder[0];
            if (array_key_exists($folderPath, $result)) {
                continue;
            }
            if (($flag = $this->get_forced_flag($folder[0], $devicetype)) !== false) {
                $folder[0] = $flag;
                $result[$folderPath] = $folder;
            }
        }

        return $result ?? [];
    }

    /**
     * Get list of devices the folder is subscribed to
     *
     * @param string $folder Folder (IMAP path or DAV path)
     * @param string $type   Folder type
     *
     * @return array<string,int> Index is a device IMEI, value is a subscription flag
     */
    public function folder_subscriptions($folder, $type = null)
    {
        $db = $this->rc->get_dbh();
        $table = $db->table_name('syncroton_subscriptions');
        $device_table = $db->table_name('syncroton_device');
        $result = [];

        $query = $db->query(
            "SELECT s.*, d.`deviceid` FROM {$table} s"
            . " JOIN {$device_table} d ON (s.`device_id` = d.`id`)"
            . " WHERE `owner_id` = ?"
            . ($type ? " AND s.type = " . $db->quote($type) : ''),
            $this->rc->user->ID
        );

        while ($record = $db->fetch_assoc($query)) {
            $list = json_decode($record['data'], true);
            if (!empty($list[$folder])) {
                $result[$record['deviceid']] = $list[$folder];
            }
        }

        return $result;
    }

    /**
     * Set folder subscription flag for specific device
     *
     * @param string  $deviceid Device IMEI identifier
     * @param string  $folder   Folder (an IMAP path or a DAV path)
     * @param int     $flag     Subscription flag (1 or 2)
     * @param ?string $type     Folder type class
     *
     * @return bool True on success, False on failure
     */
    public function folder_subscribe($deviceid, $folder, $flag, $type = null)
    {
        // For DAV folders it is required to use $type argument
        // otherwise it's hard to get the folder type
        if (empty($type)) {
            $type = (string) kolab_storage::folder_type($folder);
        }

        if (!$type) {
            $type = 'mail';
        }

        [$type, ] = explode('.', $type);

        if (!in_array($type, ['mail', 'event', 'contact', 'task', 'note'])) {
            return false;
        }

        // Folder hrefs returned by kolab_dav_client aren't normalized, i.e. include path prefix
        // We make sure here we use the same path
        if ($this->dav && $type != 'mail') {
            if ($type == 'note') {
                return false;
            }

            if ($path = parse_url($this->dav->dav->url, PHP_URL_PATH)) {
                if (strpos($folder, $path) !== 0) {
                    $folder = '/' . trim($path, '/') . $folder;
                }
            }

            $folder = rtrim($folder, '/');
        }

        $subscribed = $this->get_subscriptions($deviceid, $type);

        if (isset($subscribed[$folder])) {
            if ($subscribed[$folder] == $flag) {
                return true;
            }

            unset($subscribed[$folder]);
        }

        if ($flag) {
            $subscribed[$folder] = (int) $flag;
        }

        return $this->update_subscriptions($deviceid, $type, $subscribed);
    }

    /**
     * Set folder subscriptions (in SQL database)
     *
     * @param string            $deviceid      Device IMEI identifier
     * @param string            $type          Folder type class
     * @param array<string,int> $subscriptions Subscriptions
     *
     * @return bool True on success, False on failure
     */
    public function set_subscriptions($deviceid, $type, $subscriptions)
    {
        $id = $this->imei_to_id($deviceid);

        if (empty($id)) {
            return false;
        }

        if ($this->dav && $type == 'note') {
            return true;
        }

        $data = json_encode($subscriptions);

        if ($data === false) {
            return false;
        }

        $db = $this->rc->get_dbh();
        $table = $db->table_name('syncroton_subscriptions');

        $query = $db->query("SELECT 1 FROM $table WHERE `device_id` = ? AND `type` = ?", $id, $type);

        if ($record = $db->fetch_array($query)) {
            $query = $db->query("UPDATE {$table} SET `data` = ? WHERE `device_id` = ? AND `type` = ?", $data, $id, $type);
        } else {
            $query = $db->query("INSERT INTO {$table} (`device_id`, `type`, `data`) VALUES (?, ?, ?)", $id, $type, $data);
        }

        return $db->affected_rows($query) > 0;
    }

    /**
     * Device delete.
     *
     * @param string $id Device ID
     *
     * @return bool True on success, False on failure
     */
    public function device_delete($id)
    {
        $db = $this->rc->get_dbh();
        $table = $db->table_name('syncroton_device');

        $query = $db->query(
            "DELETE FROM {$table} WHERE `owner_id` = ? AND `deviceid` = ?",
            $this->rc->user->ID,
            $id
        );

        return $db->affected_rows($query) > 0;
    }

    /**
     * Device information
     *
     * @param string $id Device ID
     *
     * @return array|null Device data
     */
    public function device_info($id)
    {
        $db = $this->rc->get_dbh();
        $table = $db->table_name('syncroton_device');

        $query = $db->query(
            "SELECT `id`, `deviceid`, " . $db->array2list(self::DEVICE_FIELDS, 'ident')
            . " FROM {$table} WHERE `owner_id` = ? AND `deviceid` = ?",
            $this->rc->user->ID,
            $id
        );

        if ($device = $db->fetch_assoc($query)) {
            return $device;
        }

        return null;
    }

    /**
     * Device update
     *
     * @param string $id     Device ID
     * @param array  $device Device data
     *
     * @return bool True on success, False on failure
     */
    public function device_update($id, $device)
    {
        $db = $this->rc->get_dbh();
        $cols = $params = [];
        $allow = ['friendlyname'];

        foreach ((array) $device as $col => $value) {
            $cols[] = $db->quote_identifier($col) . ' = ?';
            $params[] = $value;
        }

        $params[] = $id;
        $params[] = $this->rc->user->ID;

        $query = $db->query(
            'UPDATE ' . $db->table_name('syncroton_device', true) .
            ' SET ' . implode(', ', $cols) . ' WHERE `deviceid` = ? AND `owner_id` = ?',
            $params
        );

        return $db->affected_rows($query) > 0;
    }

    /**
     * Get subscriptions from database.
     */
    private function get_subscriptions($deviceid, $type)
    {
        $id = $this->imei_to_id($deviceid);

        if ($id === null) {
            return [];
        }

        $db = $this->rc->get_dbh();
        $table = $db->table_name('syncroton_subscriptions');

        // Get the subscriptions from database
        $query = $db->query("SELECT `data` FROM {$table} WHERE `device_id` = ? AND `type` = ?", $id, $type);

        if ($record = $db->fetch_assoc($query)) {
            $result = json_decode($record['data'], true);
        }

        // No record yet...
        if (!isset($result)) {
            $result = [];

            // Get the old subscriptions from an IMAP annotations, create the record
            if (!$this->dav || $type == 'mail') {
                foreach ($this->folder_meta() as $folder => $meta) {
                    if ($meta[0] == $type && !empty($meta[1][$deviceid]['S'])) {
                        $result[$folder] = (int) $meta[1][$deviceid]['S'];
                    }
                }
            }

            $data = json_encode($result);

            $db->query("INSERT INTO {$table} (`device_id`, `type`, `data`) VALUES (?, ?, ?)", $id, $type, $data);
        }

        return $result;
    }

    /**
     * Update subscriptions in the database.
     */
    private function update_subscriptions($deviceid, $type, $list)
    {
        $id = $this->imei_to_id($deviceid);

        if ($id === null) {
            return false;
        }

        $db = $this->rc->get_dbh();
        $table = $db->table_name('syncroton_subscriptions');

        $data = json_encode($list);

        $query = $db->query("UPDATE {$table} SET `data` = ? WHERE `device_id` = ? AND `type` = ?", $data, $id, $type);

        return $db->affected_rows($query) > 0;
    }

    /**
     * Getter for folders metadata (type and activesync subscription)
     *
     * @return array Hash array with meta data for each folder
     */
    private function folder_meta()
    {
        if ($this->folder_meta === null) {
            $this->folder_meta = [];

            $storage = $this->rc->get_storage();
            $keys = [
                kolab_storage::ASYNC_KEY,
                kolab_storage::CTYPE_KEY,
                kolab_storage::CTYPE_KEY_PRIVATE,
            ];

            // get folders activesync config
            $folderdata = $storage->get_metadata('*', $keys);

            foreach ((array) $folderdata as $folder => $meta) {
                $type = kolab_storage::folder_select_metadata($meta) ?? 'mail';
                [$type, ] = explode('.', $type);
                $asyncdata = isset($meta[kolab_storage::ASYNC_KEY]) ? json_decode($meta[kolab_storage::ASYNC_KEY], true) : [];
                $this->folder_meta[$folder] = [$type ?: 'mail', $asyncdata['FOLDER'] ?? []];
            }
        }

        return $this->folder_meta;
    }

    /**
     * Get syncroton device_id from IMEI identifier
     *
     * @param string $imei IMEI identifier
     *
     * @return string|null Syncroton device identifier
     */
    private function imei_to_id($imei)
    {
        $userid = $this->rc->user->ID;

        if (isset($this->icache["deviceid:{$userid}:{$imei}"])) {
            return $this->icache["deviceid:{$userid}:{$imei}"];
        }

        $db = $this->rc->get_dbh();
        $table = $db->table_name('syncroton_device');

        $result = $db->query("SELECT id FROM {$table} WHERE `owner_id` = ? AND `deviceid` = ?", $userid, $imei);

        return $this->icache["deviceid:{$userid}:{$imei}"] = $db->fetch_array($result)[0] ?? null;
    }

    /**
     * Get syncroton device type from IMEI identifier
     *
     * @param string $imei IMEI identifier
     *
     * @return string|null Syncroton device identifier
     */
    private function imei_to_type($imei)
    {
        $userid = $this->rc->user->ID;

        if (isset($this->icache["devicetype:{$userid}:{$imei}"])) {
            return $this->icache["devicetype:{$userid}:{$imei}"];
        }

        $db = $this->rc->get_dbh();
        $table = $db->table_name('syncroton_device');

        $result = $db->query("SELECT devicetype FROM {$table} WHERE `owner_id` = ? AND `deviceid` = ?", $userid, $imei);

        return $this->icache["devicetype:{$userid}:{$imei}"] = $db->fetch_array($result)[0] ?? null;
    }

    /**
     * IMAP folder properties for list_folders/list_subscriptions output
     */
    private static function imap_folder_prop($folder)
    {
        return [
            $folder,
            kolab_storage::object_prettyname($folder),
        ];
    }

    /**
     * DAV folder properties for list_folders/list_subscriptions output
     */
    private static function dav_folder_prop($folder)
    {
        return [
            $folder->href,
            $folder->get_name(),
            $folder,
        ];
    }
}
