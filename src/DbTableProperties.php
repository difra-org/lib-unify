<?php

declare(strict_types=1);

namespace Difra\Unify;

#[\Attribute(\Attribute::TARGET_CLASS)]
class DbTableProperties
{
    public function __construct(
        public readonly string $table,
        public readonly string $child,
        public readonly bool $autoSave = false
    ) {
    }
}
