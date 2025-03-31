<?php

/**
 * Kolab storage cache class for calendar event objects
 *
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2013, Kolab Systems AG <contact@kolabsys.com>
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

class kolab_storage_cache_event extends kolab_storage_cache
{
    protected $extra_cols = ['dtstart','dtend'];
    protected $data_props = ['categories', 'status', 'attendees']; // start, end

    /**
     * Helper method to convert the given Kolab object into a dataset to be written to cache
     *
     * @override
     */
    protected function _serialize($object)
    {
        $sql_data = parent::_serialize($object);

        $sql_data['dtstart'] = $this->_convert_datetime($object['start'] ?? null);
        $sql_data['dtend']   = $this->_convert_datetime($object['end'] ?? null);

        // extend date range for recurring events
        if (!empty($object['recurrence'])) {
            $recurrence = new kolab_date_recurrence($object['_formatobj']);
            if ($dtend = $recurrence->end()) {
                $sql_data['dtend'] = $this->_convert_datetime($dtend);
            }
        }

        // extend start/end dates to spawn all exceptions
        if (!empty($object['exceptions'])) {
            foreach ($object['exceptions'] as $exception) {
                if (($exception['start'] ?? null) instanceof DateTimeInterface) {
                    $exstart = $this->_convert_datetime($exception['start']);
                    if ($exstart < $sql_data['dtstart']) {
                        $sql_data['dtstart'] = $exstart;
                    }
                }
                if (($exception['end'] ?? null) instanceof DateTimeInterface) {
                    $exend = $this->_convert_datetime($exception['end']);
                    if ($exend > $sql_data['dtend']) {
                        $sql_data['dtend'] = $exend;
                    }
                }
            }
        }

        return $sql_data;
    }
}
