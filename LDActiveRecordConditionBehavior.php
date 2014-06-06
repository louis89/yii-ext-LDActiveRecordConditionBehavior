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
 * LDActiveRecordConditionBehavior generates fully quoted, escaped, and parameter bound CDbCriterias based on a CActiveRecord's attribute values.
 * Attribute values can be scalar values or arrays. If the latter correctly parenthesized IN conditions will be generated.
 * 
 * @author Louis DaPrato <l.daprato@gmail.com>
 *
 */
class LDActiveRecordConditionBehavior extends CActiveRecordBehavior
{
	
	const PARAM_PREFIX = ':ldarcb';
	
	/**
	 * @var integer the global counter for anonymous binding parameters.
	 * This counter is used for generating the name for the anonymous parameters.
	 */
	public static $paramCount = 0;
	
	/**
	 * Set this property to configure which columns and how those columns should be part of generated condition criterias
	 * Keys should be column names. Values should be arrays with two boolean values set "partialMatch" and "escape".
	 * This property defaults to Null which will cause the configuration to be generated automatically when this behavior is attached to a CActiveRecord.
	 * The default configuration includes all columns defined in the CActiveRecord's table schema with "partialMatch" set false and "escape" set true.
	 * @var array configuration for generating column conditions
	 */
	public $columns;
	
	/**
	 * (non-PHPdoc)
	 * @see CBehavior::attach()
	 */
	public function attach($owner)
	{
		parent::attach($owner);
		if($this->columns === null)
		{
			foreach($owner->getTableSchema()->columns as $col => $config)
			{
				$this->columns[$col] = array('partialMatch' => false, 'escape' => true);
			}
		}
	}
	
