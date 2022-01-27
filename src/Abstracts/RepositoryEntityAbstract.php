<?php

namespace WebServer\Abstracts;

use WebServer\Interfaces\EntityRepositoryInterface;
use WebServer\Exceptions\LocalizedException;
use mysqli_result;

/**
 * @package ws-database
 */
abstract class RepositoryEntityAbstract extends RepositoryAbstract implements EntityRepositoryInterface
{
    protected const JOIN_LIST = [
        'inner_join'    => 'INNER JOIN',
        'left_join'     => 'LEFT JOIN',
        'right_join'    => 'RIGHT JOIN',
        'full_join'     => 'FULL JOIN'
    ];

    /**
     * @var array
     */
    protected array $tableList = [];

    /**
     * @var array
     */
    protected array $joinParamList = [];

    /**
     * @param DatabaseAbstract $mySQL
     */
    public function __construct(
        DatabaseAbstract $mySQL
    ) {
        parent::__construct($mySQL);
    }

    /**
     * @param int $id
     * @return ModelAbstract|null
     */
    public function getItemById(int $id): ?ModelAbstract
    {
        $query = $this->getSelectPart();
        $query .= " FROM `{$this->mainTableName}`";
        $query .= $this->getJoinPart();
        $query .= $this->getWherePart(['id' => [$id]]);
        $query .= " LIMIT 1;";

        try {
            $result = $this->prepare($query)->fetch_assoc();
        } catch (LocalizedException $error) {
            return null;
        }

        return !empty($result) ? $this->createItem($result) : null;
    }

    /**
     * @param array|null $criteriaList
     * @param string|null $order
     * @param string|null $orderDirection
     * @param int|null $limit
     * @return ModelAbstract[]
     */
    public function getItemList(
        array $criteriaList = [],
        string $order = null,
        string $orderDirection = null,
        int $limit = null
    ): array {
        $query = $this->getSelectPart();
        $query .= " FROM `{$this->mainTableName}`";
        $query .= $this->getJoinPart();
        $query .= $this->getWherePart($criteriaList);
        $query .= is_string($order)
            ? $orderDirection === 'ASC' || $orderDirection === 'DESC'
                ? 'ORDER BY `'. $this->mySQL->escape($order) . "` $orderDirection"
                : 'ORDER BY `'. $this->mySQL->escape($order) . '`'
            : '';
        $query .= is_int($limit) ? " LIMIT $limit;" : ';';

        try {
            $result = $this->prepare($query)->fetch_all(MYSQLI_ASSOC);
        } catch (LocalizedException $error) {
            return [];
        }

        $entityList = [];

        foreach ($result as $data) {
            $entity = $this->createItem($data);

            if ($entity) {
                $entityList[] = $entity;
            }
        }

        return $entityList;
    }

    /**
     * Filtered describe query to database. Without alias mapping.
     *
     * As: [“Field”, “Type”, “Null”, “Key”, “Default”, “Extra”]
     *
     * @return array
     */
    public function getFieldList(): array
    {
        $data = [];

        try {
            foreach ($this->tableList['field_list'] as $table => $fieldAliasList) {
                $tableName = addslashes($table);

                $fieldList = $this->mySQL->describe($tableName);

                foreach ($fieldList as $key => $fieldData) {
                    $fieldName = $fieldData['Field'];

                    if (!in_array($fieldName, array_values($fieldAliasList))) {
                        array_splice($fieldList, $key, 1);
                    }
                }

                $data[$tableName] = $fieldList;
            }
        } catch (\Exception $error) {
            return [];
        }

        return $data;
    }

    /**
     * @param string $query
     * @return mysqli_result
     * @throws LocalizedException
     */
    protected function prepare(string $query): mysqli_result
    {
        $paramList = [];

        foreach (static::JOIN_LIST as $joinKey => $joinName) {
            if (empty($this->tableList[$joinKey])) {
                continue;
            }

            foreach ($this->tableList[$joinKey] as $joinList) {
                foreach ($joinList as $join) {
                    if (is_string($join) && !empty($this->joinParamList[$join])) {
                        $paramList[] = $this->joinParamList[$join];
                    }
                }
            }
        }

        return $this->mySQL->send($query, $paramList);
    }

