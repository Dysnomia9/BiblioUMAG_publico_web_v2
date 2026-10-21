<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\Usuario;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Los tokens de sesión que vencen sin que nadie cierre sesión se borran una vez al día
 * (routes/console.php), en vez de quedarse para siempre en personal_access_tokens.
 */
class SesionesVencidasTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_limpieza_de_sesiones_vencidas_esta_programada_cada_dia(): void
    {
        $tarea = collect(app(Schedule::class)->events())
            ->first(fn ($evento) => str_contains($evento->command, 'sanctum:prune-expired --hours=24'));

        $this->assertNotNull($tarea, 'Se esperaba la tarea sanctum:prune-expired en routes/console.php.');
        $this->assertSame('0 0 * * *', $tarea->expression);
    }

    public function test_borra_las_sesiones_vencidas_hace_mas_de_un_dia_y_deja_las_demas(): void
    {
        $staff = Staff::factory()->create();
        $usuario = Usuario::factory()->create();

        // Vencen a las 8 horas: la de hace 2 días venció hace más de un día; la de hace
        // 12 horas recién venció, y la de recién sigue vigente.
        $antigua = $staff->createToken('staff-token')->accessToken;
        $antigua->forceFill(['created_at' => now()->subDays(2)])->save();
        $recienVencida = $usuario->createToken('usuario-token')->accessToken;
        $recienVencida->forceFill(['created_at' => now()->subHours(12)])->save();
        $vigente = $usuario->createToken('usuario-token')->accessToken;

        $this->artisan('sanctum:prune-expired', ['--hours' => 24])->assertSuccessful();

        $this->assertEqualsCanonicalizing([$recienVencida->id, $vigente->id], PersonalAccessToken::pluck('id')->all());
    }
}
