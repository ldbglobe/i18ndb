<?php
namespace ldbglobe\i18ndb;

define('I18NDB_DEFAULT_STATIC_INSTANCE','DEFAULT');
define('I18NDB_DEFAULT_FALLBACK_CHAIN','DEFAULT');

class i18ndb {

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
			$__PRIVATE_INSTANCE_CACHE = '__PRIVATE_INSTANCE_CACHE__'.sha1(json_encode($pdo_handler).'_'.$table_name);
		} catch (Exception $e) {
			
		}
		
		if(ENV_DEBUG && $__PRIVATE_INSTANCE_CACHE)
		{
			$instance = self::LoadInstance($__PRIVATE_INSTANCE_CACHE);
			if($instance)
			{
				$this->_pdo_handler = $instance->_pdo_handler;
				$this->_table_name = $instance->_table_name;
				$this->_get_statement_all = $instance->_get_statement_all;
				$this->_get_statement_language = $instance->_get_statement_language;
				$this->_get_statement_language_index = $instance->_get_statement_language_index;
				$this->_set_statement = $instance->_set_statement;
				$this->_clear_statement = $instance->_clear_statement;
				$this->_clear_statement_language = $instance->_clear_statement_language;
				$this->_clear_statement_language_index = $instance->_clear_statement_language_index;
				return ;
			}
		}

		$this->_pdo_handler = $pdo_handler;
		$this->_table_name = $table_name;


		if($this->table_is_ready())
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
		}

		if(ENV_DEBUG && $__PRIVATE_INSTANCE_CACHE)
		{
			self::RegisterInstance($this,$__PRIVATE_INSTANCE_CACHE);
		}
	}

	// ---------------------------------------------------------
	// Read / Write function
	// ---------------------------------------------------------

	public function get($type,$id,$key,$language = null, $index = null)
	{
		$Morphoji = new \Chefkoch\Morphoji\Converter();

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
						$result =  $Morphoji->toEmojis($results[0]->value);
					}
				}
				else
					$result = false;
			}
			else
			{
				$result = $this->_get_statement_language_index->execute(array('id'=>$id, 'type'=>$type, 'key'=>$key, 'language'=>$language, 'index'=>$index));
				if(!$result)
					throw new Exception($this->_pdo_handler->errorInfo());
				$result =  $this->_get_statement_language->fetch(\PDO::FETCH_OBJ);
				if(isset($result->value))
					$result->value = $Morphoji->toEmojis($result->value);
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
		$query_filter = [];

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
						$regexp[] = preg_quote($word);
					}
				}
			}
			$regexp = implode('|',$regexp);
		}

		$query_filter[] ='value REGEXP :regexp';
		$execute_values['regexp'] = "(".$regexp.")";

		if($id!==null)
		{
			$query_filter[] = 'id = :id';
			$execute_values['id'] = $id;
		}
		if($type!==null)
		{
			$query_filter[] = 'type = :type';
			$execute_values['type'] = $type;
		}
		if($key!==null)
		{
			$query_filter[] = 'key = :key';
			$execute_values['key'] = $key;
		}
		if($language!==null)
		{
			$query_filter[] = 'language = :language';
			$execute_values['language'] = $language;
		}

		$stmt = $this->_pdo_handler->prepare("SELECT * FROM `".$this->_table_name."` WHERE ".implode(' AND ',$query_filter)." ORDER BY `type`, `id`, `key`, `language`");
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
			) ENGINE=MyISAM DEFAULT CHARSET=utf8;
		");
		if(!$result)
			throw new Exception($this->_pdo_handler->errorInfo());

		return $this->test_table();
	}
}