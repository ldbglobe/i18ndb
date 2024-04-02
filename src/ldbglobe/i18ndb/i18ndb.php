<?php
namespace ldbglobe\i18ndb;

define('I18NDB_DEBUG',false);
define('I18NDB_DEFAULT_STATIC_INSTANCE','DEFAULT');
define('I18NDB_DEFAULT_FALLBACK_CHAIN','DEFAULT');

class i18ndb {

	private static $_predisClient = false;
	private static $_predisTTL = null;
	private static $_static_instances = [];

	private $_language_fallbacks = [];
	private $_pdo_handler = null;
	private $_table_name = null;
	private $_table_is_ready = null;
	private $_predisHash = null;
	private $_predisCache = [];

	static function factory($pdo_handler, $table_name)
	{
		$__PRIVATE_INSTANCE_CACHE = false;
		try {
			$__PRIVATE_INSTANCE_CACHE = '__PRIVATE_INSTANCE_CACHE__'.hash('sha256',json_encode($pdo_handler).'_'.$table_name);
		} catch (\Exception $e) {
			if(I18NDB_DEBUG)
			{
				print_r($e);
			}
		}

		if($__PRIVATE_INSTANCE_CACHE)
		{
			$instance = self::LoadInstance($__PRIVATE_INSTANCE_CACHE);
			if($instance)
				return $instance;
			else
				return new i18ndb($pdo_handler, $table_name);
		}
	}

	public function __construct($pdo_handler, $table_name)
	{
		$__PRIVATE_INSTANCE_CACHE = false;
		try {
			$__PRIVATE_INSTANCE_CACHE = '__PRIVATE_INSTANCE_CACHE__'.hash('sha256',json_encode($pdo_handler).'_'.$table_name);
		} catch (\Exception $e) {
			if(I18NDB_DEBUG)
			{
				print_r($e);
			}
		}

		$this->_pdo_handler = $pdo_handler;
		$this->_table_name = $table_name;

		if($__PRIVATE_INSTANCE_CACHE)
		{
			self::RegisterInstance($this,$__PRIVATE_INSTANCE_CACHE);
		}
	}

	public static function setPredisClient($predisClient,$ttl=null)
	{
		$ttl = $ttl ? $ttl : 3600*48;
		self::$_predisClient = $predisClient;
		self::$_predisTTL = $ttl;
	}

	public function getPredisValue($type,$id,$key,$language,$index)
	{
		if(!self::$_predisClient)
			return null;

		$cacheBlockHash = $this->getCacheBlockHash(['type'=>$type,'id'=>$id,'language'=>$language]);
		$cacheBlock = $this->getPredisCacheBlock($cacheBlockHash);
		if(!is_array($cacheBlock))
		{
			// no data found in redis cache
			$dictionnary = $this->search(['type'=>$type,'id'=>$id,'language'=>$language]);
			$cacheBlock = [];
			if($dictionnary)
			{
				foreach($dictionnary as $text)
				{
					$k = $this->getContentHash($text->type, $text->id, $text->key, $text->language, $text->index);
					$cacheBlock[$k] = $text->value;
				}
			}
			$this->setPredisCacheBlock($cacheBlockHash,$cacheBlock,true);
		}

		$k = $this->getContentHash($type,$id,$key,$language,$index);
		return isset($cacheBlock[$k]) ? $cacheBlock[$k] : '';
	}
	public function delPredisValue($type,$id,$key,$language,$index)
	{
		if(!self::$_predisClient)
			return null;

		$cacheBlockHash = $this->getCacheBlockHash(['type'=>$type,'id'=>$id,'language'=>$language]);
		$cacheBlock = $this->getPredisCacheBlock($cacheBlockHash);
		if(is_array($cacheBlock))
		{
			$k = $this->getContentHash($type,$id,$key,$language,$index);
			if(isset($cacheBlock[$k]))
			{
				unset($cacheBlock[$k]);
				$this->setPredisCacheBlock($cacheBlockHash,$cacheBlock);
			}
		}
	}
	public function setPredisValue($type,$id,$key,$language,$index,$value)
	{
		if(!self::$_predisClient)
			return null;

		$cacheBlockHash = $this->getCacheBlockHash(['type'=>$type,'id'=>$id,'language'=>$language]);
		$cacheBlock = $this->getPredisCacheBlock($cacheBlockHash);
		if(is_array($cacheBlock))
		{
			$k = $this->getContentHash($type,$id,$key,$language,$index);
			$cacheBlock[$k] = $value;
			$this->setPredisCacheBlock($cacheBlockHash,$cacheBlock);
		}
	}

