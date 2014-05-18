<?php
/**
 * LDActiveRecordConditionBehavior class file.
 *
 * @author Louis A. DaPrato <l.daprato@gmail.com>
 * @link https://lou-d.com
 * @copyright 2014 Louis A. DaPrato
 * @license The MIT License (MIT)
 * @since 1.0
 */

/**
 * 
 * @author Louis DaPrato <l.daprato@gmail.com>
 *
 */
class LDActiveRecordConditionBehavior extends CActiveRecordBehavior
{
	
	const PARAM_PREFIX = ':ldarcb';
	
	protected static $_paramCount = 0;
	
	public $columns = array();
	
	public function getSearchCriteria($mergeCriteria = array(), $prefix = null, $operator = 'AND', $quoteTableName = true, $zip = true)
	{
		$owner = $this->getOwner();
		$values = array();
		$multivalues = array();
		foreach(array_keys($this->columns) as $column)
		{
			$value = $owner->$column;
			if($value !== null && $value !== '')
			{
				if(is_array($value))
				{
					$multivalues[$column] = $value;
				}
				else
				{
					$values[$column] = (string)$value;
				}
			}
		}

		$values = array_merge($values, $zip ? self::zip($multivalues) : $multivalues);

		return $this->buildSearchCriteria($values, $mergeCriteria, $prefix, $quoteTableName);
	}
	
	/**
	 * Generates an SQL expression for selecting rows of specified key values from the table
	 * of the CActiveRecord object this behavior is been attached to. A custom table name may be specified
	 * using the prefix parameter, but the table schema of this behavior's CActiveRecord owner will still
	 * be used to verify column names and cast column values. Quoting keys is not necessary as this will be done
	 * automaticallu. Escaping values is also not necessary as all values will be bound to the expression using 
	 * unique parameter names. 
	 * The method will return a two part array:
	 * 	The first part of the return value is the SQL condition indexed in the returned array as 'condition'.
	 * 	The second part of the return value is a mapping of unique parameter names to values indexed in the returned array as 'params'.
	 * 
	 * @param mixed $values
	 *        	list of key values to be selected within
	 * @param string $prefix
	 *        	column prefix (WITHOUT dot ending!). If null, it will be the 
	 *        	table name alias of the active record this behavior is attached to.
	 * @param boolean $quoteTableName
	 * 			whether to quote the table alias or prefix name
	 * @throws CDbException if specified column is not found in given table
	 * @return array In the form array('condition' => 'the expression for selection', 'params' => array('parameter name' => 'value to bind to parameter'))
	 */
	public function buildSearchCriteria($values, $mergeCriteria = array(), $prefix = null, $operator = 'AND', $quoteTableName = true) 
	{
		$owner = $this->getOwner();
		$criteria = clone $owner->getDbCriteria();
		$criteria->mergeWith($mergeCriteria);
		if($values === false)
		{
			$criteria->mergeWith(array('condition' => '0=1'), $operator);
		}
		else if($values === true)
		{
			$criteria->mergeWith(array('condition' => '1=1'), $operator);
		} 
		else if(!is_array($values))
		{
			return $criteria;
		}
		else
		{
			$table = $owner->getTableSchema();
			
			if($prefix === null)
			{
				$prefix = isset($criteria->alias) ? $criteria->alias : $owner->getTableAlias();
			}
			
			if($quoteTableName)
			{
				$prefix = $owner->getDbConnection()->quoteTableName($prefix);
			}
			
			$prefix .= '.';
			
			$compositeKeys = array();
			foreach($values as $columnName => &$vals)
			{
				if(is_string($columnName)) 		// simple key
				{
					if(!isset($table->columns[$columnName]))
					{
						throw new CDbException(Yii::t('yii', 'Table "{table}" does not have a column named "{column}".', array(
							'{table}' => $table->name,
							'{column}' => $columnName 
						)));
					}
					
					$column = $table->columns[$columnName];

					if($vals === null)
					{
						$criteria->mergeWith(array('condition' => $prefix.$column->rawName.' IS NULL'), $operator);
					}
					else
					{
						$vals = (array)$vals;
						
						if(count($vals) === 1) 
						{
							$value = reset($vals);
							if($value === null)
							{
								$criteria->mergeWith(array('condition' => $prefix.$column->rawName.' IS NULL'), $operator);
							}
							else if($this->createConditionHelper($value, $op, $this->columns[$columnName]['partialMatch'], $this->columns[$columnName]['escape']))
							{
								$paramName = self::getNextParameterName();
								$criteria->mergeWith(array('condition' => $prefix.$column->rawName." $op ".$paramName, 'params' => array($paramName => ($this->columns[$columnName]['partialMatch'] ? $value : $table->columns[$columnName]->typecast($value)))), $operator);
							}
						} 
						else 
						{
							$params = array();
							foreach($vals as &$value)
							{
								$params[self::getNextParameterName()] = $column->typecast($value);
							}
							
							$criteria->mergeWith(array('condition' => $prefix.$column->rawName.' IN ('.implode(', ', array_keys($params)).')', 'params' => $params), $operator);
						}
					}
				}
				else	// composite key
				{
					foreach($vals as $columnName => &$value) 
					{
						if(!isset($table->columns[$columnName]))
						{
							throw new CDbException (Yii::t('yii', 'Table "{table}" does not have a column named "{column}".', array(
								'{table}' => $table->name,
								'{column}' => $columnName 
							)));
						}
					}
					$compositeKeys[] = $vals;
				}
			}
			if(count($compositeKeys) === 1)
			{
				$entries = array();
				$params = array();
				foreach($compositeKeys[0] as $columnName => &$value)
				{
					if($value === null)
					{
						$entries[] = $prefix.$table->columns[$columnName]->rawName.' IS NULL';
					}
					else if($this->createConditionHelper($value, $op, $this->columns[$columnName]['partialMatch'], $this->columns[$columnName]['escape']))
					{
						$paramName = self::getNextParameterName();
						$params[$paramName] = ($this->columns[$columnName]['partialMatch'] ? $value : $table->columns[$columnName]->typecast($value));
						$entries[] = $prefix.$table->columns[$columnName]->rawName." $op ".$paramName;
					}
				}
				$criteria->mergeWith(array('condition' => implode(' AND ', $entries), 'params' => $params), $operator);
			}
			else if(count($compositeKeys) > 1)
			{
				$keyNames = array();
				foreach($compositeKeys[0] as $columnName => &$value)
				{
					$keyNames[] = $prefix.$table->columns[$columnName]->rawName;
				}
				$params = array();
				$vs = array();
				foreach($compositeKeys as &$value)
				{
					$ps = array();
					foreach($value as $columnName => &$v)
					{
						$paramName = self::getNextParameterName();
						$ps[] = $paramName;
						$params[$paramName] = $table->columns[$columnName]->typecast($v);
					}
					$vs[] = '('.implode(', ', $ps).')';
				}
			
				$criteria->mergeWith(array('condition' => '('.implode(', ', $keyNames).') IN ('.implode(', ', $vs).')', 'params' => $params), $operator);
			}
		}
		return $criteria;
	}
	
