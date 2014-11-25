<?php
namespace MDO;

class Adapter extends \mysqli
{
	/**
	 * Use the PROFILER constant in the config of a Zend_Db_Adapter.
	 */
	const PROFILER = 'profiler';
	
	/**
	 * Use the AUTO_QUOTE_IDENTIFIERS constant in the config of a Zend_Db_Adapter.
	 */
	const AUTO_QUOTE_IDENTIFIERS = 'autoQuoteIdentifiers';
	
	/**
	 * Use the ALLOW_SERIALIZATION constant in the config of a Zend_Db_Adapter.
	 */
	const ALLOW_SERIALIZATION = 'allowSerialization';
	
	/**
	 * Use the AUTO_RECONNECT_ON_UNSERIALIZE constant in the config of a Zend_Db_Adapter.
	 */
	const AUTO_RECONNECT_ON_UNSERIALIZE = 'autoReconnectOnUnserialize';
	
	/**
	 * User-provided configuration
	 *
	 * @var array
	 */
	protected $_config = array();

	/**
	 * Query profiler object, of type Profiler
	 * or a subclass of that.
	 *
	 * @var Profiler
	 */
	protected $_profiler;

	/**
	 * Default class name for the profiler object.
	 *
	 * @var string
	 */
	protected $_defaultProfilerClass = 'MDO\Profiler';

	/**
	 * Specifies whether the adapter automatically quotes identifiers.
	 * If true, most SQL generated by Zend_Db classes applies
	 * identifier quoting automatically.
	 * If false, developer must quote identifiers themselves
	 * by calling quoteIdentifier().
	 *
	 * @var bool
	 */
	protected $_autoQuoteIdentifiers = true;

	/** Weither or not that object can get serialized
	 *
	 * @var bool
	 */
	protected $_allowSerialization = true;

	/**
	 * Weither or not the database should be reconnected
	 * to that adapter when waking up
	 *
	 * @var bool
	 */
	protected $_autoReconnectOnUnserialize = false;
	
	/**
	 * 
	 * @var bool
	 */
	protected $_isConnected = false;

	/**
	 * 尚未发射的语句
	 * @var array
	 */
	protected $_waitingQueue = array();
	
	/**
	 * 已经发射的语句
	 * @var array
	*/
	protected $_fetchingQueue = array();
	
	/**
	 * Constructor.
	 *
	 * $config is an array of key/value pairs
	 * containing configuration options.  These options are common to most adapters:
	 *
	 * dbname		 => (string) The name of the database to user
	 * username	   => (string) Connect to the database as this username.
	 * password	   => (string) Password associated with the username.
	 * host		   => (string) What host to connect to, defaults to localhost
	 *
	 * Some options are used on a case-by-case basis by adapters:
	 *
	 * port		   => (string) The port of the database
	 * persistent	 => (boolean) Whether to use a persistent connection or not, defaults to false
	 * protocol	   => (string) The network protocol, defaults to TCPIP
	 * caseFolding	=> (int) style of case-alteration used for identifiers
	 *
	 * @param  array $config An array having configuration data
	 * @throws Zend_Db_Adapter_Exception
	 */
	public function __construct($config)
	{
		parent::init();
		//$this->_checkRequiredOptions($config);

		$options = array(
			self::AUTO_QUOTE_IDENTIFIERS => $this->_autoQuoteIdentifiers,
		);
		$driverOptions = array();

		/*
		 * normalize the config and merge it with the defaults
		 */
		if (array_key_exists('options', $config)) {
			// can't use array_merge() because keys might be integers
			foreach ((array) $config['options'] as $key => $value) {
				$options[$key] = $value;
			}
		}
		if (array_key_exists('driver_options', $config)) {
			if (!empty($config['driver_options'])) {
				// can't use array_merge() because keys might be integers
				foreach ((array) $config['driver_options'] as $key => $value) {
					$driverOptions[$key] = $value;
				}
			}
		}

		if (!isset($config['charset'])) {
			$config['charset'] = null;
		}

		if (!isset($config['persistent'])) {
			$config['persistent'] = false;
		}

		$this->_config = array_merge($this->_config, $config);
		$this->_config['options'] = $options;
		$this->_config['driver_options'] = $driverOptions;

		// obtain quoting property if there is one
		if (array_key_exists(self::AUTO_QUOTE_IDENTIFIERS, $options)) {
			$this->_autoQuoteIdentifiers = (bool) $options[self::AUTO_QUOTE_IDENTIFIERS];
		}

		// obtain allow serialization property if there is one
		if (array_key_exists(self::ALLOW_SERIALIZATION, $options)) {
			$this->_allowSerialization = (bool) $options[self::ALLOW_SERIALIZATION];
		}

		// obtain auto reconnect on unserialize property if there is one
		if (array_key_exists(self::AUTO_RECONNECT_ON_UNSERIALIZE, $options)) {
			$this->_autoReconnectOnUnserialize = (bool) $options[self::AUTO_RECONNECT_ON_UNSERIALIZE];
		}

		// 修改了原来的Zend_Db代码，在不开启profiler的情况下，不再生成profiler实例
		$this->_profiler = false;
		
		if (array_key_exists(self::PROFILER, $this->_config)) {
			if ($this->_config[self::PROFILER])
				$this->setProfiler($this->_config[self::PROFILER]);
			unset($this->_config[self::PROFILER]);
		}
	}