    /**
     * @return string
     */
    protected function getSelectPart(): string
    {
        $query = 'SELECT ';

        foreach ($this->tableList['field_list'] as $table => $fieldList) {
            $tableName = addslashes($table);

            foreach ($fieldList as $alias => $field) {
                $currentFieldName = addslashes($field);

                $fieldAlias = is_string($alias) ? addslashes($alias) : null;

                if (!is_null($fieldAlias)) {
                    $query .= "`$tableName`.`$currentFieldName` AS `$fieldAlias`, ";
                } else {
                    $query .= "`$tableName`.`$currentFieldName`, ";
                }
            }
        }

        return substr($query, 0, -2);
    }

    /**
     * @return string
     */
    protected function getJoinPart(): string
    {
        $query = '';

        foreach (static::JOIN_LIST as $joinKey => $joinName) {
            if (empty($this->tableList[$joinKey])) {
                continue;
            }

            foreach ($this->tableList[$joinKey] as $table => $joinList) {
                $tableName = addslashes($table);
                $query .= " $joinName `$tableName` ON ";

                foreach ($joinList as $field => $join) {
                    if (is_array($join)) {
                        $currentFieldName = addslashes($field);
                        $joinTableName = array_key_first($join);
                        $joinFieldName = array_shift($join);
                        $query .= "`$tableName`.`$currentFieldName` = `$joinTableName`.`$joinFieldName` AND ";
                    } else if (is_string($join)) {
                        $currentFieldName = addslashes($join);
                        $query .= "`$tableName`.`$currentFieldName` = ? AND ";
                    }
                }

                $query = substr($query, 0, -5);
            }
        }

        return $query;
    }

    /**
     * @param array $criteriaList
     * @return string
     */
    protected function getWherePart(array $criteriaList): string
    {
        if (empty($criteriaList)) {
            return '';
        }

        $query = ' WHERE ';

        foreach ($criteriaList as $fieldName => $valueList) {
            $fieldName = addslashes($fieldName);

            if (!is_array($valueList)) {
                $valueList = [$valueList];
            }

            $query .= "`{$this->mainTableName}`.`$fieldName` IN (";

            foreach ($valueList as $value) {
                $query .= "\"". addslashes($value) ."\", ";
            }

            $query = substr($query, 0, -2) . ") ";
        }

        return substr($query, 0, -1);
    }

    /**
     * @param array $data
     * @return array|null
     */
    protected function getCleanAttributes(array $data): ?array
    {
        $databaseFieldList = $this->getFieldList();

        // Remove fields that do not exist in fields list.
        foreach ($data as $attributeName => $attributeValue) {
            $found = false;

            foreach ($databaseFieldList as $tableName => $fieldList) {
                if (is_string($attributeName)
                    && (in_array($attributeName, array_column(array_values($fieldList), "Field"))
                        || array_key_exists($attributeName, $this->tableList['field_list'][$tableName])
                        || in_array($attributeName, $this->tableList['field_list'][$tableName]))
                ) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                unset($data[$attributeName]);
            }
        }

        // Check if all required fields are present.
        foreach ($databaseFieldList as $tableName => $fieldList) {
            foreach ($fieldList as $field) {
                $fieldName = $field["Field"];
                $aliasName = array_search($fieldName, $this->tableList['field_list'][$tableName]);
                $fieldName = is_string($aliasName) ? $aliasName : $fieldName;

                if ($field['Null'] === 'YES' || $field['Key'] === "PRI" || !is_null($field['Default'])) {
                    continue;
                }

                if (!in_array($fieldName, array_keys($data))) {
                    return null;
                }
            }
        }

        return $data;
    }
}
