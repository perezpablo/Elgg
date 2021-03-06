<?php

/**
 * An object representing a single Elgg database.
 *
 * WARNING: THIS API IS IN FLUX. PLUGIN AUTHORS SHOULD NOT USE. See lib/database.php instead.
 *
 * TODO: Convert query cache to a private local variable (or remove completely).
 *
 * @access private
 *
 * @package    Elgg.Core
 * @subpackage Database
 */
class Elgg_Database {

	/** @var string $tablePrefix Prefix for database tables */
	private $tablePrefix;

	/** @var resource[] $dbLinks Database connection resources */
	private $dbLinks = array();

	/** @var int $queryCount The number of queries made */
	private $queryCount = 0;

	/**
	 * Query cache for select queries.
	 *
	 * Queries and their results are stored in this cache as:
	 * <code>
	 * $DB_QUERY_CACHE[query hash] => array(result1, result2, ... resultN)
	 * </code>
	 * @see Elgg_Database::getResults() for details on the hash.
	 *
	 * @var Elgg_Cache_LRUCache $queryCache The cache
	 */
	private $queryCache;

	/**
	 * Queries are saved to an array and executed using
	 * a function registered by register_shutdown_function().
	 *
	 * Queries are saved as an array in the format:
	 * <code>
	 * $this->delayedQueries[] = array(
	 * 	'q' => string $query,
	 * 	'l' => string $query_type,
	 * 	'h' => string $handler // a callback function
	 * );
	 * </code>
	 *
	 * @var array $delayedQueries Queries to be run during shutdown
	 */
	private $delayedQueries = array();

	/**
	 * Constructor
	 */
	public function __construct() {
		global $CONFIG;

		$this->tablePrefix = $CONFIG->dbprefix;

		$queryCachingOn = true;
		if (isset($CONFIG->db_disable_query_cache)) {
			$queryCachingOn = !$CONFIG->db_disable_query_cache;
		}

		if ($queryCachingOn) {
			// @todo if we keep this cache in 1.9, expose the size as a config parameter
			$this->queryCache = new Elgg_Cache_LRUCache(200);
		}
	}

	/**
	 * Gets (if required, also creates) a database link resource.
	 *
	 * The database link resources are created by
	 * {@link Elgg_Database::setupConnections()}, which is called if no links exist.
	 *
	 * @param string $type The type of link we want: "read", "write" or "readwrite".
	 *
	 * @return resource Database link
	 * @throws DatabaseException
	 * @todo make protected once we get rid of get_db_link()
	 */
	public function getLink($type) {
		if (isset($this->dbLinks[$type])) {
			return $this->dbLinks[$type];
		} else if (isset($this->dbLinks['readwrite'])) {
			return $this->dbLinks['readwrite'];
		} else {
			$this->setupConnections();
			return $this->getLink($type);
		}
	}

	/**
	 * Establish database connections
	 *
	 * If the configuration has been set up for multiple read/write databases, set those
	 * links up separately; otherwise just create the one database link.
	 *
	 * @return void
	 * @throws DatabaseException
	 */
	public function setupConnections() {
		global $CONFIG;

		if (!empty($CONFIG->db->split)) {
			$this->establishLink('read');
			$this->establishLink('write');
		} else {
			$this->establishLink('readwrite');
		}
	}


