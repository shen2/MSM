<?php
namespace MSM;

abstract class Model extends \ArrayObject
{

	protected static $_defaultValues = [];

	/**
	 * Fetches a new blank row (not from the database).
	 *
	 * @param  array $data OPTIONAL data to populate in the new row.
	 * @param  string $defaultSource OPTIONAL flag to force default values into new row
	 * @return DataObject
	 */
	public static function createRow(array $data = [])
	{
		$row = new static(static::$_defaultValues, false, false);
		$row->setFromArray($data);
		return $row;
	}

	/*	下面是Object实例		*/

	/**
	 * The clean data of dirty fields
	 * null      : This row is not stored
	 * []        : This row is stored and not modified
	 * not empty : This row is stored and modified
	 *
	 * @var array
	 */
	protected $_cleanData = [];

	/**
	 * A row is marked read only if it contains columns that are not physically represented within
	 * the database schema (e.g. evaluated columns/Expr columns). This can also be passed
	 * as a run-time config options as a means of protecting row data.
	 *
	 * @var boolean
	 */
	protected $_readOnly = false;
	
	
	/**
	 * Constructor.
	 *
	 * Supported params for $config are:-
	 * - table	   = class name or object of type \MDO\Table_Abstract
	 * - data		= values of columns in this row.
	 *
	 * @param  array $config OPTIONAL Array of user-specified config options.
	 * @param
	 * @return void
	 * @throws ModelException
	 */
	/**
	 * 
	 * @param array	  $data
	 * @param boolean $stored
	 * @param boolean $readOnly
	 */
	public function __construct($data = [], $stored = null, $readOnly = null)
	{
		parent::__construct($data, 0);
		
		if (!$stored) {
			$this->_cleanData = null;
		}

		if ($readOnly) {
			$this->setReadOnly(true);
		}

		$this->init();
	}

	/**
	 * Set row field value
	 *
	 * @param  string $columnName The column key.
	 * @param  mixed  $value	  The value for the property.
	 * @return void
	 * @throws ModelException
	 */
	public function offsetSet($columnName, $value)
	{
		if ($this->_cleanData !== null)
			$this->_cleanData[$columnName] = $value;
		parent::offsetSet($columnName,$value);
	}

	/**
	 * Store table, primary key and data in serialized object
	 *
	 * @return array
	 */
	public function __sleep()
	{
		return array('_cleanData', '_readOnly');
	}

	/**
	 * Setup to do on wakeup.
	 * A de-serialized Row should not be assumed to have access to a live
	 * database connection, so set _connected = false.
	 *
	 * @return void
	 */
	public function __wakeup()
	{
	}

	/**
	 * Initialize object
	 *
	 * Called from {@link __construct()} as final step of object instantiation.
	 *
	 * @return void
	 */
	public function init()
	{
	}

	public function isModified(){
		return $this->_cleanData === null || !empty($this->_cleanData); 
	}

	/**
	 * Test the read-only status of the row.
	 *
	 * @return boolean
	 */
	public function isReadOnly()
	{
		return $this->_readOnly;
	}

	/**
	 * Set the read-only status of the row.
	 *
	 * @param boolean $flag
	 * @return boolean
	 */
	public function setReadOnly($flag)
	{
		$this->_readOnly = (bool) $flag;
	}

	/**
	 * Saves the properties to the database.
	 *
	 * This performs an intelligent insert/update, and reloads the
	 * properties with fresh data from the table on success.
	 *
	 * @return mixed The primary key value(s), as an associative array if the
	 *	 key is compound, or a scalar if the key is single-column.
	 */
	public function save()
	{
		/**
		 * A read-only row cannot be saved.
		 */
		if ($this->_readOnly === true) {
			throw new ModelException('This row has been marked read-only');
		}

		if ($this->_cleanData === []){
			throw new ModelException('This row hasn\'t been modified');
		}

		/**
		 * If the _cleanData is null,
		 * this is an INSERT of a new row.
		 * Otherwise it is an UPDATE.
		 */
		if ($this->_cleanData === null) {
			$result = $this->_doInsert();
		}
		else {
			$result = $this->_doUpdate();
		}

		/**
		 * 并不真的从数据库中查询记录，而只是记录当成是全新的
		 */
	    $this->_cleanData = [];

		return $result;
	}

	/**
	 * Deletes existing rows.
	 *
	 * @return int The number of rows deleted.
	 */
	public function remove()
	{
		/**
		 * A read-only row cannot be deleted.
		 */
		if ($this->_readOnly === true) {
			throw new ModelException('This row has been marked read-only');
		}

		$result = $this->_doDelete();

		return $result;
	}

	/**
	 * Sets all data in the row from an array.
	 *
	 * @param  array $data
	 * @return DataObject Provides a fluent interface
	 */
	public function setFromArray(array $data)
	{
		//原来是array_intersect_key($data, $this->getArrayCopy())，现在取消参数列表检查，因此直接使用data
		foreach ($data as $columnName => $value) {
			$this[$columnName] = $value;
		}

		return $this;
	}

	/**
	 * Refreshes properties from the database.
	 *
	 */
	abstract public function refresh();

	abstract protected function _doInsert();

	abstract protected function _doUpdate();

	abstract protected function _doDelete();
}
