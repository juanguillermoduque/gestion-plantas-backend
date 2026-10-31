<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use LogicException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $connection = $app->make('db')->connection();

        if (! $app->environment('testing') || $app->make('config')->get('app.env') !== 'testing' || $connection->getDriverName() !== 'pgsql' || $connection->getDatabaseName() !== 'vivero_test') {
            throw new LogicException('Pruebas bloqueadas para proteger los datos: se exige APP_ENV=testing y la conexión PostgreSQL efectiva debe apuntar exactamente a vivero_test. No se ejecutaron los traits de migración o limpieza.');
        }

        return $app;
    }
}
