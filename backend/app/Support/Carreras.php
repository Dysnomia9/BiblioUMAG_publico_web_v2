<?php

namespace App\Support;

/**
 * Deja el nombre de una carrera escrito como corresponde: con mayúsculas de nombre
 * propio, con tildes y con las abreviaturas que trae Horizon («Ing.», «Téc. Niv. Sup.»,
 * «Ped.») escritas completas. Los nombres que llegaron de Horizon vienen en mayúsculas sin
 * tilde o abreviados para caber en pocos caracteres, y los reportes agrupan por el texto
 * exacto, así que «DERECHO», «Derecho» y «Ing. Comercial» contaban como carreras distintas.
 *
 * Solo corrige la escritura: no junta carreras distintas aunque se parezcan (un «Diurno» y
 * un «Vespertino» siguen siendo dos), y lo que no reconoce lo deja como estaba.
 */
final class Carreras
{
    /** Palabras sueltas sin tilde → como se escriben (solo las que llevan tilde o ñ). */
    private const ACENTOS = [
        'administracion' => 'administración', 'agronomia' => 'agronomía', 'analisis' => 'análisis', 'antartica' => 'antártica',
        'antarticas' => 'antárticas', 'antartico' => 'antártico', 'antarticos' => 'antárticos', 'arqueologia' => 'arqueología', 'automatizacion' => 'automatización',
        'auditoria' => 'auditoría', 'basica' => 'básica', 'biologia' => 'biología', 'biologica' => 'biológica', 'biologicas' => 'biológicas',
        'bioinformatica' => 'bioinformática', 'castellano' => 'castellano', 'comunicacion' => 'comunicación', 'computacion' => 'computación',
        'comun' => 'común', 'conservacion' => 'conservación', 'construccion' => 'construcción', 'contabilidad' => 'contabilidad',
        'curriculo' => 'currículo', 'ecologia' => 'ecología', 'economia' => 'economía', 'educacion' => 'educación', 'ejecucion' => 'ejecución',
        'electronica' => 'electrónica', 'energetica' => 'energética', 'energias' => 'energías', 'enfermeria' => 'enfermería', 'ensenanza' => 'enseñanza',
        'especializacion' => 'especialización', 'estadistica' => 'estadística', 'fisica' => 'física', 'fonoaudiologia' => 'fonoaudiología',
        'geografia' => 'geografía', 'gestion' => 'gestión', 'glaciologia' => 'glaciología', 'informatica' => 'informática', 'ingenieria' => 'ingeniería',
        'ingles' => 'inglés', 'instrumentacion' => 'instrumentación', 'interpretacion' => 'interpretación', 'investigacion' => 'investigación',
        'kinesiologia' => 'kinesiología', 'logistica' => 'logística', 'magister' => 'magíster', 'matematica' => 'matemática', 'mecanica' => 'mecánica',
        'mediacion' => 'mediación', 'mencion' => 'mención', 'musica' => 'música', 'nutricion' => 'nutrición', 'odontologia' => 'odontología',
        'pedagogia' => 'pedagogía', 'prevencion' => 'prevención', 'psicobiologia' => 'psicobiología', 'psicologia' => 'psicología', 'publico' => 'público',
        'publica' => 'pública', 'quimica' => 'química', 'quimico' => 'químico', 'sociologia' => 'sociología', 'tecnico' => 'técnico', 'tecnologia' => 'tecnología',
        'tecnica' => 'técnica', 'terapia' => 'terapia', 'topografia' => 'topografía', 'turistica' => 'turística', 'turistico' => 'turístico',
        'veterinaria' => 'veterinaria', 'electrica' => 'eléctrica', 'electrico' => 'eléctrico', 'maritima' => 'marítima', 'maritimo' => 'marítimo',
        'organizacion' => 'organización', 'organizaciones' => 'organizaciones', 'psicopedagogia' => 'psicopedagogía', 'relaciones' => 'relaciones',
        'subantarticas' => 'subantárticas', 'subantarticos' => 'subantárticos', 'arquitectura' => 'arquitectura', 'agropecuaria' => 'agropecuaria',
        'diplomado' => 'diplomado', 'ocupacional' => 'ocupacional', 'patrimonio' => 'patrimonio', 'higiene' => 'higiene', 'sanitaria' => 'sanitaria',
        'obstetricia' => 'obstetricia', 'prevencion' => 'prevención', 'fotografia' => 'fotografía', 'filosofia' => 'filosofía', 'historia' => 'historia',
    ];

