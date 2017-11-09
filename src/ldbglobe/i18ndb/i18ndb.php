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
		$this->_pdo_handler = $pdo_handler;
		$this->_table_name = $table_name;

		if($this->table_is_ready())
		{
			$this->_get_statement_all = $this->_pdo_handler->prepare("SELECT `language`, `value` FROM `".$this->_table_name."` WHERE
				`group_id` = :group_id AND
				`type` = :type AND
				`key` = :key
			");

			$this->_get_statement_language = $this->_pdo_handler->prepare("SELECT `value` FROM `".$this->_table_name."` WHERE
				`group_id` = :group_id AND
				`type` = :type AND
				`key` = :key AND
				`language` = :language
			");

			$this->_set_statement = $this->_pdo_handler->prepare("INSERT INTO `".$this->_table_name."` SET
				`group_id` = :group_id,
				`type` = :type,
				`key` = :key,
				`language` = :language,
				`value` = :value,
				created_at = NOW(),
				updated_at = NOW()
				ON DUPLICATE KEY UPDATE updated_at = NOW()
			");

			$this->_clear_statement = $this->_pdo_handler->prepare("DELETE FROM `".$this->_table_name."` WHERE
				`group_id` = :group_id AND
				`type` = :type AND
				`key` = :key AND
				`language` = :language
			");
		}
	}

	// ---------------------------------------------------------
	// Read / Write function
	// ---------------------------------------------------------

	public function get($group_id,$type,$key,$language = null)
	{
		$group_id = $group_id>0 ? $group_id : 0;
		if($language===null)
		{
			$result = $this->_get_statement_all->execute(array('group_id'=>$group_id, 'type'=>$type, 'key'=>$key));
			if(!$result)
				throw new Exception($this->_pdo_handler->errorInfo());
			$results = $this->_get_statement_all->fetchAll(\PDO::FETCH_OBJ);
			$result = array();
			if($results)
			{
				foreach($results as $k=>$v)
				{
					$result[$v->language] = $v->value;
				}
			}
		}
		else
		{
			$result = $this->_get_statement_language->execute(array('group_id'=>$group_id, 'type'=>$type, 'key'=>$key, 'language'=>$language));
			if(!$result)
				throw new Exception($this->_pdo_handler->errorInfo());
			$result = $this->_get_statement_language->fetch(\PDO::FETCH_OBJ);
			if($result)
				$result = $result->value;
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

	public function getWithFallback($group_id,$type,$key,$language)
	{
		$r = $this->get($group_id,$type,$key);
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

	public function set($group_id,$type,$key,$language, $value = null)
	{
		$group_id = $group_id>0 ? $group_id : 0;
		if($value!==null)
		{
			// write in database only if change is needed
			$old = $this->get($group_id,$type,$key,$language);
			if($old->value!=$value)
				return $this->_set_statement->execute(array('group_id'=>$group_id, 'type'=>$type, 'key'=>$key, 'language'=>$language, 'value'=>$value));
			return true;
		}
		else
			return $this->_clear_statement->execute(array('group_id'=>$group_id, 'type'=>$type, 'key'=>$key, 'language'=>$language));
	}

	public function search($q, $group_id=null, $type=null, $key=null, $language=null)
	{
		$execute_values = [];
		$query_filter = [];

		$regexp = array();
		foreach(explode(' ',$q) as $word)
		{
			if(strlen($word)>3)
			{
				$regexp[] = preg_quote($word);
			}
		}
		$query_filter[] ='value REGEXP :regexp';
		$execute_values['regexp'] = "(".implode('|',$regexp).")";

		if($group_id!==null)
		{
			$query_filter[] = 'group_id = :group_id';
			$execute_values['group_id'] = $group_id;
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

		$stmt = $this->_pdo_handler->prepare("SELECT * FROM `".$this->_table_name."` WHERE ".implode(' AND ',$query_filter)." ORDER BY `group_id`, `type`, `key`, `language`");
		$result = $stmt->execute($execute_values);
		if($result)
			return $stmt->fetchAll(\PDO::FETCH_OBJ);
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
		return $this->_pdo_handler->query("SHOW TABLES LIKE '".$this->_table_name."'")->rowCount() > 0;
	}

	private function init_table()
	{
		$result = $this->_pdo_handler->query("
			CREATE TABLE `".$this->_table_name."` (
			`group_id` int(11) NOT NULL DEFAULT '0',
			`type` varchar(64) NOT NULL DEFAULT '',
			`key` varchar(64) NOT NULL,
			`language` varchar(6) NOT NULL,
			`value` longtext,
			`created_at` datetime NOT NULL,
			`updated_at` datetime NOT NULL,
			PRIMARY KEY (`group_id`,`type`,`key`,`language`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8;
		");
		if(!$result)
			throw new Exception($this->_pdo_handler->errorInfo());

		return $this->test_table();
	}
}