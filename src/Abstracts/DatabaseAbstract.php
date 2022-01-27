<?php

namespace WebServer\Abstracts;

use mysqli;
use mysqli_result;
use WebServer\Exceptions\LocalizedException;

/**
 * @package ws-database
 */
abstract class DatabaseAbstract
{
    protected const DB_HOST = null;

    protected const DB_USER = null;

    protected const DB_PASS = null;

    protected const DB_NAME = null;

    /**
     * @var mysqli|null
     */
    private ?mysqli $mysqli = null;

    /**
     * Send statement and return result.
     *
     * @param string $query
     * @param array|null $paramList
     * @param bool|null $returnResult
     * @return mysqli_result|bool
     * @throws LocalizedException
     */
    public function send(
        string $query,
        array $paramList = [],
        bool $returnResult = true
    ) {
        $this->connect();

        /* Set params types */
        if (!empty($paramList)) {
            $types = '';

            foreach ($paramList as $param) {
                switch (gettype($param)) {
                    case 'string':
                        $types .= 's';
                        break;
                    case 'integer':
                        $types .= 'i';
                        break;
                    case 'double':
                    case 'float':
                        $types .= 'd';
                        break;
                    default:
                        throw new LocalizedException('Unexpected param type: ' . gettype($param));
                }
            }
        }

        $stmt = $this->mysqli->prepare($query);

        if (!$stmt) {
            throw new LocalizedException('Unable to prepare statement.');
        }

        try {
            if (!empty($paramList) && !empty($types)) {
                $stmt->bind_param($types, ...array_values($paramList));
            }
            $stmt->execute();
            $result = $returnResult ? $stmt->get_result() : true;
        } catch (\Exception $error) {
            throw new LocalizedException('Unable to execute statement.', $error);
        } finally {
            $stmt->close();
        }

        return $result;
    }

