<?php

declare(strict_types=1);

namespace Difra\Unify;

use Difra\Unify\Stubs\DbExceptionStub;

abstract class DbObject
{
    /** Called before create or update (e.g. object validation) */
    abstract protected function beforeSave(): void;

    /** Called after new object is created in the database */
    abstract protected function afterCreate(): void;

    /** Called after an object is modified in the database */
    abstract protected function afterUpdate(): void;

    /** Called before an object property is modified */
    abstract protected function beforeSet(string $name, mixed $oldValue, mixed $newValue): void;

    /** Called after and object property is modified */
    abstract protected function afterSet(string $name, mixed $oldValue, mixed $newValue): void;

    /** @var int|null int auto_increment */
    #[DbField(readonly: true, uniqueCache: true)]
    protected ?int $id;

    protected array $loadedFields = [];
    protected array $modifiedFields = [];

    public function __construct(protected readonly DbTable $table)
    {
    }

    /**
     * @codeCoverageIgnore
     */
    public function __destruct()
    {
        if ($this->table->properties->autoSave) {
            try {
                $this->save();
            } catch (\Exception) {
            }
        }
    }

    /**
     * Prevent cloning
     * @codeCoverageIgnore
     */
    protected function __clone()
    {
    }

    /**
     * Create an object from the database data
     * @param \Difra\Unify\DbTable $table
     * @param array $row
     * @return static
     * @throws \Difra\Unify\Exception
     */
    protected static function load(DbTable $table, array $row): static
    {
        if ($existing = $table->getObjectMap($row['id'])) {
            $existing->updateCache();
            $existing->updateObjectMap();
            return $existing;
        }
        $obj = new static($table);
        foreach ($row as $field => $value) {
            if (!empty($table->fields[$field])) {
                $obj->$field = $value;
                $obj->loadedFields[$field] = true;
            }
        }
        $obj->updateCache();
        $obj->updateObjectMap();
        return $obj;
    }

    /**
     * @throws \Difra\Unify\Exception
     */
    public function save(): void
    {
        if (empty($this->modifiedFields)) {
            return;
        }
        $this->beforeSave();

        try {
            $update = isset($this->id);

            $setStr = [];
            $parameters = [];
            $db = $this->table->db;
            foreach ($this->table->fields as $name => $dbField) {
                $name = $db->escape($name);
                if (!empty($this->modifiedFields[$name])) {
                    $parameters[$name] = $this->$name ?? null;
                    $setStr[] = "`name`=:$name";
                } elseif (!$update && $dbField->createValue) {
                    $setStr[] = "`$name`=$dbField->createValue";
                } elseif ($update && $dbField->updateValue) {
                    $setStr[] = "`$name`=$dbField->updateValue";
                }
            }
            $setStr = implode(',', $setStr);

            if (!$update) {
                $db->query("INSERT INTO `{$db->escape($this->table->table)}` SET $setStr", $parameters);
                $this->id = $db->getLastId();
                $this->afterCreate();
            } else {
                $parameters['id'] = $this->id;
                $db->query("UPDATE `{$db->escape($this->table->table)}` SET $setStr WHERE `id`=:id", $parameters);
                if ($db->getAffectedRows()) {
                    $this->afterUpdate();
                }
            }
            $this->modifiedFields = [];
            $this->updateCache();
            $this->updateObjectMap();
        } catch (DbExceptionStub $exception) {
            $this->modifiedFields = [];
            throw new Exception(
                message: "DbObject::save() database error: " . $exception->getMessage(),
                previous: $exception,
                context: $this,
                log: $this->table->dbLog
            );
        }
    }

    public function isModified(): bool
    {
        return !empty($this->modifiedFields);
    }

    public function unsetModified(): static
    {
        $this->modifiedFields = [];
        return $this;
    }

    /**
     * @param \Difra\Unify\DbTable $table
     * @param string $name
     * @param mixed $value
     * @param bool $fullLoad Load object with deferred fields
     * @return static|null
     * @throws \Difra\Unify\Exception
     */
    public static function getByField(
        DbTable $table,
        string $name,
        mixed $value,
        bool $fullLoad = false
    ): ?static {
        $fieldProps = $table->fields[$name] ?? null;
        if (!$fieldProps) {
            throw new Exception(
                message: "DbObject $table->table has no property $name",
                log: $table->dbLog
            );
        }

        // try to get from cache
        if ($cached = static::getFromCache($table, $name, $value)) {
            return $cached;
        }

        // get from the database
        try {
            $loadProps = $fullLoad ? null : self::getDefaultProps($table);
            $row = self::getSqlRow($table, $name, $value, $loadProps);
        } catch (DbExceptionStub $exception) {
            throw new Exception(
                message: "DbObject::getByField() database error: " . $exception->getMessage(),
                previous: $exception,
                log: $table->dbLog
            );
        }
        if (empty($row)) {
            return null;
        }
        return static::load($table, $row);
    }

