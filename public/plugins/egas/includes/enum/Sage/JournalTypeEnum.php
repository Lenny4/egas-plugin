<?php

declare(strict_types=1);

namespace Egas\enum\Sage;

enum JournalTypeEnum: int
{
    case JournalTypeAchat = 0;
    case JournalTypeVente = 1;
    case JournalTypeTresorerie = 2;
    case JournalTypeGeneral = 3;
    case JournalTypeSituation = 4;
}
