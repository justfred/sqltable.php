<?php

	//table-specific defines
	define(SQLDATETIME,'Y-m-d H:i:s'); //2011-05-10 19:48:12 	
	define('TBL_CLASS_PATH','/raymond'); //path from DOCUMENT_ROOT to directory where tables are stored
	define('LAST_MODIFIED_DATE_FIELDNAME','LastModifiedDate');
	define('CREATE_DATE_FIELDNAME','CreateDate');

class sqltable {

	/**
	 * table name
	 * supplied when class is constructed
	 */	
	var $_tablename;

	/**
	 * key field name
	 * it's handy to reference this quickly
	 */
	var $_keyfield; 

	/**
	 * array of fields for the table
	 * with info from define()
	*/
	var $_fields = array();
	
	function __construct($tablename) {
		$this->_tablename = $tablename;
		$this->_fields = $this->describe();
	} //function __construct

	/**
	 * delete record for key value
	 */
	function delete($parms=null) {
		//create output object
		$obj = new stdClass();//return
		$obj->tbl = $this->_tablename;
		$obj->task = 'delete';

		$keyvalue = $this->getField($this->_keyfield,$parms);
		if(!$keyvalue)
		{
			$obj->error = $this->_tablename.'::delete : key value '.$this->_keyfield.' not supplied';				
		}
		else
		{
			$sql = 'DELETE FROM '.$this->_tablename
				.' WHERE '.$this->_keyfield.'='.$keyvalue;
			$obj->sql = $sql;
			
			$result = mysql_query($sql);

			$rows = mysql_affected_rows();

			//if no result
			if(!$result)
			{
				$obj->error = mysql_error();				
			}
			elseif (!$rows)
			{
				$obj->error = $this->_tablename.'::delete('.$this->_keyfield.'='.$keyvalue.') : no records found';				
			}
			else
			{
				$obj->result = true;
			}
		}
		return $obj;
	}

	/**
	 * create an array of fields for a table
	 * NOTE these really ought to be cached, since tabledefs are unlikely to be changed
	 * is it quicker to read a file than read the table?
	 */
	function describe() {
		if (!$this->tblExists())
			return null;
		
		$table = $this->_tablename;
        // LIMIT 1 means to only read rows before row 1 (0-indexed)
        $result = mysql_query("SELECT * FROM $table LIMIT 1");
        $describe = mysql_query("SHOW COLUMNS FROM $table");
        $num = mysql_num_fields($result);
        $fields = array();
        for ($i = 0; $i < $num; ++$i) {
            $field = mysql_fetch_field($result, $i);
            // Analyze 'extra' field
            $field->auto_increment = (strpos(mysql_result($describe, $i, 'Extra'), 'auto_increment') === FALSE ? 0 : 1);
            // Create the column_definition
            $field->definition = mysql_result($describe, $i, 'Type');
            if ($field->not_null && !$field->primary_key) $field->definition .= ' NOT NULL';
            if ($field->def) $field->definition .= " DEFAULT '" . mysql_real_escape_string($field->def) . "'";
            if ($field->auto_increment) $field->definition .= ' AUTO_INCREMENT';
            if ($key = mysql_result($describe, $i, 'Key')) {
                    if ($field->primary_key) $field->definition .= ' PRIMARY KEY';
                    else $field->definition .= ' UNIQUE KEY';
            }
            // Create the field length
            $field->len = mysql_field_len($result, $i);
            // Store the field into the output
            $fields[$field->name] = $field;

			//set the key field
			if ($field->primary_key)
				$this->_keyfield = $field->name;
        }
		mysql_free_result($result);
        return $fields;
	}

	/** 
	 * getField user inputs
	 * if they come from REQUEST, sanitize
	 * some fields might come from REQUEST, others overridden from parms
	 * 	 */
	static function getField($field,$parms=null) {
		global $mysql;
		/* we're either getting the value from $parms, if supplied, or from $_REQUEST */
		if (isset($parms->$field))
			return $parms->$field;
		elseif (isset($_REQUEST[$field]))
			return mysql_real_escape_string($_REQUEST[$field],$mysql);
		else 
			return null;
	}

	/**
	 * get an instance of the specified tableclass
	 * either from an object that extends sqltable, or from sqltable itself.
	 */
	static function gettableclass($tbl) {
		//dynamically get the table class
		$tblclassname = 'tbl'.$tbl;
		$tblclasspath = $_SERVER{'DOCUMENT_ROOT'} . TBL_CLASS_PATH . '/' . $tblclassname . '.php';
#util::debug('$tblclasspath',$tblclasspath);
		if (file_exists($tblclasspath))
		{
#util::debug('@file_exists',$tblclasspath);
			require_once($tblclasspath);
		}

		//create the class
		if (!class_exists($tblclassname))
		{
			//create an object from the generic table class
			$tblclass = new sqltable($tbl);
		}
		else
			$tblclass = new $tblclassname();
#util::debug('$tblclass',$tblclass);
		return $tblclass;
	}

