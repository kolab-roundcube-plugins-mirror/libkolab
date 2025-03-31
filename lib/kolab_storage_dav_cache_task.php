<?php

/**
 * Kolab storage cache class for task objects
 *
 * @author Aleksander Machniak <machniak@apheleia-it.ch>
 *
 * Copyright (C) 2013-2022 Apheleia IT AG <contact@apheleia-it.ch>
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

class kolab_storage_dav_cache_task extends kolab_storage_dav_cache
{
    protected $extra_cols = ['dtstart','dtend'];
    protected $data_props = ['categories', 'status', 'complete', 'start', 'due'];
    protected $fulltext_cols = ['title', 'description', 'categories'];

    /**
     * Helper method to convert the given Kolab object into a dataset to be written to cache
     *
     * @override
     */
    protected function _serialize($object)
    {
        $sql_data = parent::_serialize($object);

        $sql_data['dtstart'] = !empty($object['start']) ? $this->_convert_datetime($object['start']) : null;
        $sql_data['dtend']   = !empty($object['due']) ? $this->_convert_datetime($object['due']) : null;

        $sql_data['tags']  = ' ' . implode(' ', $this->get_tags($object)) . ' ';  // pad with spaces for strict/prefix search
        $sql_data['words'] = ' ' . implode(' ', $this->get_words($object)) . ' ';

        return $sql_data;
    }

    /**
     * Callback to get words to index for fulltext search
     *
     * @return array List of words to save in cache
     */
    public function get_words($object = [])
    {
        $data = '';

        foreach ($this->fulltext_cols as $colname) {
            [$col, $field] = strpos($colname, ':') ? explode(':', $colname) : [$colname, null];

            if (empty($object[$col])) {
                continue;
            }

            if ($field) {
                $a = [];
                foreach ((array) $object[$col] as $attr) {
                    if (!empty($attr[$field])) {
                        $a[] = $attr[$field];
                    }
                }
                $val = implode(' ', $a);
            } else {
                $val = is_array($object[$col]) ? implode(' ', $object[$col]) : $object[$col];
            }

            if (is_string($val) && strlen($val)) {
                $data .= $val . ' ';
            }
        }

        $words = rcube_utils::normalize_string($data, true);

        return array_unique($words);
    }

    /**
     * Callback to get object specific tags to cache
     *
     * @return array List of tags to save in cache
     */
    public function get_tags($object)
    {
        $tags = [];

        if ((isset($object['status']) && $object['status'] == 'COMPLETED')
            || (isset($object['complete']) && $object['complete'] == 100 && empty($object['status']))
        ) {
            $tags[] = 'x-complete';
        }

        if (!empty($object['priority']) && $object['priority'] == 1) {
            $tags[] = 'x-flagged';
        }

        if (!empty($object['parent_id'])) {
            $tags[] = 'x-parent:' . $object['parent_id'];
        }

        return array_unique($tags);
    }
}
