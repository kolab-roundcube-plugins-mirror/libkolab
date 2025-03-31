<?php

/**
 * Kolab storage cache class for contact objects
 *
 * @author Aleksander Machniak <machniak@apcheleia-it.ch>
 *
 * Copyright (C) 2013-2022, Apheleia IT AG <contact@apcheleia-it.ch>
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

class kolab_storage_dav_cache_contact extends kolab_storage_dav_cache
{
    protected $extra_cols_max = 255;
    protected $extra_cols     = ['type', 'name', 'firstname', 'surname', 'email'];
    protected $data_props     = ['type', 'name', 'firstname', 'middlename', 'prefix', 'suffix', 'surname', 'email', 'organization', 'member'];
    protected $fulltext_cols  = ['name', 'firstname', 'surname', 'middlename', 'email'];

    /**
     * Helper method to convert the given Kolab object into a dataset to be written to cache
     *
     * @override
     */
    protected function _serialize($object)
    {
        $sql_data = parent::_serialize($object);
        $sql_data['type'] = !empty($object['_type']) ? $object['_type'] : 'contact';

        if ($sql_data['type'] == 'group' || (!empty($object['kind']) && $object['kind'] == 'group')) {
            $sql_data['type'] = 'group';
        }

        // columns for sorting
        $sql_data['name']      = rcube_charset::clean(($object['name'] ?? '') . ($object['prefix'] ?? ''));
        $sql_data['firstname'] = rcube_charset::clean(($object['firstname'] ?? '') . ($object['middlename'] ?? '') . ($object['surname'] ?? ''));
        $sql_data['surname']   = rcube_charset::clean(($object['surname'] ?? '') . ($object['firstname'] ?? '') . ($object['middlename'] ?? ''));
        $sql_data['email']     = '';

        foreach ($object as $colname => $value) {
            if (!empty($value) && ($colname == 'email' || strpos($colname, 'email:') === 0)) {
                $sql_data['email'] = is_array($value) ? $value[0] : $value;
                break;
            }
        }

        // use organization if name is empty
        if (empty($sql_data['name']) && !empty($object['organization'])) {
            $sql_data['name'] = rcube_charset::clean($object['organization']);
        }

        // make sure some data is not longer that database limit (#5291)
        foreach ($this->extra_cols as $col) {
            if (strlen($sql_data[$col]) > $this->extra_cols_max) {
                $sql_data[$col] = rcube_charset::clean(substr($sql_data[$col], 0, $this->extra_cols_max));
            }
        }

        $sql_data['tags']  = ' ' . implode(' ', $this->get_tags($object)) . ' ';  // pad with spaces for strict/prefix search
        $sql_data['words'] = ' ' . implode(' ', $this->get_words($object)) . ' ';

        return $sql_data;
    }

    /**
     * Callback to get words to index for fulltext search
     *
     * @return array List of words to save in cache
     */
    public function get_words($object)
    {
        $data = '';

        foreach ($object as $colname => $value) {
            [$col, $field] = strpos($colname, ':') ? explode(':', $colname) : [$colname, null];

            $val = null;
            if (in_array($col, $this->fulltext_cols)) {
                $val = is_array($value) ? implode(' ', $value) : $value;
            }

            if (is_string($val) && strlen($val)) {
                $data .= $val . ' ';
            }
        }

        return array_unique(rcube_utils::normalize_string($data, true));
    }

    /**
     * Callback to get object specific tags to cache
     *
     * @return array List of tags to save in cache
     */
    public function get_tags($object)
    {
        $tags = [];

        if (!empty($object['birthday'])) {
            $tags[] = 'x-has-birthday';
        }

        return $tags;
    }
}