	public function getCacheBlockHash($param=null)
	{
		if(!self::$_predisClient)
			return null;

		$type     = is_array($param) && isset($param['type'])     ? $param['type']     : null; // Classname
		$id       = is_array($param) && isset($param['id'])       ? $param['id']       : null; // item ID
		$language = is_array($param) && isset($param['language']) ? $param['language'] : null; // language

		return 'i18ndb::'.hash('sha256',serialize([$this->_table_name,$type,$id,$language]));
	}
	public function getContentHash($type,$id,$key,$language,$index)
	{
		if(!self::$_predisClient)
			return null;

		return implode('::',[$type?$type:'', $id?$id:'', $key?$key:'', $language?$language:'', $index?$index:'']);
	}
	public function getPredisCacheBlock($cacheBlockHash)
	{
		if(!self::$_predisClient)
			return null;

		// la signature du cache ne correspond pas
		$cert = self::$_predisClient->get($cacheBlockHash.'_cert');
		if($cert != $this->getPredisCommonHash())
		{
			// on met à jour la signature du cache et on test à nouveau
			if($cert != $this->getPredisCommonHash(true))
			{
				// toujours différent => on invalide le cache
				unset($this->_predisCache[$cacheBlockHash]);
				return;
			}
		}

		if(!isset($this->_predisCache[$cacheBlockHash]))
		{
			try {
				$this->_predisCache[$cacheBlockHash] = unserialize(self::$_predisClient->get($cacheBlockHash));
			} catch (\Exception $e) {
				if(I18NDB_DEBUG)
				{
					print_r($e);
				}
			}
		}
		return $this->_predisCache[$cacheBlockHash];
	}
	public function setPredisCacheBlock($cacheBlockHash,$cacheBlock,$update_ttl=false)
	{
		if(!self::$_predisClient)
			return null;

		$commonHash = $this->getPredisCommonHash();

		$this->_predisCache[$cacheBlockHash] = $cacheBlock;
		self::$_predisClient->set($cacheBlockHash,serialize($cacheBlock));
		self::$_predisClient->set($cacheBlockHash.'_cert',$commonHash);
		if($update_ttl)
		{
			self::$_predisClient->expire($cacheBlockHash, self::$_predisTTL);
			self::$_predisClient->expire($cacheBlockHash.'_cert', self::$_predisTTL);
		}
	}

	public function getPredisCommonHash($refresh=false)
	{
		if(!self::$_predisClient)
			return null;

		if($this->_predisHash)
			return $this->_predisHash;

		$commonHash = $this->getCacheBlockHash();

		$this->_predisHash = self::$_predisClient->get($commonHash);
		if(!$this->_predisHash)
		{
			$this->_predisHash = hash('sha256',microtime(true).'_'.random_int(0,999999999));
			self::$_predisClient->set($commonHash,$this->_predisHash);
			self::$_predisClient->expire($commonHash, self::$_predisTTL);
		}
	}
	public function flushPredis()
	{
		if(!self::$_predisClient)
			return null;

		$commonHash = $this->getPredisCommonHash();

		self::$_predisClient->expire($commonHash, -1);
		self::$_predisClient->del($commonHash);

		$this->_predisCache = [];
	}

	public static function LoadInstance($key=null)
	{
		$key = $key!==null ? $key : I18NDB_DEFAULT_STATIC_INSTANCE;
		return isset(self::$_static_instances[$key]) ? self::$_static_instances[$key] : false;
	}

	public static function RegisterInstance($i18ndb_instance,$key=null)
	{
		$key = $key!==null ? $key : I18NDB_DEFAULT_STATIC_INSTANCE;
		self::$_static_instances[$key] = $i18ndb_instance;
	}

	// ---------------------------------------------------------
	// Read / Write function
	// ---------------------------------------------------------