    /**
     * Describe query to database.
     *
     * As: ["Field", "Type", "Null", "Key", "Default", "Extra"]
     *
     * @param string $tableName Name of the database table.
     * @return array List of the table fields.
     * @throws LocalizedException
     */
    public function describe(string $tableName) :array
    {
        $this->connect();

        $tableName = $this->mysqli->real_escape_string($tableName);
        $query = "DESCRIBE `$tableName`;";

        return $this->send($query)
            ->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Get number of entities in the provided table.
     *
     * @param string $tableName Name of the database table.
     * @return int Number of entity.
     * @throws LocalizedException
     */
    public function count(string $tableName): int
    {
        $this->connect();

        $tableName = $this->mysqli->real_escape_string($tableName);
        $query = "SELECT COUNT(*) FROM `$tableName`;";

        return $this->send($query)
            ->fetch_row()[0];
    }

    /**
     * Escape string.
     *
     * @param string $value
     * @return string
     */
    public function escape(string $value): string
    {
        try {
            $this->connect();
            $escapedString = $this->mysqli->real_escape_string($value);
        } catch (LocalizedException $error) {
            $escapedString = addslashes($value);
        }

        return $escapedString;
    }

    /**
     * Get last auto incremented ID.
     *
     * @return int|null Last auto incremented ID.
     */
    public function getLastId(): ?int
    {
        if (!$this->mysqli) {
            return null;
        }

        $id = $this->mysqli->insert_id;

        return is_numeric($id) ? (int)$id : null;
    }

    /**
     * Insert query to database. One entity.
     *
     * @param string $tableName Name of the table to send query.
     * @param array $entity Keys of the array will become names of the fields.
     * @param bool|null $ignore Ignore if entity already exists.
     * @param bool $update Update on duplicate entity.
     * @return bool Result of the query.
     * @throws LocalizedException
     */
    public function insertOne (
        string $tableName,
        array $entity,
        bool $ignore = false,
        bool $update = false
    ) :bool {
        $this->connect();

        $tableName = $this->mysqli->real_escape_string($tableName);
        $paramList = [];

        $query = $ignore ? 'INSERT IGNORE ' : 'INSERT ';
        $query .= "INTO `$tableName` (";

        $fieldList = array_column($this->describe($tableName), 'Field');

        foreach ($fieldList as $fieldName) {
            if (isset($entity[$fieldName])) {
                $query .= "`$fieldName`, ";
            }
        }

        $query = substr($query, 0, -2) . ') VALUES (';

        foreach ($fieldList as $fieldName) {
            if (isset($entity[$fieldName])) {
                $query .= "?, ";
                $paramList[] = $this->mysqli->real_escape_string($entity[$fieldName]);
            }
        }

        $query = substr($query, 0, -2) .")";

        if ($update && $this->mysqli->server_version > 80000) {
            $alias = 'new_table';

            $query .= " AS `$alias` ON DUPLICATE KEY UPDATE ";

            foreach ($fieldList as $fieldName) {
                if ($fieldName === 'id' && empty($entity['id'])) {
                    continue;
                }

                $query .= "`$fieldName` = `$alias`.`$fieldName`, ";
            }

            $query = substr($query, 0, -2);
        } else if ($update) {
            $query .= ' ON DUPLICATE KEY UPDATE ';

            foreach ($fieldList as $fieldName) {
                if ($fieldName === 'id' && empty($entity['id'])) {
                    continue;
                }

                $query .= "`$fieldName` = VALUES(`$fieldName`), ";
            }

            $query = substr($query, 0, -2);
        }

        $query .= ";";

        return $this->send($query, $paramList, false);
    }

    /**
     * Insert array of entities to database.
     *
     * @param string $tableName Name of the table to send query.
     * @param array $entityList Array of the entity values.
     * @param bool $ignore Ignore if entity already exists.
     * @param bool $update Update on duplicate entity.
     * @return bool Result of the query.
     * @throws LocalizedException
     */
    public function insertArray (
        string $tableName,
        array $entityList,
        bool $ignore = false,
        bool $update = false
    ) :bool {
        $this->connect();

        $tableName = $this->mysqli->real_escape_string($tableName);
        $paramList = [];

        $fieldList = $this->describe($tableName);

        $query = $ignore ? 'INSERT IGNORE ' : 'INSERT ';

        $query .= "INTO `$tableName` (";

        foreach ($fieldList as $field) {
            $query .= "`{$field['Field']}`, ";
        }

        $query = substr($query, 0, -2) . ') VALUES ';

        foreach ($entityList as $entity) {
            $query .= '(';

            foreach ($fieldList as &$field) {
                $fieldName = $field['Field'];

                $value = $entity[$fieldName] ?? 'NULL';

                $query .= $value === 'NULL' ? "$value, " : '?, ';

                if ($value && $value !== 'NULL') {
                    $field['Updated'] = true;
                    $paramList[] = $this->mysqli->real_escape_string($value);
                }
            }

            /* Because it was linked with & */
            unset($field);

            $query = substr($query, 0, -2)."), ";
        }

        $query = substr($query, 0, -2);

        if ($update && $this->mysqli->server_version > 80000) {
            $alias = 'new_table';

            $query .= " AS `$alias` ON DUPLICATE KEY UPDATE ";

            foreach ($fieldList as $field) {
                $fieldName = $field['Field'];

                if (!isset($field['Updated']) || !$field['Updated']) {
                    continue;
                }

                $query .= "`$fieldName` = `$alias`.`$fieldName`, ";
            }

            $query = substr($query, 0, -2);
        } else if ($update) {
            $query .= ' ON DUPLICATE KEY UPDATE ';

            foreach ($fieldList as $field) {
                $fieldName = $field['Field'];

                if (!isset($field['Updated']) || !$field['Updated']) {
                    continue;
                }

                $query .= "`$fieldName` = VALUES(`$fieldName`), ";
            }

            $query = substr($query, 0, -2);
        }

        $query .= ";";

        return $this->send($query, $paramList, false);
    }

    /**
     * Delete query to database. One entity.
     *
     * @param string $tableName Name of the table to send query.
     * @param int $id ID of the item to delete.
     * @return bool Result of the query.
     *
     * @throws LocalizedException
     */
    public function removeOne (
        string $tableName,
        int $id
    ) :bool {
        $this->connect();

        $tableName = $this->mysqli->real_escape_string($tableName);

        $query = "DELETE FROM `$tableName` WHERE `id` = ? LIMIT 1;";

        return $this->send($query, [$id], false);
    }

    /**
     * Delete query to database. Delete all items from the $entityList by ID.
     *
     * @param string $tableName Name of the table to send query.
     * @param array $entityList Array of the entities to be deleted by their IDs.
     * @return bool Result of the query.
     * @throws LocalizedException
     */
    public function removeArray (
        string $tableName,
        array $entityList
    ) :bool {
        $this->connect();

        $tableName = $this->mysqli->real_escape_string($tableName);
        $paramList = [];

        $query = "DELETE FROM `$tableName` WHERE `id` IN(";

        foreach ($entityList as $entity) {
            if (is_numeric($entity)) {
                $query .= '?, ';
                $paramList[] = (int)$entity;
            } else if (is_array($entity) && isset($entity['id'])) {
                $query .= '?, ';
                $paramList[] = (int)$entity['id'];
            }

        }

        $query = substr($query, 0, -2) . ");";

        return $this->send($query, $paramList, false);
    }

    /**
     * Select query to database.
     *
     * @param string $tableName Name of the table to send query.
     * @param array|null $conditions Array with keys as fields name and values. Uses AND command to combine.
     * @param string|null $order Name of the field to sort result.
     * @param string|null $orderDirection Direction of the sorting.
     * @param int|null $limit Number of records in the result.
     * @return array Result array of the select query.
     *
     * @throws LocalizedException
     */
    public function select(
        string $tableName,
        array $conditions = null,
        string $order = null,
        string $orderDirection = null,
        int $limit = null
    ) :array {
        $this->connect();

        $tableName = $this->mysqli->real_escape_string($tableName);
        $paramList = [];
        $order = !empty($order) ? $this->mysqli->real_escape_string($order) : null;
        $orderDirection = $orderDirection === 'ASC' || $orderDirection === 'DESC' ? $orderDirection : '';
        $fieldList = array_column($this->describe($tableName), 'Field');

        $query = 'SELECT * ';

        $query .= "FROM `$tableName`";

        if (!empty($conditions)) {
            $query .= ' WHERE ';

            foreach ($conditions as $fieldName => $fieldValue) {
                if (!in_array($fieldName, $fieldList)) {
                    continue;
                }

                $fieldName = $this->mysqli->real_escape_string($fieldName);

                if (empty($fieldValue)) {
                    $fieldValue = 'IS NULL';
                } else if (is_array($fieldValue)) {
                    $fieldSubValue = 'IN (';
                    foreach ($fieldValue as $value) {
                        $fieldSubValue .= '?, ';
                        $paramList[] = $this->mysqli->real_escape_string($value);
                    }
                    $fieldValue = substr($fieldSubValue, 0, -2) . ')';
                } else if ($fieldValue !== 'IS NOT NULL') {
                    $paramList[] = $this->mysqli->real_escape_string($fieldValue);
                    $fieldValue = '= ?';
                }

                $query .= "`$fieldName` $fieldValue AND ";
            }

            $query = substr($query, 0, -5);
        }

        if (!is_null($order)) $query .= " ORDER BY `$order` $orderDirection";
        if (is_int($limit)) $query .= " LIMIT $limit";

        $query .= ";";

        var_dump($query);

        return $limit === 1
            ? $this->send($query, $paramList)
                ->fetch_assoc() ?? []
            : $this->send($query, $paramList)
                ->fetch_all(MYSQLI_ASSOC) ?? [];
    }

    /**
     * Update query to database. One entity.
     *
     * @param string $tableName Name of the table to send query.
     * @param array $entity Keys of the array will become names of the fields.
     * @return bool Result of the update query.
     * @throws LocalizedException
     */
    public function updateOne (
        string $tableName,
        array $entity
    ) :bool {
        $this->connect();

        if (!isset($entity['id'])) {
            return false;
        }

        $entity['id'] = (int)$entity['id'];

        $tableName = $this->mysqli->real_escape_string($tableName);
        $fieldList = array_diff(array_column($this->describe($tableName), 'Field'), ['id']);
        $paramList = [];

        $query = "UPDATE `$tableName` SET ";

        foreach ($fieldList as $fieldName) {
            if (!isset($entity[$fieldName])) {
                continue;
            }

            $fieldValue = $this->mysqli->real_escape_string($entity[$fieldName]);

            $query .= "`$fieldName` = ?, ";
            $paramList[] = $fieldValue;
        }

        $query = substr($query, 0, -2);

        $query .= ' WHERE `id` = ? LIMIT 1;';
        $paramList[] = $entity['id'];

        return $this->send($query, $paramList, false);
    }

    /**
     * Update query to database. Updates all entities.
     *
     * @param string $tableName Name of the table to send query.
     * @param array $entityList Array of the entities to be updated by their IDs.
     * @return bool Result of the query.
     * @throws LocalizedException
     */
    public function updateArray (
        string $tableName,
        array $entityList
    ) :bool {
        $this->connect();

        $tableName = $this->mysqli->real_escape_string($tableName);
        $fieldList = array_diff(array_column($this->describe($tableName), 'Field'), ['id']);

        $query = "UPDATE `$tableName` SET ";

        $paramList = [];

        foreach ($fieldList as $fieldName) {
            $fieldValueList = [];

            foreach ($entityList as $entity) {
                if (isset($entity[$fieldName])) {
                    $value = $this->mysqli->real_escape_string($entity[$fieldName]);
                    $fieldValueList[] = [
                        'id'    => (int)$entity['id'],
                        'value' => $value
                    ];
                }
            }

            if (!$fieldValueList) continue;

            $query .= "`$fieldName` = CASE ";

            foreach ($fieldValueList as $case) {
                $query .= "WHEN `id` = ? THEN ? ";
                $paramList[] = $case['id'];
                $paramList[] = $case['value'];
            }

            $query .= "ELSE `$fieldName` END, ";
        }

        if (!isset($fieldValueList)) {
            throw new LocalizedException('Incorrect attributes provided for update query.');
        }

        $query = substr($query, 0, -2) . ';';

        return $this->send($query, $paramList, false);
    }

    /**
     * Test connection and reconnect if needed.
     *
     * @throws LocalizedException
     */
    private function connect()
    {
        if ($this->mysqli && $this->mysqli->ping()) {
            return;
        } else if ($this->mysqli) {
            $this->mysqli->close();
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        try {
            $mysqli = new mysqli(
                static::DB_HOST,
                static::DB_USER,
                static::DB_PASS,
                static::DB_NAME
            );
        } catch (\Exception $error) {
            throw new LocalizedException('Unable to connect to database.', $error);
        }

        $mysqli->set_charset("utf8mb4");
        $this->mysqli = $mysqli;
    }

    /**
     * Shutdown function.
     */
    public function __destruct()
    {
        if ($this->mysqli) {
            $this->mysqli->close();
        }
    }
}