    /** Abreviaturas de una palabra (sin tildes, con su punto) → palabra completa. */
    private const ABREVIATURAS = [
        'ing.' => 'ingeniería', 'ped.' => 'pedagogía', 'tec.' => 'técnico', 'licenc.' => 'licenciatura', 'magis.' => 'magíster', 'cs.' => 'ciencias',
        'educ.' => 'educación', 'adm.' => 'administración', 'admi.' => 'administración', 'administ.' => 'administración', 'emp.' => 'empresas',
        'esp.' => 'especial', 'espe.' => 'especial', 'prev.' => 'prevención', 'ries.' => 'riesgos', 'comp.' => 'computación', 'comput.' => 'computacionales',
        'inform.' => 'informática', 'infor.' => 'informática', 'sistem.' => 'sistemas', 'proces.' => 'procesos', 'tecnol.' => 'tecnología',
        'organiz.' => 'organizaciones', 'ens.' => 'enseñanza', 'menc.' => 'mención', 'pto.' => 'puerto', 'doctor.' => 'doctorado', 'niv.' => 'nivel',
        'sup.' => 'superior', 'super.' => 'superior', 'univ.' => 'universitario', 'univers.' => 'universitario', 'efic.' => 'eficiencia',
        'ejec.' => 'ejecución', 'p.' => 'plan', 'ed.' => 'educación', 'indust.' => 'industrial', 'inf.' => 'informática',
        'quim.' => 'química', 'computad.' => 'computadores', 'renov.' => 'renovables', 'prof' => 'profesional', 'prof.' => 'profesional',
        'm.' => 'mención',
    ];

    /** Secuencias de palabras (sin tildes) que se escriben distinto a la suma de sus partes. */
    private const FRASES = [
        [['tec.', 'de', 'niv.', 'sup.'], 'técnico de nivel superior'],
        [['tec.', 'niv.', 'sup.'], 'técnico de nivel superior'],
        [['tec.', 'niv.', 'super.'], 'técnico de nivel superior'],
        [['tec.', 'nivel', 'superior'], 'técnico de nivel superior'],
        [['tec.', 'nivel', 'sup.'], 'técnico de nivel superior'],
        [['tec.', 'de', 'nivel', 'sup.'], 'técnico de nivel superior'],
        [['tec.', 'de', 'nivel', 'superior'], 'técnico de nivel superior'],
        [['tecnico', 'de', 'nivel', 'superior'], 'técnico de nivel superior'],
        [['tec.', 'univ.'], 'técnico universitario'],
        [['tec.', 'univers.'], 'técnico universitario'],
        [['tecnico', 'univers.'], 'técnico universitario'],
        [['ing.', 'ejec.'], 'ingeniería de ejecución'],
        [['ing.', 'ej.'], 'ingeniería de ejecución'],
        [['adm.', 'emp.'], 'administración de empresas'],
        [['adm.', 'empresas'], 'administración de empresas'],
        [['admi.', 'empresas'], 'administración de empresas'],
        [['administracion', 'empresas'], 'administración de empresas'],
        [['prev.', 'ries.'], 'prevención de riesgos'],
        [['prev.', 'riesgos'], 'prevención de riesgos'],
        [['prev.', 'riesgo'], 'prevención de riesgo'],
        [['prevencion', 'riesgos'], 'prevención de riesgos'],
        [['prevencion', 'riesgo'], 'prevención de riesgo'],
        [['doctor.', 'cs.'], 'doctorado en ciencias'],
    ];

    /** Se escriben en minúscula salvo al comienzo. */
    private const MINUSCULAS = ['a', 'al', 'con', 'de', 'del', 'e', 'el', 'en', 'la', 'las', 'los', 'o', 'para', 'por', 'u', 'y'];

    /** Tras estas palabras se agrega «en» si la siguiente no es «en»/«de»/«para»: «Licenciatura Educación» → «Licenciatura en Educación». */
    private const PIDEN_EN = ['licenciatura', 'magíster', 'doctorado', 'pedagogía'];

    public static function normalizar(?string $texto): ?string
    {
        if ($texto === null || trim($texto) === '') {
            return $texto;
        }

        // «Ing.Comp.» → «Ing. Comp.»; un solo espacio entre palabras.
        $texto = preg_replace('/\.(?=\p{L})/u', '. ', trim($texto));
        $texto = preg_replace('/\s+/u', ' ', $texto);
        $texto = preg_replace('/ \/(?! )|(?<! )\/ /u', ' / ', $texto);

        // «A / B» son dos carreras en un mismo registro: cada lado se arregla por separado.
        $lados = array_map(fn (string $lado) => self::ladoNormalizado(trim($lado)), explode(' / ', $texto));

        return implode(' / ', $lados);
    }

