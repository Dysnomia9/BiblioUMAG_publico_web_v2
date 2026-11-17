<?php

namespace App\Services;

use App\Models\Ejemplar;
use App\Models\Libro;
use App\Support\TomoEjemplar;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Un mismo registro de ejemplar para catalogación e ingreso interbibliotecario. */
class CatalogarEjemplarService
{
    public function reglas(): array
    {
        return [

            // Ni de otra copia (su código o el de lomo) ni de un equipo: al escanear, sale uno solo
            'codigo_barras' => ['required', 'string', 'max:255', 'unique:ejemplares,codigo_barras', 'unique:ejemplares,codigo_lomo', 'unique:equipos,codigo_barras'],
            'ubicacion_id' => ['nullable', 'exists:ubicaciones,id'],
            'volumen' => ['nullable', 'string', 'max:100'],
            // Obra en varios tomos: el tomo de esta copia («t.1», «t.1-v.II»).
            'tomo' => ['nullable', 'string', 'max:50'],
            // La «c.N» de la copia, como la escribe quien cataloga; vacía (null) = sin número. Si
            // no se manda, se calcula sola: la siguiente de ese tomo (o del título).
            'copia' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:9999'],
            'precio' => ['nullable', 'numeric', 'min:0'],
            'forma_adquisicion' => ['nullable', Rule::in(Ejemplar::FORMAS_ADQUISICION)],
            'numero_registro' => ['nullable', 'string', 'max:50'],
            'coleccion' => ['nullable', 'string', 'max:255'],
            'nota_publica' => ['nullable', 'string', 'max:5000'],
            'nota_interna' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function crear(int $libroId, array $data, string $estado = 'inventario', string $prefijo = ''): Ejemplar
    {
        $data['libro_id'] = $libroId;
        $data['codigo_barras'] = trim($data['codigo_barras']);
        // En «tomo» va solo el tomo («t.1», «Tomo 1»); si trae la copia («t.1 c.2») se toma el
        // tomo (el número de copia va en «copia»). Lo que no es un tomo no se guarda.
        if (filled($data['tomo'] ?? null)) {
            [$tomoEscrito] = TomoEjemplar::desdeEtiqueta($data['tomo']);
            if ($tomoEscrito === null) {
                throw ValidationException::withMessages([$prefijo.'tomo' => 'En «Tomo» va solo el tomo, por ejemplo «t.1» o «Tomo 1»: el número de copia va aparte.']);
            }
            $data['tomo'] = $tomoEscrito;
        }

        $ejemplar = DB::transaction(function () use ($data, $estado, $prefijo) {
            Libro::whereKey($data['libro_id'])->lockForUpdate()->firstOrFail();
            $siguienteNumero = Ejemplar::siguienteNumero((int) $data['libro_id']);
            $copiaEscrita = array_key_exists('copia', $data);
            // Con tomo: la copia que sigue de ese tomo y el volumen como en Horizon («t.1 c.3»).
            $tomo = TomoEjemplar::normalizar($data['tomo'] ?? null);
            [$tomoDelVolumen, $copiaDelVolumen] = TomoEjemplar::desdeEtiqueta($data['volumen'] ?? null);
            if ($tomo) {
                Ejemplar::where('libro_id', $data['libro_id'])->lockForUpdate()->get(['id']);
                $copiaTomo = $copiaEscrita ? $data['copia'] : TomoEjemplar::siguienteCopia((int) $data['libro_id'], $tomo);
                $data['volumen'] = TomoEjemplar::etiqueta($tomo, $copiaTomo);
            } elseif (blank($data['volumen'] ?? null)) {
                // Sin tomo: la primera copia del título va sin «c.» y las demás siguen la
                // numeración que ya tiene, también la de Horizon (c.1, c.2…)
                Ejemplar::where('libro_id', $data['libro_id'])->lockForUpdate()->get(['id']);
                $tomo = null;
                $copiaTomo = $copiaEscrita ? $data['copia'] : TomoEjemplar::siguienteCopia((int) $data['libro_id'], null);
            } else {
                [$tomo, $copiaTomo] = [$tomoDelVolumen, $copiaEscrita ? $data['copia'] : $copiaDelVolumen];
            }

            // La misma «c.N» no se repite en el mismo tomo (o, sin tomo, con la misma designación)
            if ($copiaTomo !== null) {
                $repetida = Ejemplar::where('libro_id', $data['libro_id'])->where('copia_tomo', $copiaTomo)
                    ->when($tomo !== null, fn ($q) => $q->where('tomo', $tomo), fn ($q) => $q->whereNull('tomo')
                        ->where(fn ($w) => blank($data['volumen'] ?? null) ? $w->whereNull('volumen')->orWhere('volumen', '') : $w->where('volumen', $data['volumen'])))
                    ->exists();
                if ($repetida) {
                    throw ValidationException::withMessages([$prefijo.'copia' => "Esta obra ya tiene la copia c.{$copiaTomo}".($tomo ? ' de ese tomo' : '').': elige otro número o déjala sin número.']);
                }
            }

            $copia = new Ejemplar([
                'libro_id' => $data['libro_id'],
                'numero_copia' => $siguienteNumero,
                'codigo_barras' => $data['codigo_barras'],
                'ubicacion_id' => $data['ubicacion_id'] ?? null,
                'volumen' => $data['volumen'] ?? null,
                'tomo' => $tomo,
                'copia_tomo' => $copiaTomo,
                'precio' => $data['precio'] ?? null,
                'forma_adquisicion' => $data['forma_adquisicion'] ?? null,
                'nota_publica' => $data['nota_publica'] ?? null,
                'nota_interna' => $data['nota_interna'] ?? null,
                'coleccion' => $data['coleccion'] ?? null,
                'numero_registro' => $data['numero_registro'] ?? null,
                'estado_proceso' => $estado,
            ]);
            // Un código corto del sistema (8 dígitos desde 10000001, ver siguienteCodigoBarras):
            // es también su código de lomo, y la secuencia sigue después de él para no repetirlo.
            if (Ejemplar::esCodigoCorto($data['codigo_barras'])) {
                $copia->forceFill(['codigo_lomo' => $data['codigo_barras']]);
                DB::statement("SELECT setval('ejemplares_codigo_lomo_seq', GREATEST((SELECT last_value FROM ejemplares_codigo_lomo_seq), ?))", [(int) $data['codigo_barras']]);
            }
            $copia->save();

            return $copia;
        });

        return $ejemplar;
    }
}
