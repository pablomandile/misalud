<?php

declare(strict_types=1);

namespace App\Concerns;

/**
 * Cómo se contacta a un lugar (un centro, una farmacia): dirección, teléfono,
 * WhatsApp, correo, web y horarios. Las mismas reglas y la misma limpieza en
 * los dos formularios, así no se separan.
 */
trait ValidaDatosDeContacto
{
    /**
     * La web se escribe como se la lee ("www.hospital.com.ar"), sin el
     * `https://` que la regla `url` exige: se lo agrega acá, ANTES de validar, o
     * la regla rechazaría lo que la persona escribió bien. El correo, sin
     * espacios y en minúsculas.
     */
    protected function normalizarDatosDeContacto(): void
    {
        $web = $this->input('web');
        $email = $this->input('email');

        if (is_string($web)) {
            $web = trim($web);
            $web = $web === '' ? null : (preg_match('#^https?://#i', $web) ? $web : 'https://'.$web);
        }

        $this->merge([
            'web' => $web,
            'email' => is_string($email) ? (mb_strtolower(trim($email)) ?: null) : $email,
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function reglasDeContacto(): array
    {
        return [
            'direccion' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:50'],
            'whatsapp' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\s.\-]+$/'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'web' => ['nullable', 'string', 'url:http,https', 'max:255'],
            'horarios' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function mensajesDeContacto(): array
    {
        return [
            'whatsapp.regex' => 'El WhatsApp solo puede tener números, espacios, guiones, paréntesis y el +.',
            'web.url' => 'Escribí la dirección de la página, por ejemplo www.hospital.com.ar.',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function atributosDeContacto(): array
    {
        return [
            'direccion' => 'dirección',
            'telefono' => 'teléfono',
            'whatsapp' => 'WhatsApp',
            'email' => 'correo electrónico',
            'web' => 'página web',
            'horarios' => 'horarios de atención',
        ];
    }
}