	/**
	 * Establish a connection to the database server
	 *
	 * Connect to the database server and use the Elgg database for a particular database link
	 *
	 * @param string $dblinkname The type of database connection. Used to identify the
	 * resource: "read", "write", or "readwrite".
	 *
	 * @return void
	 * @throws DatabaseException
	 */
	public function establishLink($dblinkname = "readwrite") {
		global $CONFIG;

		if ($dblinkname != "readwrite" && isset($CONFIG->db[$dblinkname])) {
			if (is_array($CONFIG->db[$dblinkname])) {
				$index = rand(0, sizeof($CONFIG->db[$dblinkname]));
				$dbhost = $CONFIG->db[$dblinkname][$index]->dbhost;
				$dbuser = $CONFIG->db[$dblinkname][$index]->dbuser;
				$dbpass = $CONFIG->db[$dblinkname][$index]->dbpass;
				$dbname = $CONFIG->db[$dblinkname][$index]->dbname;
			} else {
				$dbhost = $CONFIG->db[$dblinkname]->dbhost;
				$dbuser = $CONFIG->db[$dblinkname]->dbuser;
				$dbpass = $CONFIG->db[$dblinkname]->dbpass;
				$dbname = $CONFIG->db[$dblinkname]->dbname;
			}
		} else {
			$dbhost = $CONFIG->dbhost;
			$dbuser = $CONFIG->dbuser;
			$dbpass = $CONFIG->dbpass;
			$dbname = $CONFIG->dbname;
		}

		// Connect to database
		if (!$this->dbLinks[$dblinkname] = mysql_connect($dbhost, $dbuser, $dbpass, true)) {
			$msg = "Elgg couldn't connect to the database using the given credentials. Check the settings file.";
			throw new DatabaseException($msg);
		}

		if (!mysql_select_db($dbname, $this->dbLinks[$dblinkname])) {
			$msg = "Elgg couldn't select the database '$dbname', please check that the database is created and you have access to it.";
			throw new DatabaseException($msg);
		}

		// Set DB for UTF8
		mysql_query("SET NAMES utf8");
	}

	/**
	 * Retrieve rows from the database.
	 *
	 * Queries are executed with {@link Elgg_Database::executeQuery()} and results
	 * are retrieved with {@link mysql_fetch_object()}.  If a callback
	 * function $callback is defined, each row will be passed as a single
	 * argument to $callback.  If no callback function is defined, the
	 * entire result set is returned as an array.
	 *
	 * @param mixed  $query    The query being passed.
	 * @param string $callback Optionally, the name of a function to call back to on each row
	 *
	 * @return array An array of database result objects or callback function results. If the query
	 *               returned nothing, an empty array.
	 * @throws DatabaseException
	 */
	public function getData($query, $callback = '') {
		return $this->getResults($query, $callback, false);
	}

	/**
	 * Retrieve a single row from the database.
	 *
	 * Similar to {@link Elgg_Database::getData()} but returns only the first row
	 * matched.  If a callback function $callback is specified, the row will be passed
	 * as the only argument to $callback.
	 *
	 * @param mixed  $query    The query to execute.
	 * @param string $callback A callback function
	 *
	 * @return mixed A single database result object or the result of the callback function.
	 * @throws DatabaseException
	 */
	public function getDataRow($query, $callback = '') {
		return $this->getResults($query, $callback, true);
	}

	/**
	 * Insert a row into the database.
	 *
	 * @note Altering the DB invalidates all queries in the query cache.
	 *
	 * @param mixed $query The query to execute.
	 *
	 * @return int|false The database id of the inserted row if a AUTO_INCREMENT field is
	 *                   defined, 0 if not, and false on failure.
	 * @throws DatabaseException
	 */
	public function insertData($query) {

		elgg_log("DB query $query", 'NOTICE');

		$dblink = $this->getLink('write');

		$this->invalidateQueryCache();

		if ($this->executeQuery("$query", $dblink)) {
			return mysql_insert_id($dblink);
		}

		return false;
	}

	/**
	 * Update the database.
	 *
	 * @note Altering the DB invalidates all queries in the query cache.
	 *
	 * @internal Not returning the number of rows updated as this depends on the
	 * type of update query and whether values were actually changed.
	 *
	 * @param string $query The query to run.
	 *
	 * @return bool
	 * @throws DatabaseException
	 */
	public function updateData($query) {

		elgg_log("DB query $query", 'NOTICE');

		$dblink = $this->getLink('write');

		$this->invalidateQueryCache();

		return $this->executeQuery("$query", $dblink);
	}

	/**
	 * Delete data from the database
	 *
	 * @note Altering the DB invalidates all queries in query cache.
	 *
	 * @param string $query The SQL query to run
	 *
	 * @return int|false The number of affected rows or false on failure
	 * @throws DatabaseException
	 */
	public function deleteData($query) {

		elgg_log("DB query $query", 'NOTICE');

		$dblink = $this->getLink('write');

		$this->invalidateQueryCache();

		if ($this->executeQuery("$query", $dblink)) {
			return mysql_affected_rows($dblink);
		}

		return false;
	}

