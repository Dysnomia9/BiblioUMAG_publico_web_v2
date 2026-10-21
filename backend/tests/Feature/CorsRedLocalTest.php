<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * config/cors.php: desde la IP de red local del computador (un celular por WiFi) se
 * llama a la API solo en desarrollo; en producción vale únicamente FRONTEND_URL.
 */
class CorsRedLocalTest extends TestCase
{
    public function test_un_celular_de_la_red_local_llama_a_la_api_solo_en_desarrollo(): void
    {
        $celular = 'http://192.168.1.50:5173';

        $this->assertTrue($this->aceptaOrigen($celular, 'local'));
        $this->assertFalse($this->aceptaOrigen($celular, 'production'));
        $this->assertFalse($this->aceptaOrigen($celular, 'staging'));
    }

    public function test_el_frontend_configurado_se_acepta_siempre(): void
    {
        $this->withHeaders(['Origin' => config('cors.allowed_origins')[0], 'Access-Control-Request-Method' => 'POST'])
            ->options('/api/auth/login')
            ->assertHeader('Access-Control-Allow-Origin', config('cors.allowed_origins')[0]);
    }

    /** Lee config/cors.php como lo haría el servidor con ese APP_ENV. */
    private function aceptaOrigen(string $origen, string $entorno): bool
    {
        [$servidor, $entornoPhp, $proceso] = [$_SERVER['APP_ENV'] ?? null, $_ENV['APP_ENV'] ?? null, getenv('APP_ENV')];
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = $entorno;
        putenv("APP_ENV={$entorno}");

        try {
            $config = require config_path('cors.php');
        } finally {
            if ($servidor === null) {
                unset($_SERVER['APP_ENV']);
            } else {
                $_SERVER['APP_ENV'] = $servidor;
            }
            if ($entornoPhp === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $entornoPhp;
            }
            putenv($proceso === false ? 'APP_ENV' : "APP_ENV={$proceso}");
        }

        return in_array($origen, $config['allowed_origins'], true)
            || collect($config['allowed_origins_patterns'])->contains(fn (string $patron) => preg_match($patron, $origen) === 1);
    }
}
