<?php

/**
 * Kolab storage class providing access to groupware objects on a *DAV server.
 *
 * @author Aleksander Machniak <machniak@apheleia-it.ch>
 *
 * Copyright (C) 2022, Apheleia IT AG <contact@apheleia-it.ch>
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

class kolab_storage_dav
{
    public const ERROR_DAV_CONN       = 1;
    public const ERROR_CACHE_DB       = 2;
    public const ERROR_NO_PERMISSION  = 3;
    public const ERROR_INVALID_FOLDER = 4;

    public static $last_error;

    protected $dav;
    protected $url;


    /**
     * Object constructor
     */
    public function __construct($url)
    {
        $this->url = $url;
        $this->dav = new kolab_dav_client($this->url);
    }

    /**
     * Get a list of storage folders for the given data type
     *
     * @param string $type Data type to list folders for (contact,event,task,note)
     *
     * @return array List of kolab_storage_dav_folder objects
     */
    public function get_folders($type)
    {
        $folders = $this->dav->listFolders($this->get_dav_type($type));

        if (is_array($folders)) {
            foreach ($folders as $idx => $folder) {
                // Exclude some special folders
                if (in_array('schedule-inbox', $folder['resource_type']) || in_array('schedule-outbox', $folder['resource_type'])) {
                    unset($folders[$idx]);
                    continue;
                }

                $folders[$idx] = new kolab_storage_dav_folder($this->dav, $folder, $type);
            }


            usort($folders, function ($a, $b) {
                return strcoll($a->get_foldername(), $b->get_foldername());
            });
        }

        return $folders ?: [];
    }

    /**
     * Getter for the storage folder for the given type
     *
     * @param string $type Data type to list folders for (contact,event,task,note)
     *
     * @return kolab_storage_dav_folder|null The folder object
     */
    public function get_default_folder($type)
    {
        // TODO: Not used
        return null;
    }

    /**
     * Getter for a specific storage folder
     *
     * @param string $id   Folder to access
     * @param string $type Expected folder type
     *
     * @return ?object kolab_storage_folder The folder object
     */
    public function get_folder($id, $type)
    {
        foreach ($this->get_folders($type) as $folder) {
            if ($folder->id == $id) {
                return $folder;
            }
        }

        return null;
    }

    /**
     * Getter for a single Kolab object, identified by its UID.
     * This will search all folders storing objects of the given type.
     *
     * @param string $uid  Object UID
     * @param string $type Object type (contact,event,task,journal,file,note,configuration)
     *
     * @return array|false The Kolab object represented as hash array or false if not found
     */
    public function get_object($uid, $type)
    {
        // TODO
        return false;
    }

    /**
     * Execute cross-folder searches with the given query.
     *
     * @param array  $query Pseudo-SQL query as list of filter parameter triplets
     * @param string $type  Folder type (contact,event,task,journal,file,note,configuration)
     * @param int    $limit Expected number of records or limit (for performance reasons)
     *
     * @return array List of Kolab data objects (each represented as hash array)
     */
    public function select($query, $type, $limit = null)
    {
        $result = [];

        foreach ($this->get_folders($type) as $folder) {
            if ($limit) {
                $folder->set_order_and_limit(null, $limit);
            }

            foreach ($folder->select($query) as $object) {
                $result[] = $object;
            }
        }

        return $result;
    }

    /**
     * Compose an URL to query the free/busy status for the given user
     *
     * @param string    $email Email address of the user to get free/busy data for
     * @param ?DateTime $start Start of the query range (optional)
     * @param ?DateTime $end   End of the query range (optional)
     *
     * @return ?string Fully qualified URL to query free/busy data
     */
    public static function get_freebusy_url($email, $start = null, $end = null)
    {
        return kolab_storage::get_freebusy_url($email, $start, $end);
    }

    /**
     * Creates folder ID from a DAV folder location and server URI.
     *
     * @param string $uri  DAV server location
     * @param string $href Folder location
     *
     * @return string Folder ID string
     */
    public static function folder_id($uri, $href)
    {
        if (($rootPath = parse_url($uri, PHP_URL_PATH)) && strpos($href, $rootPath) === 0) {
            $href = substr($href, strlen($rootPath));
        }

        // Start with a letter to prevent from all kind of issues if it starts with a digit
        return 'f' . md5(rtrim($uri, '/') . '/' . trim($href, '/'));
    }

    /**
     * Deletes a folder
     *
     * @param string $id   Folder ID
     * @param string $type Folder type (contact,event,task,journal,file,note,configuration)
     *
     * @return bool True on success, false on failure
     */
    public function folder_delete($id, $type)
    {
        if ($folder = $this->get_folder($id, $type)) {
            return $this->dav->folderDelete($folder->href);
        }

        return false;
    }

    /**
     * Creates a folder
     *
     * @param string $name       Folder name (UTF7-IMAP)
     * @param string $type       Folder type
     * @param bool   $subscribed Sets folder subscription
     * @param bool   $active     Sets folder state (client-side subscription)
     *
     * @return bool True on success, false on failure
     */
    public function folder_create($name, $type = null, $subscribed = false, $active = false)
    {
        // TODO
        return false;
    }

    /**
     * Renames DAV folder
     *
     * @param string $oldname Old folder name (UTF7-IMAP)
     * @param string $newname New folder name (UTF7-IMAP)
     *
     * @return bool True on success, false on failure
     */
    public function folder_rename($oldname, $newname)
    {
        // TODO
        return false;
    }

    /**
     * Update or Create a new folder.
     *
     * Does additional checks for permissions and folder name restrictions
     *
     * @param array &$prop Hash array with folder properties and metadata
     *  - name:       Folder name
     *  - oldname:    Old folder name when changed
     *  - parent:     Parent folder to create the new one in
     *  - type:       Folder type to create
     *  - subscribed: Subscribed flag (IMAP subscription)
     *  - active:     Activation flag (client-side subscription)
     *
     * @return string|false Folder ID or False on failure
     */
    public function folder_update(&$prop)
    {
        // TODO: Folder hierarchies, active and subscribed state

        // sanity checks
        if (!isset($prop['name']) || !is_string($prop['name']) || !strlen($prop['name'])) {
            if (empty($prop['id'])) {
                self::$last_error = 'cannotbeempty';
                return false;
            }
        } elseif (strlen($prop['name']) > 256) {
            self::$last_error = 'nametoolong';
            return false;
        }

        if (!empty($prop['id'])) {
            if ($folder = $this->get_folder($prop['id'], $prop['type'])) {
                $result = $this->dav->folderUpdate($folder->href, $folder->get_dav_type(), $prop);

                if ($result) {
                    return $prop['id'];
                }
            }

            return false;
        }

        $rcube = rcube::get_instance();
        $uid   = rtrim(chunk_split(md5($prop['name'] . $rcube->get_user_name() . uniqid('-', true)), 12, '-'), '-');
        $type  = $this->get_dav_type($prop['type']);
        $home  = $this->dav->getHome($type);

        if ($home === null) {
            return false;
        }

        $location = unslashify($home) . '/' . $uid;
        $result   = $this->dav->folderCreate($location, $type, $prop);

        if ($result) {
            return self::folder_id($this->dav->url, $location);
        }

        return false;
    }

    /**
     * Getter for human-readable name of a folder
     *
     * @param string $folder    Folder name (UTF7-IMAP)
     * @param string $folder_ns Will be set to namespace name of the folder
     *
     * @return string Name of the folder-object
     */
    public static function object_name($folder, &$folder_ns = null)
    {
        // TODO: Shared folders
        $folder_ns = 'personal';
        return $folder;
    }

    /**
     * Creates a SELECT field with folders list
     *
     * @param string $type    Folder type
     * @param array  $attrs   SELECT field attributes (e.g. name)
     * @param string $current The name of current folder (to skip it)
     *
     * @return html_select SELECT object
     */
    public function folder_selector($type, $attrs, $current = '')
    {
        // TODO
        return null; // @phpstan-ignore-line
    }

    /**
     * Returns a list of folder names
     *
     * @param string $root       Optional root folder
     * @param string $mbox       Optional name pattern
     * @param string $filter     Data type to list folders for (contact,event,task,journal,file,note,mail,configuration)
     * @param bool   $subscribed Enable to return subscribed folders only (null to use configured subscription mode)
     * @param array  $folderdata Will be filled with folder-types data
     *
     * @return array List of folders
     */
    public function list_folders($root = '', $mbox = '*', $filter = null, $subscribed = null, &$folderdata = [])
    {
        // TODO
        return [];
    }

    /**
     * Search for shared or otherwise not listed groupware folders the user has access
     *
     * @param string $type       Folder type of folders to search for
     * @param string $query      Search string
     * @param array  $exclude_ns Namespace(s) to exclude results from
     *
     * @return array List of matching kolab_storage_folder objects
     */
    public function search_folders($type, $query, $exclude_ns = [])
    {
        // TODO
        return [];
    }

    /**
     * Sort the given list of folders by namespace/name
     *
     * @param array $folders List of kolab_storage_dav_folder objects
     *
     * @return array Sorted list of folders
     */
    public static function sort_folders($folders)
    {
        // TODO
        return $folders;
    }

    /**
     * Returns folder types indexed by folder name
     *
     * @param string $prefix Folder prefix (Default '*' for all folders)
     *
     * @return array|bool List of folders, False on failure
     */
    public function folders_typedata($prefix = '*')
    {
        // TODO: Used by kolab_folders, kolab_activesync, kolab_delegation
        return [];
    }

    /**
     * Returns type of a DAV folder
     *
     * @param string $folder Folder name (UTF7-IMAP)
     *
     * @return string Folder type
     */
    public function folder_type($folder)
    {
        // TODO: Used by kolab_folders, kolab_activesync, kolab_delegation
        return '';
    }

    /**
     * Sets folder content-type.
     *
     * @param string $folder Folder name
     * @param string $type   Content type
     *
     * @return bool True on success, False otherwise
     */
    public function set_folder_type($folder, $type = 'mail')
    {
        // NOP: Used by kolab_folders, kolab_activesync, kolab_delegation
        return false;
    }

    /**
     * Check subscription status of this folder
     *
     * @param string $folder Folder name
     * @param bool   $temp   Include temporary/session subscriptions
     *
     * @return bool True if subscribed, false if not
     */
    public function folder_is_subscribed($folder, $temp = false)
    {
        // NOP
        return true;
    }

    /**
     * Change subscription status of this folder
     *
     * @param string $folder Folder name
     * @param bool   $temp   Only subscribe temporarily for the current session
     *
     * @return bool True on success, false on error
     */
    public function folder_subscribe($folder, $temp = false)
    {
        // NOP
        return true;
    }

    /**
     * Change subscription status of this folder
     *
     * @param string $folder Folder name
     * @param bool   $temp   Only remove temporary subscription
     *
     * @return bool True on success, false on error
     */
    public function folder_unsubscribe($folder, $temp = false)
    {
        // NOP
        return false;
    }

    /**
     * Check activation status of this folder
     *
     * @param string $folder Folder name
     *
     * @return bool True if active, false if not
     */
    public function folder_is_active($folder)
    {
        return true; // TODO
    }

    /**
     * Change activation status of this folder
     *
     * @param string $folder Folder name
     *
     * @return bool True on success, false on error
     */
    public function folder_activate($folder)
    {
        return true; // TODO
    }

    /**
     * Change activation status of this folder
     *
     * @param string $folder Folder name
     *
     * @return bool True on success, false on error
     */
    public function folder_deactivate($folder)
    {
        return false; // TODO
    }

    /**
     * Creates default folder of specified type
     * To be run when none of subscribed folders (of specified type) is found
     *
     * @param string $type  Folder type
     * @param array  $props Folder properties (color, etc)
     *
     * @return string Folder name
     */
    public function create_default_folder($type, $props = [])
    {
        // TODO: For kolab_addressbook??
        return '';
    }

    /**
     * Returns a list of IMAP folders shared by the given user
     *
     * @param array  $user       User entry from LDAP
     * @param string $type       Data type to list folders for (contact,event,task,journal,file,note,mail,configuration)
     * @param int    $subscribed 1 - subscribed folders only, 0 - all folders, 2 - all non-active
     * @param array  $folderdata Will be filled with folder-types data
     *
     * @return array List of folders
     */
    public function list_user_folders($user, $type, $subscribed = 0, &$folderdata = [])
    {
        // TODO
        return [];
    }

    /**
     * Get a list of (virtual) top-level folders from the other users namespace
     *
     * @param string $type       Data type to list folders for (contact,event,task,journal,file,note,mail,configuration)
     * @param bool   $subscribed Enable to return subscribed folders only (null to use configured subscription mode)
     *
     * @return array List of kolab_storage_folder_user objects
     */
    public function get_user_folders($type, $subscribed)
    {
        // TODO
        return [];
    }

    /**
     * Handler for user_delete plugin hooks
     *
     * Remove all cache data from the local database related to the given user.
     */
    public static function delete_user_folders($args)
    {
        $db = rcube::get_instance()->get_dbh();
        $table  = $db->table_name('kolab_folders', true);
        $prefix = 'dav://' . urlencode($args['username']) . '@' . $args['host'] . '/%';

        $db->query("DELETE FROM $table WHERE `resource` LIKE ?", $prefix);
    }

    /**
     * Get folder METADATA for all supported keys
     * Do this in one go for better caching performance
     */
    public function folder_metadata($folder)
    {
        // TODO ?
        return [];
    }

    /**
     * Get a folder DAV content type
     */
    public static function get_dav_type($type)
    {
        $types = [
            'event' => 'VEVENT',
            'task'  => 'VTODO',
            'contact' => 'VCARD',
        ];

        return $types[$type];
    }

    /**
     * Accept a share invitation.
     *
     * @param string $type     Folder type (contact, event, task)
     * @param string $location Invitation object location
     *
     * @return kolab_storage_dav_folder|false A new folder object, False on error
     */
    public function accept_share_invitation($type, $location)
    {
        // Note: The 'create-in' property is not supported by Cyrus, and even then we
        // can't specify the new folder location. The new folder will be created
        // at implementation-specific location. To find that location we'll compare list of folders
        // before and after accepting the invitation.

        $old_folders = $this->dav->listFolders($this->get_dav_type($type));

        $result = $this->dav->inviteReply($location);

        if (!$result) {
            return false;
        }

        $new_folders = $this->dav->listFolders($this->get_dav_type($type));

        if (is_array($old_folders) && is_array($new_folders)) {
            foreach ($new_folders as $newfolder) {
                foreach ($old_folders as $oldfolder) {
                    if ($oldfolder['href'] === $newfolder['href']) {
                        continue 2;
                    }
                }

                return new kolab_storage_dav_folder($this->dav, $newfolder, $type);
            }
        }

        return false;
    }

    /**
     * Get a list of share invitations
     *
     * @param string $type   Folder type (contact, event, task)
     * @param string $filter Search string
     *
     * @return array List of kolab_storage_dav_folder objects
     */
    public function get_share_invitations($type, $filter)
    {
        $result = [];

        if (strlen($filter) === 0) {
            return $result;
        }

        $notifications = $this->dav->listNotifications([kolab_dav_client::NOTIFICATION_SHARE_INVITE]);

        if (is_array($notifications)) {
            foreach ($notifications as $idx => $note) {
                // Sanity checks
                if (empty($note['resource-uri'])) {
                    continue;
                }

                // Skip accepted invitations
                if (!empty($note['status']) && $note['status'] == 'accepted') {
                    continue;
                }

                // Filter by folder type
                if (($type == 'contact' && !empty($note['types']))
                    || ($type == 'event' && !in_array('VEVENT', $note['types']))
                    || ($type == 'task' && !in_array('VTODO', $note['types']))
                ) {
                    continue;
                }

                $owner = explode('/', trim($note['organizer'] ?? '', '/'));
                $owner = end($owner);
                $path = explode('/', trim($note['resource-uri'], '/'));
                $name = end($path);
                $displayname = $note['displayname'] ?? '';

                // Filter by the input text
                if (stripos($owner . ' ' . $name . ' ' . $displayname, $filter) === false) {
                    continue;
                }

                $attrs = [
                    'owner' => $owner,
                    'name' => $displayname ?: $name,
                    'href' => $note['resource-uri'],
                    'invitation' => $note['href'],
                    'alarms' => false,
                    'myrights' => [$note['access'] ?? 'read'],
                    'resource_type' => ['shared', 'collection'],
                ];

                $result[] = new kolab_storage_dav_folder($this->dav, $attrs, $type);
            }

            usort($result, function ($a, $b) {
                return strcoll($a->href, $b->href);
            });
        }

        return $result;
    }
}
