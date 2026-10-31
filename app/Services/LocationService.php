<?php

namespace App\Services;

use App\Exceptions\BusinessRule;
use App\Models\Location;

class LocationService
{
    public function resolve(mixed $id = null, bool $activeRequired = false, bool $lock = false): Location
    {
        $query = Location::query()->when($lock, fn ($builder) => $builder->lockForUpdate());
        if ($id === null) {
            $location = $query->where('active', true)->orderBy('id')->first();
            if ($location === null) {
                throw new BusinessRule('No hay una sede activa. Configura una sede antes de continuar.');
            }
        } else {
            if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new BusinessRule('Selecciona una sede válida.', 400);
            }
            $location = $query->findOrFail((int) $id);
        }
        if ($activeRequired && ! $location->active) {
            throw new BusinessRule('Esta sede está inactiva. Puedes consultar su historial y completar pedidos existentes, pero no crear operaciones nuevas.');
        }

        return $location;
    }
}