	/**
	 * create a GUID
	 */
	static function guid(){
	    if (function_exists('com_create_guid')){
	        return com_create_guid();
	    }else{
	        mt_srand((double)microtime()*10000);//optional for php 4.2.0 and up.
	        $charid = strtoupper(md5(uniqid(rand(), true)));
	        $hyphen = chr(45);// "-"
	        $uuid = chr(123)// "{"
	                .substr($charid, 0, 8).$hyphen
	                .substr($charid, 8, 4).$hyphen
	                .substr($charid,12, 4).$hyphen
	                .substr($charid,16, 4).$hyphen
	                .substr($charid,20,12)
	                .chr(125);// "}"
	        return $uuid;
	    }
	}

	/** 
	 * initialize the sql connection
	 */
	static function initsql() {
		global $parms,$mysql,$db;
		
		$mysql = mysql_connect($parms['mysql_host'],$parms['mysql_user'],$parms['mysql_pw']);
		$db = mysql_select_db($parms['mysql_db'],$mysql);
	}

	/**
	 * INSERT a new record into the database
	 * with the fields provided
	 */
	function insert($parms=null) {
#util::debug('@insert');
		//create output object
		$obj = new stdClass();
		$obj->tbl = $this->_tablename;
		$obj->task = 'insert';
		
		$error = new stdClass();
		
		//build the insert statement
		$fields = '';
		$values = '';
#util::debug('$this->_fields',$this->_fields);
		if (!$this->_fields)
			$error = $this->_tablename.'::insert : no fields supplied'; 
		else foreach ($this->_fields as $field) {
#util::debug('$field->name',$field->name);
			//skip key fields - actually I may want to check that they're primary and auto-increment
			if (!$field->primary_key)
			{
				$value = $this->getField($field->name,$parms);

				//special fields for dates
				//these date field names are reserved/special
				#!should be in DEFINED
				//put this after getField in case these vables are overwritten
				if (
					(!$value && $field->name == LAST_MODIFIED_DATE_FIELDNAME)
					|| 
					(!$value && $field->name == CREATE_DATE_FIELDNAME)
					)
				{
					$value = date(SQLDATETIME,time());
				}
#util::debug('$field',$field);
#util::debug('$value',$value);
				if (!$value && $field->not_null)
				{
					//this formats as array of arrays
					$error->fields[] = array('field'=>$field->name, 'error' => 'required');
#util::debug('$error',$error);
				}
				else
				{
					if ($fields)
						$fields .= ',';			
					$fields .= $field->name;
						
					if ($values)			
						$values .= ',';			
					if ($field->numeric)
					{
						//numeric values dont need quotes
						$values .= $value;
					}
					else
					{
						//numeric values dont need quotes
						$values .= "'".$value."'";
					}
				}
			}
		}
#util::debug('$fields',$fields);
#util::debug('$values',$values);
		if (isset($error->fields)) 
		{
#util::debug('$error',$error);
			$obj->error = $error;
		}
		elseif (!$obj->error)
		{
			$sql = 'INSERT INTO '.$this->_tablename
				.' ('.$fields.')'
				.' VALUES '
				.' ('.$values.')';
			$obj->sql = $sql;

			$result = mysql_query($sql);
			$rows = mysql_affected_rows();
#util::debug('$rows',$rows);
			//if no result
			if(!$result)
			{
				$obj->error = mysql_error();				
			}
			elseif (!$rows)
			{
				$obj->error = $this->_tablename.'::insert : no records found';				
			}
			else
			{
				//return the inserted key id
				$keyvalue = mysql_insert_id();
				$obj->result->{$this->_keyfield} = $keyvalue;				
			}
		}
		//if no result
		return $obj;
	}