	/**
	 * Returns the configuration variables in this adapter.
	 *
	 * @return array
	 */
	public function getConfig()
	{
		return $this->_config;
	}

	/**
	 * Set the adapter's profiler object.
	 *
	 * The argument may be a boolean, an associative array, an instance of
	 * Profiler.
	 *
	 * A boolean argument sets the profiler to enabled if true, or disabled if
	 * false.  The profiler class is the adapter's default profiler class,
	 * Profiler.
	 *
	 * An instance of Profiler sets the adapter's instance to that
	 * object.  The profiler is enabled and disabled separately.
	 *
	 * An associative array argument may contain any of the keys 'enabled',
	 * 'class', and 'instance'. The 'enabled' and 'instance' keys correspond to the
	 * boolean and object types documented above. The 'class' key is used to name a
	 * class to use for a custom profiler. The class must be Profiler or a
	 * subclass. The class is instantiated with no constructor arguments. The 'class'
	 * option is ignored when the 'instance' option is supplied.
	 *
	 * An object of type Zend_Config may contain the properties 'enabled', 'class', and
	 * 'instance', just as if an associative array had been passed instead.
	 *
	 * @param  Profiler|array|boolean $profiler
	 * @return Adapter Provides a fluent interface
	 * @throws ProfilerException if the object instance or class specified
	 *		 is not Profiler or an extension of that class.
	 */
	public function setProfiler($profiler)
	{
		$enabled		  = null;
		$profilerClass	= $this->_defaultProfilerClass;
		$profilerInstance = null;

		if ($profilerIsObject = is_object($profiler)) {
			if ($profiler instanceof Profiler) {
				$profilerInstance = $profiler;
			} else {
				throw new ProfilerException('Profiler argument must be an instance of either Profiler'
					. ' or Zend_Config when provided as an object');
			}
		}

		if (is_array($profiler)) {
			if (isset($profiler['enabled'])) {
				$enabled = (bool) $profiler['enabled'];
			}
			if (isset($profiler['class'])) {
				$profilerClass = $profiler['class'];
			}
			if (isset($profiler['instance'])) {
				$profilerInstance = $profiler['instance'];
			}
		} else if (!$profilerIsObject) {
			$enabled = (bool) $profiler;
		}

		if ($profilerInstance === null) {
			$profilerInstance = new $profilerClass();
		}

		if (!$profilerInstance instanceof Profiler) {
			throw new ProfilerException('Class ' . get_class($profilerInstance) . ' does not extend '
				. 'Profiler');
		}

		if (null !== $enabled) {
			$profilerInstance->setEnabled($enabled);
		}

		$this->_profiler = $profilerInstance;

		return $this;
	}

