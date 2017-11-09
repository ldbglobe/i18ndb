<?php
namespace ldbglobe\i18ndb;

class i18ndb {

	private $_pdo_handler = null;
	private $_table_name = null;
	private $_table_is_ready = null;
	private $_get_statement_all = null;
	private $_get_statement_language = null;
	private $_set_statement = null;
	private $_clear_statement = null;

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

			$this->_get_statement_language = $this->_pdo_handler->prepare("SELECT `language`, `value` FROM `".$this->_table_name."` WHERE
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
			$result = $this->_get_statement_all->fetchAll(\PDO::FETCH_OBJ);
		}
		else
		{
			$result = $this->_get_statement_language->execute(array('group_id'=>$group_id, 'type'=>$type, 'key'=>$key, 'language'=>$language));
			if(!$result)
				throw new Exception($this->_pdo_handler->errorInfo());
			$result = $this->_get_statement_language->fetch(\PDO::FETCH_OBJ);
		}

		if($result)
			return $result;
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