	/**
	 * get all records, or records matching supplied fields
	 */
	function select($parms=null) {
		//create output object
		$obj = new stdClass;
		$obj->tbl = $this->_tablename;
		$obj->task = 'select';

		//build the where
		//for each table field, check if that value exists
		$where = '';
		foreach ($this->_fields as $field) {
			$value = $this->getField($field->name,$parms);
			#!need to find a way to handle > <
			#=? so I could say username=fred instead of username like fred but I guess like works like that
			if ($value)
			{
				if ($field->numeric)
					$like = ' = '.$value;
				else
					$like = ' LIKE "'.$value.'"';					

				if (!$where)
					$where = ' WHERE '.$field->name.$like;
				else
					$where .= ' AND '.$field->name.$like;
			}
		}
		
		$sql = 'SELECT * FROM '.$this->_tablename.$where;
		$obj->sql = $sql;
		$result = mysql_query($sql);
		if (!$result)
		{
			$obj->error = mysql_error();				
		}
		else
		{
			//collect the row data
			$rows = array();
			while ($row = mysql_fetch_object($result)) {
				$rows[] = $row;
			}
			mysql_free_result($result);

			//if no result
			if(!$rows)
			{
				$obj->result = $this->_tablename.'::select : no records found';				
			}
			else
			{
				$obj->result->{$this->_tablename} = $rows;
			}
		}
		return $obj;
	}
	
	#?is this really necessary?  select does this just fine, though it does return an array.
/*
 	function getkey($parms=null) {
		$obj = new stdClass();//return

		$keyvalue = getField($this->_keyfield,$parms);
		if(!$keyvalue)
		{
			$obj->error = $this->_tablename.': '.$this->_keyfield.' not supplied';				
		}
		else
		{
			$sql = 'SELECT * FROM '.$this->_tablename
				.' WHERE '.$this->_keyfield.'='.$keyvalue;
			$result = mysql_query($sql);
			$obj = mysql_fetch_object($result);
			mysql_free_result($result);
			//if no result
			if(!$obj)
			{
				$obj->error = $this->_tablename.': no records found';				
			}
		}
		return $obj;
	}
*/

	/**
	 * check if the table itself exists
	 */
	function tblExists() {
	    $sql= 'DESC '.$this->_tablename;
	    mysql_query($sql);
	    if (mysql_errno()==1146){
		    //table_name doesn't exist
			return false;
	    }
	    elseif (!mysql_errno())
		{
		    //table exists		
			return true;
		}
	}
	
	/**
	 * dynamic update
	 * updates any and all editable fields
	 * 
	 * NOTE - this assumes we always update by key
	 */
	function update($parms=null) {
#util::debug('@update');
		//create output object
		$obj = new stdClass();
		$obj->tbl = $this->_tablename;
		$obj->task = 'update';
		$error = new stdClass();
		
		$keyvalue = $this->getField($this->_keyfield,$parms);
		if(!$keyvalue)
		{
			$obj->error = $this->_tablename.'::update : '.$this->_keyfield.' not supplied';				
		}
		else
		{
			$where = $this->_keyfield.'='.$keyvalue;
			
			//build the update statement
			$fieldvalues = '';
			if (!$this->_fields)
				$error = $this->_tablename.'::update : no fields supplied'; 
			else foreach ($this->_fields as $field) {
				//skip key fields - actually I may want to check that they're primary and auto-increment
				#!this assumes key fields are autoincrement.
				if (!$field->primary_key)
				{
					$value = $this->getField($field->name,$parms);
#util::debug('$field->name',$field->name);
#util::debug('$value',$value);
	
					//special fields for dates
					//put this after getField in case these vables are overwritten
					if (!$value && $field->name == LAST_MODIFIED_DATE_FIELDNAME)
					{
						$value = date(SQLDATETIME,time());
					}
	
					if ($value)
					{
						if ($fieldvalues)	
							$fieldvalues .= ',';			
						if ($field->numeric)
						{
							//numeric values dont need quotes
							$fieldvalues .= $field->name . '=' . $value;
						}
						else
						{
							//string values need quotes
							$fieldvalues .= $field->name . '="' . $value . '"';
						}
					}
				}
			}
#util::debug('$fieldvalues',$fieldvalues);
			if (isset($error->fields)) 
			{
				$obj->error = $error;
			}
			elseif (!$obj->error)
			{
				$sql = 'UPDATE '.$this->_tablename . ' SET ' . $fieldvalues . ' WHERE ' . $where;
#util::debug('$sql',$sql);
				$obj->sql = $sql;

				$result = mysql_query($sql);
#util::debug('$result',$result);

				$rows = mysql_affected_rows();
#util::debug('$rows',$rows);
				//if no result
				if(!$result)
				{
					$obj->error = mysql_error();				
				}
				elseif (!$rows)
				{
					$obj->error = $this->_tablename.'::update('.$this->_keyfield.'='.$keyvalue.') : no records found';				
				}
				else
				{
					$obj->result = true;
				}
			}
		}
		return $obj;
	}


	
} //class