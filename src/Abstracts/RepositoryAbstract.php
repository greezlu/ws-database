<?php

namespace WebServer\Abstracts;

/**
 * @package ws-database
 */
abstract class RepositoryAbstract
{
    /**
     * @var DatabaseAbstract
     */
    protected DatabaseAbstract $mySQL;

    /**
     * Class name for model.
     * @var string
     */
    public string $modelClass;

    /**
     * Name of the related table in database.
     * @var string
     */
    public string $mainTableName;

    /**
     * @param DatabaseAbstract $mySQL
     */
    public function __construct(
        DatabaseAbstract $mySQL
    ) {
        $this->mySQL = $mySQL;
    }

    /**
     * @param array|null $criteriaList
     * @param string|null $order
     * @param string|null $orderDirection
     * @param int|null $limit
     * @return DataAbstract[]
     */
    abstract public function getItemList(
        array $criteriaList = [],
        string $order = null,
        string $orderDirection = null,
        int $limit = null
    ): array;

    /**
     * @param int|null $offset
     * @param int|null $amount
     * @return array
     */
    public function getItemIdList(int $offset = null, int $amount = null): array
    {
        $query = "SELECT `id` FROM `{$this->mainTableName}`";

        $query .= is_int($offset) && is_int($amount)
            ? " LIMIT $offset, $amount;"
            : ';';

        try {
            $result = $this->mySQL
                    ->send($query)
                    ->fetch_all(MYSQLI_ASSOC) ?: [];;
        } catch (\Exception $error) {
            return [];
        }

        foreach ($result as $key => &$row) {
            if (!is_array($row)) {
                array_splice($result, $key, 1);
                continue;
            }

            $value = array_values($row)[0];

            if (!is_numeric($value)) {
                array_splice($result, $key, 1);
                continue;
            }

            $row = (int)$value;
        }

        return $result;
    }

    /**
     * @param array $data
     * @param bool $clean
     * @return ModelAbstract|null
     */
    public function createItem(array $data, bool $clean = true): ?ModelAbstract
    {
        $data = $clean ? $this->getCleanAttributes($data) : $data;
        return !empty($data) ? new $this->modelClass($data) : null;
    }

    /**
     * Get table name.
     *
     * @return string
     */
    public function getTableName(): string {
        return $this->mainTableName;
    }

    /**
     * @param array $data
     * @return array|null
     */
    abstract protected function  getCleanAttributes(array $data): ?array;

    /**
     * @return array
     */
    abstract protected function getFieldList(): array;
}
