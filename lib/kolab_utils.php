<?php

/**
 * Utility class providing unified functionality for other plugins.
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2012-2018, Kolab Systems AG <contact@kolabsys.com>
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

class kolab_utils
{
    public static function folder_form($form, $folder, $domain, $hidden_fields = [], $no_acl = false)
    {
        $rcmail = rcmail::get_instance();

        // add folder ACL tab
        if (!$no_acl && $folder) {
            if (($folder instanceof kolab_storage_dav_folder) || (is_string($folder) && strlen($folder))) {
                $sharing_content = self::folder_acl_form($folder);
                $form['sharing'] = [
                    'name'    => rcube::Q($rcmail->gettext('libkolab.tabsharing')),
                    'content' => $sharing_content,
                ];
            }
        }

        $form_html = '';

        if (is_array($hidden_fields)) {
            foreach ($hidden_fields as $field) {
                $hiddenfield = new html_hiddenfield($field);
                $form_html .= $hiddenfield->show() . "\n";
            }
        }

        // Allow plugins to add something to the form (e.g. kolab_activesync)
        $plugin = $rcmail->plugins->exec_hook('kolab_folder_form', [
                'form'   => $form,
                'folder' => $folder,
        ]);

        // create form output
        foreach ($plugin['form'] as $tab) {
            if (isset($tab['fields']) && is_array($tab['fields']) && empty($tab['content'])) {
                $table = new html_table(['cols' => 2, 'class' => 'propform']);
                foreach ($tab['fields'] as $col => $colprop) {
                    $label = !empty($colprop['label']) ? $colprop['label'] : $rcmail->gettext("$domain.$col");

                    $table->add('title', html::label($colprop['id'], rcube::Q($label)));
                    $table->add(null, $colprop['value']);
                }
                $content = $table->show();
            } else {
                $content = $tab['content'];
            }

            if (!empty($content)) {
                $form_html .= html::tag('fieldset', null, html::tag('legend', null, rcube::Q($tab['name'])) . $content) . "\n";
            }
        }

        return $form_html;
    }

    /**
     * Handler for ACL form template object
     *
     * @param string|kolab_storage_dav_folder $folder DAV folder object or IMAP folder name
     *
     * @return ?string HTML content
     */
    private static function folder_acl_form($folder)
    {
        if ($folder instanceof kolab_storage_dav_folder) {
            return self::folder_dav_acl_form($folder);
        }

        $rcmail  = rcmail::get_instance();
        $storage = $rcmail->get_storage();
        $options = $storage->folder_info($folder);

        $rcmail->plugins->load_plugin('acl', true);

        // get sharing UI from acl plugin
        $acl = $rcmail->plugins->exec_hook('folder_form', [
                'form'    => [],
                'options' => $options,
                'name'    => $folder,
        ]);

        if (!empty($acl['form']['sharing']['content'])) {
            return $acl['form']['sharing']['content'];
        }

        return html::div('hint', $rcmail->gettext('libkolab.aclnorights'));
    }

    /**
     * Handler for DAV ACL form template object
     *
     * @param kolab_storage_dav_folder $folder DAV folder object
     *
     * @return ?string HTML content
     */
    private static function folder_dav_acl_form($folder)
    {
        $rcmail = rcmail::get_instance();
        $sharing = $rcmail->config->get('kolab_dav_sharing');

        if (!$sharing) {
            return null;
        }

        $class = 'kolab_dav_' . $sharing;

        if ($form = $class::form($folder)) {
            return $form;
        }

        return html::div('hint', rcmail::get_instance()->gettext('libkolab.aclnorights'));
    }
}
