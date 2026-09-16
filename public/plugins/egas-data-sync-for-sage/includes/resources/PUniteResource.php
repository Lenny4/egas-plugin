<?php

declare(strict_types=1);

namespace Egas\resources;

class PUniteResource implements Resource
{
    use ResourceTrait;

    public const ENTITY_NAME = 'pUnites';

    public function selectionSet(array $options = []): array
    {
        return [
            ...$this->formatOperationFilterInput('StringOperationFilterInput', [
                'cbIndice',
                'uIntitule',
            ]),
        ];
    }
}