    private static function ladoNormalizado(string $texto): string
    {
        $palabras = array_map(fn (string $p) => ['original' => mb_strtolower($p), 'plano' => Texto::sinTildes(mb_strtolower($p))], explode(' ', $texto));

        // 1. Frases y abreviaturas → palabras completas.
        $salida = [];
        for ($i = 0; $i < count($palabras); $i++) {
            foreach (self::FRASES as [$secuencia, $reemplazo]) {
                $coincide = true;
                foreach ($secuencia as $k => $plano) {
                    if (! isset($palabras[$i + $k]) || rtrim($palabras[$i + $k]['plano'], ',') !== $plano) {
                        $coincide = false;
                        break;
                    }
                }
                if ($coincide) {
                    $cola = str_ends_with($palabras[$i + count($secuencia) - 1]['plano'], ',') ? ',' : '';
                    foreach (explode(' ', $reemplazo) as $j => $parte) {
                        $salida[] = ['texto' => $parte.($j === substr_count($reemplazo, ' ') ? $cola : ''), 'conocida' => true];
                    }
                    $i += count($secuencia) - 1;
                    continue 2;
                }
            }
            $salida[] = self::palabra($palabras[$i]);
        }

        // 2. «en» después de Licenciatura, Magíster, Doctorado y Pedagogía cuando falta.
        $conEn = [];
        foreach ($salida as $i => $p) {
            $conEn[] = $p;
            $nucleo = mb_strtolower(rtrim($p['texto'], ','));
            $siguiente = isset($salida[$i + 1]) ? mb_strtolower($salida[$i + 1]['texto']) : null;
            if (in_array($nucleo, self::PIDEN_EN, true) && $siguiente !== null && ! in_array($siguiente, ['en', 'de', 'para', 'plan', 'con'], true) && $p['conocida']) {
                $conEn[] = ['texto' => 'en', 'conocida' => true];
            }
        }

        // 3. Mayúsculas de nombre propio.
        $palabrasFinales = [];
        foreach ($conEn as $i => $p) {
            $palabrasFinales[] = self::conMayuscula($p['texto'], $i === 0);
        }
        $resultado = implode(' ', $palabrasFinales);

        // 4. Puntuación de sobra al final: una coma o el punto de una palabra que no es abreviatura.
        $resultado = rtrim($resultado, ',');
        if (preg_match('/\p{L}{5,}\.$/u', $resultado)) {
            $resultado = rtrim($resultado, '.');
        }

        return $resultado;
    }

    /**
     * Una palabra: parte de la puntuación que la rodea («(P.», «Obras,»), su abreviatura
     * expandida o su tilde restituida.
     *
     * @param  array{original: string, plano: string}  $palabra
     * @return array{texto: string, conocida: bool}
     */
    private static function palabra(array $palabra): array
    {
        if (! preg_match('/^([^\p{L}]*)([\p{L}][\p{L}\p{M}\']*\.?)(.*)$/u', $palabra['original'], $m)) {
            return ['texto' => $palabra['original'], 'conocida' => false];
        }
        [, $antes, $nucleo, $despues] = $m;
        $plano = Texto::sinTildes($nucleo);
        $sinPunto = rtrim($plano, '.');

        if (isset(self::ABREVIATURAS[$plano])) {
            return ['texto' => $antes.self::ABREVIATURAS[$plano].$despues, 'conocida' => true];
        }
        if (isset(self::ACENTOS[$sinPunto]) && ! str_contains($nucleo, '.')) {
            return ['texto' => $antes.self::ACENTOS[$sinPunto].$despues, 'conocida' => false];
        }

        return ['texto' => $antes.$nucleo.$despues, 'conocida' => false];
    }

    private static function conMayuscula(string $palabra, bool $primera): string
    {
        // Cada tramo de letras: «m/obras» → «M/Obras»; «(plan» → «(Plan»; las de una letra, en mayúscula.
        return preg_replace_callback('/\p{L}[\p{L}\p{M}\']*/u', function (array $m) use ($palabra, $primera) {
            $tramo = $m[0];
            if (! $primera && in_array($tramo, self::MINUSCULAS, true)) {
                return $tramo;
            }
            if (mb_strlen($tramo) === 1) {
                return mb_strtoupper($tramo);
            }

            return mb_convert_case($tramo, MB_CASE_TITLE);
        }, $palabra);
    }
}
