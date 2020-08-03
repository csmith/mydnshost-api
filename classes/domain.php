<?php

use shanemcc\phpdb\DB;
use shanemcc\phpdb\DBObject;
use shanemcc\phpdb\ValidationFailed;
use shanemcc\phpdb\Operations\OrderByFunction;
use shanemcc\phpdb\Operations\DBOperation;

class Domain extends DBObject {
	protected static $_fields = ['id' => NULL,
	                             'domain' => NULL,
	                             'disabled' => false,
	                             'defaultttl' => 86400,
	                             'nsec3params' => NULL,
	                             'aliasof' => NULL,
	                            ];
	protected static $_key = 'id';
	protected static $_table = 'domains';

	// SOA for unknown objects.
	protected $_soa = FALSE;
	// Access levels for unknown objects.
	protected $_access = [];

	public function __construct($db) {
		parent::__construct($db);
	}

	public function setDomain($value) {
		return $this->setData('domain', do_idn_to_ascii($value));
	}

	public function setDisabled($value) {
		return $this->setData('disabled', parseBool($value) ? 'true' : 'false');
	}

	public function setDefaultTTL($value) {
		return $this->setData('defaultttl', $value);
	}

	public function setNSEC3Params($value) {
		return $this->setData('nsec3params', $value);
	}

	public function setAliasOf($value) {
		return $this->setData('aliasof', $value);
	}

	/**
	 * Load an object from the database based on domain name.
	 *
	 * @param $db Database object to load from.
	 * @param $name Name to look for.
	 * @return FALSE if no object exists, else the object.
	 */
	public static function loadFromDomain($db, $name) {
		$result = static::find($db, ['domain' => do_idn_to_ascii($name)]);
		if ($result) {
			return $result[0];
		} else {
			return FALSE;
		}
	}

	public function getAccess($user) {
		if ($user instanceof DomainKeyUser) {
			$key = $user->getDomainKey();
			if ($key->getDomainID() != $this->getID()) { return 'none'; }

			return $key->getDomainWrite() ? 'write' : 'read';
		} else if ($user instanceof User) {
			$user = $user->getID();
		}

		return array_key_exists($user, $this->_access) ? $this->_access[$user] : 'none';
	}

	public function setAccess($user, $level) {
		if ($user instanceof DomainKeyUser) {
			return $this;
		} else if ($user instanceof User) {
			$user = $user->getID();
		}

		$level = strtolower($level);
		if (in_array($level, ['none', 'read', 'write', 'admin', 'owner'])) {
			$this->_access[$user] = $level;
			$this->setChanged();
		}
		return $this;
	}

	public function getAccessUsers() {
		$users = User::findByID(DB::get(), array_keys($this->_access));

		$result = [];
		foreach ($this->_access as $k => $v) {
			if (isset($users[$k]) && $v != 'none') {
				$result[$users[$k]->getEmail()] = ['access' => $v, 'avatar' => $users[$k]->getAvatar()];
			}
		}

		return $result;
	}

	public function postSave($result) {
		if ($result) {
			// Persist access changes
			$setQuery = 'INSERT INTO domain_access (`user_id`, `domain_id`, `level`) VALUES (:user, :domain, :level) ON DUPLICATE KEY UPDATE `level` = :level';
			$setStatement = $this->getDB()->getPDO()->prepare($setQuery);

			$removeQuery = 'DELETE FROM domain_access WHERE `user_id` = :user AND `domain_id` = :domain';
			$removeStatement = $this->getDB()->getPDO()->prepare($removeQuery);

			$params = [':domain' => $this->getID()];
			foreach ($this->_access as $user => $access) {
				$params[':user'] = $user;
				$params[':level'] = $access;
				if ($access == 'none') {
					unset($params[':level']);
					$removeStatement->execute($params);
				} else {
					$setStatement->execute($params);
				}
			}
		}
	}

