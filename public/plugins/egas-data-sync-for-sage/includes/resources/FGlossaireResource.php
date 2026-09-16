<?php

declare(strict_types=1);

namespace Egas\resources;

class FGlossaireResource implements Resource
{
    use ResourceTrait;

    public const ENTITY_NAME = 'fGlossaires';

    public function selectionSet(array $options = []): array
    {
        return [
            ...$this->formatOperationFilterInput('IntOperationFilterInput', [
                'glNo',
                'glDomaine', // 0 -> Article, 1 => document
            ]),
            ...$this->formatOperationFilterInput('StringOperationFilterInput', [
                'glIntitule',
                'glText',
            ]),
        ];
    }
}
