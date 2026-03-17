<?php

namespace App\enum\Sage;

enum JournalTypeEnum: int
{
    case JournalTypeAchat = 0;
    case JournalTypeVente = 1;
    case JournalTypeTresorerie = 2;
    case JournalTypeGeneral = 3;
    case JournalTypeSituation = 4;
}