	public function postLoad() {
		// Get access levels;
		$query = 'SELECT `user_id`,`level` FROM domain_access WHERE `domain_id` = :domain';
		$params = [':domain' => $this->getID()];
		$statement = $this->getDB()->getPDO()->prepare($query);
		$statement->execute($params);
		$result = $statement->fetchAll(PDO::FETCH_ASSOC);

		foreach ($result as $row) {
			$this->setAccess($row['user_id'], $row['level']);
		}
	}

	public function getID() {
		return $this->getData('id');
	}

	public function getDomain() {
		return do_idn_to_utf8($this->getData('domain'));
	}

	public function getDomainRaw() {
		return $this->getData('domain');
	}

	public function isDisabled() {
		return parseBool($this->getData('disabled'));
	}

	public function getDefaultTTL() {
		return $this->getData('defaultttl');
	}

	public function getNSEC3Params() {
		return $this->getData('nsec3params');
	}

	public function getAliasOf() {
		return $this->getData('aliasof');
	}

	/**
	 * Get all the domains that are an alias of this one.
	 *
	 * @return List of record objects for this domain
	 */
	public function getAliases() {
		$searchParams = ['aliasof' => $this->getID()];

		$search = Domain::getSearch($this->getDB());

		$search = $search->order('domain');
		$result = $search->search($searchParams);
		return ($result) ? $result : [];
	}

	/**
	 * Get the Domain we are an alias of.
	 *
	 * @param $recursive (Default: false) Keep going until we find the ultimate parent alias.
	 * @return Domain we are an alias of.
	 */
	public function getAliasDomain($recursive = false) {
		$dom = $this;
		if ($dom->getAliasOf() == null) { return FALSE; }

		do {
			$dom = Domain::load($dom->getDB(), $dom->getAliasOf());
		} while ($recursive && $dom->getAliasOf() != null);

		return $dom;
	}

	/**
	 * Get all the records for this domain.
	 *
	 * @param $name (Optional) Limit results to this name.
	 * @param $type (Optional) Limit results to this rrtype.
	 * @return List of record objects for this domain
	 */
	public function getRecords($name = NULL, $rrtype = NULL) {
		$searchParams = ['domain_id' => $this->getID(), 'type' => 'SOA'];
		$searchFilters = ['type' => '!='];

		if ($name !== NULL) {
			if ($name == '@' || $name == '') {
				$name = $this->getDomain();
			} else {
				$name .= '.' . $this->getDomain();
			}

			$searchParams['name'] = $name;
		}

		if ($rrtype !== NULL) {
			if ($rrtype == 'SOA') {
				return [];
			} else {
				$searchParams['type'] = $rrtype;
				unset($searchFilters['type']);
			}
		}

		$search = Record::getSearch($this->getDB());

		if (endsWith($this->getDomain(), 'ip6.arpa')) {
			$search = $search->addOperation(new OrderByFunction('reverse', 'name'));
		} else if (endsWith($this->getDomain(), 'in-addr.arpa')) {
			$search = $search->addOperation(new OrderByFunction('length', 'name'))->order('name');
		} else {
			$rawDomain = $this->getDomainRaw();
			$search = $search->addOperation(new class($rawDomain) extends DBOperation {
				private $rawDomain;
				public function __construct($rawDomain) { $this->rawDomain = $rawDomain; }
				public function __toString() { return 'SUBSTRING(name, 1, LENGTH(name) - ' . strlen($this->rawDomain) . ')'; }
				public static function operation() { return 'ORDER BY'; }
			});
		}

		$search = $search->order('type')->order('priority');
		$result = $search->search($searchParams, $searchFilters);
		return ($result) ? $result : [];
	}

	/**
	 * Get a specific record ID if it is owned by this domain.
	 *
	 * @param $id Record ID to look for.
	 * @return Record object if found else FALSE.
	 */
	public function getRecord($id) {
		$result = Record::find($this->getDB(), ['domain_id' => $this->getID(), 'id' => $id, 'type' => 'SOA'], ['type' => '!=']);
		return ($result) ? $result[0] : FALSE;
	}

