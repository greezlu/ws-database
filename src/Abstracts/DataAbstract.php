<?php

namespace WebServer\Abstracts;

/**
 * @package ws-database
 */
abstract class DataAbstract
{
    /**
     * @var array
     */
    private array $data = [];

    /**
     * @param array|null $data
     */
    public function __construct(array $data = null)
    {
        if (is_array($data)) {
            $this->setAllData($data);
        }
    }

    /**
     * @param string $name
     * @return mixed|null
     */
    public function getData(string $name)
    {
        return $this->data[$name] ?? null;
    }

    /**
     * @return array
     */
    public function getAllData(): array
    {
        return $this->data;
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return $this
     */
    public function setData(string $name, $value): DataAbstract
    {
        $this->data[$name] = $value;
        return $this;
    }

    /**
     * @param array $data
     * @return $this
     */
    public function setAllData(array $data): DataAbstract
    {
        $this->data = $data;
        return $this;
    }

    /**
     * @param string $name
     * @return $this
     */
    public function unsetData(string $name): DataAbstract
    {
        if (isset($this->data[$name])) {
            unset($this->data[$name]);
        }
        return $this;
    }

    /**
     * @return void
     * @return $this
     */
    public function unsetAllData(): DataAbstract
    {
        $this->data = [];
        return $this;
    }

    /**
     * @param string $name
     * @param mixed $value
     * @return $this
     */
    public function addData(string $name, $value): DataAbstract
    {
        $currentValue = $this->getData($name);

        if (is_array($currentValue) && is_array($value)) {
            $this->setData($name, array_merge($currentValue, $value));
        } else if (is_array($currentValue)) {
            $this->setData($name, array_merge($currentValue, [$value]));
        } else if (is_null($currentValue)) {
            $this->setData($name, $value);
        } else if (is_array($value)) {
            $this->setData($name, array_merge($value, [$currentValue]));
        }

        return $this;
    }

    /**
     * @param string $name
     * @return bool
     */
    public function hasData(string $name): bool
    {
        return !empty($this->data[$name]);
    }

    /**
     * @return int|null
     */
    public function getId(): ?int
    {
        $id = $this->getData('id');
        return is_numeric($id) ? (int)$id : null;
    }
}
