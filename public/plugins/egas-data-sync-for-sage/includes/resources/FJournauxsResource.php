<?php

declare(strict_types=1);

namespace Egas\resources;

class FJournauxsResource implements Resource
{
    use ResourceTrait;

    public const ENTITY_NAME = 'fJournauxes';

    public function selectionSet(array $options = []): array
    {
        return [
            ...$this->formatOperationFilterInput('StringOperationFilterInput', [
                'joNum',
                'joIntitule',
                'joType',
            ]),
        ];
    }
}