	/**
	 * Get the SOA record for this domain.
	 *
	 * @param $fresh Get a fresh copy from the DB rather than using our cached copy.
	 * @return Record object if found else FALSE.
	 */
	public function getSOARecord($fresh = FALSE) {
		$soa = $this->_soa;
		if (($soa === FALSE || $fresh) && $this->isKnown()) {
			$soa = Record::find($this->getDB(), ['domain_id' => $this->getID(), 'type' => 'SOA']);
		}

		if ($soa === FALSE) {
			$soa = new Record($this->getDB());
			$soa->setDomainID($this->getID());
			$soa->setName($this->getDomain());
			$soa->setType('SOA');
			$soa->setContent(sprintf('ns1.%s. dnsadmin.%s. %d 86400 7200 2419200 60', $this->getDomain(), $this->getDomain(), $this->getNextSerial()));
			$soa->setTTL(86400);
			$soa->setChangedAt(time());
			if ($this->isKnown()) {
				$soa->save();
			}
			$this->_soa = [$soa];
			return $soa;
		} else {
			$this->_soa = $soa;
			return $soa[0];
		}
	}

	/**
	 * Get the DNSSEC public ksk data for this domain. (Subject to change.)
	 *
	 * @return Array of `Record` objects for DNSSEC public ksk data.
	 */
	public function getDSKeys($fromDB = false) {
		$result = [];

		$keys = $this->getZoneKeys(257);
		if (!empty($keys)) {
			foreach ($keys as $key) {
				foreach ($key->getKeyPublicRecords() as $rec) {
					$result[] = $rec;
				}
			}
		}

		return $result;
	}

	/**
	 * Get the ZoneKeys for this domain.
	 *
	 * @param  $flags [Default: NULL] Limit to keys with the given flags value.
	 * @return Array of `ZoneKey` objects for DNSSEC public ksk data.
	 */
	public function getZoneKeys($flags = NULL) {
		$searchParams = ['domain_id' => $this->getID()];
		if ($flags !== NULL) { $searchParams['flags'] = $flags; }

		$search = ZoneKey::getSearch($this->getDB());

		$search = $search->order('created');
		$result = $search->search($searchParams);
		return ($result) ? $result : [];
	}

	/**
	 * Get a specific ZoneKey for this domain.
	 *
	 * @param $keyid The KeyID to get
	 * @return ZoneKey with the given KeyID, or FALSE.
	 */
	public function getZoneKey($keyID) {
		return ZoneKey::loadFromDomainKey($this->getDB(), $this->getID(), $keyID);
	}

	/**
	 * Get the next serial number to use.
	 *
	 * @param $oldSerial Current serial to ensure we are greater than.
	 * @return New serial to use.
	 */
	function getNextSerial($oldSerial = 0) {
		$serial = (int)(date('Ymd').'00');
		$diff = ($oldSerial - $serial);

		// If we already have a serial for today, the difference will be
		// >= 0. Older days serials are < 0.
		if ($diff >= 0) {
			$serial += ($diff + 1);
		}

		return $serial;
	}

	/**
	 * Update the domain serial.
	 *
	 * @param $serial Minimum serial to set. Use null to auto-generate, otherwise
	 *                we will set a serial at least 1 larger than this.
	 * @return Record object if found else FALSE.
	 */
	public function updateSerial($serial = null) {
		$soa = $this->getSOARecord();
		$parsed = $soa->parseSOA();
		if ($serial == null) { $serial = 0; }

		$serial = $this->getNextSerial(max($serial, $parsed['serial']));

		$parsed['serial'] = $serial;
		$soa->updateSOAContent($parsed);

		$soa->save();

		return $serial;
	}

	/**
	 * Look for a parent for this domain.
	 *
	 * @return Parent domain object, or FALSE if no parent found.
	 */
	public function findParent() {
		$bits = explode('.', $this->getDomain());
		while (!empty($bits)) {
			array_shift($bits);

			$p = Domain::loadFromDomain($this->getDB(), implode('.', $bits));
			if ($p !== FALSE) {
				return $p;
			}
		}

		return FALSE;
	}

