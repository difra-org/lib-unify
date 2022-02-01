<?php

declare(strict_types=1);

namespace Difra\Unify;

use Difra\Unify\Stubs\CacheStub;
use Difra\Unify\Stubs\DbStub;
use Difra\Unify\Stubs\LogStub;
use Difra\Unify\User;

abstract class DbTable
{
    public readonly string $child;
    public readonly string $table;

    /** @var \Difra\Unify\DbField[] */
    public readonly array $fields;
    /** @var \Difra\Unify\DbTableProperties */
    public readonly mixed $properties;
    /** @var \WeakReference[][] */
    protected array $objectMap = [];

    /**
     * @throws \ReflectionException
     */
    public function __construct(
        public readonly DbStub $db,
        public readonly ?CacheStub $cache = null,
        public readonly ?LogStub $dbLog = null
    ) {
        $this->initProperties();
        $this->initFields();
    }

    protected function initProperties()
    {
        $reflection = new \ReflectionClass($this);
        $attributes = $reflection->getAttributes(DbTableProperties::class);
        if (!empty($attributes)) {
            $this->properties = $attributes[0]->newInstance();
        }
        $this->table = $this->properties->table;
        $this->child = $this->properties->child;
    }

    /**
     * @throws \ReflectionException
     */
    protected function initFields()
    {
        $fields = [];
        $reflection = new \ReflectionClass($this->child);
        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(DbField::class);
            if (empty($attributes)) {
                continue;
            }
            $fieldName = $property->getName();
            $fields[$fieldName] = $fieldProps = $attributes[0]->newInstance();
            $fields[$fieldName]->configure($this, $property->name);

            // init objectMap
            if ($fieldProps->uniqueCache) {
                $this->objectMap[$fieldName] = [];
            }
        }
        $this->fields = $fields;
    }

    public function create(): DbObject
    {
        return new $this->child(...['table' => $this]);
    }

    public function getById(int $id, bool $fullLoad = false): ?DbObject
    {
        return $this->getByField('id', $id, $fullLoad);
    }

    protected function getByField(string $name, mixed $value, bool $fullLoad = false): ?DbObject
    {
        if ($this->fields[$name]->toLower) {
            $value = strtolower($value);
        }
        /** @var ?\Difra\Unify\DbObject $val */
        if (isset($this->objectMap[$name][$value]) && $val = $this->objectMap[$name][$value]->get()) {
            return $val;
        }
        return call_user_func_array([$this->child, 'getByField'], ['table' => $this, 'name' => $name, 'value' => $value, 'fullLoad' => $fullLoad]);
    }

    public function getList(string $sortField = 'id', string $sortDir = DbObject::SORT_ASC, ?int $offset = null, ?int $rows = null): array
    {
        return call_user_func_array(
            [$this->child, 'getList'],
            ['table' => $this, 'sortField' => $sortField, 'sortDir' => $sortDir, $offset, $rows]
        );
    }

    public function updateObjectMap(string $field, mixed $value, ?DbObject $object)
    {
        if (!isset($this->objectMap[$field])) {
            return;
        }
        if ($this->fields[$field]->toLower) {
            $value = strtolower($value);
        }
        if ($object) {
            if (!isset($this->objectMap[$field][$value]) || !$this->objectMap[$field][$value]->get()) {
                $this->objectMap[$field][$value] = \WeakReference::create($object);
            }
        } else {
            unset($this->objectMap[$field][$value]);
        }
    }

    public function getObjectMap(int $id): ?DbObject
    {
        return isset($this->objectMap['id'][$id]) ? $this->objectMap['id'][$id]->get() : null;
    }
}