	/**
	 * Handles queries that return results, running the results through a
	 * an optional callback function. This is for R queries (from CRUD).
	 *
	 * @param string $query    The select query to execute
	 * @param string $callback An optional callback function to run on each row
	 * @param bool   $single   Return only a single result?
	 *
	 * @return array An array of database result objects or callback function results. If the query
	 *               returned nothing, an empty array.
	 * @throws DatabaseException
	 */
	protected function getResults($query, $callback = null, $single = false) {

		// Since we want to cache results of running the callback, we need to
		// need to namespace the query with the callback and single result request.
		// http://trac.elgg.org/ticket/4049
		$callback_hash = is_object($callback) ? spl_object_hash($callback) : (string)$callback;
		$hash = $callback_hash . (int)$single . $query;

		// Is cached?
		if ($this->queryCache) {
			if (isset($this->queryCache[$hash])) {
				elgg_log("DB query $query results returned from cache (hash: $hash)", 'NOTICE');
				return $this->queryCache[$hash];
			}
		}

		$dblink = $this->getLink('read');
		$return = array();

		if ($result = $this->executeQuery("$query", $dblink)) {

			// test for callback once instead of on each iteration.
			// @todo check profiling to see if this needs to be broken out into
			// explicit cases instead of checking in the interation.
			$is_callable = is_callable($callback);
			while ($row = mysql_fetch_object($result)) {
				if ($is_callable) {
					$row = call_user_func($callback, $row);
				}

				if ($single) {
					$return = $row;
					break;
				} else {
					$return[] = $row;
				}
			}
		}

		if (empty($return)) {
			elgg_log("DB query $query returned no results.", 'NOTICE');
		}

		// Cache result
		if ($this->queryCache) {
			$this->queryCache[$hash] = $return;
			elgg_log("DB query $query results cached (hash: $hash)", 'NOTICE');
		}

		return $return;
	}

	/**
	 * Execute a query.
	 *
	 * $query is executed via {@link mysql_query()}.  If there is an SQL error,
	 * a {@link DatabaseException} is thrown.
	 *
	 * @param string   $query  The query
	 * @param resource $dblink The DB link
	 *
	 * @return resource|bool The result of mysql_query()
	 * @throws DatabaseException
	 * @todo should this be public?
	 */
	public function executeQuery($query, $dblink) {

		if ($query == null) {
			throw new DatabaseException("Query cannot be null");
		}

		if (!is_resource($dblink)) {
			throw new DatabaseException("Connection to database was lost.");
		}

		$this->queryCount++;

		$result = mysql_query($query, $dblink);

		if (mysql_errno($dblink)) {
			throw new DatabaseException(mysql_error($dblink) . "\n\n QUERY: $query");
		}

		return $result;
	}

	/**
	 * Return tables matching the database prefix {@link $this->tablePrefix}% in the currently
	 * selected database.
	 *
	 * @return array Array of tables or empty array on failure
	 * @static array $tables Tables found matching the database prefix
	 * @throws DatabaseException
	 */
	public function getTables() {
		static $tables;

		if (isset($tables)) {
			return $tables;
		}

		$result = $this->getData("SHOW TABLES LIKE '$this->tablePrefix%'");

		$tables = array();
		if (is_array($result) && !empty($result)) {
			foreach ($result as $row) {
				$row = (array) $row;
				if (is_array($row) && !empty($row)) {
					foreach ($row as $element) {
						$tables[] = $element;
					}
				}
			}
		}

		return $tables;
	}

