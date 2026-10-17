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

        // Una persona queda presente una sola vez por día: cada entrada medida es de un
        // usuario distinto, para medir el registro real y no la respuesta «ya presente».
        $usuarios = collect(range(1, $n))->map(fn () => $this->crearUsuarioDeBenchmark());

        $this->info("Ejecutando {$n} requests por endpoint...");

        $resultados = [
            'GET /api/usuarios' => $this->medir($n, fn () => $client->get('/api/usuarios')),
            'POST /api/entrada' => $this->medirEntrada($client, $usuarios->all()),
            'GET /api/reportes/resumen' => $this->medir($n, fn () => $client->get('/api/reportes/resumen')),
        ];

        // entradas.usuario_id es RESTRICT — hay que borrar las entradas de prueba antes
        // que los usuarios. (Las filas de la bitácora quedan: son lo que hizo la cuenta
        // con la que se midió, y la bitácora no se borra.)
        foreach ($usuarios as $usuario) {
            $usuario->entradas()->delete();
            $usuario->delete();
        }

        $this->newLine();
        $this->imprimirTablaMarkdown($resultados);

        return self::SUCCESS;
    }

    private function crearUsuarioDeBenchmark(): Usuario
    {
        do {
            $rut = Rut::formatear(random_int(1000000, 24999999));
        } while (Usuario::porIdentificador($rut)->exists());

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
     * POST /api/entrada responde 409 si la persona ya marcó su entrada hoy, así que cada
     * request es de un usuario de prueba distinto. Si alguna no responde 201, se avisa:
     * la medición no sería la del registro real.
     *
     * @param  list<Usuario>  $usuarios
     */
    private function medirEntrada($client, array $usuarios): array
    {
        $tiempos = [];
        $fallidas = 0;

        foreach ($usuarios as $usuario) {
            $inicio = hrtime(true);
            $response = $client->post('/api/entrada', ['rut' => $usuario->rut]);
            $tiempos[] = (hrtime(true) - $inicio) / 1_000_000;
            $fallidas += $response->status() === 201 ? 0 : 1;
        }

        if ($fallidas) {
            $this->warn("POST /api/entrada: {$fallidas} de ".count($usuarios).' requests no respondieron 201.');
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
