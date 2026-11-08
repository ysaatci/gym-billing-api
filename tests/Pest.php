<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Feature tests boot the full Laravel app and run against a fresh in-memory
// SQLite database for every test (see phpunit.xml). Unit tests are plain PHP.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