	/**
	 * Returns the profiler for this adapter.
	 *
	 * @return Profiler
	 */
	public function getProfiler()
	{
		return $this->_profiler;
	}

	/**
	 * Leave autocommit mode and begin a transaction.
	 *
	 * @return Adapter
	 */
	public function beginTransaction()
	{
		if (!$this->_isConnected) $this->_connect();
		if ($this->_profiler) $q = $this->_profiler->queryStart('begin', Profiler::TRANSACTION);
		parent::begin_transaction();
		if ($this->_profiler) $this->_profiler->queryEnd($q);
		return $this;
	}

	/**
	 * Commit a transaction and return to autocommit mode.
	 *
	 * @return Adapter
	 */
	public function commit($flags = NULL, $name = NULL)
	{
		if (!$this->_isConnected) $this->_connect();
		if ($this->_profiler) $q = $this->_profiler->queryStart('commit', Profiler::TRANSACTION);
		parent::commit($flags, $name);
		if ($this->_profiler) $this->_profiler->queryEnd($q);
		return $this;
	}

	/**
	 * Roll back a transaction and return to autocommit mode.
	 *
	 * @return Adapter
	 */
	public function rollBack($flags = NULL, $name = NULL)
	{
		if (!$this->_isConnected) $this->_connect();
		if ($this->_profiler) $q = $this->_profiler->queryStart('rollback', Profiler::TRANSACTION);
		parent::rollback($flags, $name);
		if ($this->_profiler) $this->_profiler->queryEnd($q);
		return $this;
	}

	/**
	 * Creates and returns a new Select object for this adapter.
	 *
	 * @return Select
	 */
	public function select()
	{
		return new Select($this);
	}
	
	/**
	 * 
	 * @return Insert
	 */
	public function insert($keyword = null){
		$insert = new Insert($this);
		if ($keyword !== null)
			$insert->setKeyword($keyword);
		return $insert;
	}
	
	/**
	 * 
	 * @return Update
	 */
	public function update(){
		return new Update($this);
	}
	
	/**
	 * 
	 * @return Delete
	 */
	public function delete(){
		return new Delete($this);
	}
	
	/**
	 * Safely quotes a value for an SQL statement.
	 *
	 * @param mixed $value The value to quote.
	 * @return mixed An SQL-safe quoted value (or string of separated values).
	 */
	public function quote($value)
	{
		if ($value instanceof Select) {
			return '(' . $value->assemble() . ')';
		}

		if ($value instanceof Expr) {
			return $value->__toString();
		}
		
		if ($value === null){
			return 'null';
		}
		
		if (!$this->_isConnected) $this->_connect();

		return '\'' . parent::real_escape_string($value) . '\'';
	}
	
	/**
	 * the array values are quoted and then returned as a comma-separated string.
	 * 为了解决quote的性能问题，将quote的Array迭代单独提出来
	 */
	public function quoteArray(array $array){
		foreach ($array as &$val) {
			$val = $this->quote($val);
		}
		return implode(', ', $array); 
	}

	/**
	 * Quotes a value and places into a piece of text at a placeholder.
	 *
	 * The placeholder is a question-mark; all placeholders will be replaced
	 * with the quoted value.   For example:
	 *
	 * <code>
	 * $text = "WHERE date < ?";
	 * $date = "2005-01-02";
	 * $safe = $sql->quoteInto($text, $date);
	 * // $safe = "WHERE date < '2005-01-02'"
	 * </code>
	 *
	 * @param string  $text  The text with a placeholder.
	 * @param mixed   $value The value to quote.
	 * @param string  $type  OPTIONAL SQL datatype
	 * @param integer $count OPTIONAL count of placeholders to replace
	 * @return string An SQL-safe quoted value placed into the original text.
	 */
	public function quoteInto($text, $value, $count = null)
	{
		//这里加入了连接检查之后quote就不需要再连接检查了
		if (!$this->_isConnected) $this->_connect();
		
		$quotedValue = is_array($value) ? $this->quoteArray($value) : $this->quote($value);
		
		if ($count === null) {
			return str_replace('?', $quotedValue, $text);
		} else {
			while ($count > 0) {
				if (strpos($text, '?') !== false) {
					$text = substr_replace($text, $quotedValue, strpos($text, '?'), 1);
				}
				--$count;
			}
			return $text;
		}
	}

