<?php

declare(strict_types=1);

namespace Egas\enum\Sage;

enum NomenclatureTypeEnum: int
{
    case NomenclatureTypeAucun = 0;
    case NomenclatureTypeFabrication = 1;
    case NomenclatureTypeCompose = 2;
    case NomenclatureTypeComposant = 3;
    case NomenclatureTypeLies = 4;
}