	public function get($type,$id,$key,$language = null, $index = null)
	{
		$Morphoji = new \Chefkoch\Morphoji\Converter();

		// PREDIS HACK TO READ FROM MEMORY IF AVAILABLE
		$r = $this->getPredisValue($type,$id,$key,$language,$index);
		if($r!==null)
			return $Morphoji->toEmojis($r);

		//print_r([$type,$id,$key,$language,$index]);
		//die;

		if(!$this->table_is_ready())
			return false;

		$id = $id>0 ? $id : 0;
		if($language===null)
		{
			$stmt = $this->_pdo_handler->prepare("SELECT `language`, `index`, `value` FROM `".$this->_table_name."` WHERE `id` = :id AND `type` = :type AND `key` = :key");
			$result = $stmt->execute(array('id'=>$id, 'type'=>$type, 'key'=>$key));
			if(!$result)
				throw new \Exception($this->_pdo_handler->errorInfo());
			$results = $stmt->fetchAll(\PDO::FETCH_OBJ);
			unset($stmt);

			$result = array();
			if($results)
			{
				foreach($results as $v)
				{
					if(!isset($result[$v->language]))
						$result[$v->language] = array();

					$result[$v->language][$v->index] = $Morphoji->toEmojis($v->value);
				}
				foreach($result as $l=>$v)
				{
					if(count($v)==1)
					{
						foreach($v as $v)
							$result[$l] = $v;
					}
				}
			}
		}
		else
		{
			if($index===null)
			{
				$stmt = $this->_pdo_handler->prepare("SELECT `index`, `value` FROM `".$this->_table_name."` WHERE `id` = :id AND `type` = :type AND `key` = :key AND `language` = :language");
				$result = $stmt->execute(array('id'=>$id, 'type'=>$type, 'key'=>$key, 'language'=>$language));
				if(!$result)
					throw new \Exception($this->_pdo_handler->errorInfo());
				$results = $stmt->fetchAll(\PDO::FETCH_OBJ);
				unset($stmt);

				if($results)
				{
					if(count($results)>1 || $results[0]->index!=='')
					{
						$result = array();
						foreach($results as $v)
						{
							$result[$v->index] = $Morphoji->toEmojis($v->value);
						}
					}
					else
					{
						// PREDIS HACK: WRITE A MISSING KEY INTO MEMORY
						if(self::$_predisClient && $language!==null)
						{
							$this->setPredisValue($type,$id,$key,$language,'', $results[0]->value);
						}

						$result =  $Morphoji->toEmojis($results[0]->value);
					}
				}
				else
				{
					// PREDIS HACK: WRITE A MISSING KEY INTO MEMORY
					if(self::$_predisClient && $language!==null)
					{
						$this->setPredisValue($type,$id,$key,$language,'', '');
					}

					$result = false;
				}
			}
			else
			{
				$stmt = $this->_pdo_handler->prepare("SELECT `value` FROM `".$this->_table_name."` WHERE `id` = :id AND `type` = :type AND `key` = :key AND `language` = :language AND `index` = :index");
				$result = $stmt->execute(array('id'=>$id, 'type'=>$type, 'key'=>$key, 'language'=>$language, 'index'=>$index));
				if(!$result)
					throw new \Exception($this->_pdo_handler->errorInfo());
				$result =  $stmt->fetch(\PDO::FETCH_OBJ);
				unset($stmt);
				if(isset($result->value))
				{
					// PREDIS HACK: WRITE A MISSING KEY INTO MEMORY
					if(self::$_predisClient && $language!==null)
					{
						$this->setPredisValue($type,$id,$key,$language,'', $result->value);
					}

					$result->value = $Morphoji->toEmojis($result->value);
				}
				else
				{
					// PREDIS HACK: WRITE A MISSING KEY INTO MEMORY
					if(self::$_predisClient && $language!==null)
					{
						$this->setPredisValue($type,$id,$key,$language,'', '');
					}
				}
			}
		}

		if($result)
			return $result;
		return false;
	}

