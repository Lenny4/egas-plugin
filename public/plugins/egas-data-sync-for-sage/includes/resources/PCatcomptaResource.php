<?php

declare(strict_types=1);

namespace Egas\resources;

use Egas\utils\PCatComptaUtils;

class PCatcomptaResource implements Resource
{
    use ResourceTrait;

    public const ENTITY_NAME = 'pCatcomptas';

    public function selectionSet(array $options = []): array
    {
        $result = [];
        foreach (PCatComptaUtils::ALL_TIERS_TYPE as $t) {
            $result = [
                ...$result,
                ...$this->formatOperationFilterInput('StringOperationFilterInput', [
                    ...array_map(static fn(int $number): string => 'caCompta' . $t . str_pad((string)$number, 2, '0', STR_PAD_LEFT), range(1, PCatComptaUtils::NB_TIERS_TYPE)),
                ]),
            ];
        }
        return $result;
    }

    /**
     * Shared "nCatCompta" fragment, nested inside FArticle and PExpedition selection sets.
     */
    public function nCatComptaSelectionSet(): array
    {
        return [
            ...$this->formatOperationFilterInput('IntOperationFilterInput', [
                'cbIndice',
            ]),
        ];
    }
}
