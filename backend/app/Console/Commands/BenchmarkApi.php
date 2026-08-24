<?php

namespace App\Console\Commands;

use App\Models\Usuario;
use App\Support\Rut;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class BenchmarkApi extends Command
{
    protected $signature = 'benchmark:api
        {--host=http://localhost:8000 : URL base del backend a medir (ej: http://localhost:8000)}
        {--n=100 : Cantidad de requests por endpoint}
        {--email=admin@umag.cl : Email de staff para autenticar}
        {--password=admin123 : Password de staff para autenticar}';

    protected $description = 'Mide la latencia de la API (promedio, mediana, p95, máximo) sobre los endpoints '
        .'GET /api/usuarios, POST /api/entrada y GET /api/reportes/resumen, para el Cuadro 10.2 de la tesis.';

    public function handle(): int
    {
        $host = rtrim($this->option('host'), '/');
        $n = max(1, (int) $this->option('n'));

        $this->info("Autenticando como staff contra {$host}...");
        $login = Http::post("{$host}/api/auth/login", [
            'email' => $this->option('email'),
            'password' => $this->option('password'),
        ]);

        if (! $login->successful()) {
            $this->error("No se pudo autenticar ({$login->status()}): {$login->body()}");

            return self::FAILURE;
        }

        $token = $login->json('token');
        $client = Http::withToken($token)->baseUrl($host);

        $benchmarkUsuario = $this->crearUsuarioDeBenchmark();

        $this->info("Ejecutando {$n} requests por endpoint...");

        $resultados = [
            'GET /api/usuarios' => $this->medir($n, fn () => $client->get('/api/usuarios')),
            'POST /api/entrada' => $this->medirEntrada($n, $client, $benchmarkUsuario),
            'GET /api/reportes/resumen' => $this->medir($n, fn () => $client->get('/api/reportes/resumen')),
        ];

        // entradas.usuario_id es RESTRICT (ver CLAUDE.md) — hay que borrar las
        // entradas de prueba generadas por medirEntrada() antes que el usuario.
        $benchmarkUsuario->entradas()->delete();
        $benchmarkUsuario->delete();

        $this->newLine();
        $this->imprimirTablaMarkdown($resultados);

        return self::SUCCESS;
    }

    private function crearUsuarioDeBenchmark(): Usuario
    {
        $rut = Rut::formatear(random_int(1000000, 24999999));

        return Usuario::create([
            'rut' => $rut,
            'nombre' => 'Benchmark',
            'apellido' => 'API',
            'tipo' => 'estudiante',
            'activo' => true,
        ]);
    }

    /**
     * @return array{avg: float, median: float, p95: float, max: float}
     */
    private function medir(int $n, \Closure $request): array
    {
        $tiempos = [];

        for ($i = 0; $i < $n; $i++) {
            $inicio = hrtime(true);
            $request();
            $tiempos[] = (hrtime(true) - $inicio) / 1_000_000; // ns -> ms
        }

        return $this->estadisticas($tiempos);
    }

    /**
     * POST /api/entrada exige que el usuario no tenga una entrada activa (409 si ya la
     * tiene), así que cada request se cierra inmediatamente con PATCH .../salida antes de
     * medir la siguiente iteración. Esa llamada de limpieza no se incluye en la medición.
     */
    private function medirEntrada(int $n, $client, Usuario $usuario): array
    {
        $tiempos = [];

        for ($i = 0; $i < $n; $i++) {
            $inicio = hrtime(true);
            $response = $client->post('/api/entrada', ['rut' => $usuario->rut]);
            $tiempos[] = (hrtime(true) - $inicio) / 1_000_000;

            $entradaId = $response->json('id');
            if ($entradaId) {
                $client->patch("/api/entrada/{$entradaId}/salida");
            }
        }

        return $this->estadisticas($tiempos);
    }

    /**
     * @param  float[]  $tiempos
     * @return array{avg: float, median: float, p95: float, max: float}
     */
    private function estadisticas(array $tiempos): array
    {
        sort($tiempos);
        $n = count($tiempos);

        $mediana = $n % 2 === 0
            ? ($tiempos[$n / 2 - 1] + $tiempos[$n / 2]) / 2
            : $tiempos[intdiv($n, 2)];

        $p95Index = (int) ceil(0.95 * $n) - 1;
        $p95 = $tiempos[max(0, min($n - 1, $p95Index))];

        return [
            'avg' => array_sum($tiempos) / $n,
            'median' => $mediana,
            'p95' => $p95,
            'max' => end($tiempos),
        ];
    }

    /**
     * @param  array<string, array{avg: float, median: float, p95: float, max: float}>  $resultados
     */
    private function imprimirTablaMarkdown(array $resultados): void
    {
        $this->line('| Endpoint | Promedio | Mediana | p95 | Máximo |');
        $this->line('|---|---|---|---|---|');

        foreach ($resultados as $endpoint => $stats) {
            $this->line(sprintf(
                '| `%s` | %.1f ms | %.1f ms | %.1f ms | %.1f ms |',
                $endpoint,
                $stats['avg'],
                $stats['median'],
                $stats['p95'],
                $stats['max'],
            ));
        }
    }
}
