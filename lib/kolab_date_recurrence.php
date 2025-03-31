<?php

/**
 * Recurrence computation class for xcal-based Kolab format objects
 *
 * Utility class to compute instances of recurring events.
 * It requires the libcalendaring PHP extension to be installed and loaded.
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2012-2016, Kolab Systems AG <contact@kolabsys.com>
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

class kolab_date_recurrence
{
    /** @var EventCal */
    private $engine;

    /* @var kolab_format_xcal */
    private $object;

    /** @var DateTime */
    private $start;

    /** @var DateTime */
    private $next;

    /** @var cDateTime */
    private $cnext;

    /** @var DateInterval */
    private $duration;

    /** @var bool */
    private $allday;


    /**
     * Default constructor
     *
     * @param kolab_format_xcal $object The Kolab object to operate on
     */
    public function __construct($object)
    {
        $data = $object->to_array();

        $this->object = $object;
        $this->engine = $object->to_libcal();
        $this->start  = $this->next = $data['start'];
        $this->allday = !empty($data['allday']);
        $this->cnext  = kolab_format::get_datetime($this->next);

        if (is_object($data['start']) && is_object($data['end'])) {
            $this->duration = $data['start']->diff($data['end']);
        } else {
            // Prevent from errors when end date is not set (#5307) RFC5545 3.6.1
            $seconds = !empty($data['end']) ? ($data['end'] - $data['start']) : 0;
            $this->duration = new DateInterval('PT' . $seconds . 'S');
        }
    }

    /**
     * Get date/time of the next occurence of this event
     *
     * @param bool $timestamp Return a Unix timestamp instead of a DateTime object
     *
     * @return DateTime|int|false Object/unix timestamp or False if recurrence ended
     */
    public function next_start($timestamp = false)
    {
        $time = false;

        if ($this->engine && $this->next) {
            $cnext = new cDateTime($this->engine->getNextOccurence($this->cnext));
            if ($cnext->isValid()) {
                $next = kolab_format::php_datetime($cnext, $this->start->getTimezone());
                $time = $timestamp ? $next->format('U') : $next;

                if ($this->allday) {
                    // it looks that for allday events the occurrence time
                    // is reset to 00:00:00, this is causing various issues
                    $next->setTime($this->start->format('G'), $this->start->format('i'), $this->start->format('s'));
                    $next->_dateonly = true;
                }

                $this->cnext = $cnext;
                $this->next  = $next;
            }
        }

        return $time;
    }

    /**
     * Get the next recurring instance of this event
     *
     * @return mixed Array with event properties or False if recurrence ended
     */
    public function next_instance()
    {
        if ($next_start = $this->next_start()) {
            $next_end = clone $next_start;
            $next_end->add($this->duration);

            $next                    = $this->object->to_array();
            $recurrence_id_format    = libkolab::recurrence_id_format($next);
            $next['start']           = $next_start;
            $next['end']             = $next_end;
            $next['recurrence_date'] = clone $next_start;
            $next['_instance']       = $next_start->format($recurrence_id_format);

            unset($next['_formatobj']);

            return $next;
        }

        return false;
    }

    /**
     * Get the end date of the last occurence of this recurrence cycle
     *
     * @return DateTimeInterface|bool End datetime of the last event or False if recurrence exceeds limit
     */
    public function end()
    {
        $event = $this->object->to_array();

        // recurrence end date is given
        if (isset($event['recurrence']['UNTIL']) && $event['recurrence']['UNTIL'] instanceof DateTimeInterface) {
            return $event['recurrence']['UNTIL'];
        }

        // let libkolab do the work
        if ($this->engine && ($cend = $this->engine->getLastOccurrence())
            && ($end_dt = kolab_format::php_datetime(new cDateTime($cend)))
        ) {
            return $end_dt;
        }

        // determine a reasonable end date for an infinite recurrence
        if (empty($event['recurrence']['COUNT'])) {
            if (!empty($event['start']) && $event['start'] instanceof DateTime) {
                $start_dt = clone $event['start'];
                $start_dt->add(new DateInterval('P100Y'));
                return $start_dt;
            }
        }

        return false;
    }

    /**
     * Find date/time of the first occurrence
     *
     * @return DateTime|null First occurrence
     */
    public function first_occurrence()
    {
        $event      = $this->object->to_array();
        $start      = clone $this->start;
        $orig_start = clone $this->start;
        $interval   = intval($event['recurrence']['INTERVAL'] ?: 1);

        switch ($event['recurrence']['FREQ']) {
            case 'WEEKLY':
                if (empty($event['recurrence']['BYDAY'])) {
                    return $orig_start;
                }

                $start->sub(new DateInterval("P{$interval}W"));
                break;

            case 'MONTHLY':
                if (empty($event['recurrence']['BYDAY']) && empty($event['recurrence']['BYMONTHDAY'])) {
                    return $orig_start;
                }

                $start->sub(new DateInterval("P{$interval}M"));
                break;

            case 'YEARLY':
                if (empty($event['recurrence']['BYDAY']) && empty($event['recurrence']['BYMONTH'])) {
                    return $orig_start;
                }

                $start->sub(new DateInterval("P{$interval}Y"));
                break;

            case 'DAILY':
                if (!empty($event['recurrence']['BYMONTH'])) {
                    break;
                }

                // no break
            default:
                return $orig_start;
        }

        $event['start'] = $start;
        $event['recurrence']['INTERVAL'] = $interval;
        if (!empty($event['recurrence']['COUNT'])) {
            // Increase count so we do not stop the loop to early
            $event['recurrence']['COUNT'] += 100;
        }

        // Create recurrence that starts in the past
        $object_type = $this->object instanceof kolab_format_task ? 'task' : 'event';
        $object      = kolab_format::factory($object_type, 3.0);
        $object->set($event);
        $recurrence = new self($object);

        $orig_date = $orig_start->format('Y-m-d');
        $found     = false;

        // find the first occurrence
        while ($next = $recurrence->next_start()) {
            $start = $next;
            if ($next->format('Y-m-d') >= $orig_date) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            rcube::raise_error([
                'file' => __FILE__,
                'line' => __LINE__,
                'message' => sprintf(
                    "Failed to find a first occurrence. Start: %s, Recurrence: %s",
                    $orig_start->format(DateTime::ISO8601),
                    json_encode($event['recurrence'])
                ),
            ], true);

            return null;
        }

        if ($this->allday && $start instanceof libcalendaring_datetime) {
            $start->_dateonly = true;
        }

        return $start;
    }
}
