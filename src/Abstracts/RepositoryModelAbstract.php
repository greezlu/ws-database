<?php

namespace WebServer\Abstracts;

use WebServer\Interfaces\EntityRepositoryInterface;

/**
 * @package ws-database
 */
abstract class RepositoryModelAbstract extends RepositoryAbstract implements EntityRepositoryInterface
{
    /**
     * @param int $id
     * @return ModelAbstract|null
     */
    public function getItemById(int $id): ?ModelAbstract
    {
        return $this->getItemByField('id', (string)$id);
    }

    /**
     * @param string $fieldName
     * @param string|int|double $fieldValue
     * @return ModelAbstract|null
     */
    public function getItemByField(string $fieldName, $fieldValue): ?ModelAbstract
    {
        try {
            $data = $this->mySQL->select(
                $this->mainTableName,
                [$fieldName => $fieldValue],
                null,
                null,
                1
            );
        } catch (\Exception $error) {
            return null;
        }

        $data = $this->getCleanAttributes($data);

        return $data ? new $this->modelClass($data) : null;
    }

    /**
     * @param array $criteriaList
     * @return ModelAbstract|null
     */
    public function getItemByFieldList(array $criteriaList): ?ModelAbstract
    {
        try {
            $data = $this->mySQL->select(
                $this->mainTableName,
                $criteriaList,
                null,
                null,
                1
            );
        } catch (\Exception $error) {
            return null;
        }

        $data = $this->getCleanAttributes($data);

        return $data ? new $this->modelClass($data) : null;
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
        try {
            $entityList = $this->mySQL->select(
                $this->mainTableName,
                $criteriaList,
                $order,
                $orderDirection,
                $limit
            );
        } catch (\Exception $error) {
            return [];
        }

        $itemsList = [];

        foreach ($entityList as $entity) {
            $data = $this->getCleanAttributes($entity);

            if ($data) {
                $itemsList[] = new $this->modelClass($data);
            }
        }

        return $itemsList;
    }

    /**
     * @param string $fieldName
     * @param string $fieldValue
     * @return ModelAbstract[]
     */
    public function getItemListByField(
        string $fieldName,
        string $fieldValue
    ): array {
        try {
            $entityList = $this->mySQL->select(
                $this->mainTableName,
                [$fieldName => $fieldValue]
            );
        } catch (\Exception $error) {
            return [];
        }

        $itemsList = [];

        foreach ($entityList as $entity) {
            $data = $this->getCleanAttributes($entity);

            if ($data) {
                $itemsList[] = new $this->modelClass($data);
            }
        }

        return $itemsList;
    }

    /**
     * Try to save list of models to database.
     *
     * @param ModelAbstract[] $itemList
     * @return bool
     */
    public function saveAll(array $itemList): bool
    {
        foreach($itemList as $item){
            if (!$item instanceof ModelAbstract) {
                return false;
            }
        }

        foreach($itemList as $item){
            if (!$this->save($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array $data
     * @param bool|null $clean
     * @return ModelAbstract[]|null
     */
    public function createItemArray(array $data, bool $clean = true): ?array
    {
        $itemList = [];

        foreach ($data as $item) {
            $model = $this->createItem($item, $clean);
            if (!is_null($model)) {
                $itemList[] = $model;
            } else {
                return null;
            }
        }

        return $itemList;
    }

    /**
     * @param ModelAbstract $item
     * @return bool
     */
    public function update(ModelAbstract $item): bool
    {
        if (is_null($item->getId())) {
            return false;
        }

        $data = $item->getAllData();

        try {
            return $this->mySQL->updateOne($this->mainTableName, $data);
        } catch (\Exception $error) {
            return false;
        }
    }

    /**
     * @param ModelAbstract[] $itemList
     * @return bool
     */
    public function updateAll(array $itemList): bool
    {
        foreach($itemList as $item){
            if (!$this->update($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Try to save model to database.
     *
     * @param ModelAbstract $item Entity to be saved.
     * @param bool $update On duplicate update.
     * @return bool Result of the insert query.
     */
    public function save(ModelAbstract $item, bool $update = false): bool
    {
        $data = $item->getAllData();

        $data = $this->getCleanAttributes($data);

        if (!$data) {
            return false;
        }

        try {
            return $this->mySQL->insertOne($this->mainTableName, $data, false, $update);
        } catch (\Exception $error) {
            return false;
        }
    }

    /**
     * @param int $id
     * @return bool
     */
    public function removeItemById(int $id): bool
    {
        try {
            return $this->mySQL->removeOne($this->mainTableName, $id);
        } catch (\Exception $error) {
            return false;
        }
    }

    /**
     * @param ModelAbstract $item
     * @return bool
     */
    public function removeItem(ModelAbstract $item): bool
    {
        $id = $item->getData('id');

        if (!is_numeric($id)) {
            return false;
        }

        try {
            return $this->mySQL->removeOne($this->mainTableName, (int)$id);
        } catch (\Exception $error) {
            return false;
        }
    }

    /**
     * Describe query to database.
     *
     * @return array
     */
    protected function getFieldList(): array
    {
        try {
            return $this->mySQL->describe($this->mainTableName) ?: [];
        } catch (\Exception $error) {
            return [];
        }
    }

    /**
     * Clean and check incoming attributes.
     *
     * @param array $data Incoming attributes
     * @return array|null
     */
    protected function getCleanAttributes(array $data): ?array
    {
        $fieldList = $this->getFieldList();

        // Remove fields that do not exist in fields list.
        foreach ($data as $attributeName => $attributeValue) {
            if (!in_array($attributeName, array_column($fieldList, "Field"))) {
                unset($data[$attributeName]);
            }
        }

        // Check if all required fields are present.
        foreach ($fieldList as $field) {
            $fieldName = $field['Field'];

            if ($field["Null"] = 'YES' || $field['Key'] === 'PRI') {
                continue;
            }

            if (!isset($data[$fieldName])) {
                return null;
            }
        }

        return $data;
    }
}