	public function registerLanguageFallback($languages,$from=null)
	{
		$from = $from!==null ? $from : I18NDB_DEFAULT_FALLBACK_CHAIN;
		$this->_language_fallbacks[$from] = $languages;
	}
	public function loadLanguageFallback($from=null)
	{
		$from = $from!==null ? $from : I18NDB_DEFAULT_FALLBACK_CHAIN;
		return isset($this->_language_fallbacks[$from])
			? $this->_language_fallbacks[$from]
			: (
				isset($this->_language_fallbacks[I18NDB_DEFAULT_FALLBACK_CHAIN])
				? $this->_language_fallbacks[I18NDB_DEFAULT_FALLBACK_CHAIN]
				: array()
			);
	}

	public function getWithFallback($type,$id,$key,$language)
	{
		$r = $this->get($type,$id,$key);
		if(isset($r[$language]))
			return $r[$language];

		$fallback_chain = $this->loadLanguageFallback($language);
		foreach($fallback_chain as $fblang)
		{
			if($fblang != $language)
			{
				if(isset($r[$fblang]))
					return $r[$fblang];
			}
		}
		return false;
	}

	public function set($type,$id,$key,$language, $value = null, $index = '')
	{
		if(!$this->table_is_ready(true))
			return false;

		$Morphoji = new \Chefkoch\Morphoji\Converter();

		$id = $id>0 ? $id : 0;

		if($value!==null)
		{
			if(is_array($value))
			{
				foreach($value as $i=>$v)
				{
					$this->set($type,$id,$key,$language, $v, $i);
				}
				return true;
			}
			else
			{
				if(!empty($value))
				{
					// write in database only if change is needed
					$old = $this->get($type,$id,$key,$language,$index);
					if(!$old || $old->value!=$value)
					{
						// PREDIS HACK: WRITE A KEY INTO MEMORY
						if(self::$_predisClient && $language!==null && $index!==null)
						{
							$this->setPredisValue($type,$id,$key,$language,$index, $value);
						}

						$db_value = $Morphoji->fromEmojis($value);
						$stmt = $this->_pdo_handler->prepare("INSERT INTO `".$this->_table_name."` SET `id` = :id, `type` = :type, `key` = :key, `language` = :language, `index` = :index, `value` = :value, created_at = NOW(), updated_at = NOW() ON DUPLICATE KEY UPDATE updated_at = NOW(), `value` = :value");
						$result = $stmt->execute(array('id'=>$id, 'type'=>$type, 'key'=>$key, 'language'=>$language, 'value'=>$db_value, 'index'=>$index));
						unset($stmt);
						return $result;
					}
					return true;
				}
				else
				{
					return $this->clear($type,$id,$key,$language,$index);
				}
			}
		}
		else
		{
			return $this->clear($type,$id,$key,$language,$index);
		}
	}

	public function clear($type,$id,$key,$language = null,$index=null)
	{
		$this->flushPredis();

		if(!$this->table_is_ready())
			return true;

		$id = $id>0 ? $id : 0;
		if($index)
		{
			$stmt = $this->_pdo_handler->prepare("DELETE FROM `".$this->_table_name."` WHERE `id` = :id AND `type` = :type AND `key` = :key AND `language` = :language AND `index` = :index");
			$result = $stmt->execute(array('id'=>$id, 'type'=>$type, 'key'=>$key, 'language'=>$language, 'index'=>$index));
			unset($stmt);
			return $result;
		}
		if($language)
		{
			$stmt = $this->_pdo_handler->prepare("DELETE FROM `".$this->_table_name."` WHERE `id` = :id AND `type` = :type AND `key` = :key AND `language` = :language");
			$result = $stmt->execute(array('id'=>$id, 'type'=>$type, 'key'=>$key, 'language'=>$language));
			unset($stmt);
			return $result;
		}
		else
		{
			$stmt = $this->_pdo_handler->prepare("DELETE FROM `".$this->_table_name."` WHERE `id` = :id AND `type` = :type AND `key` = :key");
			$result = $stmt->execute(array('id'=>$id, 'type'=>$type, 'key'=>$key));
			unset($stmt);
			return $result;
		}
	}

