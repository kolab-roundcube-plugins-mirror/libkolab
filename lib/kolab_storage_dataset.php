<?php

/**
 * Dataset class providing the results of a select operation on a kolab_storage_folder.
 *
 * Can be used as a normal array as well as an iterator in foreach() loops.
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2014, Kolab Systems AG <contact@kolabsys.com>
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

class kolab_storage_dataset implements Iterator, ArrayAccess, Countable
{
    public const CHUNK_SIZE = 25;

    private $cache;  // kolab_storage_cache instance to use for fetching data
    private $memlimit = 0;
    private $buffer = false;
    private $index = [];
    private $data = [];
    private $iteratorkey = 0;
    private $error = null;
    private $chunk = [];

    /**
     * Default constructor
     *
     * @param kolab_storage_cache $cache Cache instance to be used for fetching objects upon access
     */
    public function __construct($cache)
    {
        $this->cache = $cache;

        // enable in-memory buffering up until 1/5 of the available memory
        if (function_exists('memory_get_usage')) {
            $this->memlimit = parse_bytes(ini_get('memory_limit')) / 5;
            $this->buffer = true;
        }
    }

    /**
     * Return error state
     */
    public function is_error()
    {
        return !empty($this->error);
    }

    /**
     * Set error state
     */
    public function set_error($err)
    {
        $this->error = $err;
    }


    /*** Implement PHP Countable interface ***/

    public function count(): int
    {
        return count($this->index);
    }


    /*** Implement PHP ArrayAccess interface ***/

    public function offsetSet($offset, $value): void
    {
        if (is_string($value)) {
            $uid = $value;
        } else {
            $uid = !empty($value['_msguid']) ? $value['_msguid'] : $value['uid'];
        }

        if (is_null($offset)) {
            $offset = count($this->index);
        }

        $this->index[$offset] = $uid;

        // keep full payload data in memory if possible
        if ($this->memlimit && $this->buffer) {
            $this->data[$offset] = $value;

            // check memory usage and stop buffering
            if ($offset % 10 == 0) {
                $this->buffer = memory_get_usage() < $this->memlimit;
            }
        }
    }

    public function offsetExists($offset): bool
    {
        return isset($this->index[$offset]);
    }

    public function offsetUnset($offset): void
    {
        unset($this->index[$offset]);
    }

    #[ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        if (isset($this->chunk[$offset])) {
            return $this->chunk[$offset] ?: null;
        }

        // The item is a string (object's UID), use multiget method to pre-fetch
        // multiple objects from the server in one request
        if (isset($this->data[$offset]) && is_string($this->data[$offset]) && method_exists($this->cache, 'multiget')) {
            $idx  = $offset;
            $uids = [];

            while (isset($this->index[$idx]) && count($uids) < self::CHUNK_SIZE) {
                if (isset($this->data[$idx]) && !is_string($this->data[$idx])) {
                    // skip objects that had the raw content in the cache (are not empty)
                } else {
                    $uids[$idx] = $this->index[$idx];
                }

                $idx++;
            }

            if (!empty($uids)) {
                $this->chunk = $this->cache->multiget($uids);
            }

            if (isset($this->chunk[$offset])) {
                return $this->chunk[$offset] ?: null;
            }

            return null;
        }

        if (isset($this->data[$offset])) {
            return $this->data[$offset];
        }

        if ($uid = ($this->index[$offset] ?? null)) {
            return $this->cache->get($uid);
        }

        return null;
    }


    /*** Implement PHP Iterator interface ***/

    #[ReturnTypeWillChange]
    public function current()
    {
        return $this->offsetGet($this->iteratorkey);
    }

    public function key(): int
    {
        return $this->iteratorkey;
    }

    public function next(): void
    {
        $this->iteratorkey++;
    }

    public function rewind(): void
    {
        $this->iteratorkey = 0;
    }

    public function valid(): bool
    {
        return !empty($this->index[$this->iteratorkey]);
    }
}
