<?php

namespace Owise\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Owise\Concerns\Payable;

class Freelancer extends Model
{
    use Payable;

    protected $table = 'freelancers';

    protected $guarded = [];
}
