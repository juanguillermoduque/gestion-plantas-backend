<?php

use App\Exceptions\BusinessRule;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = $app->make('db')->connection();
if (! $app->environment('testing') || $app->make('config')->get('app.env') !== 'testing' || $connection->getDriverName() !== 'pgsql' || $connection->getDatabaseName() !== 'vivero_test') {
    throw new LogicException('Prueba de concurrencia bloqueada: solo se permite APP_ENV=testing y PostgreSQL vivero_test.');
}

try {
    $app->make(OrderService::class)->create(['customerName' => 'Reserva simultánea '.($argv[2] ?? ''), 'dueDate' => '2026-12-15', 'status' => 'confirmed', 'items' => [['productId' => 'mini-jardin', 'quantity' => 50]]], User::findOrFail((int) $argv[1]));
    exit(0);
} catch (BusinessRule $exception) {
    exit($exception->status === 409 ? 9 : 10);
}
