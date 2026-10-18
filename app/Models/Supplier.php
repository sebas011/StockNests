<?php

namespace App\Models;

use App\Policies\StockNestPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property bool $is_active
 */
#[UsePolicy(StockNestPolicy::class)]
class Supplier extends DomainModel {}