	private function createConditionHelper(&$value, &$op, $partialMatch, $escape)
	{
		if(is_string($value) && preg_match('/^(?:\s*(<>|<=|>=|<|>|=))?(.*)$/', $value, $matches))
		{
			$value = $matches[2];
			$op = $matches[1];
		}
		else
		{
			$op = '';
		}
		
		if($partialMatch)
		{
			$value = (string)$value;
			if($value === '')
			{
				return false;
			}
			if($escape)
			{
				$value = '%'.strtr($value, array('%' => '\%', '_' => '\_', '\\' => '\\\\')).'%';
			}
			if($op === '')
			{
				$op = 'LIKE';
			}
			else if($op === '<>')
			{
				$op = 'NOT LIKE';
			}
		}
		else if($op === '')
		{
			$op = '=';
		}
		return true;
	}
	
	public static function getNextParameterName()
	{
		return self::PARAM_PREFIX.self::$_paramCount++;
	}
	
	/**
	 * A utility function for converting array formats for use with the condition generator function of this behavior.
	 * 
	 * In format:
	 * array('key1' => array('v1', 'v2', 'v3', ...), 'key2' => array('v1', 'v2', 'v3', ...), ...)
	 * 
	 * Out format:
	 * array(array('key1' => 'v1', 'key2' => 'v1', ...), array('key1' => 'v2', 'key2' => 'v2', ...), array('key1' => 'v3', 'key2' => 'v3', ...), ...)
	 * 
	 * @param array $values The array values to be converted in the form: array('key1' => array('v1', 'v2', 'v3', ...), 'key2' => array('v1', 'v2', 'v3', ...), ...)
	 * @throws CException thrown if input arrays are not of equal length
	 * @return array A 'combined' array in the form: array(array('key1' => 'v1', 'key2' => 'v1', ...), array('key1' => 'v2', 'key2' => 'v2', ...), array('key1' => 'v3', 'key2' => 'v3', ...), ...)
	 */
	public static function zip($values)
	{
		if(empty($values))
		{
			return $values;
		}
		$values = array_map(create_function('$value', 'return array_values((array)$value);'), $values);
		$n = array_reduce($values, create_function('$count, $arr', 'return min($count, count($arr));'), -1);
		$combinedValues = array();
		foreach($values as $key => $val)
		{
			foreach($val as $k => $v)
			{
				$combinedValues[$k][$key] = $v; 
			}
		}
		return $combinedValues;
	}
	
}