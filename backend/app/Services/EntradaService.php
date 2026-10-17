<?php

namespace App\Services;

use App\Models\Entrada;
use App\Models\Reserva;
use App\Models\Staff;
use App\Models\Usuario;
use App\Support\Bitacora;
use App\Support\Documento;
use Illuminate\Support\Facades\DB;

/**
 * Una persona queda presente en la biblioteca una sola vez por día: si ya marcó su
 * entrada (en el mesón, con el QR, desde el portal o al llegar a una sala reservada),
 * un segundo intento no crea otra fila. Todos los caminos que registran una entrada
 * pasan por acá.
 *
 * La revisión y la escritura van en la misma transacción con un bloqueo por persona
 * (la fila del usuario, o un candado de Postgres por documento para los externos):
 * sin eso, dos escaneos casi simultáneos del mismo carnet pasarían ambos la revisión.
 * Las entradas repetidas que quedaron de antes de esta regla no se tocan.
 */
class EntradaService
{
    public function entradaDeHoy(Usuario $usuario): ?Entrada
    {
        return Entrada::where('usuario_id', $usuario->id)
            ->whereDate('fecha_hora_entrada', now()->toDateString())
            ->oldest('fecha_hora_entrada')
            ->first();
    }

    public function entradaExternaDeHoy(string $documento): ?Entrada
    {
        return Entrada::whereNull('usuario_id')
            ->whereRaw(Documento::sql('rut_externo').' = ?', [Documento::normalizar($documento)])
            ->whereDate('fecha_hora_entrada', now()->toDateString())
            ->oldest('fecha_hora_entrada')
            ->first();
    }

    /**
     * Registra la entrada de un usuario del sistema, salvo que ya la tenga hoy.
     *
     * @param  array<string, mixed>  $atributos  via, registrado_por_staff_id, codigo_barras
     * @return array{0: Entrada, 1: bool} la entrada y si se creó recién (false = ya estaba presente)
     */
    public function registrarUsuario(Usuario $usuario, array $atributos): array
    {
        return DB::transaction(function () use ($usuario, $atributos) {
            Usuario::whereKey($usuario->id)->lockForUpdate()->first();

            if ($existente = $this->entradaDeHoy($usuario)) {
                return [$existente, false];
            }

            // La salida se marca en el mismo instante que la entrada: Horizon no registra un
            // evento de salida por separado (ver EntradaController::store()).
            $ahora = now();

            return [Entrada::create([
                ...$atributos,
                'usuario_id' => $usuario->id,
                'fecha_hora_entrada' => $ahora,
                'fecha_hora_salida' => $ahora,
            ]), true];
        });
    }

    /**
     * Mismo criterio para externos, visitas y convenio, comparando el documento
     * declarado sin puntos ni guiones.
     *
     * @param  array<string, mixed>  $atributos
     * @return array{0: Entrada, 1: bool}
     */
    public function registrarExterno(string $documento, array $atributos): array
    {
        return DB::transaction(function () use ($documento, $atributos) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['entrada-externa:'.Documento::normalizar($documento)]);

            if ($existente = $this->entradaExternaDeHoy($documento)) {
                return [$existente, false];
            }

            $ahora = now();

            return [Entrada::create([
                ...$atributos,
                'rut_externo' => $documento,
                'fecha_hora_entrada' => $ahora,
                'fecha_hora_salida' => $ahora,
            ]), true];
        });
    }

    /**
     * Al confirmar la llegada a una sala reservada, cada integrante queda presente en
     * la biblioteca: a quien no había marcado entrada hoy se le registra; a quien ya
     * la tenía, no se le crea otra.
     *
     * @return array{registradas: list<string>, ya_presentes: list<string>}
     */
    public function marcarPresentesPorReserva(Reserva $reserva, Staff $staff): array
    {
        $resultado = ['registradas' => [], 'ya_presentes' => []];

        foreach ($reserva->participantes()->orderBy('nombre')->get() as $usuario) {
            [$entrada, $creada] = $this->registrarUsuario($usuario, [
                'via' => 'sala',
                'registrado_por_staff_id' => $staff->id,
                'codigo_barras' => config('horizon_barcodes.puesto_generico'),
            ]);

            $nombre = trim("{$usuario->nombre} {$usuario->apellido}");

            if ($creada) {
                $resultado['registradas'][] = $nombre;
                Bitacora::registrar(
                    'entradas.registrar',
                    'Registró la entrada al confirmar la llegada a '.($reserva->sala?->nombre ?? 'la sala'),
                    entidad: $entrada,
                    afectado: $usuario,
                    actor: $staff,
                );
            } else {
                $resultado['ya_presentes'][] = $nombre;
            }
        }

        return $resultado;
    }

    /** «Valentina Soto ya marcó su entrada hoy a las 10:42 (QR).» */
    public function mensajeYaPresente(Entrada $entrada, string $quien): string
    {
        $via = match ($entrada->via) {
            'qr' => 'con el código QR',
            'portal' => 'desde el portal',
            'sala' => 'al llegar a una sala reservada',
            default => 'en el mesón',
        };

        return "{$quien} ya marcó su entrada hoy a las {$entrada->fecha_hora_entrada->format('H:i')} ({$via}). Una persona queda presente una sola vez por día.";
    }
}
