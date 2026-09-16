<?php

declare(strict_types=1);

namespace Egas\resources;

class FPaysResource implements Resource
{
    use ResourceTrait;

    public const ENTITY_NAME = 'fPays';

    public function selectionSet(array $options = []): array
    {
        return [
            ...$this->formatOperationFilterInput('StringOperationFilterInput', [
                'paIntitule',
                'paCode',
            ]),
        ];
    }
}