	public function search($param=null, $type=null, $id=null, $key=null, $language=null)
	{
		if(!$this->table_is_ready())
			return false;

		$Morphoji = new \Chefkoch\Morphoji\Converter();

		$regexp = null;
		if(is_array($param))
		{
			$q =         isset($param['q'])        ? $param['q']        : null;
			$regexp =    isset($param['regexp'])   ? $param['regexp']   : false;
			$type =      isset($param['type'])     ? $param['type']     : null;
			$id =        isset($param['id'])       ? $param['id']       : null;
			$key =       isset($param['key'])      ? $param['key']      : null;
			$language =  isset($param['language']) ? $param['language'] : null;
		}
		else
		{
			$q = $param;
		}

		$execute_values = [];
		$query_filter = ['TRUE'];

		if($regexp)
		{
			// nothing to do
		}
		else
		{
			$regexp = array();
			if($q!==null)
			{
				foreach(explode(' ',$q) as $word)
				{
					if(strlen($word)>0)
					{
						$regexp[] = self::preg_unaccent(preg_quote($word));
					}
				}
			}
			$regexp = implode('|',$regexp);
		}

		if($regexp)
		{
			$query_filter[] ='`value` REGEXP :regexp';
			$execute_values['regexp'] = "(".$regexp.")";
		}

		if($id!==null)
		{
			$query_filter[] = '`id` = :id';
			$execute_values['id'] = $id;
		}
		if($type!==null)
		{
			$query_filter[] = '`type` = :type';
			$execute_values['type'] = $type;
		}
		if($key!==null)
		{
			$query_filter[] = '`key` = :key';
			$execute_values['key'] = $key;
		}
		if($language!==null)
		{
			$query_filter[] = '`language` = :language';
			$execute_values['language'] = $language;
		}

		$full_query = "SELECT * FROM `".$this->_table_name."` WHERE ".implode(' AND ',$query_filter)." ORDER BY `type`, `id`, `key`, `language`";
		$stmt = $this->_pdo_handler->prepare($full_query);
		$result = $stmt->execute($execute_values);
		if($result)
		{
			$r = $stmt->fetchAll(\PDO::FETCH_OBJ);
			foreach($r as $k=>$v)
			{
				$v->value = $Morphoji->toEmojis($v->value);
				$r[$k] = $v;
			}
			return $r;
		}
		return false;
	}

	public static function preg_unaccent($str)
	{
		$str = preg_replace('/(e|é|è|ê|ë)/','[eéèêë]',$str);
		$str = preg_replace('/(a|à|â|ä)/','[aàâä]',$str);
		$str = preg_replace('/(u|ù|û|ü)/','[uùûü]',$str);
		$str = preg_replace('/(i|î|ï)/','[iîï]',$str);
		$str = preg_replace('/(o|ô|ö)/','[oôö]',$str);
		return $str;
	}

	// ---------------------------------------------------------
	// Internal tools to test en build storage table
	// ---------------------------------------------------------

	private function table_is_ready($build_if_missing=false)
	{
		if($this->_table_is_ready===null)
		{
			if($this->test_table())
			{
				$this->_table_is_ready = true;
			}
			else if($build_if_missing)
			{
				$this->_table_is_ready = $this->init_table();
			}
			else
			{
				$this->_table_is_ready = false;
			}
		}

		if($this->_table_is_ready===false && $build_if_missing)
		{
			$this->_table_is_ready = $this->init_table();
		}

		return $this->_table_is_ready;
	}

	private function test_table()
	{
		$table = $this->_table_name;
		try {

			$result = $this->_pdo_handler->query("SELECT 1 FROM `".$table."` LIMIT 1");
			// Result is either boolean FALSE (no table found) or PDOStatement Object (table found)
			return $result !== FALSE;
		} catch (\Exception $e) {
			// We got an exception == table not found
			if(I18NDB_DEBUG)
			{
				print_r($e);
			}
			return FALSE;
		}
	}

	private function init_table()
	{
		$result = $this->_pdo_handler->query("
			CREATE TABLE `".$this->_table_name."` (
			`type` varchar(64) NOT NULL DEFAULT '',
			`id` int(11) NOT NULL DEFAULT '0',
			`key` varchar(64) NOT NULL,
			`language` varchar(6) NOT NULL,
			`index` varchar(32) NOT NULL DEFAULT '',
			`value` longtext,
			`created_at` datetime NOT NULL,
			`updated_at` datetime NOT NULL,
			PRIMARY KEY (`type`,`id`,`key`,`language`,`index`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
		");
		if(!$result)
			throw new \Exception($this->_pdo_handler->errorInfo());

		return $this->test_table();
	}
}