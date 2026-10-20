<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Carrera;
use App\Models\Usuario;
use App\Services\MultaService;
use App\Support\Bitacora;
use App\Support\Documento;
use App\Support\Texto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UsuarioController extends Controller
{
    private const TIPOS = ['estudiante', 'docente', 'funcionario'];

    /** Campos que se pueden escribir desde Administración (el resto —qr_code— no se toca). */
    private const CAMPOS = ['rut', 'tipo_documento', 'pais', 'nombre', 'apellido', 'email', 'tipo', 'carrera', 'anio_ingreso', 'sexo', 'activo'];

    public function index(Request $request)
    {
        $query = Usuario::query();

        if ($busqueda = $request->query('q')) {
            $documento = Documento::normalizar($busqueda);
            $query->where(function ($q) use ($busqueda, $documento) {
                // Las palabras del nombre en cualquier orden, aunque se salten segundos nombres.
                $q->whereCadaPalabra("(nombre || ' ' || apellido)", $busqueda)
                    ->orWhere('rut', 'ilike', "%{$busqueda}%")
                    ->orWhereSinTildes('carrera', $busqueda)
                    // RUT sin puntos ni guion, pasaporte o DNI.
                    ->when(strlen($documento) >= 3, fn ($q) => $q->orWhereRaw(Documento::sql('rut').' like ?', ["%{$documento}%"]));
            });
        }

        if ($tipo = $request->query('tipo')) {
            $query->where('tipo', $tipo);
        }

        if ($carrera = $request->query('carrera')) {
            $query->where('carrera', $carrera);
        }

        if ($request->has('activo')) {
            $query->where('activo', filter_var($request->query('activo'), FILTER_VALIDATE_BOOLEAN));
        }

        // De a una página (25 por defecto): con el reporte de matrícula son miles de personas.
        $porPagina = min(max((int) $request->query('por_pagina', 25), 1), 100);

        return response()->json(
            $query->orderBy('nombre')->orderBy('apellido')->orderBy('id')->paginate($porPagina)->withQueryString()
        );
    }

    public function porRut(string $rut, MultaService $multaService)
    {
        // RUT, pasaporte o DNI, con o sin puntos y guion.
        $usuario = Usuario::buscarPorIdentificador($rut);

        if (! $usuario) {
            return response()->json(['message' => 'No hay ningún usuario registrado con ese RUT o documento. Revisa que esté bien escrito (el RUT con su dígito verificador).'], 404);
        }

        $usuario->setAttribute('multas_pendientes', $multaService->resumenPendiente($usuario));

        return response()->json($usuario);
    }

    /** Listas para el formulario de estudiantes: carreras conocidas, tipos de documento y de usuario. */
    public function opciones()
    {
        $carreras = Carrera::pluck('nombre')
            ->concat(Usuario::whereNotNull('carrera')->distinct()->pluck('carrera'))
            ->map(fn ($c) => trim($c))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        return response()->json([
            'carreras' => $carreras,
            'tipos_documento' => collect(Documento::TIPOS)->map(fn ($nombre, $clave) => ['clave' => $clave, 'nombre' => $nombre])->values(),
            'tipos' => self::TIPOS,
            'sexos' => ['Femenino', 'Masculino', 'Otro'],
        ]);
    }

    public function store(Request $request)
    {
        $datos = $this->validarFicha($request->all());

        $usuario = Usuario::create($datos + ['activo' => true]);

        Bitacora::registrar(
            'usuarios.crear',
            'Creó '.($usuario->tipo === 'estudiante' ? 'al estudiante' : "al usuario ({$usuario->tipo})")." {$usuario->nombre} {$usuario->apellido}",
            entidad: $usuario,
            datos: array_diff_key($datos, ['password' => 0]) + (isset($datos['password']) ? ['password' => '(asignada)'] : []),
            afectado: $usuario,
        );

        return response()->json($usuario, 201);
    }

    public function update(Request $request, Usuario $usuario)
    {
        $datos = $this->validarFicha($request->all(), $usuario);

        $antes = $usuario->only(self::CAMPOS);
        $usuario->update($datos);

        $cambios = Bitacora::cambios($antes, $usuario->only(self::CAMPOS));
        if (isset($datos['password'])) {
            $cambios['password'] = '(cambiada)';
        }

        Bitacora::registrar(
            'usuarios.editar',
            "Editó los datos de {$usuario->nombre} {$usuario->apellido}",
            entidad: $usuario,
            datos: $cambios,
            afectado: $usuario,
        );

        return response()->json($usuario);
    }

    /**
     * Primer paso de la carga masiva: revisa las filas de la planilla sin escribir
     * nada y dice qué pasará con cada una — nueva, ya existe (no se carga), repetida
     * dentro del mismo archivo (no se carga) o con errores (no se carga).
     */
    public function revisarImportacion(Request $request)
    {
        return response()->json($this->clasificar($this->filasDe($request)));
    }

    /**
     * Segundo paso: exige la confirmación explícita y vuelve a clasificar las filas en
     * el servidor (no confía en la revisión anterior: alguien pudo crear a uno de esos
     * estudiantes entretanto). Solo se crean las filas nuevas; las que ya existen no
     * se tocan ni se sobrescriben.
     */
    public function importar(Request $request)
    {
        $request->validate(
            ['confirmado' => ['accepted'], 'archivo' => ['nullable', 'string', 'max:255']],
            ['confirmado.accepted' => 'Confirma la carga antes de continuar: revisa el resumen y marca la casilla.'],
        );
        $filas = $this->filasDe($request);

        $resultado = DB::transaction(function () use ($filas) {
            // Dos cargas a la vez con el mismo estudiante: la segunda espera a la primera y
            // lo ve como "ya existe" en vez de chocar con el índice único.
            DB::statement('LOCK TABLE usuarios IN SHARE ROW EXCLUSIVE MODE');

            $clasificacion = $this->clasificar($filas);
            $creados = [];

            foreach ($clasificacion['filas'] as $fila) {
                if ($fila['estado'] === 'nuevo') {
                    $creados[] = Usuario::create($fila['datos'] + ['activo' => true]);
                }
            }

            return [$clasificacion, $creados];
        });

        [$clasificacion, $creados] = $resultado;
        $resumen = $clasificacion['resumen'];
        $omitidas = $resumen['existentes'] + $resumen['duplicados'] + $resumen['invalidos'];

        Bitacora::registrar(
            'usuarios.importar',
            'Cargó '.count($creados).' '.(count($creados) === 1 ? 'usuario' : 'usuarios').' desde una planilla'
                .($omitidas ? " ({$omitidas} ".($omitidas === 1 ? 'fila no se cargó' : 'filas no se cargaron').')' : ''),
            datos: [
                'archivo' => $request->input('archivo'),
                'resumen' => $resumen,
                'creados' => collect($creados)->map(fn (Usuario $u) => $u->rut)->all(),
                'ya_existian' => collect($clasificacion['filas'])->where('estado', 'existe')->pluck('datos.rut')->values()->all(),
            ],
        );

        return response()->json([
            'creados' => count($creados),
            'resumen' => $resumen,
            'filas' => $clasificacion['filas'],
        ], 201);
    }

    /** @return list<array<string, mixed>> */
    private function filasDe(Request $request): array
    {
        $data = $request->validate([
            'filas' => ['required', 'array', 'min:1', 'max:10000'],
            'filas.*' => ['array'],
        ], [
            'filas.required' => 'El archivo no trae filas para cargar.',
            'filas.max' => 'El archivo trae más de 10.000 filas: divídelo en partes.',
        ]);

        return array_values($data['filas']);
    }

    /**
     * Valida y normaliza la ficha de una persona (formulario o fila de planilla).
     * Lanza ValidationException con los mensajes por campo.
     *
     * @return array<string, mixed>
     */
    private function validarFicha(array $entrada, ?Usuario $ignorar = null): array
    {
        $errores = [];
        $datos = $this->normalizar($entrada, $errores);

        $validador = Validator::make($datos, $this->reglas($ignorar), $this->mensajes());
        $errores = array_merge_recursive($errores, $validador->errors()->toArray());

        if (! isset($errores['rut']) && $existente = $this->existente($datos['rut'], $ignorar)) {
            $errores['rut'] = ["Ya existe un usuario con ese {$this->nombreDocumento($datos['tipo_documento'])}: {$existente->nombre} {$existente->apellido} ({$existente->rut})."];
        }

        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        // La contraseña del portal es opcional: en blanco, no se cambia.
        if (empty($datos['password'])) {
            unset($datos['password']);
        }

        return $datos;
    }

    /**
     * Lo que llega del formulario o de la planilla, limpio: el documento con su tipo
     * (deducido si no viene) y formateado, textos sin espacios de más, tipo y sexo en
     * su forma estándar.
     *
     * @param  array<string, list<string>>  $errores
     * @return array<string, mixed>
     */
    private function normalizar(array $entrada, array &$errores): array
    {
        $texto = fn (string $campo) => ($v = trim(preg_replace('/\s+/u', ' ', (string) ($entrada[$campo] ?? '')))) === '' ? null : $v;

        $identificador = $texto('rut');
        // Solo la trae el reporte de matrícula: sirve para saber qué documento es.
        $nacionalidad = $texto('nacionalidad');
        $tipoDeclarado = array_key_exists($entrada['tipo_documento'] ?? null, Documento::TIPOS)
            ? $entrada['tipo_documento']
            : Documento::tipoDesdeTexto($entrada['tipo_documento'] ?? null);
        $tipoDocumento = $tipoDeclarado ?? Documento::detectarTipo($identificador, extranjero: ! Documento::esNacionalidadChilena($nacionalidad));

        if ($error = Documento::validar($identificador, $tipoDocumento)) {
            // Solo números, sin tipo ni nacionalidad que diga que es extranjero: se lee como
            // RUT. Si no cuadra, puede ser el DNI o pasaporte de un estudiante extranjero.
            if (! $tipoDeclarado && $tipoDocumento === 'rut' && $identificador !== null) {
                $error = "«{$identificador}» no es un RUT válido (el dígito verificador no corresponde). Si es el DNI o pasaporte de un estudiante extranjero, indica su nacionalidad en la columna «Nacionalidad» o escribe DNI o Pasaporte en «Tipo de documento».";
            }
            $errores['rut'] = [$error];
        }

        $sexo = $texto('sexo');
        $sexo = match (mb_strtolower((string) $sexo)) {
            'f', 'fem', 'femenino', 'mujer' => 'Femenino',
            'm', 'masc', 'masculino', 'hombre' => 'Masculino',
            '' => null,
            default => mb_convert_case($sexo, MB_CASE_TITLE),
        };

        $anio = $texto('anio_ingreso');

        $datos = [
            'tipo_documento' => $tipoDocumento,
            'rut' => isset($errores['rut']) ? (string) $identificador : Documento::formatear($identificador, $tipoDocumento),
            'pais' => $tipoDocumento === 'rut' ? null : ($texto('pais') ?? Documento::paisDesdeNacionalidad($nacionalidad)),
            'nombre' => $texto('nombre'),
            // El reporte de matrícula trae los apellidos en dos columnas (PATERNO y MATERNO).
            'apellido' => $texto('apellido') ?? (trim($texto('apellido_paterno').' '.$texto('apellido_materno')) ?: null),
            'email' => ($email = $texto('email')) ? mb_strtolower($email) : null,
            'tipo' => mb_strtolower($texto('tipo') ?? 'estudiante'),
            'carrera' => $this->carreraConEspecialidad($texto('carrera'), $texto('especialidad')),
            'anio_ingreso' => $anio === null ? null : (ctype_digit($anio) ? (int) $anio : $anio),
            'sexo' => $sexo,
        ];

        if (array_key_exists('activo', $entrada)) {
            $datos['activo'] = filter_var($entrada['activo'], FILTER_VALIDATE_BOOLEAN);
        }
        if (! empty($entrada['password'])) {
            $datos['password'] = (string) $entrada['password'];
        }

        return $datos;
    }

    private function reglas(?Usuario $ignorar = null): array
    {
        return [
            'tipo_documento' => ['required', Rule::in(array_keys(Documento::TIPOS))],
            'pais' => ['nullable', 'string', 'max:100'],
            'nombre' => ['required', 'string', 'max:255'],
            'apellido' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('usuarios', 'email')->ignore($ignorar?->id)],
            'tipo' => ['required', Rule::in(self::TIPOS)],
            'carrera' => ['nullable', 'string', 'max:255'],
            'anio_ingreso' => ['nullable', 'integer', 'min:1950', 'max:'.(now()->year + 1)],
            'sexo' => ['nullable', 'string', 'max:30'],
            'activo' => ['sometimes', 'boolean'],
            'password' => ['nullable', 'string', 'min:6', 'max:100'],
        ];
    }

    private function mensajes(): array
    {
        return [
            'nombre.required' => 'Falta el nombre.',
            'apellido.required' => 'Falta el apellido.',
            'email.email' => 'El correo no es válido.',
            'email.unique' => 'Ese correo ya lo usa otro usuario.',
            'tipo.in' => 'El tipo debe ser estudiante, docente o funcionario.',
            'anio_ingreso.integer' => 'El año de ingreso debe ser un número (ej. 2025).',
            'anio_ingreso.min' => 'El año de ingreso no es válido.',
            'anio_ingreso.max' => 'El año de ingreso no puede ser futuro.',
            'password.min' => 'La contraseña del portal debe tener al menos 6 caracteres.',
        ];
    }

    private function existente(string $identificador, ?Usuario $ignorar = null): ?Usuario
    {
        return Usuario::porIdentificador($identificador)
            ->when($ignorar, fn ($q) => $q->whereKeyNot($ignorar->id))
            ->first();
    }

    private function nombreDocumento(string $tipo): string
    {
        return $tipo === 'rut' ? 'RUT' : mb_strtolower(Documento::TIPOS[$tipo] ?? 'documento');
    }

    /**
     * El reporte de matrícula trae la especialidad aparte («INGENIERÍA CIVIL» + «EN
     * MECÁNICA»): va junto a la carrera para que los reportes distingan cada una. Lo que
     * no es una especialidad («SIN MODO», «PLAN COMÚN», «PLAN ESPECIAL») no se agrega.
     */
    private function carreraConEspecialidad(?string $carrera, ?string $especialidad): ?string
    {
        $clave = Texto::clave($especialidad);

        if ($carrera === null || in_array($clave, ['', 'sin modo', 'sin especialidad', 'sin mencion', 'plan comun', 'plan especial', 'no aplica'], true)) {
            return $carrera;
        }

        // «en Mecánica» o «mención …» se leen seguidos de la carrera; lo demás, entre paréntesis.
        return preg_match('/^(en|con|mencion) /', $clave) ? "{$carrera} {$especialidad}" : "{$carrera} ({$especialidad})";
    }

    /**
     * Los reportes de la universidad vienen en mayúsculas («JENNIFER ANDREA», «INGENIERÍA
     * CIVIL INDUSTRIAL»): el nombre y los apellidos se guardan como nombre propio, y la
     * carrera tal como ya está escrita en el sistema (sin importar tildes ni mayúsculas),
     * para que los reportes la agrupen con la de los demás estudiantes.
     *
     * @param  array<string, string>  $carreras  ver carrerasConocidas()
     */
    private function comoEnElSistema(array $datos, array $carreras): array
    {
        $datos['nombre'] = Texto::nombrePropio($datos['nombre']);
        $datos['apellido'] = Texto::nombrePropio($datos['apellido']);

        if ($datos['carrera'] !== null) {
            $datos['carrera'] = $carreras[Texto::clave($datos['carrera'])] ?? Texto::nombrePropio($datos['carrera']);
        }

        return $datos;
    }

    /**
     * Las carreras que ya conoce el sistema (catálogo y estudiantes), por su clave sin
     * tildes ni mayúsculas. Si una misma carrera está escrita de dos formas, gana la que
     * no está entera en mayúsculas.
     *
     * @return array<string, string>
     */
    private function carrerasConocidas(): array
    {
        $conocidas = [];

        foreach (Carrera::pluck('nombre')->concat(Usuario::whereNotNull('carrera')->distinct()->pluck('carrera')) as $nombre) {
            $nombre = trim($nombre);
            $clave = Texto::clave($nombre);
            $actual = $conocidas[$clave] ?? null;

            if ($actual === null || (! preg_match('/\p{Ll}/u', $actual) && preg_match('/\p{Ll}/u', $nombre))) {
                $conocidas[$clave] = $nombre;
            }
        }

        return $conocidas;
    }

    /**
     * Qué pasará con cada fila de la planilla, sin escribir nada.
     *
     * @param  list<array<string, mixed>>  $filas  cada una con 'fila' (su número en el archivo) y los campos
     * @return array{resumen: array<string, int>, filas: list<array<string, mixed>>}
     */
    private function clasificar(array $filas): array
    {
        $resultado = [];
        $vistos = [];
        $emailsVistos = [];

        $carreras = $this->carrerasConocidas();

        // Cada fila como quedaría en el sistema. Contraseñas no viajan en planillas: quien se
        // carga así entra al portal con el login institucional o con la contraseña que se le
        // asigne después al editarlo.
        $preparadas = array_map(function (array $fila) use ($carreras) {
            $errores = [];
            $datos = $this->comoEnElSistema($this->normalizar(array_diff_key($fila, ['password' => 0]), $errores), $carreras);

            return [$fila, $datos, $errores];
        }, $filas);

        // Una sola consulta para saber quiénes ya existen, en vez de una por fila. Se busca con
        // el documento ya formateado, el mismo que se guardaría: el reporte de matrícula trae
        // los RUT de 7 dígitos con un cero adelante («08733712-K») y se guardan sin él.
        $normalizados = collect($preparadas)->map(fn ($p) => Documento::normalizar($p[1]['rut'] ?? ''))->filter()->unique()->values();
        $existentes = $normalizados->chunk(1000)->flatMap(fn ($lote) => Usuario::whereRaw(
            Documento::sql('rut').' in ('.implode(',', array_fill(0, $lote->count(), '?')).')',
            $lote->values()->all(),
        )->get(['id', 'rut', 'nombre', 'apellido']))->keyBy(fn (Usuario $u) => Documento::normalizar($u->rut));

        $emails = collect($filas)->map(fn ($f) => mb_strtolower(trim((string) ($f['email'] ?? ''))))->filter()->unique()->values();
        $emailsTomados = $emails->chunk(1000)->flatMap(fn ($lote) => Usuario::whereIn(DB::raw('lower(email)'), $lote->all())
            ->get(['rut', 'nombre', 'apellido', 'email']))->keyBy(fn (Usuario $u) => mb_strtolower($u->email));

        $reglasFila = $this->reglas();
        $reglasFila['email'] = ['nullable', 'email', 'max:255'];

        foreach ($preparadas as $i => [$fila, $datos, $errores]) {
            $numero = (int) ($fila['fila'] ?? $i + 2);
            $clave = Documento::normalizar($datos['rut']);
            $mensajes = [];
            $existente = null;

            if (isset($errores['rut'])) {
                $estado = 'invalido';
                $mensajes = array_merge($errores['rut'], Validator::make($datos, $reglasFila, $this->mensajes())->errors()->all());
            } elseif ($existente = $existentes->get($clave)) {
                $estado = 'existe';
                $mensajes[] = "Ya existe: {$existente->nombre} {$existente->apellido} ({$existente->rut}). No se cargará.";
            } elseif (isset($vistos[$clave])) {
                $estado = 'duplicado';
                $mensajes[] = "Este {$this->nombreDocumento($datos['tipo_documento'])} ya aparece en la fila {$vistos[$clave]} del archivo. No se cargará dos veces.";
            } else {
                $mensajes = Validator::make($datos, $reglasFila, $this->mensajes())->errors()->all();

                if ($datos['email'] && $duenio = $emailsTomados->get($datos['email'])) {
                    $mensajes[] = "El correo {$datos['email']} ya lo usa {$duenio->nombre} {$duenio->apellido} ({$duenio->rut}).";
                } elseif ($datos['email'] && isset($emailsVistos[$datos['email']])) {
                    $mensajes[] = "El correo {$datos['email']} ya aparece en la fila {$emailsVistos[$datos['email']]} del archivo.";
                }

                $estado = $mensajes ? 'invalido' : 'nuevo';
            }

            if ($clave !== '' && ! isset($vistos[$clave])) {
                $vistos[$clave] = $numero;
            }
            if ($estado === 'nuevo' && $datos['email']) {
                $emailsVistos[$datos['email']] = $numero;
            }

            $resultado[] = [
                'fila' => $numero,
                'estado' => $estado,
                'datos' => $datos,
                'mensajes' => $mensajes,
                'existente' => $existente?->only(['id', 'rut', 'nombre', 'apellido']),
            ];
        }

        $porEstado = collect($resultado)->countBy('estado');

        return [
            'resumen' => [
                'total' => count($resultado),
                'nuevos' => $porEstado->get('nuevo', 0),
                'existentes' => $porEstado->get('existe', 0),
                'duplicados' => $porEstado->get('duplicado', 0),
                'invalidos' => $porEstado->get('invalido', 0),
            ],
            'filas' => $resultado,
        ];
    }
}
