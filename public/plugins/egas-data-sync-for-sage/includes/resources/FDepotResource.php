<?php

declare(strict_types=1);

namespace Egas\resources;

class FDepotResource implements Resource
{
    use ResourceTrait;

    public const ENTITY_NAME = 'fDepots';

    public function selectionSet(array $options = []): array
    {
        return [
            ...$this->formatOperationFilterInput('StringOperationFilterInput', [
                'deIntitule',
            ]),
            ...$this->formatOperationFilterInput('IntOperationFilterInput', [
                'deNo',
            ]),
        ];
    }
}