	/**
	 * Runs a full database script from disk.
	 *
	 * The file specified should be a standard SQL file as created by
	 * mysqldump or similar.  Statements must be terminated with ;
	 * and a newline character (\n or \r\n) with only one statement per line.
	 *
	 * The special string 'prefix_' is replaced with the database prefix
	 * as defined in {@link $this->tablePrefix}.
	 *
	 * @warning Errors do not halt execution of the script.  If a line
	 * generates an error, the error message is saved and the
	 * next line is executed.  After the file is run, any errors
	 * are displayed as a {@link DatabaseException}
	 *
	 * @param string $scriptlocation The full path to the script
	 *
	 * @return void
	 * @throws DatabaseException
	 */
	function runSqlScript($scriptlocation) {
		if ($script = file_get_contents($scriptlocation)) {
			global $CONFIG;

			$errors = array();

			// Remove MySQL -- style comments
			$script = preg_replace('/\-\-.*\n/', '', $script);

			// Statements must end with ; and a newline
			$sql_statements = preg_split('/;[\n\r]+/', $script);

			foreach ($sql_statements as $statement) {
				$statement = trim($statement);
				$statement = str_replace("prefix_", $this->tablePrefix, $statement);
				if (!empty($statement)) {
					try {
						$result = $this->updateData($statement);
					} catch (DatabaseException $e) {
						$errors[] = $e->getMessage();
					}
				}
			}
			if (!empty($errors)) {
				$errortxt = "";
				foreach ($errors as $error) {
					$errortxt .= " {$error};";
				}

				$msg = "There were a number of issues: " . $errortxt;
				throw new DatabaseException($msg);
			}
		} else {
			$msg = "Elgg couldn't find the requested database script at " . $scriptlocation . ".";
			throw new DatabaseException($msg);
		}
	}

	/**
	 * Queue a query for execution upon shutdown.
	 *
	 * You can specify a handler function if you care about the result. This function will accept
	 * the raw result from {@link mysql_query()}.
	 *
	 * @param string $query   The query to execute
	 * @param string $type    The query type ('read' or 'write')
	 * @param string $handler A callback function to pass the results array to
	 *
	 * @return boolean Whether registering was successful.
	 * @todo deprecate passing resource for $type as that should not be part of public API
	 */
	public function registerDelayedQuery($query, $type, $handler = "") {

		if (!is_resource($type) && $type != 'read' && $type != 'write') {
			return false;
		}

		// Construct delayed query
		$delayed_query = array();
		$delayed_query['q'] = $query;
		$delayed_query['l'] = $type;
		$delayed_query['h'] = $handler;

		$this->delayedQueries[] = $delayed_query;

		return true;
	}


	/**
	 * Trigger all queries that were registered as "delayed" queries. This is
	 * called by the system automatically on shutdown.
	 *
	 * @return void
	 * @access private
	 * @todo make protected once this class is part of public API
	 */
	public function executeDelayedQueries() {

		foreach ($this->delayedQueries as $query_details) {
			try {
				$link = $query_details['l'];

				if ($link == 'read' || $link == 'write') {
					$link = $this->getLink($link);
				} elseif (!is_resource($link)) {
					elgg_log("Link for delayed query not valid resource or db_link type. Query: {$query_details['q']}", 'WARNING');
				}

				$result = $this->executeQuery($query_details['q'], $link);

				if ((isset($query_details['h'])) && (is_callable($query_details['h']))) {
					$query_details['h']($result);
				}
			} catch (DatabaseException $e) {
				// Suppress all exceptions since page already sent to requestor
				elgg_log($e, 'ERROR');
			}
		}
	}

	/**
	 * Invalidate the query cache
	 *
	 * @return void
	 */
	protected function invalidateQueryCache() {
		$this->queryCache->clear();
		elgg_log("Query cache invalidated", 'NOTICE');
	}

	/**
	 * Test that the Elgg database is installed
	 *
	 * @return void
	 * @throws InstallationException
	 */
	public function assertInstalled() {
		global $CONFIG;

		if (isset($CONFIG->installed)) {
			return;
		}

		try {
			$dblink = $this->getLink('read');
			mysql_query("SELECT value FROM {$this->tablePrefix}datalists WHERE name = 'installed'", $dblink);
			if (mysql_errno($dblink) > 0) {
				throw new DatabaseException();
			}
		} catch (DatabaseException $e) {
			throw new InstallationException("Unable to handle this request. This site is not configured or the database is down.");
		}

		$CONFIG->installed = true;
	}

	/**
	 * Get the number of queries made to the database
	 *
	 * @return int
	 */
	public function getQueryCount() {
		return $this->queryCount;
	}
}
