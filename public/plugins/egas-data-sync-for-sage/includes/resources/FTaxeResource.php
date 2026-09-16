<?php

declare(strict_types=1);

namespace Egas\resources;

class FTaxeResource implements Resource
{
    use ResourceTrait;

    public const ENTITY_NAME = 'fTaxes';

    public function selectionSet(array $options = []): array
    {
        return [
            ...$this->formatOperationFilterInput('StringOperationFilterInput', [
                'taIntitule',
                'taCode',
            ]),
            ...$this->formatOperationFilterInput('DecimalOperationFilterInput', [
                'taTaux',
            ]),
            ...$this->formatOperationFilterInput('IntOperationFilterInput', [
                'taTtaux',
                'taNp',
            ]),
        ];
    }

    /**
     * Shared "price + taxes" fragment, nested inside FArticle and PExpedition selection sets.
     */
    public function priceSelectionSet(): array
    {
        return [
            ...$this->formatOperationFilterInput('DecimalOperationFilterInput', [
                'priceHt',
                'priceTtc',
            ]),
            'taxes' => [
                ...$this->formatOperationFilterInput('DecimalOperationFilterInput', [
                    'amount',
                ]),
                ...$this->formatOperationFilterInput('IntOperationFilterInput', [
                    'taxeNumber',
                ]),
                'fTaxe' => $this->selectionSet(),
            ],
        ];
    }
}