	public function validate() {
		$required = ['domain'];
		foreach ($required as $r) {
			if (!$this->hasData($r)) {
				throw new ValidationFailed('Missing required field: '. $r);
			}
		}

		if (!self::validDomainName($this->getDomain())) {
			throw new ValidationFailed($this->getDomain() . ' is not a valid domain name');
		}

		return TRUE;
	}


	private function fixRecordName($record, $recordDomain = Null) {
		$name = $record->getName() . '.';
		if ($recordDomain instanceof Domain && $recordDomain != $this) {
			$name = preg_replace('#' . preg_quote($recordDomain->getDomainRaw()) . '.$#', $this->getDomainRaw() . '.', $name);
		}

		return $name;
	}

	private function fixRecordContent($record, $recordDomain = Null) {
		$content = $record->getContent();
		if (in_array($record->getType(), ['CNAME', 'NS', 'MX', 'PTR', 'RRCLONE'])) {
			$content = $record->getContent() . '.';
			if ($recordDomain instanceof Domain && $recordDomain != $this) {
				$content = preg_replace('#' . preg_quote($recordDomain->getDomainRaw()) . '.$#', $this->getDomainRaw() . '.', $content);
			}
		} else if ($record->getType() == 'SRV') {
			if (preg_match('#^[0-9]+ [0-9]+ ([^\s]+)$#', $content, $m)) {
				if ($m[1] != ".") {
					$content = $record->getContent() . '.';
					if ($recordDomain instanceof Domain && $recordDomain != $this) {
						$content = preg_replace('#' . preg_quote($recordDomain->getDomainRaw()) . '.$#', $this->getDomainRaw() . '.', $content);
					}
				}
			}
		}

		return $content;
	}

	public function getRecordsInfo($expandRecordsInfo = false) {
		$recordDomain = ($this->getAliasOf() != null) ? $this->getAliasDomain(true) : $this;

		$soa = $recordDomain->getSOARecord()->parseSOA();
		$bindSOA = array('Nameserver' => $soa['primaryNS'],
		                 'Email' => $soa['adminAddress'],
		                 'Serial' => $soa['serial'],
		                 'Refresh' => $soa['refresh'],
		                 'Retry' => $soa['retry'],
		                 'Expire' => $soa['expire'],
		                 'MinTTL' => $soa['minttl']);

		$records = new RecordsInfo();

		$cloneRecords = [];

		$hasNS = false;
		foreach ($recordDomain->getRecords() as $record) {
			if ($record->isDisabled()) { continue; }

			$name = $this->fixRecordName($record, $recordDomain);
			$content = $this->fixRecordContent($record, $recordDomain);

			$hasNS |= ($record->getType() == "NS" && $record->getName() == $recordDomain->getDomain());

			if ($record->getType() == 'RRCLONE') {
				$cloneRecords[] = $record;
				continue;
			}

			$records->addRecord($name, $record->getType(), $content, $record->getTTL(), $record->getPriority());
		}

		// TODO: Allow RRCLONE to reference other RRCLONE records eventually.
		foreach ($cloneRecords as $record) {
			$name = $this->fixRecordName($record, $recordDomain);
			$content = $this->fixRecordContent($record, $recordDomain);

			foreach ($records->getByName($content) as $sourceRecord) {
				$records->addRecord($name, $sourceRecord['Type'], $sourceRecord['Address'], $sourceRecord['TTL'], $sourceRecord['Priority']);
			}
		}

		if ($expandRecordsInfo) { $records = $records->get(); }

		return ['soa' => $bindSOA, 'hasNS' => $hasNS, 'records' => $records];
	}

	public static function validDomainName($name) {
		// https://www.safaribooksonline.com/library/view/regular-expressions-cookbook/9781449327453/ch08s15.html
		return preg_match('#^((?=[_a-z0-9-]{1,63}\.)(xn--)?[_a-z0-9]+(-[_a-z0-9]+)*\.)+(xn--)?[a-z]{2,63}$#i', do_idn_to_ascii($name));
	}
}
