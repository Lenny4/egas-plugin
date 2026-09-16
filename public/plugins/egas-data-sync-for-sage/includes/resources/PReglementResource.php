<?php

declare(strict_types=1);

namespace Egas\resources;

class PReglementResource implements Resource
{
    use ResourceTrait;

    public const ENTITY_NAME = 'pReglements';

    public function selectionSet(array $options = []): array
    {
        return [
            ...$this->formatOperationFilterInput('StringOperationFilterInput', [
                'rIntitule',
            ]),
            ...$this->formatOperationFilterInput('IntOperationFilterInput', [
                'cbIndice',
            ]),
        ];
    }
}
