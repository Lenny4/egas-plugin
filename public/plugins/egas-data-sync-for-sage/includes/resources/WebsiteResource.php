<?php

declare(strict_types=1);

namespace Egas\resources;

class WebsiteResource implements Resource
{
    use ResourceTrait;

    public const ENTITY_NAME = 'websites';

    public function selectionSet(array $options = []): array
    {
        return [
            ...$this->formatOperationFilterInput('StringOperationFilterInput', [
                'idNewOrder',
                'idNewProduct',
                'idNewUser',
            ]),
            ...$this->formatOperationFilterInput('IntOperationFilterInput', [
                'id',
                'cbMarqNewFArticle',
                'cbMarqNewFDocentete',
                'cbMarqNewFComptet',
            ]),
        ];
    }
}
