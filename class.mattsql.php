<?php
/**
 * Matt SQL
 * 
 * This is a simple means of connecting to a mysql database and fetching data
 * while taking advantage of PHP5 features like method chaining.  If you have a
 * simple script, and all you need is some quick access to a mysql database,
 * then this is the class for you.   If you have a large, professional
 * project, there are far better means of connecting to a mysql database.
 * 
 * What this class does not do:
 * - Abstract database calls - This class only connects to a mysql database
 * - Track relations - This is not like ORM which tracks relations for you.
 * 
 * What this class does do:
 * - Provides you with a quick and simple way to connect to a mysql database.
 * - Allows you to chain several methods for DRY code.
 * - Throws Exceptions that you can catch when things go bad.
 * - Optionally caches SELECT, SHOW, DESCRIBE, EXPLAIN queries.
 * - Cleans up all its connections upon destruction.
 * 
 * Example of usage:
 * <code>
 * 	$o_db = mattsql::init();
 *	$a_chapter_names = $o_db
 *		->newConnection('localhost', 'login', 'password', 'database')
 *		->q("SELECT `title` FROM `database`.`chapter_names` WHERE `date_folder` = '2011-04' ORDER BY `chapter` ASC")
 *		->fetchCol('title'); 
 * </code>
 * 
 * Changelog:
 * - Version 2.0.0 by Matthew Toledo on 2011-04-25
 *		o Culled a lot of useless methods regarding handling errors.  That 
 *		  should be done elsewhere.
 *		o Now uses the singleton pattern.
 *		o Switched documentation to JavaDoc stype PhpDoc documentation.
 *		o Several class methods are now chainable.
 *		o Now can optionally cache SELECT-type query results.
 * - Version 0.0.2 by Matthew Toledo on 2009-10-01
 *		o added documentation to class
 * - Version 0.0.1 by Matthew Toledo on 2009-07-06
 *		o default action is to throw an exception when query fails
 *		o default action is NOT to print error message to browser or error logs
 * - Version 0.0.0 by Matthew Toledo on 2009-03-11
 *		o Script created
 * 
 * @author Matthew Toledo
 * @link http://www.matthewtoledo.net/projects/mattsql
 */
class mattsql {

	/**
	 * Holds an instance of this class
	 * @var mattsql
	 */
	private static $o_instance;
	
	/**
	 * An array of connection resources so we can have more than one mysql
	 * connection if we need to.  Most people won't need to.
	 * @var array 
	 */
	private $aLinks;
	
	/**
	 * The current connection.
	 * @var integer 
	 */
	private $iCurrentLink;
	
	/**
	 * Holds the resource id of the current MySQL result
	 * @var integer 
	 */
	public $iResult;
	
	/**
	 * An integer representing the id of the most recent MySQL insert
	 * @var integer
	 */
	public $iInsertId;

	/**
	 * The number of SELECT, SHOW, DESCRIBE, EXPLAIN rows
	 * @var integer 
	 */
	public $iReturnedRows;
	
	/**
	 * The number of INSERT, UPDATE, DELETE, DROP rows
	 * @var integer
	 */
	public $iAffectedRows;

	/**
	 * The number of SELECT, SHOW, DESCRIBE, EXPLAIN, INSERT, UPDATE, DELETE, 
	 * DROP rows.  Best to just use this variable when you need to find the
	 * number of rows returned, affected, or inserted.
	 * @var integer
	 */
	public $iRows;

	/**
	 * Determines the format of the mysql query results.  Must be either 
	 * OBJECT, HASH, or ARRAY.
	 * 
	 * @see mattsql::setMode()
	 * @var string 
	 */
	protected $sMode;

	/**
	 * String holding the current query.
	 * @see mattsql::q()
	 * @var string
	 */
	protected $sQuery;

	/**
	 * Boolean that holds feedback about the last query. True if it succeeded. 
	 * False if not.  Null if no query has been run yet.
	 * @var boolean
	 */
	protected $bSuccess;
	
	/**
	 * Holds an array my mysql result resources so they can be used again.
	 * 
	 * @var array
	 */
	protected $aQueryCache;
	
