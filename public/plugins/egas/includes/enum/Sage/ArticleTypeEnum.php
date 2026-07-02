<?php

declare(strict_types=1);

namespace Egas\enum\Sage;

enum ArticleTypeEnum: int
{
    case ArticleTypeStandard = 0;
    case ArticleTypeGamme = 1;
    case ArticleTypeRessourceUnitaire = 2;
    case ArticleTypeRessourceMultiple = 3;
}
