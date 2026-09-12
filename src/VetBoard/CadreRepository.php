<?php

declare(strict_types=1);

namespace VetBoard;

class CadreRepository extends LookupRepository
{
    protected function tableName(): string
    {
        return 'cadres';
    }
}