	/**
	 * Holds the ID of the last query that was cached.
	 */
	protected $iLastCacheId;


	/**
	 * Class constructor
	 */
	private function __construct()
	{
		
	}

	/**
	 * Used to create a new singleton connection to the database.
	 * 
	 * @return mattsql
	 */
	public static function init()
	{

		if (!isset(self::$o_instance)) 
		{
			$s = __CLASS__;
			self::$o_instance = new $s(); 
		}

		return self::$o_instance;
		
	}

	/**
	 * Make a connection to the database and add it to the list of connections
	 * 
	 * This method is chainable.
	 * 
	 * @param string $sHost
	 * @param string $sLogin
	 * @param string $sPass
	 * @param string $sDatabase
	 * @param integer $iPort 
	 * @return mattsql
	 */	
	public function newConnection($sHost, $sLogin, $sPass, $sDatabase, $iPort=NULL) 
	{
		
		// Sanity check the constructor arguments
		if (!is_string($sHost) || !strlen($sHost))			throw new Exception ('The first argument, host, must be a non-empty string.');
		if (!is_string($sLogin) || !strlen($sLogin))		throw new Exception ('The second argument, login, must be a non-empty string.');
		if (!is_string($sPass) || !strlen($sPass))			throw new Exception ('The third argument, password, must be a non-empty string.');
		if (!is_string($sDatabase) || !strlen($sDatabase))	throw new Exception ('The fourth argument, database, must be a non-empty string.');
		if ($iPort && (!is_int($iPort) || $iPort < 1))		throw new Exception ('The optional fifth argument, port, must be a positive integer.');	
		
		// make a connection with port supplied
		if (NULL !== $iPort) {
			$m = mysql_connect($sHost.':'.$iPort, $sLogin, $sPass);
		} 
		// make connection without port number (this is more common)
		else {
			$m = mysql_connect($sHost, $sUser, $sPass);
		}
		
		// see if link was successful
		if (FALSE === $m) throw new Exception ('Failed connecting to server : '.mysql_error());		
		
		$this->$aLinks[] = $m;
		$this->iCurrentLink = count($this->aLinks) - 1;
		
		// connect to a database
		$this->selectDB($sDatabase);
		
		// set the default mode
		$this->setMode('ASSOC');
		
		return $this;

	}

    /** 
     * Deconstruct the object 
	 * 
     * Close all of the open database connections 
     */  
    public function __deconstruct()  
    {  
        foreach( $this->aLinks as $iConnection )  
        {  
            mysql_close($iConnection);
        }  
    } 
	
	/**
	 * Prevent cloning
	 */
	public function __clone()
	{
		throw new Exception("You can not clone this object");
	}
	
	/**
	 * Returns the current mysql connection resource.
	 * 
	 * @return resource
	 */
	public function getActiveLink()
	{
		if (!(isset($this->aLinks[$this->iCurrentLink]))) {
			throw new Exception("There is no current connection");
		}
		return $this->aLinks[$this->iCurrentLink];
	}
	
	/**
	 * Set the ID of the database connection that we wish to use.
	 * 
	 * If you have more than one connection open, this lets you pick which
	 * connection to use. This method is chainable.
	 * 
	 * @param integer $i A link id.
	 * @return mattsql 
	 */
	public function setActiveLink($i)
	{
		if (!(isset($this->aLinks[$i]))) {
			throw new Exception("There is no current connection");
		}
		$this->iCurrentLink = $i;
		return $this;
	}

