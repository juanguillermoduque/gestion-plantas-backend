<?php

namespace Tests\Feature;

use App\Models\InventoryBalance;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\ViveroSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrentReservationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_processes_cannot_reserve_the_same_plants(): void
    {
        $this->seed(ViveroSeeder::class);
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        InventoryBalance::findOrFail(1)->update(['unclassified' => 0, 'ready' => 250]);
        $connection = config('database.connections.pgsql');
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'] ?? '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'];
        $processes = [];
        foreach (['A', 'B'] as $label) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/reserve-order.php'), (string) $admin->id, $label], base_path(), $environment, timeout: 30);
            $process->start();
            $processes[] = $process;
        }
        $codes = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertContains($process->getExitCode(), [0, 9], $process->getErrorOutput());
            $codes[] = $process->getExitCode();
        }
        sort($codes);
        $this->assertSame([0, 9], $codes, 'Solo un pedido de 150 plantas puede reservarse con 250 plantas listas.');
        $this->assertSame(1, Order::where('status', 'confirmed')->count());
        $this->assertSame(150, (int) Order::where('status', 'confirmed')->sum('plants_needed'));
        $this->assertSame(250, InventoryBalance::findOrFail(1)->ready);
    }
}