	/**
	 * Quotes an identifier.
	 *
	 * Accepts a string representing a qualified indentifier. For Example:
	 * <code>
	 * $adapter->quoteIdentifier('myschema.mytable')
	 * </code>
	 * Returns: "myschema"."mytable"
	 *
	 * Or, an array of one or more identifiers that may form a qualified identifier:
	 * <code>
	 * $adapter->quoteIdentifier(array('myschema','my.table'))
	 * </code>
	 * Returns: "myschema"."my.table"
	 *
	 * The actual quote character surrounding the identifiers may vary depending on
	 * the adapter.
	 *
	 * @param string|array|Expr $ident The identifier.
	 * @param boolean $auto If true, heed the self::AUTO_QUOTE_IDENTIFIERS config option.
	 * @return string The quoted identifier.
	 */
	public function quoteIdentifier($ident, $auto=false)
	{
		return $this->_quoteIdentifierAs($ident, null, $auto);
	}

	/**
	 * Quote a column identifier and alias.
	 *
	 * @param string|array|Expr $ident The identifier or expression.
	 * @param string $alias An alias for the column.
	 * @param boolean $auto If true, heed the self::AUTO_QUOTE_IDENTIFIERS config option.
	 * @return string The quoted identifier and alias.
	 */
	public function quoteColumnAs($ident, $alias, $auto=false)
	{
		return $this->_quoteIdentifierAs($ident, $alias, $auto);
	}

	/**
	 * Quote a table identifier and alias.
	 *
	 * @param string|array|Expr $ident The identifier or expression.
	 * @param string $alias An alias for the table.
	 * @param boolean $auto If true, heed the self::AUTO_QUOTE_IDENTIFIERS config option.
	 * @return string The quoted identifier and alias.
	 */
	public function quoteTableAs($ident, $alias = null, $auto = false)
	{
		return $this->_quoteIdentifierAs($ident, $alias, $auto);
	}

	/**
	 * Quote an identifier and an optional alias.
	 *
	 * @param string|array|Expr $ident The identifier or expression.
	 * @param string $alias An optional alias.
	 * @param boolean $auto If true, heed the self::AUTO_QUOTE_IDENTIFIERS config option.
	 * @param string $as The string to add between the identifier/expression and the alias.
	 * @return string The quoted identifier and alias.
	 */
	protected function _quoteIdentifierAs($ident, $alias = null, $auto = false, $as = ' AS ')
	{
		if ($ident instanceof Expr) {
			$quoted = $ident->__toString();
		} elseif ($ident instanceof Select) {
			$quoted = '(' . $ident->assemble() . ')';
		} else {
			if (is_string($ident)) {
				$ident = explode('.', $ident);
			}
			if (is_array($ident)) {
				$segments = array();
				foreach ($ident as $segment) {
					if ($segment instanceof Expr) {
						$segments[] = $segment->__toString();
					} else {
						$segments[] = $this->_quoteIdentifier($segment, $auto);
					}
				}
				if ($alias !== null && end($ident) == $alias) {
					$alias = null;
				}
				$quoted = implode('.', $segments);
			} else {
				$quoted = $this->_quoteIdentifier($ident, $auto);
			}
		}
		if ($alias !== null) {
			$quoted .= $as . $this->_quoteIdentifier($alias, $auto);
		}
		return $quoted;
	}