	/**
	 * Select a database using the current connection.
	 * 
	 * This method can be chained.
	 * 
	 * @param string $sDb The name of the database.
	 * @return mattsql 
	 * @throws Exception
	 */
	public function selectDB($sDb) 
	{
		
		// sanity check function arguments
		if (!is_string($sDb) || !strlen($sDb)) throw new Exception ('First argument must be a non-blank string.');
		
		// attempt to make connection
		if (FALSE === mysql_select_db($sDb, $this->getActiveLink()))
		{
			throw new Exception ("Failed connection to database '$sDb' : ".mysql_error($this->getActiveLink()) );
		}
		
		return $this;
		
	}

	
	/**
	 * Execute a query.
	 * 
	 * The main workhorse of the mattsql class. Does the query. This method
	 * can be chained.
	 * 
	 * @param string $sQuery A MySQL query.
	 * @param boolean $bCache If true, cache the query results for use again.
	 * @return mattsql 
	 * @throws Exception
	 */
	public function q($sQuery, $bCache = FALSE) 
	{
	
		// clean all the variables
		$this->bSuccess = FALSE;
		$this->iAffectedRows = 0;
		$this->iInsertId = NULL;
		$this->iReturnedRows = 0;
		$this->iResult = NULL;
		$this->iRows = 0;
		$this->sQuery = NULL;
		
		
		// sanity check function arguments
		if (!is_string($sQuery) || empty($sQuery)) throw new Exception ('First argument must be a non-blank string.');
		if (!is_bool($bCache)) throw new Exception ('Optional second argument must be boolean.');
 
		// query should not end with a semicolon
		if (substr($sQuery,-1) == ';') $sQuery = substr($sQuery,0,strlen($sQuery)-1);
		
		// copy to class variable
		$this->sQuery = $sQuery;
		
		// run the query
		$this->iResult = mysql_query($this->sQuery,$this->getActiveLink());
		
		// query failed for some reason
		if ($this->iResult === FALSE) 
		{
			throw new Exception ('FATAL ERROR : '.$sQuery.' - '.mysql_error($this->getActiveLink()));
		}
		
		// handle INSERT, UPDATE, DELETE, DROP
		elseif ($this->iResult === TRUE) 
		{
			$this->bSuccess = TRUE;
			$this->iAffectedRows = mysql_affected_rows($this->getActiveLink());
			$this->iRows = $this->iAffectedRows;
			$this->iInsertId = mysql_insert_id($this->getActiveLink());
		}
		
		// handle SELECT, SHOW, DESCRIBE, EXPLAIN
		elseif (is_resource($this->iResult)) 
		{
			$this->bSuccess = TRUE;
			$this->iReturnedRows = mysql_num_rows($this->iResult);
			$this->iRows = $this->iReturnedRows;
			// only cache these sorts of results.
			if ($bCache) 
			{
				$this->aQueryCache[] = $this->iResult;	
				$this->iLastCacheId = count($this->aQueryCache) - 1;
			}
		}
		
		// This should never happen
		else {
			throw new Exception('Fatal Error :  Unable to determine query type.  Value for iResult is not an integer or boolean. Query was : '.$this->sQuery);
		}
		
		return $this;
		
	}
	
	
	/**
	 * @return integer The id of a cached query
	 */
	public function getLastCacheId()
	{
		return $this->iLastCacheId;
	}
	
	/**
	 * Use previously cached query results.
	 * 
	 * This method is chainable
	 * 
	 * @param integer $iCacheId A query cache ID
	 * @return mattsql
	 * @throws Exception 
	 */
	public function useCachedQuery($iCacheId)
	{
		if (!isset($this->aQueryCache[$iCacheId])) throw new Exception('There is no query in the cache with the given ID.');

		$this->iResult = $this->aQueryCache[$iCacheId];
		
		return $this;
		
	}



	/**
	 * Determines the format of the mysql query results.  
	 * 
	 * This method can be chained.
	 * 
	 * @param type $sMode Valid modes are "OBJECT", "HASH" and "ARRAY"
	 * @return mattsql
	 * @throws Exception
	 */
	public function setMode($sMode) 
	{
		
		if (	
				!is_string($sMode) || 
				!strlen($sMode) ||	
				!in_array($sMode,array('OBJECT','HASH','ARRAY'))
		) throw new Exception ('$sMode must OBJECT, HASH, or ARRAY.');

		$this->sMode = $sMode;
		
		return $this;
	
	}
	

	/**
	 * Get the current mode.
	 * 
	 * @return mixed Can be string or NULL.
	 */
	public function getMode() 
	{
		return $this->sMode;
	}