    public static function getDefaultProps(DbTable $table): array
    {
        $loadProps = [];
        foreach ($table->fields as $fieldName => $fieldProps) {
            if (!$fieldProps->defer) {
                $loadProps[] = $table->db->escape($fieldName);
            }
        }
        return $loadProps;
    }

    public const SORT_ASC = 'ASC';
    public const SORT_DESC = 'DESC';

    /**
     * @throws \Difra\Unify\Exception
     */
    public static function getList(DbTable $table, string $sortField = 'id', string $sortDir = self::SORT_ASC, ?int $offset = null, ?int $rows = null): array
    {
        if (!isset($table->fields[$sortField])) {
            throw new Exception(
                message: "DbObject::getList() undefined field '$sortField' used for sortField",
                log: $table->dbLog
            );
        }
        if ($sortDir !== self::SORT_ASC && $sortDir !== self::SORT_DESC) {
            throw new Exception(
                message: "DbObject::getList() invalid sort direction",
                log: $table->dbLog
            );
        }
        $sort = " ORDER BY `$sortField` $sortDir ";

        if ($offset !== null && $rows !== null) {
            $limit = " LIMIT '$offset','$rows' ";
        } elseif ($offset !== null) {
            $limit = " LIMIT '$offset' ";
        } elseif ($rows !== null) {
            $limit = " LIMIT '0','$rows' ";
        } else {
            $limit = '';
        }

        $props = self::getDefaultProps($table);
        $props = empty($props) ? '*' : ('`' . implode ('`,`', $props) . '`');
        try {
            $data = $table->db->fetch("SELECT $props FROM `$table->table`$sort$limit");
        } catch (DbExceptionStub $exception) {
            throw new Exception(
                message: "DbObject::getList() database error: " . $exception->getMessage(),
                previous: $exception,
                log: $table->dbLog
            );
        }
        $result = [];
        foreach ($data as $row) {
            $result[] = static::load($table, $row);
        }
        return $result;
    }

    /**
     * Load all deferred fields
     * @throws \Difra\Unify\Exception
     */
    public function loadDeferred(): void
    {
        $loadProps = [];
        foreach ($this->table->fields as $fieldName => $field) {
            if (empty($this->loadedFields[$fieldName])) {
                $loadProps[] = $fieldName;
            }
        }
        if (empty($loadProps)) {
            return;
        }
        $row = self::getSqlRow($this->table, 'id', $this->getId(), $loadProps);
        foreach ($row as $newField => $newValue) {
            $this->$newField = $newValue;
            $this->loadedFields[$newField] = true;
        }
    }

    /**
     * @throws \Difra\Unify\Exception
     */
    private static function getSqlRow(DbTable $table, string $field, mixed $value, ?array $properties): ?array
    {
        try {
            $db = $table->db;
            if (empty($properties)) {
                $propertiesList = '*';
            } else {
                foreach ($properties as &$property) {
                    $property = $db->escape($property);
                }
                $propertiesList = '`' . implode('`,`', $properties) . '`';
            }
            $tableName = $db->escape($table->table);
            $field = $db->escape($field);
            return $db->fetchRow("SELECT $propertiesList FROM `$tableName` WHERE `$field`=:$field", [$field => $value]);
        } catch (DbExceptionStub $exception) {
            throw new Exception(
                message: 'DbObject::getSqlRow() database error: ' . $exception->getMessage(),
                previous: $exception,
                log: $table->dbLog
            );
        }
    }

    /**
     * @throws \Difra\Unify\Exception
     */
    public function getId(): ?int
    {
        $this->save();
        return $this->id;
    }

    /**
     * @throws \Difra\Unify\Exception
     */
    public function __set(string $name, mixed $value): void
    {
        if (!isset($this->table->fields[$name])) {
            throw new Exception(
                message: "DbObject {$this->table->table} has no property $name",
                context: $this,
                log: $this->table->dbLog
            );
        } elseif ($this->table->fields[$name]->readonly) {
            throw new Exception(
                message: "DbObject {$this->table->table} property $name is read only",
                context: $this,
                log: $this->table->dbLog
            );
        } else {
            if ($this->table->fields[$name]->toLower) {
                $value = strtolower($value);
            }
            if (!isset($this->$name) || $this->$name !== $value) {
                $this->setField($name, $value);
            }
        }
    }

    public function __isset(string $name): bool
    {
        return $this->loadedFields[$name] ?? false;
    }

    protected function setField(string $name, mixed $value): void
    {
        $oldValue = $this->$name ?? null;
        $this->beforeSet($name, $oldValue, $value);

        // remove field from cache
        $prefix = $this->table->fields[$name]->cachePrefix;
        if ($oldValue && $prefix && $this->table->fields[$name]->uniqueCache) {
            $this->removeObjectMap($name, $oldValue);
            $this->table->cache?->remove($prefix . $oldValue);
        }

        // set value
        $this->$name = $value;
        $this->modifiedFields[$name] = true;
        $this->afterSet($name, $oldValue, $value);
    }