	/**
	 * Quote an identifier.
	 *
	 * @param  string $value The identifier or expression.
	 * @param boolean $auto If true, heed the self::AUTO_QUOTE_IDENTIFIERS config option.
	 * @return string		The quoted identifier and alias.
	 */
	protected function _quoteIdentifier($value, $auto=false)
	{
		if ($auto === false || $this->_autoQuoteIdentifiers === true) {
			return ('`' . str_replace('`', '``', $value) . '`');
		}
		return $value;
	}

	/**
	 * called when object is getting serialized
	 * This disconnects the DB object that cant be serialized
	 *
	 * @throws AdapterException
	 * @return array
	 */
	public function __sleep()
	{
		if ($this->_allowSerialization == false) {
			/** @see AdapterException */
			throw new AdapterException(get_class($this) ." is not allowed to be serialized");
		}
		$this->_connection = false;
		return array_keys(array_diff_key(get_object_vars($this), array('_connection'=>false)));
	}

	/**
	 * called when object is getting unserialized
	 *
	 * @return void
	 */
	public function __wakeup()
	{
		if ($this->_autoReconnectOnUnserialize == true) {
			$this->_connect();
		}
	}

	//以下是PDO
	
	/**
	 * Test if a connection is active
	 *
	 * @return boolean
	 */
	public function isConnected()
	{
		return $this->_isConnected;
	}
	
	public function newStatement($sql){
		return $this->_waitingQueue[] = new Statement($this, $sql);
	}

	/**
	 * Prepares an SQL statement.
	 *
	 * @param string $sql The SQL statement with placeholders.
	 * @param array $bind An array of data to bind to the placeholders.
	 * @return \mysqli_stmt
	 */
	public function prepare($sql)
	{
		if (!$this->_isConnected) $this->_connect();
		
		return parent::prepare($sql);
	}
	
	/**
	 * 将buffer中现有的所有结果集都取回来
	 */
	public function flushQueue($untilStatement = null){
		while($this->more_results()){
			$this->next_result();
			$statement = array_shift($this->_fetchingQueue);
			$statement->setResult($this->store_result());
			
			if ($this->errno)
				throw new AdapterException($this->error, $this->errno);
			
			if ($statement === $untilStatement)
				return;
		}
	}
	
	public function queryStatement($statement){
		if (!$this->_isConnected) $this->_connect();
		
		//将当前的语句插到第一个，然后把所有语句一口气打包发送给mysql
		$keys = array_keys($this->_waitingQueue, $statement);
		
		if (count($keys))
			unset($this->_waitingQueue[$keys[0]]);
		
		$sql = $statement->assemble();
		if (count($this->_waitingQueue))
			$sql .= ";\n" . implode(";\n", $this->_waitingQueue);
		
		$this->multi_query($sql);
		$statement->setResult($this->store_result());
		
		$this->_fetchingQueue = $this->_waitingQueue;
		
		$this->_waitingQueue = array();
		
		if ($this->errno)
			throw new AdapterException($this->error, $this->errno);
	}
	
	/**
	 * 
	 * @param string $sql
	 * @throws AdapterException
	 * @return \mysqli_result
	 */
	public function query($sql){
		if (!$this->_isConnected) $this->_connect();
	
		//将结果缓冲当中的结果集读出来
		$this->flushQueue();
	
		if ($this->_profiler === false) {
			$result = parent::query($sql);
		}
		else{
			$q = $this->_profiler->queryStart($sql);
				
			$qp = $this->_profiler->getQueryProfile($q);
			if ($qp->hasEnded()) {
				$q = $this->_profiler->queryClone($qp);
				$qp = $this->_profiler->getQueryProfile($q);
			}
	
			$result = parent::query($sql);
	
			$this->_profiler->queryEnd($q);
		}
		
		if ($this->errno)
			throw new AdapterException($this->error, $this->errno);

		return $result;
	}
	
