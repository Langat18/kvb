<?php

declare(strict_types=1);

namespace VetBoard;

class EventModeRepository extends LookupRepository
{
    protected function tableName(): string
    {
        return 'event_modes';
    }
}