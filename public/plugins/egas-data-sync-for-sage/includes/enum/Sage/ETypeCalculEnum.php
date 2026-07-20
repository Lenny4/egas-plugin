<?php

declare(strict_types=1);

namespace Egas\enum\Sage;
enum ETypeCalculEnum: int
{
    case Valeur = 0; // Valeur
    case FixedGrid = 1; // GrilleFixe
    case VariableGrid = 2; // GrilleVariable
}
