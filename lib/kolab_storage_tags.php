<?php

/**
 * Kolab storage class providing access to tags stored in IMAP (Kolab4-style)
 *
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2024, Apheleia IT AG <contact@apheleia-it.ch>
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

class kolab_storage_tags
{
    public const ANNOTATE_KEY_PREFIX = '/vendor/kolab/tag/v1/';
    public const ANNOTATE_VALUE = '1';
    public const METADATA_ROOT = 'INBOX';
    public const METADATA_TAGS_KEY = '/private/vendor/kolab/tags/v1';

    protected $tag_props = ['name', 'color'];
    protected $tags;

    /**
     * Class constructor
     */
    public function __construct()
    {
        // FETCH message annotations (tags) by default for better performance
        rcube::get_instance()->get_storage()->set_options([
            'fetch_items' => ['ANNOTATION (' . self::ANNOTATE_KEY_PREFIX . '% (value.priv))'],
        ]);
    }

    /**
     * Tags list
     *
     * @param array $filter Search filter
     *
     * @return array<array> List of tags
     */
    public function list($filter = [])
    {
        $tags = $this->list_tags();

        if (empty($tags)) {
            return [];
        }

        // For now there's only one type of filter we support
        if (empty($filter) || empty($filter[0][0]) || $filter[0][0] != 'uid' || $filter[0][1] != '=') {
            return $tags;
        }

        $tags = array_filter(
            $tags,
            function ($tag) use ($filter) {
                return $filter[0][0] == 'uid' && in_array($tag['uid'], (array) $filter[0][2]);
            }
        );

        return array_values($tags);
    }

    /**
     * Create tag object
     *
     * @param array $props Tag properties
     *
     * @return false|string Tag identifier, or False on failure
     */
    public function create($props)
    {
        $tag = [];
        foreach ($this->tag_props as $prop) {
            if (isset($props[$prop])) {
                $tag[$prop] = $props[$prop];
            }
        }

        if (empty($tag['name'])) {
            return false;
        }

        $uid = md5($tag['name']);
        $tags = $this->list_tags();

        foreach ($tags as $existing_tag) {
            if ($existing_tag['uid'] == $uid) {
                return false;
            }
        }

        $tags[] = $tag;

        if (!$this->save_tags($tags)) {
            return false;
        }

        return $uid;
    }

    /**
     * Update tag object
     *
     * @param array $props Tag properties
     *
     * @return bool True on success, False on failure
     */
    public function update($props)
    {
        $found = null;
        foreach ($this->list_tags() as $idx => $existing) {
            if ($existing['uid'] == $props['uid']) {
                $found = $idx;
            }
        }

        if ($found === null) {
            return false;
        }

        $tag = $this->tags[$found];

        // Name is immutable
        if (isset($props['name']) && $props['name'] != $tag['name']) {
            return false;
        }

        foreach ($this->tag_props as $col) {
            if (isset($props[$col])) {
                $tag[$col] = $props[$col];
            }
        }

        $tags = $this->tags;
        $tags[$found] = $tag;

        if (!$this->save_tags($tags)) {
            return false;
        }

        return true;
    }

    /**
     * Remove a tag
     *
     * @param string $uid Tag unique identifier
     *
     * @return bool True on success, False on failure
     */
    public function delete($uid)
    {
        $found = null;
        foreach ($this->list_tags() as $idx => $existing) {
            if ($existing['uid'] == $uid) {
                $found = $idx;
                break;
            }
        }

        if ($found === null) {
            return false;
        }

        $tags = $this->tags;
        $tag_name = $tags[$found]['name'];
        unset($tags[$found]);

        if (!$this->save_tags($tags)) {
            return false;
        }

        // Remove all message annotations for this tag from all folders
        /** @var rcube_imap $imap */
        $imap = rcube::get_instance()->get_storage();
        $search = self::imap_search_criteria($tag_name);
        $annotation = [];
        $annotation[self::ANNOTATE_KEY_PREFIX . $tag_name] = ['value.priv' => null];

        foreach ($imap->list_folders() as $folder) {
            $index = $imap->search_once($folder, $search);
            if ($uids = $index->get_compressed()) {
                $imap->annotate_message($annotation, $uids, $folder);
            }
        }

        return true;
    }

    /**
     * Returns tag assignments with multiple members
     *
     * @param array<rcube_message_header|rcube_message> $messages     Mail messages
     * @param bool                                      $return_names Return tag names instead of UIDs
     *
     * @return array<string, array> Assigned tag UIDs or names by message
     */
    public function members_tags($messages, $return_names = false)
    {
        // get tags list
        $tag_uids = [];
        foreach ($this->list_tags() as $tag) {
            $tag_uids[$tag['name']] = $tag['uid'];
        }

        if (empty($tag_uids)) {
            return [];
        }

        $result = [];
        $uids = [];
        $msg_func = function ($msg) use (&$result, $tag_uids, $return_names) {
            if ($msg instanceof rcube_message) {
                $msg = $msg->headers;
            }
            /** @var rcube_message_header $msg */
            if (isset($msg->annotations)) {
                $tags = [];
                foreach ($msg->annotations as $name => $props) {
                    if (strpos($name, self::ANNOTATE_KEY_PREFIX) === 0 && !empty($props['value.priv'])) {
                        $tag_name = substr($name, strlen(self::ANNOTATE_KEY_PREFIX));
                        if (isset($tag_uids[$tag_name])) {
                            $tags[] = $return_names ? $tag_name : $tag_uids[$tag_name];
                        }
                    }
                }

                $result[$msg->uid . '-' . $msg->folder] = $tags;
                return true;
            }

            return false;
        };

        // Check if the annotation is already FETCHED
        foreach ($messages as $msg) {
            if ($msg_func($msg)) {
                continue;
            }

            if (!isset($uids[$msg->folder])) {
                $uids[$msg->folder] = [];
            }

            $uids[$msg->folder][] = $msg->uid;
        }

        /** @var rcube_imap $imap */
        $imap = rcube::get_instance()->get_storage();
        $query_items = ['ANNOTATION (' . self::ANNOTATE_KEY_PREFIX . '% (value.priv))'];

        foreach ($uids as $folder => $_uids) {
            $fetch = $imap->conn->fetch($folder, $_uids, true, $query_items);

            if ($fetch) {
                foreach ($fetch as $msg) {
                    $msg_func($msg);
                }
            }
        }

        return $result;
    }

    /**
     * Assign a tag to mail messages
     */
    public function add_members(string $uid, string $folder, $uids)
    {
        if (($tag_name = $this->tag_name_by_uid($uid)) === null) {
            return false;
        }

        /** @var rcube_imap $imap */
        $imap = rcube::get_instance()->get_storage();
        $annotation = [];
        $annotation[self::ANNOTATE_KEY_PREFIX . $tag_name] = ['value.priv' => self::ANNOTATE_VALUE];

        return $imap->annotate_message($annotation, $uids, $folder);
    }

    /**
     * Delete a tag from mail messages
     */
    public function remove_members(string $uid, string $folder, $uids)
    {
        if (($tag_name = $this->tag_name_by_uid($uid)) === null) {
            return false;
        }

        /** @var rcube_imap $imap */
        $imap = rcube::get_instance()->get_storage();
        $annotation = [];
        $annotation[self::ANNOTATE_KEY_PREFIX . $tag_name] = ['value.priv' => null];

        return $imap->annotate_message($annotation, $uids, $folder);
    }

    /**
     * Update object's tags
     *
     * @param rcube_message|string $member Kolab object UID or mail message object
     * @param array                $tags   List of tag names
     */
    public function set_tags_for($member, $tags)
    {
        // Only mail for now
        if (!$member instanceof rcube_message && !$member instanceof rcube_message_header) {
            return [];
        }

        $members = $this->members_tags([$member], true);

        $tags = array_unique($tags);
        $existing = (array) array_first($members);
        $add = array_diff($tags, $existing);
        $remove = array_diff($existing, $tags);
        $annotations = [];

        if (!empty($remove)) {
            foreach ($remove as $tag_name) {
                $annotations[self::ANNOTATE_KEY_PREFIX . $tag_name] = ['value.priv' => null];
            }
        }

        if (!empty($add)) {
            $tags = $this->list_tags();
            $tag_names = array_column($tags, 'name');
            $new = false;

            foreach ($add as $tag_name) {
                if (!in_array($tag_name, $tag_names)) {
                    $tags[] = ['name' => $tag_name];
                    $new = true;
                }

                $annotations[self::ANNOTATE_KEY_PREFIX . $tag_name] = ['value.priv' => self::ANNOTATE_VALUE];
            }

            if ($new) {
                if (!$this->save_tags($tags)) {
                    return;
                }
            }
        }

        if (!empty($annotations)) {
            /** @var rcube_imap $imap */
            $imap = rcube::get_instance()->get_storage();
            $result = $imap->annotate_message($annotations, $member->uid, $member->folder);

            if (!$result) {
                rcube::raise_error("Failed to tag/untag a message ({$member->folder}/{$member->uid}. Error: "
                    . $imap->get_error_str(), true, false);
            }
        }
    }

    /**
     * Get tags assigned to a specified object.
     *
     * @param rcube_message|string $member Kolab object UID or mail message object
     *
     * @return array<int, string> List of tag names
     */
    public function get_tags_for($member)
    {
        // Only mail for now
        if (!$member instanceof rcube_message && !$member instanceof rcube_message_header) {
            return [];
        }

        // Get message's tags
        $members = $this->members_tags([$member], true);

        return (array) array_first($members);
    }

    /**
     * Returns IMAP SEARCH item to find messages with specific tag
     */
    public static function imap_search_criteria($tag_name)
    {
        return sprintf(
            'ANNOTATION %s value.priv %s',
            rcube_imap_generic::escape(self::ANNOTATE_KEY_PREFIX . $tag_name),
            rcube_imap_generic::escape(self::ANNOTATE_VALUE, true)
        );
    }

    /**
     * Get tags list from the storage (IMAP METADATA on INBOX)
     */
    protected function list_tags()
    {
        if (!isset($this->tags)) {
            $imap = rcube::get_instance()->get_storage();
            if (!$imap->get_capability('METADATA')) {
                return [];
            }

            $this->tags = [];
            if ($meta = $imap->get_metadata(self::METADATA_ROOT, self::METADATA_TAGS_KEY)) {
                $this->tags = json_decode($meta[self::METADATA_ROOT][self::METADATA_TAGS_KEY], true);
                foreach ($this->tags as &$tag) {
                    $tag['uid'] = md5($tag['name']);
                }
            }
        }

        return $this->tags;
    }

    /**
     * Get a tag name by uid
     */
    protected function tag_name_by_uid($uid)
    {
        foreach ($this->list_tags() as $tag) {
            if ($tag['uid'] === $uid) {
                return $tag['name'];
            }
        }

        return null;
    }

    /**
     * Store tags list in IMAP metadata
     */
    protected function save_tags($tags)
    {
        $imap = rcube::get_instance()->get_storage();
        if (!$imap->get_capability('METADATA')) {
            rcube::raise_error("Failed to store tags. Missing IMAP METADATA support", true, false);
            return false;
        }

        // Don't store UIDs
        foreach ($tags as &$tag) {
            unset($tag['uid']);
        }

        $tags = array_values($tags);

        $metadata = json_encode($tags, JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_UNICODE);

        if (!$imap->set_metadata(self::METADATA_ROOT, [self::METADATA_TAGS_KEY => $metadata])) {
            rcube::raise_error("Failed to store tags in IMAP. Error: " . $imap->get_error_str(), true, false);
            return false;
        }

        // Add the uid back, and update cached list of tags
        foreach ($tags as &$tag) {
            $tag['uid'] = md5($tag['name']);
        }

        $this->tags = $tags;

        return true;
    }
}
