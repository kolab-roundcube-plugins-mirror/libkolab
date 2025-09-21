<?php

/*
 * DAV sharing based on draft-pot-webdav-resource-sharing standard
 *
 * Copyright (C) Apheleia IT <contact@aphelaia-it.ch>
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

/**
 * A class providing DAV sharing management functionality
 */
class kolab_dav_sharing
{
    public const PRIVILEGE_READ = 'read';
    public const PRIVILEGE_WRITE = 'write';

    /** @var ?kolab_storage_dav_folder $folder Current folder */
    private static $folder;

    /** @var array Special principals */
    private static $specials = [];


    /**
     * Handler for actions from the Sharing dialog (AJAX)
     */
    public static function actions()
    {
        $action = trim(rcube_utils::get_input_string('_act', rcube_utils::INPUT_GPC));

        if ($action == 'save') {
            self::action_save();
        } elseif ($action == 'delete') {
            self::action_delete();
        }

        rcmail::get_instance()->output->send();
    }

    /**
     * Returns folder sharing form (for a Sharing tab)
     *
     * @param kolab_storage_dav_folder $folder
     *
     * @return null|string Form HTML content
     */
    public static function form($folder)
    {
        $rcmail = rcmail::get_instance();
        $myrights = $folder->get_myrights();

        // Any privileges?
        if (empty($myrights)) {
            return null;
        }

        // Return if not folder admin nor can share
        if (strpos($myrights, 'a') === false && strpos($myrights, '1') === false) {
            return null;
        }

        self::$folder = $folder;

        // Add localization labels and include scripts
        $rcmail->output->add_label(
            'libkolab.nouser',
            'libkolab.newuser',
            'libkolab.editperms',
            'libkolab.deleteconfirm',
            'libkolab.delete',
            'libkolab.norights',
            'libkolab.saving'
        );

        $rcmail->output->include_script('list.js');
        $rcmail->plugins->include_script('libkolab/libkolab.js');

        $rcmail->output->add_handlers([
            'acltable' => [__CLASS__, 'templ_table'],
            'acluser' => [__CLASS__, 'templ_user'],
            'aclrights' => [__CLASS__, 'templ_rights'],
        ]);

        $rcmail->output->set_env('acl_target', self::get_folder_id($folder));
        // $rcmail->output->set_env('acl_users_source', (bool) $this->rc->config->get('acl_users_source'));
        // $rcmail->output->set_env('autocomplete_max', (int) $rcmail->config->get('autocomplete_max', 15));
        // $rcmail->output->set_env('autocomplete_min_length', $rcmail->config->get('autocomplete_min_length'));
        // $rcmail->output->add_label('autocompletechars', 'autocompletemore');

        return $rcmail->output->parse('libkolab.acl', false, false);
    }

    /**
     * Creates sharees table
     *
     * @param array $attrib Template object attributes
     *
     * @return string HTML Content
     */
    public static function templ_table($attrib)
    {
        if (empty($attrib['id'])) {
            $attrib['id'] = 'acl-table';
        }

        $out = self::list_rights($attrib);

        $rcmail = rcmail::get_instance();
        $rcmail->output->add_gui_object('acltable', $attrib['id']);

        return $out;
    }

    /**
     * Creates sharing form (rights list part)
     *
     * @param array $attrib Template object attributes
     *
     * @return string HTML Content
     */
    public static function templ_rights($attrib)
    {
        $rcmail = rcmail::get_instance();
        $input = new html_checkbox();
        $ul = '';
        $attrib['id'] = 'rights';

        $rights = [
            self::PRIVILEGE_READ,
            self::PRIVILEGE_WRITE,
        ];

        foreach ($rights as $right) {
            $id = "acl{$right}";
            $label = $rcmail->gettext($rcmail->text_exists("libkolab.acllong{$right}") ? "libkolab.acllong{$right}" : "libkolab.acl{$right}");
            $ul .= html::tag(
                'li',
                null,
                $input->show('', ['name' => "acl[{$right}]", 'value' => $right, 'id' => $id])
                . html::label(['for' => $id], $label)
            );
        }

        return html::tag('ul', $attrib, $ul, html::$common_attrib);
    }

