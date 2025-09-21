<?php

/**
 * A *DAV client.
 *
 * @author Aleksander Machniak <machniak@apheleia-it.ch>
 *
 * Copyright (C) 2022, Apheleia IT AG <contact@apheleia-it.ch>
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

class kolab_dav_client
{
    public const ACL_PRINCIPAL_SELF = 'self';
    public const ACL_PRINCIPAL_ALL = 'all';
    public const ACL_PRINCIPAL_AUTH = 'authenticated';
    public const ACL_PRINCIPAL_UNAUTH = 'unauthenticated';

    public const INVITE_ACCEPTED = 'accepted';
    public const INVITE_DECLINED = 'declined';

    public const NOTIFICATION_SHARE_INVITE = 'share-invite-notification';
    public const NOTIFICATION_SHARE_REPLY = 'share-reply-notification';

    public const SHARING_READ = 'read';
    public const SHARING_READ_WRITE = 'read-write';
    public const SHARING_NO_ACCESS = 'no-access';
    public const SHARING_OWNER = 'shared-owner';
    public const SHARING_NOT_SHARED = 'not-shared';

    public $url;

    protected $user;
    protected $password;
    protected $path;
    protected $rc;
    protected $responseHeaders = [];

    /**
     * Object constructor
     */
    public function __construct($url)
    {
        $this->rc = rcube::get_instance();

        $parsedUrl = parse_url($url);

        if (!empty($parsedUrl['user']) && !empty($parsedUrl['pass'])) {
            $this->user     = rawurldecode($parsedUrl['user']);
            $this->password = rawurldecode($parsedUrl['pass']);

            $url = str_replace(rawurlencode($this->user) . ':' . rawurlencode($this->password) . '@', '', $url);
        } else {
            $this->user     = $this->rc->get_user_name();
            $this->password = $this->rc->get_user_password();
        }

        $this->url = $url;
        $this->path = $parsedUrl['path'] ?? '';
    }

    /**
     * Execute HTTP request to a DAV server
     */
    protected function request($path, $method, $body = '', $headers = [])
    {
        $rcube = rcube::get_instance();
        $debug = (bool) $rcube->config->get('dav_debug');

        $request_config = [
            'store_body'       => true,
            'follow_redirects' => true,
        ];

        $this->responseHeaders = [];

        $path = $this->normalize_location($path);

        try {
            $request = $this->initRequest($this->url . $path, $method, $request_config);

            $request->setAuth($this->user, $this->password);

            if ($body) {
                $request->setBody($body);
                $request->setHeader(['Content-Type' => 'application/xml; charset=utf-8']);
            }

            if (!empty($headers)) {
                $request->setHeader($headers);
            }

            if ($debug) {
                rcube::write_log('dav', "C: {$method}: " . (string) $request->getUrl()
                     . "\n" . $this->debugBody($body, $request->getHeaders()));
            }

            $response = $request->send();

            $body = $response->getBody();
            $code = $response->getStatus();

            if ($debug) {
                rcube::write_log('dav', "S: [{$code}]\n" . $this->debugBody($body, $response->getHeader()));
            }

            if ($code >= 300) {
                throw new Exception("DAV Error ($code):\n{$body}");
            }

            $this->responseHeaders = $response->getHeader();

            return $this->parseXML($body);
        } catch (Exception $e) {
            rcube::raise_error($e, true, false);
            return false;
        }
    }

    /**
     * Discover (common) DAV home collections.
     *
     * @return array|false Homes locations or False on error
     */
    public function discover()
    {
        if ($cache = $this->get_cache()) {
            $cache_key = "discover." . md5($this->url);

            if ($homes = $cache->get($cache_key)) {
                return $homes;
            }
        }

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propfind xmlns:d="DAV:">'
                . '<d:prop>'
                    . '<d:current-user-principal />'
                . '</d:prop>'
            . '</d:propfind>';

        // Note: Cyrus CardDAV service requires Depth:1 (CalDAV works without it)
        $response = $this->request('/', 'PROPFIND', $body, ['Depth' => 1, 'Prefer' => 'return-minimal']);

        if (empty($response)) {
            return false;
        }

        $elements = $response->getElementsByTagName('response');
        $principal_href = '';

        foreach ($elements as $element) {
            foreach ($element->getElementsByTagName('current-user-principal') as $prop) {
                $principal_href = $prop->nodeValue;
                break;
            }
        }

        $principal_href = $this->normalize_location($principal_href);

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propfind xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav" xmlns:card="urn:ietf:params:xml:ns:carddav">'
                . '<d:prop>'
                    . '<cal:calendar-home-set/>'
                    . '<card:addressbook-home-set/>'
                    . '<d:notification-URL/>'
                . '</d:prop>'
            . '</d:propfind>';

        $response = $this->request($principal_href, 'PROPFIND', $body);

        if (empty($response)) {
            return false;
        }

        $elements = $response->getElementsByTagName('response');
        $homes = [];

        if ($element = $response->getElementsByTagName('response')->item(0)) {
            if ($prop = $element->getElementsByTagName('prop')->item(0)) {
                foreach ($prop->childNodes as $home) {
                    if ($home->firstChild && $home->firstChild->localName == 'href') {
                        $href = $home->firstChild->nodeValue;
                        $homes[$home->localName] = $this->normalize_location($href);
                    }
                }
            }
        }

        if ($cache) {
            $cache->set($cache_key, $homes);
        }

        return $homes;
    }

    /**
     * Get user home folder of specified type
     *
     * @param string $type Home type or component name
     *
     * @return string|null Folder location href
     */
    public function getHome($type)
    {
        // FIXME: Can this be discovered?
        if ($type == 'PRINCIPAL') {
            $path = '/principals/user/';
            if ($this->path) {
                $path = '/' . trim($this->path, '/') . $path;
            }

            return $path;
        }

        $options = [
            'VEVENT' => 'calendar-home-set',
            'VTODO' => 'calendar-home-set',
            'VCARD' => 'addressbook-home-set',
            'NOTIFICATION' => 'notification-URL',
        ];

        $homes = $this->discover();

        if (is_array($homes) && isset($options[$type])) {
            return $homes[$options[$type]] ?? null;
        }

        return null;
    }

    /**
     * Get list of folders of specified type.
     *
     * @param string $component Component to filter by (VEVENT, VTODO, VCARD)
     *
     * @return false|array List of folders' metadata or False on error
     */
    public function listFolders($component = 'VEVENT')
    {
        $root_href = $this->getHome($component);

        if ($root_href === null) {
            return false;
        }

        $ns    = 'xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/"';
        $props = '';

        if ($component != 'VCARD') {
            $ns .= ' xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:a="http://apple.com/ns/ical/" xmlns:k="Kolab:"';
            $props = '<c:supported-calendar-component-set />'
                . '<a:calendar-color />'
                . '<k:alarms />';
        }

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propfind ' . $ns . '>'
                . '<d:prop>'
                    . '<d:resourcetype />'
                    . '<d:displayname />'
                    . '<d:share-access/>'   // draft-pot-webdav-resource-sharing-04
                    . '<d:owner/>'          // RFC 3744 (ACL)
                    . '<cs:getctag />'
                    . $props
                . '</d:prop>'
            . '</d:propfind>';

        // Note: Cyrus CardDAV service requires Depth:1 (CalDAV works without it)
        $response = $this->request($root_href, 'PROPFIND', $body, ['Depth' => 1, 'Prefer' => 'return-minimal']);

        if (empty($response)) {
            return false;
        }

        $folders = [];
        foreach ($response->getElementsByTagName('response') as $element) {
            $folder = $this->getFolderPropertiesFromResponse($element);

            // Filter out the folders of other type
            if ($component == 'VCARD') {
                if (in_array('addressbook', $folder['resource_type'])) {
                    $folders[] = $folder;
                }
            } elseif (in_array('calendar', $folder['resource_type']) && in_array($component, (array) $folder['types'])) {
                $folders[] = $folder;
            }
        }

        return $folders;
    }

    /**
     * Create a DAV object in a folder
     *
     * @param string $location  Object location
     * @param string $content   Object content
     * @param string $component Content type (VEVENT, VTODO, VCARD)
     *
     * @return false|string|null ETag string (or NULL) on success, False on error
     */
    public function create($location, $content, $component = 'VEVENT')
    {
        $ctype = [
            'VEVENT' => 'text/calendar',
            'VTODO' => 'text/calendar',
            'VCARD' => 'text/vcard',
        ];

        $headers = ['Content-Type' => $ctype[$component] . '; charset=utf-8'];

        $response = $this->request($location, 'PUT', $content, $headers);

        return $this->getETagFromResponse($response);
    }

    /**
     * Update a DAV object in a folder
     *
     * @param string $location  Object location
     * @param string $content   Object content
     * @param string $component Content type (VEVENT, VTODO, VCARD)
     *
     * @return false|string|null ETag string (or NULL) on success, False on error
     */
    public function update($location, $content, $component = 'VEVENT')
    {
        return $this->create($location, $content, $component);
    }

    /**
     * Delete a DAV object from a folder
     *
     * @param string $location Object location
     *
     * @return bool True on success, False on error
     */
    public function delete($location)
    {
        $response = $this->request($location, 'DELETE');

        return $response !== false;
    }

    /**
     * Move a DAV object
     *
     * @param string $source Source object location
     * @param string $target Target object content
     *
     * @return false|string|null ETag string (or NULL) on success, False on error
     */
    public function move($source, $target)
    {
        $headers = ['Destination' => $target];

        $response = $this->request($source, 'MOVE', '', $headers);

        return $this->getETagFromResponse($response);
    }

    /**
     * Get folder properties.
     *
     * @param string $location Object location
     *
     * @return false|array Folder metadata or False on error
     */
    public function folderInfo($location)
    {
        $ns = implode(' ', [
            'xmlns:d="DAV:"',
            'xmlns:cs="http://calendarserver.org/ns/"',
            'xmlns:c="urn:ietf:params:xml:ns:caldav"',
            'xmlns:a="http://apple.com/ns/ical/"',
            'xmlns:k="Kolab:"',
        ]);

        // Note: <allprop> does not include some of the properties we're interested in
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propfind ' . $ns . '>'
                . '<d:prop>'
                    . '<a:calendar-color/>'
                    . '<c:supported-calendar-component-set/>'
                    . '<cs:getctag/>'
                    . '<d:acl/>'
                    . '<d:current-user-privilege-set/>'
                    . '<d:resourcetype/>'
                    . '<d:displayname/>'
                    . '<d:share-access/>'   // draft-pot-webdav-resource-sharing-04
                    . '<d:owner/>'          // RFC 3744 (ACL)
                    . '<d:invite/>'
                    . '<k:alarms/>'
                . '</d:prop>'
            . '</d:propfind>';

        // Note: Cyrus CardDAV service requires Depth:1 (CalDAV works without it)
        $response = $this->request($location, 'PROPFIND', $body, ['Depth' => 0, 'Prefer' => 'return-minimal']);

        if (!empty($response)
            && ($element = $response->getElementsByTagName('response')->item(0))
            && ($folder = $this->getFolderPropertiesFromResponse($element))
        ) {
            return $folder;
        }

        return false;
    }

    /**
     * Create a DAV folder
     *
     * @param string $location   Object location (relative to the user home)
     * @param string $component  Content type (VEVENT, VTODO, VCARD)
     * @param array  $properties Object content
     *
     * @return bool True on success, False on error
     */
    public function folderCreate($location, $component, $properties = [])
    {
        [$props, $ns] = $this->folderPropertiesToXml($properties, 'xmlns:d="DAV:"');

        if ($component == 'VCARD') {
            $ns .= ' xmlns:c="urn:ietf:params:xml:ns:carddav"';
            $props .= '<d:resourcetype><d:collection/><c:addressbook/></d:resourcetype>';
        } else {
            $ns .= ' xmlns:c="urn:ietf:params:xml:ns:caldav"';
            $props .= '<d:resourcetype><d:collection/><c:calendar/></d:resourcetype>'
                // Note: Some clients, but also Cyrus by default allows everything in calendar folders,
                // i.e. VEVENT, VTODO, VJOURNAL, VFREEBUSY, VAVAILABILITY, but we prefer a single-type folders,
                // to keep tasks and event separated
                . '<c:supported-calendar-component-set><c:comp name="' . $component . '"/></c:supported-calendar-component-set>';
        }

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:mkcol ' . $ns . '>'
                . '<d:set>'
                    . '<d:prop>' . $props . '</d:prop>'
                . '</d:set>'
            . '</d:mkcol>';

        // Create the collection
        $response = $this->request($location, 'MKCOL', $body);

        return $response !== false;
    }

    /**
     * Delete a DAV folder
     *
     * @param string $location Folder location
     *
     * @return bool True on success, False on error
     */
    public function folderDelete($location)
    {
        $response = $this->request($location, 'DELETE');

        return $response !== false;
    }

    /**
     * Update a DAV folder
     *
     * @param string $location   Object location
     * @param string $component  Content type (VEVENT, VTODO, VCARD)
     * @param array  $properties Object content
     *
     * @return bool True on success, False on error
     */
    public function folderUpdate($location, $component, $properties = [])
    {
        // Note: Changing resourcetype property is forbidden (at least by Cyrus)

        [$props, $ns] = $this->folderPropertiesToXml($properties, 'xmlns:d="DAV:"');

        if (empty($props)) {
            return true;
        }

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propertyupdate ' . $ns . '>'
                . '<d:set>'
                    . '<d:prop>' . $props . '</d:prop>'
                . '</d:set>'
            . '</d:propertyupdate>';

        $response = $this->request($location, 'PROPPATCH', $body);

        // TODO: Should we make sure "200 OK" status is set for all requested properties?

        return $response !== false;
    }

    /**
     * Parse folder properties input into XML string to use in a request
     */
    protected function folderPropertiesToXml($properties, $ns = '')
    {
        $props = '';

        foreach ($properties as $name => $value) {
            if ($name == 'name') {
                $props .= '<d:displayname>' . htmlspecialchars($value, ENT_XML1, 'UTF-8') . '</d:displayname>';
            } elseif ($name == 'color' && strlen($value)) {
                if ($value[0] != '#') {
                    $value = '#' . $value;
                }

                $ns .= ' xmlns:a="http://apple.com/ns/ical/"';
                $props .= '<a:calendar-color>' . htmlspecialchars($value, ENT_XML1, 'UTF-8') . '</a:calendar-color>';
            } elseif ($name == 'alarms') {
                if (!strpos($ns, 'Kolab:')) {
                    $ns .= ' xmlns:k="Kolab:"';
                }

                $props .= "<k:{$name}>" . ($value ? 'true' : 'false') . "</k:{$name}>";
            }
        }

        return [$props, $ns];
    }

    /**
     * Fetch DAV notifications
     *
     * @param ?array $types Notification types to return
     *
     * @return false|array Notification objects on success, False on error
     */
    public function listNotifications($types = [])
    {
        $root_href = $this->getHome('NOTIFICATION');

        if ($root_href === null) {
            return false;
        }

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . ' <d:propfind xmlns:d="DAV:">'
                . '<d:prop>'
                    . '<d:notificationtype/>'
                . '</d:prop>'
            . '</d:propfind>';

        $response = $this->request($root_href, 'PROPFIND', $body, ['Depth' => 1, 'Prefer' => 'return-minimal']);

        if (empty($response)) {
            return false;
        }

        $objects = [];

        foreach ($response->getElementsByTagName('response') as $element) {
            $type = $element->getElementsByTagName('notificationtype')->item(0);

            if ($type && $type->firstChild) {
                $type = $type->firstChild->localName;

                if (empty($types) || in_array($type, $types)) {
                    $href = $element->getElementsByTagName('href')->item(0);
                    if ($notification = $this->getNotification($href->nodeValue)) {
                        $objects[] = $notification;
                    }
                }
            }
        }

        return $objects;
    }

    /**
     * Get a single DAV notification
     *
     * @param string $location Notification href
     *
     * @return false|array Notification data on success, False on error
     */
    public function getNotification($location)
    {
        $response = $this->request($location, 'GET', '', ['Content-Type' => 'application/davnotification+xml']);

        if (empty($response)) {
            return false;
        }

        // Note: Cyrus implements draft-pot-webdav-resource-sharing v02, not the most recent one(s),
        // and even v02 support is broken in some places

        if ($access = $response->getElementsByTagName('access')->item(0)) {
            $access = $access->firstChild;
            $access = $access->localName; // 'read' or 'read-write'
        }

        foreach (['invite-noresponse', 'invite-accepted', 'invite-declined', 'invite-invalid', 'invite-deleted'] as $name) {
            if ($node = $response->getElementsByTagName($name)->item(0)) {
                $result['status'] = str_replace('invite-', '', $node->localName);
            }
        }

        if ($organizer = $response->getElementsByTagName('organizer')->item(0)) {
            if ($href = $organizer->getElementsByTagName('href')->item(0)) {
                $organizer = $href->nodeValue;
            }
            // There should be also 'displayname', but Cyrus uses 'common-name',
            // we'll ignore it for now anyway.
        } elseif ($organizer = $response->getElementsByTagName('principal')->item(0)) {
            if ($href = $organizer->getElementsByTagName('href')->item(0)) {
                $organizer = $href->nodeValue;
            }
            // There should be also 'displayname', but Cyrus uses 'common-name',
            // we'll ignore it for now anyway.
        }

        $components = [];
        if ($set_element = $response->getElementsByTagName('supported-calendar-component-set')->item(0)) {
            foreach ($set_element->getElementsByTagName('comp') as $comp_element) {
                $components[] = $comp_element->attributes->getNamedItem('name')->nodeValue;
            }
        }

        $result = [
            'href' => $location,
            'access' => $access,
            'types' => $components,
            'organizer' => $organizer,
        ];

        // Cyrus uses 'summary', but it's 'comment' in more recent standard
        foreach (['dtstamp', 'summary', 'comment'] as $name) {
            if ($node = $response->getElementsByTagName($name)->item(0)) {
                $result[$name] = $node->nodeValue;
            }
        }

        // Note: In more recent standard there are 'displayname' and 'resourcetype' props

        // Note: 'hosturl' exists in v2, but starting from v3 'sharer-resource-uri' is used
        if ($hosturl = $response->getElementsByTagName('hosturl')->item(0)) {
            if ($href = $hosturl->getElementsByTagName('href')->item(0)) {
                $result['resource-uri'] = $href->nodeValue;
            }
        } elseif ($hosturl = $response->getElementsByTagName('sharer-resource-uri')->item(0)) {
            if ($href = $hosturl->getElementsByTagName('href')->item(0)) {
                $result['resource-uri'] = $href->nodeValue;
            }
        }

        return $result;
    }

    /**
     * Fetch DAV objects metadata (ETag, href) a folder
     *
     * @param string $location  Folder location
     * @param string $component Object type (VEVENT, VTODO, VCARD)
     *
     * @return false|array Objects metadata on success, False on error
     */
    public function getIndex($location, $component = 'VEVENT')
    {
        $queries = [
            'VEVENT' => 'calendar-query',
            'VTODO' => 'calendar-query',
            'VCARD' => 'addressbook-query',
        ];

        $ns = [
            'VEVENT' => 'caldav',
            'VTODO' => 'caldav',
            'VCARD' => 'carddav',
        ];

        $filter = '';
        if ($component != 'VCARD') {
            $filter = '<c:comp-filter name="VCALENDAR">'
                    . '<c:comp-filter name="' . $component . '" />'
                . '</c:comp-filter>';
        }

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . ' <c:' . $queries[$component] . ' xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:' . $ns[$component] . '">'
                . '<d:prop>'
                    . '<d:getetag />'
                . '</d:prop>'
                . ($filter ? "<c:filter>$filter</c:filter>" : '')
            . '</c:' . $queries[$component] . '>';

        $response = $this->request($location, 'REPORT', $body, ['Depth' => 1, 'Prefer' => 'return-minimal']);

        if (empty($response)) {
            return false;
        }

        $objects = [];

        foreach ($response->getElementsByTagName('response') as $element) {
            $objects[] = $this->getObjectPropertiesFromResponse($element);
        }

        return $objects;
    }

    /**
     * Fetch DAV objects data from a folder
     *
     * @param string $location  Folder location
     * @param string $component Object type (VEVENT, VTODO, VCARD)
     * @param array  $hrefs     List of objects' locations to fetch (empty for all objects)
     *
     * @return false|array Objects metadata on success, False on error
     */
    public function getData($location, $component = 'VEVENT', $hrefs = [])
    {
        if (empty($hrefs)) {
            return [];
        }

        $body = '';
        foreach ($hrefs as $href) {
            $body .= '<d:href>' . $href . '</d:href>';
        }

        $queries = [
            'VEVENT' => 'calendar-multiget',
            'VTODO' => 'calendar-multiget',
            'VCARD' => 'addressbook-multiget',
        ];

        $ns = [
            'VEVENT' => 'caldav',
            'VTODO' => 'caldav',
            'VCARD' => 'carddav',
        ];

        $types = [
            'VEVENT' => 'calendar-data',
            'VTODO' => 'calendar-data',
            'VCARD' => 'address-data',
        ];

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . ' <c:' . $queries[$component] . ' xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:' . $ns[$component] . '">'
                . '<d:prop>'
                    . '<d:getetag />'
                    . '<c:' . $types[$component] . ' />'
                . '</d:prop>'
                . $body
            . '</c:' . $queries[$component] . '>';

        $response = $this->request($location, 'REPORT', $body, ['Depth' => 1, 'Prefer' => 'return-minimal']);

        if (empty($response)) {
            return false;
        }

        $objects = [];

        foreach ($response->getElementsByTagName('response') as $element) {
            $objects[] = $this->getObjectPropertiesFromResponse($element);
        }

        return $objects;
    }

    /**
     * Accept/Deny a share invitation (draft-pot-webdav-resource-sharing)
     *
     * @param string $location Notification location
     * @param string $action   Reply action ('accepted' or 'declined')
     * @param array  $props    Additional reply properties (slug, comment)
     *
     * @return bool True on success, False on error
     */
    public function inviteReply($location, $action = self::INVITE_ACCEPTED, $props = [])
    {
        $reply = '<d:invite-' . $action . '/>';

        // Note: <create-in> and <slug> are ignored by Cyrus

        if (!empty($props['comment'])) {
            $reply .= '<d:comment>' . htmlspecialchars($props['comment'], ENT_XML1, 'UTF-8') . '</d:comment>';
        }

        $headers = ['Content-Type' => 'application/davsharing+xml; charset=utf-8'];

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:invite-reply xmlns:d="DAV:">' . $reply . '</d:invite-reply>';

        $response = $this->request($location, 'POST', $body, $headers);

        return $response !== false;
    }

    /**
     * Normalize object location, by removing the path included the configured DAV server URI.
     *
     * @param string $href Location href
     *
     * @return string
     */
    public function normalize_location($href)
    {
        if (!strlen($href)) {
            return $href;
        }

        if ($this->path && strpos($href, $this->path) === 0) {
            $href = substr($href, strlen($this->path));
        }

        return $href;
    }

    /**
     * Set ACL on a DAV folder
     *
     * @param string $location Object location (relative to the user home)
     * @param array  $acl      ACL definition
     *
     * @return bool True on success, False on error
     */
    public function setACL($location, $acl)
    {
        $ns_privileges = [
            // CalDAV
            'read-free-busy' => 'c:read-free-busy',
            // Cyrus
            'admin' => 'cy:admin',
            'add-resource' => 'cy:add-resource',
            'remove-resource' => 'cy:remove-resource',
            'make-collection' => 'cy:make-collection',
            'remove-collection' => 'cy:remove-collection',
        ];

        foreach ($acl as $idx => $privileges) {
            if (preg_match('/^[a-z]+$/', $idx)) {
                $principal = '<d:' . $idx . '/>';
            } else {
                $principal = '<d:href>' . htmlspecialchars($idx, ENT_XML1, 'UTF-8') . '</d:href>';
            }

            $grant = [];
            $deny = [];

            foreach ($privileges['grant'] ?? [] as $i => $p) {
                $p = '<' . ($ns_privileges[$p] ?? "d:{$p}") . '/>';
                $grant[$i] = '<d:privilege>' . $p . '</d:privilege>';
            }
            foreach ($privileges['deny'] ?? [] as $i => $p) {
                $p = '<' . ($ns_privileges[$p] ?? "d:{$p}") . '/>';
                $deny[$i] = '<d:privilege>' . $p . '</d:privilege>';
            }

            $acl[$idx] = '<d:ace>'
                . '<d:principal>' . $principal . '</d:principal>'
                . (count($grant) > 0 ? '<d:grant>' . implode('', $grant) . '</d:grant>' : '')
                . (count($deny) > 0 ? '<d:deny>' . implode('', $deny) . '</d:deny>' : '')
                . '</d:ace>';
        }

        $acl = implode('', $acl);
        $ns = 'xmlns:d="DAV:"';

        if (strpos($acl, '<c:')) {
            $ns .= ' xmlns:c="urn:ietf:params:xml:ns:caldav"';
        }
        if (strpos($acl, '<cy:')) {
            $ns .= ' xmlns:cy="http://cyrusimap.org/ns/"';
        }

        $body = '<?xml version="1.0" encoding="utf-8"?><d:acl ' . $ns . '>' . $acl . '</d:acl>';

        $response = $this->request($location, 'ACL', $body);

        return $response !== false;
    }

    /**
     * Share a reasource (draft-pot-webdav-resource-sharing)
     *
     * @param string $location Resource location
     * @param array  $sharees  Sharees list
     *
     * @return bool True on success, False on error
     */
    public function shareResource($location, $sharees = [])
    {
        $props = '';

        foreach ($sharees as $href => $sharee) {
            $props .= '<d:sharee>'
                . '<d:href>' . htmlspecialchars($href, ENT_XML1, 'UTF-8') . '</d:href>'
                . '<d:share-access><d:' . ($sharee['access'] ?? self::SHARING_NO_ACCESS) . '/></d:share-access>'
                . '<d:' . ($sharee['status'] ?? 'noresponse') . '/>';

            if (isset($sharee['comment']) && strlen($sharee['comment'])) {
                $props .= '<d:comment>' . htmlspecialchars($sharee['comment'], ENT_XML1, 'UTF-8') . '</d:comment>';
            }

            if (isset($sharee['displayname']) && strlen($sharee['displayname'])) {
                $props .= '<d:prop><d:displayname>'
                    . htmlspecialchars($sharee['comment'], ENT_XML1, 'UTF-8')
                    . '</d:displayname></d:prop>';
            }

            $props .= '</d:sharee>';
        }

        $headers = ['Content-Type' => 'application/davsharing+xml; charset=utf-8'];

        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:share-resource xmlns:d="DAV:">' . $props . '</d:share-resource>';

        $response = $this->request($location, 'POST', $body, $headers);

        return $response !== false;
    }

    /**
     * Parse XML content
     */
    protected function parseXML($xml)
    {
        $doc = new DOMDocument('1.0', 'UTF-8');

        if (stripos($xml, '<?xml') === 0) {
            if (!$doc->loadXML($xml, LIBXML_NOBLANKS)) {
                throw new Exception("Failed to parse XML");
            }
        }

        return $doc;
    }

    /**
     * Parse request/response body for debug purposes
     */
    protected function debugBody($body, $headers)
    {
        $head = '';
        foreach ($headers as $header_name => $header_value) {
            $head .= "{$header_name}: {$header_value}\n";
        }

        if (stripos($body, '<?xml') === 0) {
            $doc = new DOMDocument('1.0', 'UTF-8');

            $doc->formatOutput = true;
            $doc->preserveWhiteSpace = false;

            if (!$doc->loadXML($body)) {
                throw new Exception("Failed to parse XML");
            }

            $body = $doc->saveXML();
        }

        return $head . "\n" . rtrim($body);
    }

    /**
     * Extract folder properties from a server 'response' element
     */
    protected function getFolderPropertiesFromResponse(DOMElement $element)
    {
        if ($href = $element->getElementsByTagName('href')->item(0)) {
            $href = $href->nodeValue;
        }

        if ($color = $element->getElementsByTagName('calendar-color')->item(0)) {
            if (preg_match('/^#[0-9a-fA-F]{6,8}$/', $color->nodeValue)) {
                $color = substr($color->nodeValue, 1);
            } else {
                $color = null;
            }
        }

        if ($name = $element->getElementsByTagName('displayname')->item(0)) {
            $name = $name->nodeValue;
        }

        if ($ctag = $element->getElementsByTagName('getctag')->item(0)) {
            $ctag = $ctag->nodeValue;
        }

        $components = [];
        if ($set_element = $element->getElementsByTagName('supported-calendar-component-set')->item(0)) {
            foreach ($set_element->getElementsByTagName('comp') as $comp_element) {
                $components[] = $comp_element->attributes->getNamedItem('name')->nodeValue;
            }
        }

        $types = [];
        if ($type_element = $element->getElementsByTagName('resourcetype')->item(0)) {
            foreach ($type_element->childNodes as $node) {
                $types[] = $node->localName;
            }
        }

        $result = [
            'href' => $href,
            'name' => $name,
            'ctag' => $ctag,
            'color' => $color,
            'types' => $components,
            'resource_type' => $types,
        ];

        // Note: We're supporting only a subset of RFC 3744, it is:
        //     - grant, deny
        //     - principal (all, self, authenticated, href)
        if ($acl_element = $element->getElementsByTagName('acl')->item(0)) {
            $acl = [];
            $special = [
                self::ACL_PRINCIPAL_SELF,
                self::ACL_PRINCIPAL_ALL,
                self::ACL_PRINCIPAL_AUTH,
                self::ACL_PRINCIPAL_UNAUTH,
            ];

            foreach ($acl_element->getElementsByTagName('ace') as $ace) {
                $principal = $ace->getElementsByTagName('principal')->item(0);
                $grant = [];
                $deny = [];

                if ($principal->firstChild && $principal->firstChild->localName == 'href') {
                    $principal = $principal->firstChild->nodeValue;
                } elseif ($principal->firstChild && in_array($principal->firstChild->localName, $special)) {
                    $principal = $principal->firstChild->localName;
                } else {
                    continue;
                }

                if ($grant_element = $ace->getElementsByTagName('grant')->item(0)) {
                    foreach ($grant_element->childNodes as $privilege) {
                        if (strpos($privilege->nodeName, ':privilege') !== false && $privilege->firstChild) {
                            $grant[] = preg_replace('/^[^:]+:/', '', $privilege->firstChild->nodeName);
                        }
                    }
                }

                if ($deny_element = $ace->getElementsByTagName('deny')->item(0)) {
                    foreach ($deny_element->childNodes as $privilege) {
                        if (strpos($privilege->nodeName, ':privilege') !== false && $privilege->firstChild) {
                            $deny[] = preg_replace('/^[^:]+:/', '', $privilege->firstChild->nodeName);
                        }
                    }
                }

                if (count($grant) > 0 || count($deny) > 0) {
                    $acl[$principal] = [
                        'grant' => $grant,
                        'deny' => $deny,
                    ];
                }
            }

            $result['acl'] = $acl;
        }

        if ($set_element = $element->getElementsByTagName('current-user-privilege-set')->item(0)) {
            $rights = [];

            foreach ($set_element->childNodes as $privilege) {
                if (strpos($privilege->nodeName, ':privilege') !== false && $privilege->firstChild) {
                    $rights[] = preg_replace('/^[^:]+:/', '', $privilege->firstChild->nodeName);
                }
            }

            $result['myrights'] = $rights;
        }

        if ($owner = $element->getElementsByTagName('owner')->item(0)) {
            if ($owner->firstChild) {
                $result['owner'] = $owner->firstChild->nodeValue;
            }
        }

        // 'share-access' from draft-pot-webdav-resource-sharing
        if ($share = $element->getElementsByTagName('share-access')->item(0)) {
            if ($share->firstChild) {
                $result['share-access'] = $share->firstChild->localName;
            }
        }

        // 'invite' from draft-pot-webdav-resource-sharing
        if ($invite_element = $element->getElementsByTagName('invite')->item(0)) {
            $invites = [];
            foreach ($invite_element->childNodes as $sharee) {
                /** @var DOMElement $sharee */
                $href = $sharee->getElementsByTagName('href')->item(0)->nodeValue;
                $status = 'noresponse';

                if ($comment = $sharee->getElementsByTagName('comment')->item(0)) {
                    $comment = $comment->nodeValue;
                }

                if ($displayname = $sharee->getElementsByTagName('displayname')->item(0)) {
                    $displayname = $displayname->nodeValue;
                }

                if ($access = $sharee->getElementsByTagName('share-access')->item(0)) {
                    $access = $access->firstChild->localName;
                } else {
                    $access = self::SHARING_NOT_SHARED;
                }

                foreach (['invite-noresponse', 'invite-accepted', 'invite-declined', 'invite-invalid', 'invite-deleted'] as $name) {
                    if ($node = $sharee->getElementsByTagName($name)->item(0)) {
                        $status = str_replace('invite-', '', $node->localName);
                    }
                }

                $invites[$href] = [
                    'access' => $access,
                    'status' => $status,
                    'comment' => $comment,
                    'displayname' => $displayname,
                ];
            }

            $result['invite'] = $invites;
        }

        foreach (['alarms'] as $tag) {
            if ($el = $element->getElementsByTagName($tag)->item(0)) {
                if (strlen($el->nodeValue) > 0) {
                    $result[$tag] = strtolower($el->nodeValue) === 'true';
                }
            }
        }

        return $result;
    }

    /**
     * Extract object properties from a server 'response' element
     */
    protected function getObjectPropertiesFromResponse(DOMElement $element)
    {
        $uid = null;
        if ($href = $element->getElementsByTagName('href')->item(0)) {
            $href = $href->nodeValue;

            // Extract UID from the URL
            $href_parts = explode('/', $href);
            $uid = preg_replace('/\.[a-z]+$/', '', $href_parts[count($href_parts) - 1]);
            $uid = rawurldecode($uid);
        }

        if ($data = $element->getElementsByTagName('calendar-data')->item(0)) {
            $data = $data->nodeValue;
        } elseif ($data = $element->getElementsByTagName('address-data')->item(0)) {
            $data = $data->nodeValue;
        }

        if ($etag = $element->getElementsByTagName('getetag')->item(0)) {
            $etag = $etag->nodeValue;
            if (preg_match('|^".*"$|', $etag)) {
                $etag = substr($etag, 1, -1);
            }
        }

        return [
            'href' => $href,
            'data' => $data,
            'etag' => $etag,
            'uid' => $uid,
        ];
    }

    /**
     * Get ETag from a response
     */
    protected function getETagFromResponse($response)
    {
        if ($response !== false) {
            // Note: ETag is not always returned, e.g. https://github.com/cyrusimap/cyrus-imapd/issues/2456
            $etag = $this->responseHeaders['etag'] ?? null;

            if (is_string($etag) && preg_match('|^".*"$|', $etag)) {
                $etag = substr($etag, 1, -1);
            }

            return $etag;
        }

        return false;
    }

    /**
     * Initialize HTTP request object
     */
    protected function initRequest($url = '', $method = 'GET', $config = [])
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

        // load HTTP_Request2 (support both composer-installed and system-installed package)
        if (!class_exists('HTTP_Request2')) {
            require_once 'HTTP/Request2.php';
        }

        try {
            $request = new HTTP_Request2();
            $request->setConfig($http_config);

            // proxy User-Agent string
            if (isset($_SERVER['HTTP_USER_AGENT'])) {
                $request->setHeader('user-agent', $_SERVER['HTTP_USER_AGENT']);
            }

            // cleanup
            $request->setBody('');
            $request->setUrl($url);
            $request->setMethod($method);

            return $request;
        } catch (Exception $e) {
            rcube::raise_error($e, true, true);
        }
    }

    /**
     * Return caching object if enabled
     */
    protected function get_cache()
    {
        $rcube = rcube::get_instance();
        if ($cache_type = $rcube->config->get('dav_cache', 'db')) {
            $cache_ttl  = $rcube->config->get('dav_cache_ttl', '10m');
            $cache_name = 'DAV';

            return $rcube->get_cache($cache_name, $cache_type, $cache_ttl);
        }
    }
}
