<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Los tests unitarios de modelos también necesitan la app arrancada: los
// casts de Eloquent (fechas, enums) resuelven config del container.
pest()->extend(TestCase::class)->in('Unit');