    /**
     * Creates sharing form (user part)
     *
     * @param array $attrib Template object attributes
     *
     * @return string HTML Content
     */
    public static function templ_user($attrib)
    {
        $rcmail = rcmail::get_instance();

        // Create username input
        $class = !empty($attrib['class']) ? $attrib['class'] : '';
        $attrib['name'] = 'acluser';
        $attrib['class'] = 'form-control';

        $textfield = new html_inputfield($attrib);
        $label = html::label(['for' => $attrib['id'], 'class' => 'input-group-text'], $rcmail->gettext('libkolab.username'));

        $fields = [
            'user' => html::div(
                'input-group',
                html::span('input-group-prepend', $label) . ' ' . $textfield->show()
            ),
        ];

        foreach (self::$specials as $type) {
            $fields[$type] = html::label(['for' => 'id' . $type], $rcmail->gettext("libkolab.{$type}"));
        }

        $ul = '';

        if (count($fields) == 1) {
            $ul = html::tag('li', null, $fields['user']);
        } else {
            // Create list with radio buttons
            foreach ($fields as $key => $val) {
                $radio = new html_radiobutton(['name' => 'usertype']);
                $radio = $radio->show($key == 'user' ? 'user' : '', ['value' => $key, 'id' => 'id' . $key]);
                $ul .= html::tag('li', null, $radio . $val);
            }
        }

        return html::tag('ul', ['id' => 'usertype', 'class' => $class], $ul, html::$common_attrib);
    }

    /**
     * Creates sharees table
     *
     * @param array $attrib Template object attributes
     *
     * @return string HTML Content
     */
    private static function list_rights($attrib = [])
    {
        $rcmail = rcmail::get_instance();

        // Get ACL for the folder
        $acl = self::$folder->get_sharees();

        // Sort the list by username
        uksort($acl, 'strnatcasecmp');

        // Move special entries to the top
        $specials = [];
        foreach (self::$specials as $key) {
            if (isset($acl[$key])) {
                $specials[$key] = $acl[$key];
                unset($acl[$key]);
            }
        }

        if (count($specials) > 0) {
            $acl = array_merge($specials, $acl);
        }

        $cols = [
            self::PRIVILEGE_READ,
            self::PRIVILEGE_WRITE,
        ];

        // Create the table
        $attrib['noheader'] = true;
        $table = new html_table($attrib);
        $js_table = [];

        // Create table header
        $table->add_header('user', $rcmail->gettext('libkolab.identifier'));
        foreach ($cols as $right) {
            $label = $rcmail->gettext("libkolab.acl{$right}");
            $table->add_header(['class' => "acl{$right}", 'title' => $label], $label);
        }

        foreach ($acl as $user => $sharee) {
            if (!in_array($sharee['access'], [kolab_dav_client::SHARING_READ, kolab_dav_client::SHARING_READ_WRITE])) {
                continue;
            }

            $access = $sharee['access'];
            $userid = rcube_utils::html_identifier($user);
            $title = null;

            if (!empty($specials) && isset($specials[$user])) {
                $username = $rcmail->gettext("libkolab.{$user}");
            } else {
                $username = $user;
            }

            $table->add_row(['id' => 'rcmrow' . $userid, 'data-userid' => $user]);
            $table->add(
                ['class' => 'user text-nowrap', 'title' => $title],
                html::a(['id' => 'rcmlinkrow' . $userid], rcube::Q($username))
            );

            $rights = [];
            foreach ($cols as $right) {
                $enabled = (
                    $right == self::PRIVILEGE_READ
                        && ($access == kolab_dav_client::SHARING_READ || $access == kolab_dav_client::SHARING_READ_WRITE)
                ) || (
                    $right == self::PRIVILEGE_WRITE
                    && $access == kolab_dav_client::SHARING_READ_WRITE
                );
                $class = $enabled ? 'enabled' : 'disabled';
                $table->add('acl' . $right . ' ' . $class, '<span></span>');

                if ($enabled) {
                    $rights[] = $right;
                }
            }

            $js_table[$userid] = $access;
        }

        $rcmail->output->set_env('acl', $js_table);
        $rcmail->output->set_env('acl_specials', self::$specials);

        return $table->show();
    }

