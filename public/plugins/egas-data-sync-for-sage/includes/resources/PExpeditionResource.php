<?php

declare(strict_types=1);

namespace Egas\resources;

class PExpeditionResource implements Resource
{
    use ResourceTrait;

    public const ENTITY_NAME = 'pExpeditions';

    public function selectionSet(array $options = []): array
    {
        return [
            ...$this->formatOperationFilterInput('IntOperationFilterInput', [
                'cbIndice',
                'eTypeFrais', // Base de calcul (Montant forfaitaire, quantité DocumentFraisType) // Type des frais d'expédition
                'eTypeCalcul', // Valeur, Grille frais fixe, grille frais variable)
                'eValFrais', // valeur quand eTypeCalcul == 'Valeur'
                'eTypeLigneFrais', // indique si le prix est en HT ou TTC (HT == 0)
            ]),
            ...$this->formatOperationFilterInput('StringOperationFilterInput', [
                'eIntitule',
            ]),
            // 'arRefNavigation' => FArticleResource::getInstance()->selectionSet(), // {"message":"The maximum allowed field cost was exceeded.","extensions":{"code":"HC0047","fieldCost":5963,"maxFieldCost":1000}}
            'arRefNavigation' => [
                ...$this->formatOperationFilterInput('StringOperationFilterInput', [
                    'arRef',
                ]),
                'prices' => [
                    ...FTaxeResource::getInstance()->priceSelectionSet(),
                    'nCatTarif' => [
                        ...PCattarifResource::getInstance()->nCatTarifSelectionSet(),
                    ],
                    'nCatCompta' => [
                        ...PCatcomptaResource::getInstance()->nCatComptaSelectionSet(),
                    ],
                ],
            ],
            'fExpeditiongrilles' => $this->expeditiongrillesSelectionSet(),
        ];
    }

    private function expeditiongrillesSelectionSet(): array
    {
        return [
            ...$this->formatOperationFilterInput('IntOperationFilterInput', [
                'egBorne',
                'egFrais',
            ]),
        ];
    }
}