	/**
	 * Generates a search criteria for the CActiveRecord owner of this behavior.
	 * 
	 * @param mixed $mergeCriteria the criteria to be merged with. Either an array or CDbCriteria. 
	 * @param string $prefix
	 *        	column prefix (WITHOUT dot ending!). If null, it will be the 
	 *        	table name alias of the active record this behavior is attached to.
	 * @param string $operator How to combine the condition generated by this function with the existing condition of the CDbCriteria owner or merged CDbCriteria
	 * @param boolean $quoteTableName whether to quote the table alias/prefix name
	 * @param array $columns The columns to generate a search criteria with. Defaults to Null meaning use the {@see LDActiveRecordConditionBehavior::$columns} property.
	 * @param boolean $checkEmpty Whether to check if a value is empty or not before including it in the search criteria. If True and {@see LDActiveRecordConditionBehavior::isEmpty()} returns true, the value will not be included in the search criteria
	 * @param boolean $trim Whether to trim the value when checking if it is empty. This parameter is only effective if $checkEmpty is set to true. {@see LDActiveRecordConditionBehavior::isEmpty()} for more details. 
	 * @return CDbCriteria A copy of the CActiveRecord's current CDbCriteria with generated condition and parameters set
	 */
	public function getSearchCriteria($mergeCriteria = array(), $prefix = null, $operator = 'AND', $quoteTableName = true, $columns = null, $checkEmpty = true, $trim = true)
	{
		$owner = $this->getOwner();
		$values = array();
		$multivalues = array();
		if($columns === null)
		{
			$columns = $this->columns;
		}
		foreach(array_keys($columns) as $column)
		{
			$value = $owner->$column;
			if(!($checkEmpty && $this->isEmpty($value, $trim)))
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

		return $this->buildSearchCriteria(array_merge($values, self::zip($multivalues)), $mergeCriteria, $prefix, $quoteTableName);
	}
	
	/**
	 * Generates an SQL criteria condition string with bound parameter values. 
	 * This method returns a CDbCriteria instance that is a clone of the current CDbCriteria of the CActiveRecord instance that this behavior is attached to.
	 * The condition property will contain the generated condition string.
	 * The params property will contain the parameterized condition values.
	 * 
	 * @param mixed $values list of key values to be selected within
	 * @param mixed $mergeCriteria the criteria to be merged with. Either an array or CDbCriteria.
	 * @param string $prefix
	 *        	column prefix (WITHOUT dot ending!). If null, it will be the 
	 *        	table name alias of the active record this behavior is attached to.
	 * @param string $operator How to combine the condition generated by this function with the existing condition of the CDbCriteria owner or merged CDbCriteria
	 * @param boolean $quoteTableName whether to quote the table alias/prefix name
	 * @param array $columns The columns to generate a search criteria with. Defaults to Null meaning use the {@see LDActiveRecordConditionBehavior::$columns} property.
	 * @throws CDbException if specified column is not found in given table
	 * @return CDbCriteria CDbCriteria instance clone of the current CDbCriteria of the CActiveRecord instance that this behavior is attached to 
	 * 			with "condition" property set to the condition string generated by this function and the "params" property set to the values bound to the condition generated by this function.
	 */
	public function buildSearchCriteria($values, $mergeCriteria = array(), $prefix = null, $operator = 'AND', $quoteTableName = true, $columns = null) 
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
			if($columns === null)
			{
				$columns = $this->columns;
			}
			
			$table = $owner->getTableSchema();
			
			if($prefix === null)
			{
				$prefix = $criteria->alias === null ? $owner->getTableAlias() : $criteria->alias;
			}
			
			if($quoteTableName)
			{
				$prefix = $owner->getDbConnection()->quoteTableName($prefix);
			}
			
			$prefix .= '.';
			
			$compositeKeys = array();
			foreach($values as $columnName => &$vals)
			{
				if(is_string($columnName)) 	// Simple key
				{
					if(!isset($table->columns[$columnName]))
					{
						throw new CDbException(Yii::t('yii', 'Table "{table}" does not have a column named "{column}".', array(
							'{table}' => $table->name,
							'{column}' => $columnName 
						)));
					}
					
					$column = $table->columns[$columnName];

					if($vals === null) // Null value condition
					{
						$criteria->mergeWith(array('condition' => $prefix.$column->rawName.' IS NULL'), $operator);
					}
					else
					{
						$vals = (array)$vals;
						
						if(count($vals) === 1) // Single value condition
						{
							$value = reset($vals);
							if($value === null)
							{
								$criteria->mergeWith(array('condition' => $prefix.$column->rawName.' IS NULL'), $operator);
							}
							else if($this->createConditionHelper($value, $op, $columns[$columnName]['partialMatch'], $columns[$columnName]['escape']))
							{
								$paramName = self::getNextParameterName();
								$criteria->mergeWith(array('condition' => $prefix.$column->rawName." $op ".$paramName, 'params' => array($paramName => ($columns[$columnName]['partialMatch'] ? $value : $table->columns[$columnName]->typecast($value)))), $operator);
							}
						} 
						else // Multivalued condition
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
				else // Composite key
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
			if(count($compositeKeys) === 1) // Single value composite key condition
			{
				$entries = array();
				$params = array();
				foreach($compositeKeys[0] as $columnName => &$value)
				{
					if($value === null)
					{
						$entries[] = $prefix.$table->columns[$columnName]->rawName.' IS NULL';
					}
					else if($this->createConditionHelper($value, $op, $columns[$columnName]['partialMatch'], $columns[$columnName]['escape']))
					{
						$paramName = self::getNextParameterName();
						$params[$paramName] = ($columns[$columnName]['partialMatch'] ? $value : $table->columns[$columnName]->typecast($value));
						$entries[] = $prefix.$table->columns[$columnName]->rawName." $op ".$paramName;
					}
				}
				$criteria->mergeWith(array('condition' => implode(' AND ', $entries), 'params' => $params), $operator);
			}
			else if(count($compositeKeys) > 1) // Multivalued composite key condition
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
	
	/**
	 * Helper function for generating a condition for a column value
	 * 
	 * @param mixed $value The value to generate a condition. If this is a string then it can be preceeded by <>, <=, >=, <, >, = which will be extracted and used as the operation in the condition genrated by this function.
	 * @param string $op The operation to use for the condition
	 * @param boolean $partialMatch Whether to generate a partial match conditions 'LIKE' or 'NOT LIKE'
	 * @param boolean $escape Whether to escape _, %, \ characters. The value will also be wrapped in % characters
	 * @return boolean True if the value should be tested. False otherwise.
	 */
	private function createConditionHelper(&$value, &$op, $partialMatch, $escape)
	{
		if(is_string($value) && preg_match('/^(?:\s*(<>|<=|>=|<|>|=))?(.*)$/', $value, $matches)) // Extract operation from value if the value is a string
		{
			$value = $matches[2];
			$op = trim($matches[1]) === '' ? '=' : $matches[1]; // If op was only white space set to default equals
		}
		else // Default op is equal
		{
			$op = '=';
		}
		
		if($partialMatch) // If partial match 
		{
			$value = (string)$value;
			if($value === '') // If empty string and partial match then this condition will not effect the query. Return false.
			{
				return false;
			}
			if($escape) // If the value should be escaped then escape _, %, \ characters. And wrap value is % characters
			{
				$value = '%'.strtr($value, array('%' => '\%', '_' => '\_', '\\' => '\\\\')).'%';
			}
			if($op === '<>' || $op === '!=') // If op is a negative comparison change to 'NOT LIKE'
			{
				$op = 'NOT LIKE';
			}
			else // If op is something other than a negative comparison change to 'LIKE'
			{
				$op = 'LIKE';
			}
		}
		return true;
	}
	
	/**
	 * Checks if the given value is empty.
	 * A value is considered empty if it is null, an empty array, or the trimmed result is an empty string.
	 * Note that this method is different from PHP empty(). It will return false when the value is 0.
	 *
	 * Note that this method is exactly the same as CValidator's isEmpty except it has been made public
	 *
	 * @param mixed $value the value to be checked
	 * @param boolean $trim whether to perform trimming before checking if the string is empty. Defaults to false.
	 * @return boolean whether the value is empty
	 */
	public function isEmpty($value, $trim = false)
	{
		return $value === null || $value === array() || $value === '' || $trim && is_scalar($value) && trim($value) === '';
	}
	
	/**
	 * Utility function that generates unique parameter names to be used to bind values in SQL criteria.
	 * 
	 * @return string unique SQL parameter name
	 */
	public static function getNextParameterName()
	{
		return self::PARAM_PREFIX.self::$paramCount++;
	}
	
	/**
	 * A utility function to zip together an array of arrays of equal length.
	 * 
	 * Input format:
	 * array('key1' => array('v1', 'v2', 'v3', ...), 'key2' => array('v1', 'v2', 'v3', ...), ...)
	 * 
	 * Output format:
	 * array(array('key1' => 'v1', 'key2' => 'v1', ...), array('key1' => 'v2', 'key2' => 'v2', ...), array('key1' => 'v3', 'key2' => 'v3', ...), ...)
	 * 
	 * @param array $values The arrays to be zipped together.
	 * @throws CException thrown if input arrays are not of equal length
	 * @return array The zipped form of the input array.
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