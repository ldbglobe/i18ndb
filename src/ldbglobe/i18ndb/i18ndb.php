<?php
namespace ldbglobe\i18ndb;

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
	private $_get_statement_all = null;
	private $_get_statement_language = null;
	private $_set_statement = null;
	private $_clear_statement = null;
	private $_clear_statement_language = null;

	public static function setPredisClient($predisClient,$ttl=null)
	{
		$ttl = $ttl ? $ttl : 3600*48;
		self::$_predisClient = $predisClient;
		self::$_predisTTL = $ttl;
	}
	public function getPredisHash()//$type,$id,$key,$language,$index)
	{
		if(!self::$_predisClient)
			return null;

		return 'i18ndb::'.hash('sha256',serialize([$this->_table_name]));
		//return 'i18ndb__'.hash('sha256',serialize([$this->_table_name, $type, $id, $key, $language, $index]));
	}
	public function getContentHash($type, $id, $key, $language, $index)
	{
		if(!self::$_predisClient)
			return null;

		return implode('::',[$type?$type:'', $id?$id:'', $key?$key:'', $language?$language:'', $index?$index:'']);
	}
	public function getPredisCache()
	{
		if(!self::$_predisClient)
			return null;

		$cache = false;
		try {
			$cache = unserialize(self::$_predisClient->get($this->getPredisHash()));
		} catch (\Exception $e) {
			// ...
		}
		return $cache;
	}
	public function setPredisCache($data,$update_ttl=false)
	{
		if(!self::$_predisClient)
			return null;

		self::$_predisClient->set($this->getPredisHash(),serialize($data));
		if($update_ttl)
			self::$_predisClient->expire($this->getPredisHash(), self::$_predisTTL);
	}

	public function getPredisValue($type,$id,$key,$language,$index)
	{
		if(!self::$_predisClient)
			return null;

		static $cache = null;
		$cache = $cache===null ? $this->getPredisCache() : $cache;
		if(!is_array($cache))
		{
			// no data found in redis cache
			$dictionnary = $this->search();
			$cache = [];
			foreach($dictionnary as $text)
			{
				$cache[$this->getContentHash($text->type, $text->id, $text->key, $text->language, $text->index)] = $text->value;
			}
			$this->setPredisCache($cache,true);
		}
		$cache = $cache ? $cache : [];

		$k = $this->getContentHash($type,$id,$key,$language,$index);
		return isset($cache[$k]) ? $cache[$k] : '';
	}
	public function delPredisValue($type,$id,$key,$language,$index)
	{
		if(!self::$_predisClient)
			return null;

		$cache = $this->getPredisCache();
		if(is_array($cache))
		{
			$k = $this->getContentHash($type,$id,$key,$language,$index);
			if(isset($cache[$k]))
			{
				unset($cache[$k]);
				$this->setPredisCache($cache);
			}
		}
	}
	public function setPredisValue($type,$id,$key,$language,$index,$value)
	{
		if(!self::$_predisClient)
			return null;

		$cache = $this->getPredisCache();
		if(is_array($cache))
		{
			$k = $this->getContentHash($type,$id,$key,$language,$index);
			$cache[$k] = $value;
			$this->setPredisCache($cache);
		}
	}
	public function flushPredis()
	{
		if(!self::$_predisClient)
			return null;

		self::$_predisClient->expire($this->getPredisHash(), -1);
		self::$_predisClient->del($this->getPredisHash());
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

	public function __construct($pdo_handler, $table_name)
	{
		$__PRIVATE_INSTANCE_CACHE = false;
		try {
			$__PRIVATE_INSTANCE_CACHE = '__PRIVATE_INSTANCE_CACHE__'.hash('sha256',json_encode($pdo_handler).'_'.$table_name);
		} catch (Exception $e) {
			//...
		}

		if($__PRIVATE_INSTANCE_CACHE)
		{
			$this->__PRIVATE_INSTANCE_CACHE = $__PRIVATE_INSTANCE_CACHE;

			$instance = self::LoadInstance($__PRIVATE_INSTANCE_CACHE);
			if($instance)
			{
				$this->inheritInstance($instance);
				return ;
			}
		}

		$this->_pdo_handler = $pdo_handler;
		$this->_table_name = $table_name;
		$this->_statements_ready = false;

		if($__PRIVATE_INSTANCE_CACHE)
		{
			self::RegisterInstance($this,$__PRIVATE_INSTANCE_CACHE);
		}
	}

	public function inheritInstance($instance)
	{
		$this->_pdo_handler = $instance->_pdo_handler;
		$this->_table_name = $instance->_table_name;
		$this->_statements_ready = $instance->_statements_ready;
		$this->_get_statement_all = $instance->_get_statement_all;
		$this->_get_statement_language = $instance->_get_statement_language;
		$this->_get_statement_language_index = $instance->_get_statement_language_index;
		$this->_set_statement = $instance->_set_statement;
		$this->_clear_statement = $instance->_clear_statement;
		$this->_clear_statement_language = $instance->_clear_statement_language;
		$this->_clear_statement_language_index = $instance->_clear_statement_language_index;
	}

	public function registerStatements()
	{

		if($this->_statements_ready===false && $this->table_is_ready())
		{
			$this->_get_statement_all = $this->_pdo_handler->prepare("SELECT `language`, `index`, `value` FROM `".$this->_table_name."` WHERE
				`id` = :id AND
				`type` = :type AND
				`key` = :key
			");

			$this->_get_statement_language = $this->_pdo_handler->prepare("SELECT `index`, `value` FROM `".$this->_table_name."` WHERE
				`id` = :id AND
				`type` = :type AND
				`key` = :key AND
				`language` = :language
			");
			$this->_get_statement_language_index = $this->_pdo_handler->prepare("SELECT `value` FROM `".$this->_table_name."` WHERE
				`id` = :id AND
				`type` = :type AND
				`key` = :key AND
				`language` = :language AND
				`index` = :index
			");

			$this->_set_statement = $this->_pdo_handler->prepare("INSERT INTO `".$this->_table_name."` SET
				`id` = :id,
				`type` = :type,
				`key` = :key,
				`language` = :language,
				`index` = :index,
				`value` = :value,
				created_at = NOW(),
				updated_at = NOW()
				ON DUPLICATE KEY UPDATE updated_at = NOW(), `value` = :value
			");

			$this->_clear_statement = $this->_pdo_handler->prepare("DELETE FROM `".$this->_table_name."` WHERE
				`id` = :id AND
				`type` = :type AND
				`key` = :key
			");

			$this->_clear_statement_language = $this->_pdo_handler->prepare("DELETE FROM `".$this->_table_name."` WHERE
				`id` = :id AND
				`type` = :type AND
				`key` = :key AND
				`language` = :language
			");
			$this->_clear_statement_language_index = $this->_pdo_handler->prepare("DELETE FROM `".$this->_table_name."` WHERE
				`id` = :id AND
				`type` = :type AND
				`key` = :key AND
				`language` = :language AND
				`index` = :index
			");

			$this->_statements_ready = true;
		}
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

		$this->registerStatements();

		$id = $id>0 ? $id : 0;
		if($language===null)
		{
			$result = $this->_get_statement_all->execute(array('id'=>$id, 'type'=>$type, 'key'=>$key));
			if(!$result)
				throw new Exception($this->_pdo_handler->errorInfo());
			$results = $this->_get_statement_all->fetchAll(\PDO::FETCH_OBJ);
			$result = array();
			if($results)
			{
				foreach($results as $k=>$v)
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
				$result = $this->_get_statement_language->execute(array('id'=>$id, 'type'=>$type, 'key'=>$key, 'language'=>$language));
				if(!$result)
					throw new Exception($this->_pdo_handler->errorInfo());
				$results = $this->_get_statement_language->fetchAll(\PDO::FETCH_OBJ);
				if($results)
				{
					if(count($results)>1 || $results[0]->index!=='')
					{
						$result = array();
						foreach($results as $k=>$v)
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
				$result = $this->_get_statement_language_index->execute(array('id'=>$id, 'type'=>$type, 'key'=>$key, 'language'=>$language, 'index'=>$index));
				if(!$result)
					throw new \Exception($this->_pdo_handler->errorInfo());
				$result =  $this->_get_statement_language->fetch(\PDO::FETCH_OBJ);
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
		if(!$this->table_is_ready())
			return false;

		$this->registerStatements();

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
						return $this->_set_statement->execute(array('id'=>$id, 'type'=>$type, 'key'=>$key, 'language'=>$language, 'value'=>$db_value, 'index'=>$index));
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
			return false;

		$this->registerStatements();

		$id = $id>0 ? $id : 0;
		if($index)
			return $this->_clear_statement_language_index->execute(array('id'=>$id, 'type'=>$type, 'key'=>$key, 'language'=>$language, 'index'=>$index));
		if($language)
			return $this->_clear_statement_language->execute(array('id'=>$id, 'type'=>$type, 'key'=>$key, 'language'=>$language));
		else
			return $this->_clear_statement->execute(array('id'=>$id, 'type'=>$type, 'key'=>$key));
	}

	public function search($param=null, $type=null, $id=null, $key=null, $language=null)
	{
		if(!$this->table_is_ready())
			return false;

		$this->registerStatements();

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

	private function table_is_ready()
	{
		if($this->_table_is_ready===null)
		{
			if($this->test_table())
			{
				$this->_table_is_ready = true;
			}
			else
			{
				$this->_table_is_ready = $this->init_table();
			}
		}
		return $this->_table_is_ready;
	}

	private function test_table()
	{
		$table = $this->_table_name;
		try {

			$result = $this->_pdo_handler->query("SELECT 1 FROM `${table}` LIMIT 1");
			// Result is either boolean FALSE (no table found) or PDOStatement Object (table found)
			return $result !== FALSE;
		} catch (\Exception $e) {
			// We got an exception == table not found
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