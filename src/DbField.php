<?php

declare(strict_types=1);

namespace Difra\Unify;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class DbField
{
    public readonly string $cachePrefix;
    public readonly string $tableName;
    public readonly string $fieldName;

    public function __construct(
        public readonly bool $readonly = false,
        public readonly ?string $createValue = null,
        public readonly ?string $updateValue = null,
        public readonly bool $uniqueCache = false,
        public readonly bool $defer = false,
        public readonly bool $cache = true,
        public readonly bool $toLower = false
    ) {
    }

    public function configure(DbTable $table, string $fieldName)
    {
        $this->tableName = $table->table;
        $this->fieldName = $fieldName;
        $this->cachePrefix = "$this->tableName:$fieldName:";
    }
}