<?php

/**
 * Kolab core library
 *
 * Plugin to setup a basic environment for the interaction with a Kolab server.
 * Other Kolab-related plugins will depend on it and can use the library classes
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2012-2015, Kolab Systems AG <contact@kolabsys.com>
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

class libkolab extends rcube_plugin
{
    public static $http_requests = [];
    public static $bonnie_api    = false;

    /**
     * Required startup method of a Roundcube plugin
     */
    public function init()
    {
        $rcmail = rcube::get_instance();

        // load local config
        $this->load_config();
        $this->require_plugin('libcalendaring');

        // extend include path to load bundled lib classes
        $include_path = $this->home . '/lib' . PATH_SEPARATOR . ini_get('include_path');
        set_include_path($include_path);

        $this->add_hook('storage_init', [$this, 'storage_init']);
        $this->add_hook('storage_connect', [$this, 'storage_connect']);
        $this->add_hook('user_delete', ['kolab_storage', 'delete_user_folders']);

        // For Chwala
        $this->add_hook('folder_mod', ['kolab_storage', 'folder_mod']);

        // For DAV ACL
        if ($sharing = $rcmail->config->get('kolab_dav_sharing')) {
            $class = 'kolab_dav_' . $sharing;
            $this->register_action('plugin.davacl', "$class::actions");
            $this->register_action('plugin.davacl-autocomplete', "$class::autocomplete");
        }

        try {
            kolab_format::$timezone = new DateTimeZone($rcmail->config->get('timezone', 'GMT'));
        } catch (Exception $e) {
            rcube::raise_error($e, true);
            kolab_format::$timezone = new DateTimeZone('GMT');
        }

        $this->add_texts('localization/', false);

        if (!empty($rcmail->output->type) && $rcmail->output->type == 'html') {
            // @phpstan-ignore-next-line
            $rcmail->output->add_handler('libkolab.folder_search_form', [$this, 'folder_search_form']);
            $this->include_stylesheet($this->local_skin_path() . '/libkolab.css');
        }

        // embed scripts and templates for email message audit trail
        if (property_exists($rcmail, 'task') && $rcmail->task == 'mail' && self::get_bonnie_api()) {
            if (!empty($rcmail->output->type) && $rcmail->output->type == 'html') {
                $this->add_hook('render_page', [$this, 'bonnie_render_page']);
                $this->include_script('libkolab.js');

                // add 'Show history' item to message menu
                $this->api->add_content(
                    html::tag(
                        'li',
                        ['role' => 'menuitem'],
                        $this->api->output->button([
                            'command'  => 'kolab-mail-history',
                            'label'    => 'libkolab.showhistory',
                            'type'     => 'link',
                            'classact' => 'icon history active',
                            'class'    => 'icon history disabled',
                            'innerclass' => 'icon history',
                        ])
                    ),
                    'messagemenu'
                );
            }

            $this->register_action('plugin.message-changelog', [$this, 'message_changelog']);
        }
    }

    /**
     * Hook into IMAP FETCH HEADER.FIELDS command and request Kolab-specific headers
     */
    public function storage_init($p)
    {
        $kolab_headers = 'X-KOLAB-TYPE X-KOLAB-MIME-VERSION MESSAGE-ID';

        if (!empty($p['fetch_headers'])) {
            $p['fetch_headers'] .= ' ' . $kolab_headers;
        } else {
            $p['fetch_headers'] = $kolab_headers;
        }

        return $p;
    }

    /**
     * Hook into IMAP connection to replace client identity
     */
    public function storage_connect($p)
    {
        $client_name = 'Roundcube/Kolab';

        if (empty($p['ident'])) {
            $p['ident'] = [
                'name'    => $client_name,
                'version' => RCUBE_VERSION,
/*
                'php'     => PHP_VERSION,
                'os'      => PHP_OS,
                'command' => $_SERVER['REQUEST_URI'],
*/
            ];
        } else {
            $p['ident']['name'] = $client_name;
        }

        return $p;
    }

    /**
     * Getter for a singleton instance of the Bonnie API
     *
     * @return mixed kolab_bonnie_api instance if configured, false otherwise
     */
    public static function get_bonnie_api()
    {
        // get configuration for the Bonnie API
        if (!self::$bonnie_api && ($bonnie_config = rcube::get_instance()->config->get('kolab_bonnie_api', false))) {
            self::$bonnie_api = new kolab_bonnie_api($bonnie_config);
        }

        return self::$bonnie_api;
    }

    /**
     * Hook to append the message history dialog template to the mail view
     */
    public function bonnie_render_page($p)
    {
        if (($p['template'] === 'mail' || $p['template'] === 'message') && !$p['kolab-audittrail']) {
            // append a template for the audit trail dialog
            $this->api->output->add_footer(
                html::div(
                    ['id' => 'mailmessagehistory',  'class' => 'uidialog', 'aria-hidden' => 'true', 'style' => 'display:none'],
                    self::object_changelog_table(['class' => 'records-table changelog-table'])
                )
            );
            $this->api->output->set_env('kolab_audit_trail', true);
            $p['kolab-audittrail'] = true;
        }

        return $p;
    }

    /**
     * Handler for message audit trail changelog requests
     */
    public function message_changelog()
    {
        if (!self::$bonnie_api) {
            return false;
        }

        $rcmail = rcmail::get_instance();
        $msguid = rcube_utils::get_input_value('_uid', rcube_utils::INPUT_POST, true);
        $mailbox = rcube_utils::get_input_value('_mbox', rcube_utils::INPUT_POST);

        $result = $msguid && $mailbox ? self::$bonnie_api->changelog('mail', null, $mailbox, $msguid) : null;
        if (is_array($result)) {
            if (is_array($result['changes'])) {
                $dtformat = $rcmail->config->get('date_format') . ' ' . $rcmail->config->get('time_format');
                array_walk($result['changes'], function (&$change) use ($dtformat, $rcmail) {
                    if ($change['date']) {
                        $dt = rcube_utils::anytodatetime($change['date']);
                        if ($dt instanceof DateTimeInterface) {
                            $change['date'] = $rcmail->format_date($dt, $dtformat);
                        }
                    }
                });
            }
            $this->api->output->command('plugin.message_render_changelog', $result['changes']);
        } else {
            $this->api->output->command('plugin.message_render_changelog', false);
        }

        $this->api->output->send();
    }

    /**
     * Wrapper function to load and initalize the HTTP_Request2 Object
     *
     * @param string|Net_URL2 $url    Request URL
     * @param string          $method Request method ('OPTIONS','GET','HEAD','POST','PUT','DELETE','TRACE','CONNECT')
     * @param array           $config Configuration for this Request instance, that will be merged
     *                                with default configuration
     *
     * @return HTTP_Request2 Request object
     */
    public static function http_request($url = '', $method = 'GET', $config = [])
    {
        $rcube       = rcube::get_instance();
        $http_config = (array) $rcube->config->get('kolab_http_request');

        // deprecated configuration options
        if (empty($http_config)) {
            foreach (['ssl_verify_peer', 'ssl_verify_host'] as $option) {
                $value = $rcube->config->get('kolab_' . $option, true);
                if (is_bool($value)) {
                    $http_config[$option] = $value;
                }
            }
        }

        if (!empty($config)) {
            $http_config = array_merge($http_config, $config);
        }

        // force CURL adapter, this allows to handle correctly
        // compressed responses with SplObserver registered (kolab_files) (#4507)
        $http_config['adapter'] = 'HTTP_Request2_Adapter_Curl';

        $key = md5(serialize($http_config));

        if (!empty(self::$http_requests[$key])) {
            $request = self::$http_requests[$key];
        } else {
            // load HTTP_Request2 (support both composer-installed and system-installed package)
            if (!class_exists('HTTP_Request2')) {
                require_once 'HTTP/Request2.php';
            }

            try {
                $request = new HTTP_Request2();
                $request->setConfig($http_config);
            } catch (Exception $e) {
                rcube::raise_error($e, true, true);
            }

            // proxy User-Agent string
            $request->setHeader('user-agent', $_SERVER['HTTP_USER_AGENT']);

            self::$http_requests[$key] = $request;
        }

        // cleanup
        try {
            $request->setBody('');
            $request->setUrl($url);
            $request->setMethod($method);
        } catch (Exception $e) {
            rcube::raise_error($e, true, true);
        }

        return $request;
    }

    /**
     * Table oultine for object changelog display
     */
    public static function object_changelog_table($attrib = [])
    {
        $rcube = rcmail::get_instance();
        $attrib += ['domain' => 'libkolab'];

        $table = new html_table(['cols' => 5, 'border' => 0, 'cellspacing' => 0]);
        $table->add_header('diff', '');
        $table->add_header('revision', $rcube->gettext('revision', $attrib['domain']));
        $table->add_header('date', $rcube->gettext('date', $attrib['domain']));
        $table->add_header('user', $rcube->gettext('user', $attrib['domain']));
        $table->add_header('operation', $rcube->gettext('operation', $attrib['domain']));
        $table->add_header('actions', '&nbsp;');

        $rcube->output->add_label(
            'libkolab.showrevision',
            'libkolab.actionreceive',
            'libkolab.actionappend',
            'libkolab.actionmove',
            'libkolab.actiondelete',
            'libkolab.actionread',
            'libkolab.actionflagset',
            'libkolab.actionflagclear',
            'libkolab.objectchangelog',
            'libkolab.objectchangelognotavailable',
            'close'
        );

        return $table->show($attrib);
    }

    /**
     * Wrapper function for generating a html diff using the FineDiff class by Raymond Hill
     */
    public static function html_diff($from, $to, $is_html = null)
    {
        // auto-detect text/html format
        if ($is_html === null) {
            $from_html = preg_match('/<(html|body)(\s+[a-z]|>)/', $from, $m) && strpos($from, '</' . $m[1] . '>') > 0;
            $to_html   = preg_match('/<(html|body)(\s+[a-z]|>)/', $to, $m) && strpos($to, '</' . $m[1] . '>') > 0;
            $is_html   = $from_html || $to_html;

            // ensure both parts are of the same format
            if ($is_html && !$from_html) {
                $converter = new rcube_text2html($from, false, ['wrap' => true]);
                $from = $converter->get_html();
            }
            if ($is_html && !$to_html) {
                $converter = new rcube_text2html($to, false, ['wrap' => true]);
                $to = $converter->get_html();
            }
        }

        // compute diff from HTML
        if ($is_html) {
            // replace data: urls with a transparent image to avoid memory problems
            $src  = 'src="data:image/gif;base64,R0lGODlhAQABAPAAAOjq6gAAACH/C1hNUCBEYXRhWE1QAT8AIfkEBQAAAAAsAAAAAAEAAQAAAgJEAQA7';
            $from = preg_replace('/src="data:image[^"]+/', $src, $from);
            $to   = preg_replace('/src="data:image[^"]+/', $src, $to);

            $diff = new Caxy\HtmlDiff\HtmlDiff($from, $to);
            $diffhtml = $diff->build();

            // remove empty inserts (from tables)
            return preg_replace('!<ins class="diff\w+">\s*</ins>!Uims', '', $diffhtml);
        } else {
            $diff = new cogpowered\FineDiff\Diff(new cogpowered\FineDiff\Granularity\Word());
            return $diff->render($from, $to);
        }
    }

    /**
     * Return a date() format string to render identifiers for recurrence instances
     *
     * @param array $event Hash array with event properties
     *
     * @return string Format string
     */
    public static function recurrence_id_format($event)
    {
        return !empty($event['allday']) ? 'Ymd' : 'Ymd\THis';
    }

    /**
     * Returns HTML code for folder search widget
     *
     * @param array $attrib Named parameters
     *
     * @return string HTML code for the gui object
     */
    public function folder_search_form($attrib)
    {
        $rcmail = rcmail::get_instance();
        $attrib += [
            'gui-object'    => false,
            'wrapper'       => true,
            'form-name'     => 'foldersearchform',
            'command'       => 'non-extsing-command',
            'reset-command' => 'non-existing-command',
        ];

        if (($attrib['label-domain'] ?? null) && !strpos($attrib['buttontitle'], '.')) {
            $attrib['buttontitle'] = $attrib['label-domain'] . '.' . $attrib['buttontitle'];
        }

        if ($attrib['buttontitle']) {
            $attrib['placeholder'] = $rcmail->gettext($attrib['buttontitle']);
        }

        return $rcmail->output->search_form($attrib);
    }
}
