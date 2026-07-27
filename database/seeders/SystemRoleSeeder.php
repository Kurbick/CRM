<?php

namespace Database\Seeders;

use App\Services\AccessControlSynchronizer;
use Illuminate\Database\Seeder;

class SystemRoleSeeder extends Seeder
{
    public function run(AccessControlSynchronizer $synchronizer): void
    {
        $synchronizer->sync();
    }
}
