<?php

declare(strict_types=1);

namespace Egas\class\term;

use Egas\Sage;
use WC_Product;

// like class WC_Product_Simple
class WC_Product_Egas extends WC_Product
{
    public function get_type()
    {
        return Sage::TOKEN;
    }
}
