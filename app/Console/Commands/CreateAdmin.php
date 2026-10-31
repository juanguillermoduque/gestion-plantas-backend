<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

#[Signature('app:create-admin {email? : Correo de la nueva persona administradora}')]
#[Description('Crea una cuenta administradora con contraseña solicitada de forma oculta')]
class CreateAdmin extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = Str::lower(trim((string) ($this->argument('email') ?? $this->ask('Correo de la persona administradora'))));
        $name = trim((string) $this->ask('Nombre'));
        $password = (string) $this->secret('Contraseña (mínimo 12 caracteres)');
        $confirmation = (string) $this->secret('Repite la contraseña');
        $validator = Validator::make(['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation], ['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:254', 'unique:users,email'], 'password' => ['required', 'string', 'min:12', 'max:200', 'confirmed']]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }
        User::create(['name' => $name, 'email' => $email, 'password' => $password, 'role' => 'admin', 'active' => true]);
        $this->info('Cuenta administradora creada. La contraseña no se guarda en los registros de la aplicación.');

        return self::SUCCESS;
    }
}
