<?php
	/* the dbconfig file should be somewhere non-web-accessable 
	 *  Here's what it contains:
	 * 	$parms['mysql_host'] = 'localhost';
	 *	$parms['mysql_db'] = 'databasename';
	 *	$parms['mysql_user'] = 'databaseuser';
	 *	$parms['mysql_pw'] = 'dbuserpassword';
	 */
	require_once('/dbconfig.php');
	require_once('sqltable.php');

	//inititalize the sql connection
	sqltable::initsql();

	//_tbl and _task are reserved words, can't name any of your columns that.
	$tbl = sqltable::getField('_tbl');
	$task = sqltable::getField('_task');

	//tasks return an object; we make an object here so we can put errors in it.
	$obj = new stdClass();

	//table and task are required
	if (!$task)
	{
		//fail
		$obj->error = 'no task';				
	}
	elseif (!$tbl)
	{
		//fail
		$obj->error = 'no table';				
	}
	else
	{
		//these get used for error, reset for success
		$obj->task = $task;
		$obj->tbl = $tbl;
	
		//get the table instance
		$tblclass = sqltable::gettableclass($tbl);
				
		if (!$tblclass->tblExists())	
			$obj->error = 'table '.$tbl.' does not exist.';				
		elseif (!method_exists($tblclass,$task))
			$obj->error = 'task '.$tblclassname.'::'.$task.' does not exist.';				
		else
		{
			//dynamically call tblclass function
			$obj = $tblclass->$task($parms);
		}
	}
	print json_encode($obj);

?>