    /**
     * Handler for sharee update/create action
     */
    private static function action_save()
    {
        $rcmail = rcmail::get_instance();
        $target = trim(rcube_utils::get_input_string('_target', rcube_utils::INPUT_POST, true));
        $user = trim(rcube_utils::get_input_string('_user', rcube_utils::INPUT_POST));
        $acl = trim(rcube_utils::get_input_string('_acl', rcube_utils::INPUT_POST));
        $oldid = trim(rcube_utils::get_input_string('_old', rcube_utils::INPUT_POST));

        $users = $oldid ? [$user] : explode(',', $user);
        $self = $rcmail->get_user_name();
        $updates = [];

        $folder = self::get_folder($target);

        if (!$folder || !$acl) {
            $rcmail->output->show_message($oldid ? 'libkolab.updateerror' : 'libkolab.createerror', 'error');
            return;
        }

        $sharees = $folder->get_sharees();
        $acl = explode(',', $acl);

        foreach ($users as $user) {
            $user = trim($user);
            $username = '';

            if (in_array($user, self::$specials)) {
                $username = $rcmail->gettext("libkolab.{$user}");
            } elseif (!empty($user)) {
                if (!strpos($user, '@') && ($realm = self::get_realm())) {
                    $user .= '@' . rcube_utils::idn_to_ascii(preg_replace('/^@/', '', $realm));
                }

                // Make sure it's valid email address
                if (strpos($user, '@') && !rcube_utils::check_email($user, false)) {
                    $user = null;
                }

                $username = $user;
            }

            if (!$user) {
                continue;
            }

            if ($user == $self && $username == $self) {
                continue;
            }

            if (in_array(self::PRIVILEGE_WRITE, $acl)) {
                $access = kolab_dav_client::SHARING_READ_WRITE;
            } elseif (in_array(self::PRIVILEGE_READ, $acl)) {
                $access = kolab_dav_client::SHARING_READ;
            } else {
                $access = kolab_dav_client::SHARING_NO_ACCESS;
            }

            if (isset($sharees[$user])) {
                $sharees[$user]['access'] = $access;
            } else {
                $sharees[$user] = ['access' => $access];
            }

            $updates[] = [
                'id' => rcube_utils::html_identifier($user),
                'username' => $user,
                'display' => $username,
                'acl' => $acl,
                'old' => $oldid,
            ];
        }

        if (count($updates) > 0 && $folder->set_sharees($sharees)) {
            foreach ($updates as $command) {
                $rcmail->output->command('acl_update', $command);
            }
            $rcmail->output->show_message($oldid ? 'libkolab.updatesuccess' : 'libkolab.createsuccess', 'confirmation');
        } else {
            $rcmail->output->show_message($oldid ? 'libkolab.updateerror' : 'libkolab.createerror', 'error');
        }
    }

    /**
     * Handler for sharee delete action
     */
    private static function action_delete()
    {
        $rcmail = rcmail::get_instance();
        $target = trim(rcube_utils::get_input_string('_target', rcube_utils::INPUT_POST, true));
        $user = trim(rcube_utils::get_input_string('_user', rcube_utils::INPUT_POST));

        $folder = self::get_folder($target);
        $users = explode(',', $user);

        if (!$folder) {
            $rcmail->output->show_message('libkolab.deleteerror', 'error');
            return;
        }

        $sharees = $folder->get_sharees();

        foreach ($users as $user) {
            $user = trim($user);
            $sharees[$user]['access'] = kolab_dav_client::SHARING_NO_ACCESS;
        }

        if ($folder->set_sharees($sharees)) {
            foreach ($users as $user) {
                $rcmail->output->command('acl_remove_row', rcube_utils::html_identifier($user));
            }

            $rcmail->output->show_message('libkolab.deletesuccess', 'confirmation');
        } else {
            $rcmail->output->show_message('libkolab.deleteerror', 'error');
        }
    }

    /**
     * Username realm detection.
     *
     * @return string Username realm (domain)
     */
    private static function get_realm()
    {
        // When user enters a username without domain part, realm
        // allows to add it to the username (and display correct username in the table)

        if (isset($_SESSION['acl_user_realm'])) {
            return $_SESSION['acl_user_realm'];
        }

        $rcmail = rcmail::get_instance();
        $self = $rcmail->get_user_name();

        // find realm in username of logged user (?)
        [$name, $domain] = rcube_utils::explode('@', $self);

        return $_SESSION['acl_username_realm'] = $domain;
    }

    /**
     * Get DAV folder object by ID
     */
    private static function get_folder($id)
    {
        if (strpos($id, '?')) {
            [$server_url, $folder_href] = explode('?', $id, 2);

            $dav = new kolab_dav_client($server_url);
            $props = $dav->folderInfo($folder_href);

            if ($props) {
                return new kolab_storage_dav_folder($dav, $props);
            }
        }

        return null;
    }

    /**
     * Get DAV folder identifier (with the server info)
     */
    private static function get_folder_id($folder)
    {
        // the folder identifier needs to easily allow for
        // connecting to the DAV server and getting/setting ACL
        // TODO: It might be a security issue, consider generating ID and using session
        // so the server URL is not revealed in the UI.
        return $folder->dav->url . '?' . $folder->href;
    }
}