    /**
     * @throws \Difra\Unify\Exception
     */
    public function __get(string $name): mixed
    {
        if (!isset($this->table->fields[$name])) {
            throw new Exception(
                message: "DbObject {$this->table->table} has no property $name",
                context: $this,
                log: $this->table->dbLog
            );
        }
        // deferred load
        if (empty($this->loadedFields[$name])) {
            $db = $this->table->db;
            try {
                $this->$name = $db->fetchOne(
                    'SELECT `' . $db->escape($name) . '` FROM `' . $db->escape(
                        $this->table->table
                    ) . '` WHERE `id`=:id',
                    ['id' => $this->getId()]
                );
                $this->loadedFields[$name] = true;
                $this->updateCache();
                $this->updateObjectMap();
            } catch (DbExceptionStub $exception) {
                throw new Exception(
                    message: 'DbObject::_get() database exception: ' . $exception->getMessage(),
                    previous: $exception,
                    context: $this,
                    log: $this->table->dbLog
                );
            }
        }
        return $this->$name ?? null;
    }

    /**
     * @throws \Difra\Unify\Exception
     */
    protected function forceWrite(string $name, mixed $value = null, bool $rawValue = false): void
    {
        $this->save();
        $db = $this->table->db;
        try {
            $safeName = $db->escape($name);
            if (is_null($value)) {
                $setValue = 'NULL';
            } else {
                $setValue = $rawValue ? $value : "'{$db->escape($value)}'";
            }
            $db->query(
                "UPDATE `{$db->escape($this->table->table)}` SET `$safeName`=$setValue WHERE `id`=:id",
                ['id' => $this->getId()]
            );
            $this->loadedFields[$name] = false; // force property re-fetch if value is requested
        } catch (DbExceptionStub $exception) {
            throw new Exception(
                message: "DbObject::forceWrite() database error: " . $exception->getMessage(),
                previous: $exception,
                context: $this,
                log: $this->table->dbLog
            );
        }
    }

    /**
     *
     * Cache
     *
     */

    /**
     * @throws \Difra\Unify\Exception
     */
    protected function updateCache(): void
    {
        if (!$this->table->cache) {
            return;
        }
        foreach ($this->table->fields as $name => $field) {
            if (!empty($this->loadedFields[$name]) && $field->uniqueCache && $field->cachePrefix
                && !is_null($this->$name) && $this->$name !== '') {
                $this->table->cache->put(
                    $field->cachePrefix . $this->$name,
                    $name === 'id' ? serialize($this) : $this->getId()
                );
            }
        }
    }

    /*
    protected function invalidateCache()
    {
        if (!$this->table->cache) {
            return;
        }
        foreach ($this->table->fields as $name => $field) {
            if ($field->uniqueCache && $field->cachePrefix) {
                $this->table->cache->remove($field->cachePrefix . $this->$name);
            }
        }
    }
    */

    protected static function getFromCache(DbTable $table, string $fieldName, mixed $fieldValue): ?static
    {
        if (!$table->cache) {
            return null;
        }
        $field = $table->fields[$fieldName];
        if (!$field->cachePrefix || !$field->uniqueCache) {
            return null;
        }
        if (!$cachedId = $table->cache->get($field->cachePrefix . $fieldValue)) {
            return null;
        }
        if ($fieldName !== 'id') {
            return static::getFromCache($table, 'id', $cachedId);
        }
        /** @var static $cached */
        $cached = unserialize($cachedId);
        $cached->setTable($table);
        return $cached;
    }

    /**
     *
     * Serialize logic
     *
     */

    /**
     * @return array
     */
    public function __serialize(): array
    {
        $result = ['loadedFields' => []];
        foreach ($this->loadedFields as $fieldName => $one) {
            if ($this->table->fields[$fieldName]->cache) {
                $result[$fieldName] = $this->$fieldName;
                $result['loadedFields'][$fieldName] = true;
            }
        }
        return $result;
    }

    public function __unserialize(array $data): void
    {
        foreach ($data as $key => $value) {
            $this->$key = $value;
        }
    }

    private function setTable(DbTable $table)
    {
        $this->table = $table;
        $this->updateObjectMap();
    }

    private function updateObjectMap(): void
    {
        foreach ($this->table->fields as $field => $props) {
            $value = $this->$field ?? null;
            if (!is_null($value) && $props->uniqueCache) {
                $this->table->updateObjectMap($field, $value, $this);
            }
        }
    }

    private function removeObjectMap(string $field, mixed $value = null): void
    {
        if (!$value) {
            return;
        }
        $this->table->updateObjectMap($field, $value, null);
    }
}