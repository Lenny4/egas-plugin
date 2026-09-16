<?php

declare(strict_types=1);

namespace Egas\resources;

class PCattarifResource implements Resource
{
    use ResourceTrait;

    public const ENTITY_NAME = 'pCattarifs';

    public function selectionSet(array $options = []): array
    {
        return [
            ...$this->formatOperationFilterInput('IntOperationFilterInput', [
                'cbIndice',
            ]),
            ...$this->formatOperationFilterInput('StringOperationFilterInput', [
                'ctIntitule',
            ]),
        ];
    }

    /**
     * Shared "nCatTarif" fragment, nested inside FArticle and PExpedition selection sets.
     */
    public function nCatTarifSelectionSet(): array
    {
        return [
            ...$this->formatOperationFilterInput('IntOperationFilterInput', [
                'cbIndice',
                'ctPrixTtc',
            ]),
        ];
    }
}
