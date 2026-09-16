<?php

declare(strict_types=1);

namespace Egas\resources;

class CbSyslibreResource implements Resource
{
    use ResourceTrait;

    public const ENTITY_NAME = 'cbSysLibres';

    public function selectionSet(array $options = []): array
    {
        return [
            ...$this->formatOperationFilterInput('StringOperationFilterInput', [
                'cbFile',
                'cbName',
            ]),
            ...$this->formatOperationFilterInput('IntOperationFilterInput', [
                'cbLen',
                'cbType',
            ]),
        ];
    }
}
