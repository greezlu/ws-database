<?php

namespace WebServer\Database;

use WebServer\Abstracts\DatabaseAbstract;
use WebServer\Abstracts\DataAbstract;
use WebServer\Exceptions\LocalizedException;

/**
 * @package ws-database
 */
class Queue
{
    protected const MAX_QUERY_STACK = 50000;

    /**
     * @var DatabaseAbstract
     */
    private DatabaseAbstract $mysql;

    /**
     * @param DatabaseAbstract $mysql
     */
    public function __construct (DatabaseAbstract $mysql) {
        $this->mysql = $mysql;
    }

    /**
     * @var array
     */
    private array $data = [];

    /**
     * Adding entity to selected queue for future request.
     *
     * @param string $tableName Name of the table in database.
     * @param string $type Type of the queue. insert|remove|update
     * @param array $entity Entity with keys as fields names and values or null.
     * @throws LocalizedException
     */
    public function addToQueue(
        string $tableName,
        string $type,
        array $entity
    ) :void {
        $this->data[$tableName][$type] = $entity;

        $queueSize = count($this->data[$tableName][$type], COUNT_RECURSIVE);

        if ($queueSize >= static::MAX_QUERY_STACK) {
            $this->flushQueue($tableName, $type);
        }
    }

    /**
     * Sending selected queue to database and clear queue afterwards.
     * @TODO Test
     *
     * @param string $tableName Name of the table in database.
     * @param string|null $type Type of the queue.
     * @throws LocalizedException
     */
    public function flushQueue (
        string $tableName,
        string $type = NULL
    ) :void {
        if ((empty($this->data[$tableName]))
            || (!is_null($type) && empty($this->data[$tableName][$type]))
        ) {
            return;
        }

        if (!$type) {
            foreach ($this->data[$tableName] as $currentType => $itemList) {
                $this->flushQueue($tableName, $currentType);
            }

            $this->data[$tableName] = [];
            return;
        }

        $method = $type . 'Array';

        if (!method_exists($this->mysql, $method)) {
            throw new LocalizedException("Undefined queue to flush: [$tableName] [$type].");
        }

        $tableItemList = $this->data[$tableName];
        $itemList = $tableItemList[$type] ?? [];

        $offset = 0;

        while ($queryItemList = array_slice($itemList, $offset, static::MAX_QUERY_STACK)) {
            $this->mysql->$method($tableName, $queryItemList, false, true);
            $offset += static::MAX_QUERY_STACK;
        }

        unset($tableItemList[$type]);

        if (!empty($tableItemList)) {
            unset($this->data[$tableName][$type]);
        } else {
            unset($this->data[$tableName]);
        }
    }
}
