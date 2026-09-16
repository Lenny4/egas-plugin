<?php

declare(strict_types=1);

namespace Egas\resources;

class PDossierResource implements Resource
{
    use ResourceTrait;

    public const ENTITY_NAME = 'pDossiers';

    public function selectionSet(array $options = []): array
    {
        return [
            ...$this->formatOperationFilterInput('StringOperationFilterInput', ['dRaisonSoc']),
            'nDeviseCompteNavigation' => [
                ...$this->formatOperationFilterInput('StringOperationFilterInput', ['dCodeIso']),
            ],
        ];
    }
}
