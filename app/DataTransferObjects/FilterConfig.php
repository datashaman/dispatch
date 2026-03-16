<?php

namespace App\DataTransferObjects;

use App\Enums\FilterOperator;

readonly class FilterConfig
{
    public function __construct(
        public ?string $id,
        public string $field,
        public FilterOperator $operator,
        public string $value,
    ) {}
}
