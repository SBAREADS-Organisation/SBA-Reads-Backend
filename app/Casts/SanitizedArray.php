<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

class SanitizedArray implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if (is_null($value)) {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $this->sanitizeUtf8($decoded) : [];
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return is_null($value) ? null : json_encode($value);
    }

    protected function sanitizeUtf8(mixed $data): mixed
    {
        if (is_string($data)) {
            return mb_convert_encoding($data, 'UTF-8', 'UTF-8');
        }

        if (is_array($data)) {
            return array_map([$this, 'sanitizeUtf8'], $data);
        }

        return $data;
    }
}
