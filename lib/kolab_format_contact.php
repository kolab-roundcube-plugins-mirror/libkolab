<?php

/**
 * Kolab Contact model class
 *
 * @version @package_version@
 * @author Thomas Bruederli <bruederli@kolabsys.com>
 *
 * Copyright (C) 2012, Kolab Systems AG <contact@kolabsys.com>
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

class kolab_format_contact extends kolab_format
{
    public $CTYPE = 'application/vcard+xml';
    public $CTYPEv2 = 'application/x-vnd.kolab.contact';

    protected $objclass = 'Contact';
    protected $read_func = 'readContact';
    protected $write_func = 'writeContact';

    public static $fulltext_cols = ['name', 'firstname', 'surname', 'middlename', 'email:address'];

    public $phonetypes = [
        'home'    => Telephone::Home,
        'work'    => Telephone::Work,
        'text'    => Telephone::Text,
        'main'    => Telephone::Voice,
        'homefax' => Telephone::Fax,
        'workfax' => Telephone::Fax,
        'mobile'  => Telephone::Cell,
        'video'   => Telephone::Video,
        'pager'   => Telephone::Pager,
        'car'     => Telephone::Car,
        'other'   => Telephone::Textphone,
    ];

    public $emailtypes = [
        'home' => Email::Home,
        'work' => Email::Work,
        'other' => Email::NoType,
    ];

    public $addresstypes = [
        'home' => Address::Home,
        'work' => Address::Work,
        'office' => 0,
    ];

    private $gendermap = [
        'female' => Contact::Female,
        'male'   => Contact::Male,
    ];

    private $relatedmap = [
        'manager'   => Related::Manager,
        'assistant' => Related::Assistant,
        'spouse'    => Related::Spouse,
        'children'  => Related::Child,
    ];


    /**
     * Default constructor
     */
    public function __construct($xmldata = null, $version = 3.0)
    {
        parent::__construct($xmldata, $version);

        // complete phone types
        $this->phonetypes['homefax'] |= Telephone::Home;
        $this->phonetypes['workfax'] |= Telephone::Work;
    }

    /**
     * Set contact properties to the kolabformat object
     *
     * @param array $object Contact data as hash array
     */
    public function set(&$object)
    {
        // set common object properties
        parent::set($object);

        // do the hard work of setting object values
        $nc = new NameComponents();
        $nc->setSurnames(self::array2vector($object['surname'] ?? null));
        $nc->setGiven(self::array2vector($object['firstname'] ?? null));
        $nc->setAdditional(self::array2vector($object['middlename'] ?? null));
        $nc->setPrefixes(self::array2vector($object['prefix'] ?? null));
        $nc->setSuffixes(self::array2vector($object['suffix'] ?? null));
        $this->obj->setNameComponents($nc);
        $this->obj->setName($object['name'] ?? null);
        $this->obj->setCategories(self::array2vector($object['categories'] ?? null));

        if (isset($object['nickname'])) {
            $this->obj->setNickNames(self::array2vector($object['nickname']));
        }
        if (isset($object['jobtitle'])) {
            $this->obj->setTitles(self::array2vector($object['jobtitle']));
        }

        // organisation related properties (affiliation)
        $org = new Affiliation();
        $offices = new vectoraddress();
        if (!empty($object['organization'])) {
            $org->setOrganisation($object['organization']);
        }
        if (!empty($object['department'])) {
            $org->setOrganisationalUnits(self::array2vector($object['department']));
        }
        if (!empty($object['profession'])) {
            $org->setRoles(self::array2vector($object['profession']));
        }

        $rels = new vectorrelated();
        foreach (['manager','assistant'] as $field) {
            if (!empty($object[$field])) {
                $reltype = $this->relatedmap[$field];
                foreach ((array)$object[$field] as $value) {
                    $rels->push(new Related(Related::Text, $value, $reltype));
                }
            }
        }
        $org->setRelateds($rels);

        // im, email, url
        $this->obj->setIMaddresses(self::array2vector($object['im'] ?? null));

        if (class_exists('vectoremail')) {
            $vemails = new vectoremail();
            foreach ((array)($object['email'] ?? []) as $email) {
                $type = $this->emailtypes[$email['type']];
                $vemails->push(new Email($email['address'], intval($type)));
            }
        } else {
            $vemails = self::array2vector(array_map(function ($v) { return $v['address']; }, $object['email']));
        }
        $this->obj->setEmailAddresses($vemails);

        $vurls = new vectorurl();
        foreach ((array)($object['website'] ?? []) as $url) {
            $type = $url['type'] == 'blog' ? Url::Blog : Url::NoType;
            $vurls->push(new Url($url['url'], $type));
        }
        $this->obj->setUrls($vurls);

        // addresses
        $adrs = new vectoraddress();
        foreach ((array)($object['address'] ?? []) as $address) {
            $adr = new Address();
            $type = $this->addresstypes[$address['type']];
            if (isset($type)) {
                $adr->setTypes($type);
            } elseif (!empty($address['type'])) {
                $adr->setLabel($address['type']);
            }
            if (!empty($address['street'])) {
                $adr->setStreet($address['street']);
            }
            if (!empty($address['locality'])) {
                $adr->setLocality($address['locality']);
            }
            if (!empty($address['code'])) {
                $adr->setCode($address['code']);
            }
            if (!empty($address['region'])) {
                $adr->setRegion($address['region']);
            }
            if (!empty($address['country'])) {
                $adr->setCountry($address['country']);
            }

            if (($address['type'] ?? null) == 'office') {
                $offices->push($adr);
            } else {
                $adrs->push($adr);
            }
        }
        $this->obj->setAddresses($adrs);
        $org->setAddresses($offices);

        // add org affiliation after addresses are set
        $orgs = new vectoraffiliation();
        $orgs->push($org);
        $this->obj->setAffiliations($orgs);

        // telephones
        $tels = new vectortelephone();
        foreach ((array)($object['phone'] ?? []) as $phone) {
            $tel = new Telephone();
            if (isset($this->phonetypes[$phone['type'] ?? null])) {
                $tel->setTypes($this->phonetypes[$phone['type']]);
            }
            $tel->setNumber($phone['number'] ?? null);
            $tels->push($tel);
        }
        $this->obj->setTelephones($tels);

        if (isset($object['gender'])) {
            $this->obj->setGender($this->gendermap[$object['gender']] ? $this->gendermap[$object['gender']] : Contact::NotSet);
        }
        if (isset($object['notes'])) {
            $this->obj->setNote($object['notes']);
        }
        if (isset($object['freebusyurl'])) {
            $this->obj->setFreeBusyUrl($object['freebusyurl']);
        }
        if (isset($object['lang'])) {
            $this->obj->setLanguages(self::array2vector($object['lang']));
        }
        if (isset($object['birthday'])) {
            $this->obj->setBDay(self::get_datetime($object['birthday'], false, true));
        }
        if (isset($object['anniversary'])) {
            $this->obj->setAnniversary(self::get_datetime($object['anniversary'], false, true));
        }

        if (!empty($object['photo'])) {
            if ($type = rcube_mime::image_content_type($object['photo'])) {
                $this->obj->setPhoto($object['photo'], $type);
            }
        } elseif (isset($object['photo'])) {
            $this->obj->setPhoto('', '');
        } elseif ($this->obj->photoMimetype()) {  // load saved photo for caching
            $object['photo'] = $this->obj->photo();
        }

        // spouse and children are relateds
        $rels = new vectorrelated();
        foreach (['spouse','children'] as $field) {
            if (!empty($object[$field])) {
                $reltype = $this->relatedmap[$field];
                foreach ((array)$object[$field] as $value) {
                    $rels->push(new Related(Related::Text, $value, $reltype));
                }
            }
        }
        // add other relateds
        if (!empty($object['related']) && is_array($object['related'])) {
            foreach ($object['related'] as $value) {
                $rels->push(new Related(Related::Text, $value));
            }
        }
        $this->obj->setRelateds($rels);

        // insert/replace crypto keys
        $pgp_index = $pkcs7_index = -1;
        $keys = $this->obj->keys();
        for ($i = 0; $i < $keys->size(); $i++) {
            $key = $keys->get($i);
            if ($pgp_index < 0 && $key->type() == Key::PGP) {
                $pgp_index = $i;
            } elseif ($pkcs7_index < 0 && $key->type() == Key::PKCS7_MIME) {
                $pkcs7_index = $i;
            }
        }

        $pgpkey   = !empty($object['pgppublickey']) ? new Key($object['pgppublickey'], Key::PGP) : new Key();
        $pkcs7key = !empty($object['pkcs7publickey']) ? new Key($object['pkcs7publickey'], Key::PKCS7_MIME) : new Key();

        if ($pgp_index >= 0) {
            $keys->set($pgp_index, $pgpkey);
        } elseif (!empty($object['pgppublickey'])) {
            $keys->push($pgpkey);
        }
        if ($pkcs7_index >= 0) {
            $keys->set($pkcs7_index, $pkcs7key);
        } elseif (!empty($object['pkcs7publickey'])) {
            $keys->push($pkcs7key);
        }

        $this->obj->setKeys($keys);

        // TODO: handle language, gpslocation, etc.

        // set type property for proper caching
        $object['_type'] = 'contact';

        // cache this data
        $this->data = $object;
        unset($this->data['_formatobj']);
    }

    /**
     *
     */
    public function is_valid()
    {
        return !$this->formaterror && ($this->data || (is_object($this->obj) && $this->obj->uid() /*$this->obj->isValid()*/));
    }

    /**
     * Convert the Contact object into a hash array data structure
     *
     * @param array $data Additional data for merge
     *
     * @return array Contact data as hash array
     */
    public function to_array($data = [])
    {
        // return cached result
        if (!empty($this->data)) {
            return $this->data;
        }

        // read common object props into local data object
        $object = parent::to_array($data);

        $object['name'] = $this->obj->name();

        $nc = $this->obj->nameComponents();
        $object['surname']    = implode(' ', self::vector2array($nc->surnames()));
        $object['firstname']  = implode(' ', self::vector2array($nc->given()));
        $object['middlename'] = implode(' ', self::vector2array($nc->additional()));
        $object['prefix']     = implode(' ', self::vector2array($nc->prefixes()));
        $object['suffix']     = implode(' ', self::vector2array($nc->suffixes()));
        $object['nickname']   = implode(' ', self::vector2array($this->obj->nickNames()));
        $object['jobtitle']   = implode(' ', self::vector2array($this->obj->titles()));
        $object['categories'] = self::vector2array($this->obj->categories());

        // organisation related properties (affiliation)
        $orgs = $this->obj->affiliations();
        $org = null;
        if ($orgs->size()) {
            $org = $orgs->get(0);
            $object['organization']   = $org->organisation();
            $object['profession']     = implode(' ', self::vector2array($org->roles()));
            $object['department']     = implode(' ', self::vector2array($org->organisationalUnits()));
            $this->read_relateds($org->relateds(), $object);
        }

        $object['im'] = self::vector2array($this->obj->imAddresses());

        $emails = $this->obj->emailAddresses();
        if ($emails instanceof vectoremail) {
            $emailtypes = array_flip($this->emailtypes);
            for ($i = 0; $i < $emails->size(); $i++) {
                $email = $emails->get($i);
                $object['email'][] = ['address' => $email->address(), 'type' => $emailtypes[$email->types()]];
            }
        } else {
            $object['email'] = self::vector2array($emails);
        }

        $urls = $this->obj->urls();
        for ($i = 0; $i < $urls->size(); $i++) {
            $url = $urls->get($i);
            $subtype = $url->type() == Url::Blog ? 'blog' : 'homepage';
            $object['website'][] = ['url' => $url->url(), 'type' => $subtype];
        }

        // addresses
        $this->read_addresses($this->obj->addresses(), $object);
        if ($org && ($offices = $org->addresses())) {
            $this->read_addresses($offices, $object, 'office');
        }

        // telehones
        $tels = $this->obj->telephones();
        $teltypes = array_flip($this->phonetypes);
        for ($i = 0; $i < $tels->size(); $i++) {
            $tel = $tels->get($i);
            $object['phone'][] = ['number' => $tel->number(), 'type' => $teltypes[$tel->types()] ?? null];
        }

        $object['notes'] = $this->obj->note();
        $object['freebusyurl'] = $this->obj->freeBusyUrl();
        $object['lang'] = self::vector2array($this->obj->languages());

        if ($bday = self::php_datetime($this->obj->bDay())) {
            $object['birthday'] = $bday;
        }

        if ($anniversary = self::php_datetime($this->obj->anniversary())) {
            $object['anniversary'] = $anniversary;
        }

        $gendermap = array_flip($this->gendermap);
        if (($g = $this->obj->gender()) && $gendermap[$g]) {
            $object['gender'] = $gendermap[$g];
        }

        if ($this->obj->photoMimetype()) {
            $object['photo'] = $this->obj->photo();
        } elseif ($this->xmlobject && ($photo_name = $this->xmlobject->pictureAttachmentName())) {
            $object['photo'] = $photo_name;
        }

        // relateds -> spouse, children
        $this->read_relateds($this->obj->relateds(), $object, 'related');

        // crypto settings: currently only key values are supported
        $keys = $this->obj->keys();
        for ($i = 0; is_object($keys) && $i < $keys->size(); $i++) {
            $key = $keys->get($i);
            if ($key->type() == Key::PGP) {
                $object['pgppublickey'] = $key->key();
            } elseif ($key->type() == Key::PKCS7_MIME) {
                $object['pkcs7publickey'] = $key->key();
            }
        }

        $this->data = $object;
        return $this->data;
    }

    /**
     * Callback for kolab_storage_cache to get words to index for fulltext search
     *
     * @return array List of words to save in cache
     */
    public function get_words()
    {
        $data = '';
        foreach (self::$fulltext_cols as $colname) {
            [$col, $field] = array_pad(explode(':', $colname), 2, null);

            if ($field) {
                $a = [];
                foreach ((array)($this->data[$col] ?? []) as $attr) {
                    $a[] = $attr[$field];
                }
                $val = implode(' ', $a);
            } else {
                $val = is_array($this->data[$col] ?? null) ? implode(' ', $this->data[$col] ?? null) : ($this->data[$col] ?? null);
            }

            if (strlen($val)) {
                $data .= $val . ' ';
            }
        }

        return array_unique(rcube_utils::normalize_string($data, true));
    }

    /**
     * Callback for kolab_storage_cache to get object specific tags to cache
     *
     * @return array List of tags to save in cache
     */
    public function get_tags()
    {
        $tags = [];

        if (!empty($this->data['birthday'])) {
            $tags[] = 'x-has-birthday';
        }

        return $tags;
    }

    /**
     * Helper method to copy contents of an Address vector to the contact data object
     */
    private function read_addresses($addresses, &$object, $type = null)
    {
        $adrtypes = array_flip($this->addresstypes);

        for ($i = 0; $i < $addresses->size(); $i++) {
            $adr = $addresses->get($i);
            $object['address'][] = [
                'type'     => $type ? $type : ($adrtypes[$adr->types()] ? $adrtypes[$adr->types()] : ''), /*$adr->label()),*/
                'street'   => $adr->street(),
                'code'     => $adr->code(),
                'locality' => $adr->locality(),
                'region'   => $adr->region(),
                'country'  => $adr->country(),
            ];
        }
    }

    /**
     * Helper method to map contents of a Related vector to the contact data object
     */
    private function read_relateds($rels, &$object, $catchall = null)
    {
        $typemap = array_flip($this->relatedmap);

        for ($i = 0; $i < $rels->size(); $i++) {
            $rel = $rels->get($i);
            if ($rel->type() != Related::Text) {  // we can't handle UID relations yet
                continue;
            }

            $known = false;
            $types = $rel->relationTypes();
            foreach ($typemap as $t => $field) {
                if ($types & $t) {
                    $object[$field][] = $rel->text();
                    $known = true;
                    break;
                }
            }

            if (!$known && $catchall) {
                $object[$catchall][] = $rel->text();
            }
        }
    }
}
