<?php

declare(strict_types=1);

namespace Egas\resources;

class PPreferenceResource implements Resource
{
    use ResourceTrait;

    public const ENTITY_NAME = 'pPreferences';

    public function selectionSet(array $options = []): array
    {
        return [
            ...$this->formatOperationFilterInput('IntOperationFilterInput', [
                'prUnitePoids',
            ]),
        ];
    }
}
