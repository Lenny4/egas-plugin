<?php

declare(strict_types=1);

namespace Egas\resources;

class FCatalogueResource implements Resource
{
    use ResourceTrait;

    public const ENTITY_NAME = 'fCatalogues';

    public function selectionSet(array $options = []): array
    {
        return [
            ...$this->formatOperationFilterInput('IntOperationFilterInput', [
                'clNo',
                'clNoParent',
                'clNiveau',
            ]),
            ...$this->formatOperationFilterInput('StringOperationFilterInput', [
                'clIntitule',
                'clCode',
            ]),
        ];
    }
}
