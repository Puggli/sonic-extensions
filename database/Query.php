<?php
namespace Sonic\Database;
use Sonic\Database;

/**
 * Query
 *
 * @category Sonic
 * @package Database
 * @subpackage Query
 * @author Craig Campbell
 */
class Query
{
    /**
     * @var string
     */
    protected $_schema;

    /**
     * @var string
     */
    protected $_sql;

    /**
     * @var array
     */
    protected $_binds = array();

    /**
     * @var bool
     */
    protected $_executed = false;

    /**
     * @var PDOStatement
     */
    protected $_statement;

    /**
     * @var Query\Filter
     */
    protected $_filter;

    /**
     * @var Query\Sort
     */
    protected $_sort;

    /**
     * constructor
     *
     * @param string $sql
     * @param string $schema
     * @return void
     */
    public function __construct($sql, $schema = null)
    {
        if (!$sql) {
            throw new Exception('you need to pass in sql to be executed!');
        }
        $this->_sql = $sql;
        $this->_schema = $schema;
    }

    /**
     * what class should we use for constants?
     *
     * @return string
     */
    protected function _getClass()
    {
        return Database::getDriverClass();
    }

    /**
     * gets the sql for this query
     *
     * @return string
     */
    public function getSql()
    {
        return $this->_sql;
    }

    /**
     * gets the binds for this query
     *
     * @return array
     */
    public function getBinds()
    {
        return $this->_binds;
    }

    /**
     * gets the PDOStatement for this query
     *
     * @return PDOStatement
     */
    public function getStatement()
    {
        if ($this->_statement !== null) {
            return $this->_statement;
        }

        $database = Factory::getDatabase($this->_schema);
        $this->_statement = $database->prepare($this->_sql);
        return $this->_statement;
    }

    /**
     * gets last insert id
     *
     * @return int
     */
    public function lastInsertId()
    {
        return (int) Factory::getDatabase($this->_schema)->getPdo(Database::MASTER)->lastInsertId();
    }

    /**
     * gets the bound params for this query
     *
     * @return array
     */
    public function getBoundParams()
    {
        return $this->_binds;
    }

    /**
     * executes this query
     *
     * @return bool
     */
    public function execute()
    {
        $this->_executed = true;

        $statement = $this->getStatement();

        foreach ($this->_binds as $key => $value) {
            $statement->bindValue($key, $value);
        }

        try {
            $result = $statement->execute();
        } catch (\Exception $e) {
            throw new Exception($e->getMessage());
        }

        return $result;
    }

    /**
     * gets a single value from the database
     *
     * @return mixed
     */
    public function fetchValue()
    {
        // if a sort or filter has been applied
        if ($this->_filter !== null || $this->_sort !== null) {
            $rows = $this->fetchAll();
            return count($rows) == 0 ? null : $rows[0];
        }

        if (!$this->_executed) {
            $this->execute();
        }

        $class = $this->_getClass();

        $row = $this->getStatement()->fetch($class::FETCH_NUM);
        return $row[0];
    }

    /**
     * gets a single row from the database
     *
     * @return array
     */
    public function fetchRow()
    {
        // if a sort or filter has been applied
        if ($this->_filter !== null || $this->_sort !== null) {
            $rows = $this->fetchAll();
            return count($rows) == 0 ? false : $rows[0];
        }

        if (!$this->_executed)
            $this->execute();

        $class = $this->_getClass();

        return $this->getStatement()->fetch($class::FETCH_ASSOC);
    }

    /**
     * internal fetch all function to just return the database values
     * if there is only one column selected that is returned as an array
     *
     * @return array
     */
    protected function _fetchAll()
    {
        if (!$this->_executed) {
            $this->execute();
        }

        $class = $this->_getClass();

        $results = $this->getStatement()->fetchAll($class::FETCH_ASSOC);

        if ($this->getStatement()->columnCount() != 1) {
            return $results;
        }

        // only one column
        $new_results = array();
        foreach ($results as $result)
            $new_results[] = array_pop($result);

        return $new_results;
    }

    /**
     * gets all rows from database that match
     * if there is only one column selected that is returned as an array
     *
     * @return array
     */
    public function fetchAll()
    {
        $results = $this->_fetchAll();
        $results = $this->_filter($results);
        $results = $this->_sort($results);
        return $results;
    }

    /**
     * gets all ids from the database that match
     *
     * @return array
     */
    public function fetchIds()
    {
        if ($this->_sort) {
            $this->_sort->scrapData();
        }

        $all_data = $this->fetchAll();

        // no data to begin with
        if (count($all_data) == 0) {
            return array();
        }

        // no id column in the data
        if (!isset($all_data[0]['id'])) {
            return $all_data;
        }

        $ids = array();
        foreach ($all_data as $data) {
            $ids[] = (int) $data['id'];
        }
        return $ids;
    }

    /**
     * fetches into an object
     *
     * @return Object
     */
    public function fetchObject($class)
    {
        if (!$this->_executed) {
            $this->execute();
        }

        return $this->getStatement()->fetchObject($class);
    }

    /**
     * fetches multiple objects
     *
     * @return Object
     */
    public function fetchObjects($class)
    {
        if (!$this->_executed) {
            $this->execute();
        }

        $const_class = $this->_getClass();

        $statement = $this->getStatement();
        $statement->setFetchMode($const_class::FETCH_CLASS, $class);
        return $statement->fetchAll();
    }

    /**
     * binds a parameter to this query
     *
     * @param string $key
     * @param mixed $value
     * @return Query
     */
    public function bindValue($key, $value)
    {
        if (array_key_exists($key, $this->_binds)) {
            throw new Exception('You have already bound ' . $key . ' to this query.');
        }
        $this->_binds[$key] = $value;
        return $this;
    }

    /**
     * adds a filtering pattern
     * for example: "id < 5" or "console = nintendo" or "id IN 1,2,3"
     *
     * @param string $pattern
     * @return Query
     */
    public function filter($pattern, $args = null)
    {
        if ($this->_filter === null) {
            $this->_filter = new Query\Filter();
        }

        $this->_filter->addPattern($pattern, $args);
        return $this;
    }

    /**
     * adds a sorting pattern
     *
     * @param string $column
     * @param string $direction
     * @return Query
     */
    public function sort($column, $direction)
    {
        if ($this->_sort === null) {
            $this->_sort = new Query\Sort();
        }
        $this->_sort->add($column, $direction);
        return $this;
    }

    /**
     * processes applied filters
     *
     * @param array $data
     * @return array
     */
    protected function _filter($data)
    {
        if ($this->_filter === null) {
            return $data;
        }
        return $this->_filter->process($data);
    }

    /**
     * processes applied sorts
     *
     * @param array $data
     * @return array
     */
    protected function _sort($data)
    {
        if ($this->_sort === null) {
            return $data;
        }
        return $this->_sort->process($data);
    }
}