	/**
	 * Special handling for mysqli query().
	 *
	 * @param string|Select $sql The SQL statement with placeholders.
	 * @param array $bind An array of data to bind to the placeholders.
	 * @return \mysqli_result
	 * @throws \mysqli_sql_exception.
	 */
	public function queryBind($sql, $bind = array())
	{
		//try {省略throw-catch-rethrow块，直接抛出\mysqli_sql_exception
		// connect to the database if needed
		if (!$this->_isConnected) $this->_connect();
		
		// make sure $bind to an array;
		// don't use (array) typecasting because
		// because $bind may be a Expr object
		if (!is_array($bind)) {
			$bind = array($bind);
		}
		
		//将结果缓冲当中的结果集读出来
		$this->flushQueue();
		
		$stmt = parent::stmt_init();	// TODO 以后可以派生mysqli_stmt
		$stmt->prepare($sql);
		if ($stmt === false)
			throw new Exception('Failed in preparing SQL: ' . $sql);
		
		if (!empty($bind)){
			$types = '';
			foreach($bind as $val){
				switch (gettype($val)){
					case 'string':
						$types .= 's';
						break;
					case 'integer':
						$types .= 'i';
						break;
					case 'double':
						$types .= 'd';
						break;
					case 'boolean':
					case 'object':
					case 'array':
					case 'resource':
					case 'NULL':
					case "unknown type":
					default:
						$types .= 's';
				}
			}
			$stmt->bind_param($types, $bind);
		}
		
		// 由于取消了Statement，因此将Profiler的控制代码移动到这里
		// 由于所处的程序位置，省略了$qp->start(),简化了$qp->bindParams()的相关代码
		if ($this->_profiler === false) {
			$stmt->execute();
			$result = $stmt->get_result();
		}
		else{
			$q = $this->_profiler->queryStart($sql);
			
			$qp = $this->_profiler->getQueryProfile($q);
			if ($qp->hasEnded()) {
				$q = $this->_profiler->queryClone($qp);
				$qp = $this->_profiler->getQueryProfile($q);
			}
			$qp->bindParams($bind);

			$stmt->execute();
			$result = $stmt->get_result();
	
			$this->_profiler->queryEnd($q);
		}
		
		return $result;
	}

	/**
	 * Executes an SQL statement and return the number of affected rows
	 *
	 * @param  mixed  $sql  The SQL statement with placeholders.
	 *					  May be a string or Select.
	 * @return integer	  Number of rows that were modified
	 *					  or deleted by the SQL statement
	 * @throws \mysqli_sql_exception
	 */
	public function exec($sql)
	{
		if ($sql instanceof Select) {
			$sql = $sql->assemble();
		}

		$affected = parent::query($sql);

		if ($affected === false) {
			throw new AdapterException($this->error, $this->errno);
		}

		return $affected;
	}
	
	/**
	 * 
	 * @return self
	 */
	public function ensureConnected(){
		if (!$this->_isConnected) $this->_connect();
		
		return $this;
	}

	/**
	 * Creates a PDO object and connects to the database.
	 *
	 * @return void
	 * @throws \mysqli_sql_exception
	 */
	protected function _connect()
	{
		if ($this->_profiler) $q = $this->_profiler->queryStart('connect', Profiler::CONNECT);

		$config = $this->_config;
		//try {省略throw-catch-rethrow块，直接抛出\mysqli_sql_exception
		parent::real_connect(
			(empty($config['persistent']) ? '' : 'p:') . (isset($config['host']) ? $config['host'] : ini_get("mysqli.default_host")),
			isset($config['username']) ? $config['username'] : ini_get("mysqli.default_user"),
			isset($config['password']) ? $config['password'] : ini_get("mysqli.default_pw"),
			isset($config['dbname']) ? $config['dbname'] : "",
			isset($config['port']) ? $config['port'] : ini_get("mysqli.default_port"),
			isset($config['socket']) ? $config['socket'] : ini_get("mysqli.default_socket")
		);
		$this->_isConnected = true;

		if ($this->_profiler) $this->_profiler->queryEnd($q);

		if (!empty($this->_config['charset'])) {
			parent::set_charset($this->_config['charset']);
		}
	}
}
