<?php

/**
 * libkolab class tests
 *
 * @author Aleksander Machniak <machniak@apheleia-it.ch>
 *
 * Copyright (C) Apheleia IT <contact@apheleia-it.ch>
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

class LibkolabTest extends PHPUnit\Framework\TestCase
{
    public function test_html_diff_plain()
    {
        // Empty input
        $text1 = '';
        $text2 = '';
        $diff = libkolab::html_diff($text1, $text2);
        $this->assertSame('', $diff);

        $text1 = 'test plain text';
        $text2 = '';
        $diff = libkolab::html_diff($text1, $text2);
        $this->assertSame('<del>test plain text</del>', $diff);

        $text1 = '';
        $text2 = 'test plain text';
        $diff = libkolab::html_diff($text1, $text2);
        $this->assertSame('<ins>test plain text</ins>', $diff);

        $text1 = 'test plain text';
        $text2 = 'test plain text';
        $diff = libkolab::html_diff($text1, $text2);
        $this->assertSame('test plain text', $diff);

        // TODO: more cases e.g. multiline
    }

    public function test_html_diff_html()
    {
        $text1 = '<html><p>test plain text</p></html>';
        $text2 = '';
        $diff = libkolab::html_diff($text1, $text2);
        $this->assertSame('<p class="diffmod"><del class="diffmod">test plain text</del></p><div class="diffmod pre"></div>', $diff);

        $text1 = '';
        $text2 = '<html><p>test plain text</p></html>';
        $diff = libkolab::html_diff($text1, $text2);
        $this->assertSame('<div class="diffmod pre"></div><p class="diffmod"><ins class="diffmod">test plain text</ins></p>', $diff);

        $text1 = '<html><p>test plain text</p></html>';
        $text2 = 'test plain text';
        $diff = libkolab::html_diff($text1, $text2);
        $this->assertSame('<p class="diffmod"><div class="diffmod pre">test plain text</p></div>', $diff);

        $text1 = '<html><p>test plain text</p></html>';
        $text2 = '<html><p>test</p></html>';
        $diff = libkolab::html_diff($text1, $text2);
        $this->assertSame('<p>test<del class="diffdel"> plain text</del></p>', $diff);

        // TODO: more cases e.g. multiline
    }
}
