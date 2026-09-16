<?php

declare(strict_types=1);

namespace Egas\resources;

class FFamilleResource implements Resource
{
    use ResourceTrait;

    public const ENTITY_NAME = 'fFamilles';

    public function selectionSet(array $options = []): array
    {
        return [
            ...$this->formatOperationFilterInput('StringOperationFilterInput', [
                'faCodeFamille',
                'faIntitule',
            ]),
        ];
    }
}