	/**
	 * Fetch a row of results from the previous query.
	 * 
	 * Only fetches a row if the last query had results.
	 * 
	 * @return mixed NULL if no results. Otherwise an Associative or Index array or an Object depending on the mode.
	 * @throws Exception
	 */
	public function fetchRow() 
	{
		
		// only do stuff if there are results
		if ($this->iResult && mysql_num_rows($this->iResult)) 
		{
		
			// get next row, regardless of how many were returned
			switch ($this->getMode()) 
			{
				case 'HASH' :
					return mysql_fetch_assoc($this->iResult);
					break;
				case 'ARRAY' :
					return mysql_fetch_array($this->iResult);
					break;
				case 'OBJECT' :
					return mysql_fetch_object($this->iResult);
					break;
				default :
					// this should never occur
					throw new Exception('Fatal Error :  Unable to determine mode.  Value returned by getMode no makey sense.');
					break;
			}
		} 
		
		// nothing was done
		return NULL;
	}
	

	/**
	 * Utility method to return an entire column from a query result as an array.
	 * 
	 * @param string $sColName
	 * @return array 
	 */
	public function fetchCol($sColName) 
	{

		
		// array to hold sql col
		$aResult = array();
		
		// only do stuff if there are results
		if ($this->iResult && mysql_num_rows($this->iResult)) 
		{
			while ($a = mysql_fetch_assoc($this->iResult)) 
			{
				$aResult[]=$a[$sColName];
			}
		}
		
		// send back the array or a blank array
		return $aResult;
	}	
	
	/**
	 * Utility funtion to return all the rows in a result as an array
	 * of arrays or objects depending on the mode.
	 * @return array 
	 */
	public function fetchAllRows()
	{
		
		$aResults = array();
		
		// only do stuff if there are results
		if ($this->iResult && mysql_num_rows($this->iResult)) 
		{
		
			// get next row, regardless of how many were returned
			switch ($this->getMode()) 
			{
				case 'HASH' :
					while ($a = mysql_fetch_assoc($this->iResult))
					{
						$aResults[] = $a;
					}
					break;
				case 'ARRAY' :
					while ($a = mysql_fetch_array($this->iResult))
					{
						$aResults[] = $a;
					}
					break;
				case 'OBJECT' :
					while ($o = mysql_fetch_object($this->iResult))
					{
						$aResults[] = $o;
					}
					break;
				default :
					// this should never occur
					throw new Exception('Fatal Error :  Unable to determine mode.  Value returned by getMode no makey sense.');
					break;
			}
		} 
		
		return $aResults;		
	}
	
	/**
	 * Close down the currrent connection.
	 *  
	 * @return boolean TRUE on success FALSE on failure 
	 */
	public function close() 
	{
		return mysql_close($this->getActiveLink());
	}
	



	/**
	 * Makes a string safe for use in a mysql query.
	 * 
	 * Takes a supplied string from an untrusted source, like POST or COOKIE, 
	 * and makes it safe for use in a mysql query.  Automatically senses if 
	 * magic quotes are enabled and adjusts accordingly to prevent double 
	 * slashing.
	 * 
	 * @param string $s
	 * @return string 
	 */
	public function safe($s) 
	{
		
		// Reverse magic_quotes_gpc/magic_quotes_sybase effects on those vars if ON.
        if(get_magic_quotes_gpc()) 
		{
            $s = stripslashes($s);
        }
		
		// use mysql_real_escape_string to prevent sql injection attacks
		$s = mysql_real_escape_string($s);
				
		return $s;
		
	}


	/**
	 * Shortuct for creating select associative arrays.
	 * 
	 * @param string $sKeyColName mysql column that will be used as associative array keys
	 * @param string $sValueColName mysql column that will be uses as associative array values
	 * @return array Can be an empty array if no results found. 
	 */
	public function fetchSelectOptions($sKeyColName,$sValueColName) 
	{
		
		$aReturn = array();

		while ($a = $this->fetchRow()) 
		{
			$aReturn[$a[$sKeyColName]]=$a[$sValueColName];
		}
		return $aReturn;
		
		
	}
}
