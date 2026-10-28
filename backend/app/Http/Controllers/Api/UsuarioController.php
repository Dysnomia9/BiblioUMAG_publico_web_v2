<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Carrera;
use App\Models\Usuario;
use App\Models\UsuarioCorreo;
use App\Services\MultaService;
use App\Support\Bitacora;
use App\Support\Documento;
use App\Support\Permisos;
use App\Support\Texto;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
        $query = Usuario::query()->with('correos:id,usuario_id,email');

        if ($busqueda = $request->query('q')) {
            $documento = Documento::normalizar($busqueda);
            $query->where(function ($q) use ($busqueda, $documento) {
                // Las palabras del nombre en cualquier orden, aunque se salten segundos nombres.
                $q->whereCadaPalabra("(nombre || ' ' || apellido)", $busqueda)
                    ->orWhere('rut', 'ilike', "%{$busqueda}%")
                    ->orWhereSinTildes('carrera', $busqueda)
                    ->orWhere('email', 'ilike', "%{$busqueda}%")
                    ->orWhereHas('correos', fn ($c) => $c->where('email', 'ilike', "%{$busqueda}%"))
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
            // «Activos» son los que sirven hoy (dentro de su vigencia); «inactivos», los desactivados y los vencidos.
            filter_var($request->query('activo'), FILTER_VALIDATE_BOOLEAN) ? $query->vigentes() : $query->noVigentes();
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
        $adicionales = $this->correosAdicionalesDe($request, null, $datos['email'] ?? null);

        $usuario = Usuario::create($datos + ['activo' => true, 'vigente_hasta' => Usuario::proximoVencimiento(tipo: $datos['tipo'])?->toDateString()]);
        $this->sincronizarCorreos($usuario, $adicionales ?? []);

        Bitacora::registrar(
            'usuarios.crear',
            'Creó '.($usuario->tipo === 'estudiante' ? 'al estudiante' : "al usuario ({$usuario->tipo})")." {$usuario->nombre} {$usuario->apellido}",
            entidad: $usuario,
            datos: array_diff_key($datos, ['password' => 0]) + (isset($datos['password']) ? ['password' => '(asignada)'] : []) + ($adicionales ? ['correos_adicionales' => $adicionales] : []),
            afectado: $usuario,
        );

        return response()->json($usuario->load('correos:id,usuario_id,email'), 201);
    }

    public function update(Request $request, Usuario $usuario)
    {
        $datos = $this->validarFicha($request->all(), $usuario);

        if ($usuario->activo && isset($datos['activo']) && ! $datos['activo'] && ! $request->user()->puede('usuarios.desactivar')) {
            return response()->json([
                'message' => 'Tu rol no tiene el permiso «'.Permisos::etiqueta('usuarios.desactivar').'». Si lo necesitas, pídeselo a un administrador.',
            ], 403);
        }

        // Marcar como activa una cuenta que no servía es renovarla: también corre su vigencia.
        if (($datos['activo'] ?? false) === true && ! $usuario->activo) {
            $datos['vigente_hasta'] ??= Usuario::proximoVencimiento(tipo: $datos['tipo'] ?? $usuario->tipo)?->toDateString();
        }

        $adicionales = $this->correosAdicionalesDe($request, $usuario, $datos['email'] ?? $usuario->email);

        $antes = $usuario->only(self::CAMPOS);
        $correosAntes = $usuario->correos()->pluck('email')->sort()->values()->all();
        $usuario->update($datos);
        if ($adicionales !== null) {
            $this->sincronizarCorreos($usuario, $adicionales);
        }

        // Desactivado o con contraseña nueva (por ejemplo, por sospecha de uso indebido):
        // las sesiones que tenía abiertas en el portal se cierran.
        if (! $usuario->activo || isset($datos['password'])) {
            $usuario->tokens()->delete();
        }

        $cambios = Bitacora::cambios($antes, $usuario->only(self::CAMPOS));
        if ($adicionales !== null && $correosAntes !== collect($adicionales)->sort()->values()->all()) {
            $cambios['correos_adicionales'] = [$correosAntes, $adicionales];
        }
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

        return response()->json($usuario->load('correos:id,usuario_id,email'));
    }

    /**
     * Renueva la cuenta de quien está inactivo (vencida, o desactivada, como las que
     * venían vencidas de Horizon): queda activa hasta el cierre del año académico
     * siguiente (config/usuarios.php). Lo puede hacer todo el personal. Si la cuenta
     * ya sirve, no cambia nada.
     */
    public function habilitar(Usuario $usuario)
    {
        if ($usuario->activo) {
            return response()->json($usuario->fresh());
        }

        $antes = $usuario->vigente_hasta?->toDateString();
        $hasta = Usuario::proximoVencimiento(tipo: $usuario->tipo);

        // Solo si sigue sin servir: si dos personas la renuevan a la vez, el cambio y su
        // registro en la bitácora quedan una sola vez.
        if (Usuario::whereKey($usuario->id)->noVigentes()->update(['activo' => true, 'vigente_hasta' => $hasta?->toDateString()])) {
            Bitacora::registrar(
                'usuarios.habilitar',
                "Renovó la cuenta de {$usuario->nombre} {$usuario->apellido}".($hasta ? " hasta el {$hasta->format('d/m/Y')}" : ' (sin vencimiento)'),
                entidad: $usuario,
                datos: ['activo' => [false, true], 'vigente_hasta' => [$antes, $hasta?->toDateString()]],
                afectado: $usuario,
            );
        }

        return response()->json($usuario->fresh());
    }

    /** Solo el Administrador/a: el usuario queda sin entradas, préstamos ni portal. */
    public function desactivar(Usuario $usuario)
    {
        return $this->cambiarEstado($usuario, false);
    }

    private function cambiarEstado(Usuario $usuario, bool $activo)
    {
        // Solo si sigue en el estado anterior: si dos personas lo hacen a la vez, el
        // cambio y su registro en la bitácora quedan una sola vez.
        if (Usuario::whereKey($usuario->id)->where('activo', ! $activo)->update(['activo' => $activo])) {
            if (! $activo) {
                $usuario->tokens()->delete();
            }

            Bitacora::registrar(
                'usuarios.desactivar',
                "Desactivó a {$usuario->nombre} {$usuario->apellido}",
                entidad: $usuario,
                datos: ['activo' => [true, false]],
                afectado: $usuario,
            );
        }

        return response()->json($usuario->fresh());
    }

    /**
     * Primer paso de la carga masiva: revisa las filas de la planilla sin escribir
     * nada y dice qué pasará con cada una — nueva, ya existe (se actualizan sus datos
     * y queda activa), ya existe y está al día, repetida dentro del mismo archivo (no
     * se carga) o con errores (no se carga).
     */
    public function revisarImportacion(Request $request)
    {
        return response()->json($this->clasificar($this->filasDe($request), $this->fechaDeLaCarga($request)));
    }

    /**
     * Segundo paso: exige la confirmación explícita y vuelve a clasificar las filas en
     * el servidor (no confía en la revisión anterior: alguien pudo crear o editar a una
     * de esas personas entretanto). Crea las filas nuevas y, a quienes ya existen, les
     * completa o corrige los datos con los de la planilla y renueva su cuenta; lo que
     * viene vacío en la planilla no borra nada.
     */
    public function importar(Request $request)
    {
        $request->validate(
            ['confirmado' => ['accepted'], 'archivo' => ['nullable', 'string', 'max:255']],
            ['confirmado.accepted' => 'Confirma la carga antes de continuar: revisa el resumen y marca la casilla.'],
        );
        $filas = $this->filasDe($request);
        $archivo = $request->input('archivo');
        $fechaCarga = $this->fechaDeLaCarga($request);

        $resultado = DB::transaction(function () use ($filas, $archivo, $fechaCarga) {
            // Dos cargas a la vez con la misma persona: la segunda espera a la primera y la
            // ve como "ya existe" en vez de chocar con el índice único o pisar sus cambios.
            DB::statement('LOCK TABLE usuarios IN SHARE ROW EXCLUSIVE MODE');

            $clasificacion = $this->clasificar($filas, $fechaCarga);
            $porActualizar = Usuario::findMany(collect($clasificacion['filas'])->where('estado', 'actualizar')->pluck('existente.id'))->keyBy('id');
            $creados = [];
            $actualizados = [];

            foreach ($clasificacion['filas'] as $fila) {
                if ($fila['estado'] === 'nuevo') {
                    $creados[] = Usuario::create($fila['datos'] + ['activo' => true, 'vigente_hasta' => Usuario::proximoVencimiento(tipo: $fila['datos']['tipo'] ?? null)?->toDateString()]);
                } elseif ($fila['estado'] === 'actualizar') {
                    $usuario = $porActualizar[$fila['existente']['id']];
                    $usuario->update(array_map(fn (array $cambio) => $cambio[1], Arr::except($fila['cambios'], ['correos_adicionales'])));
                    foreach ($fila['cambios']['correos_adicionales'][1] ?? [] as $correo) {
                        // Se vuelve a comprobar bajo el bloqueo de la tabla: nadie más pudo tomarlo entretanto.
                        $tomado = Usuario::whereRaw('lower(email) = ?', [$correo])->exists() || UsuarioCorreo::whereRaw('lower(email) = ?', [$correo])->exists();
                        if (! $tomado) {
                            $usuario->correos()->create(['email' => $correo]);
                        }
                    }
                    $actualizados[] = $usuario;

                    // Uno por persona, como al editarla a mano: así se encuentra buscándola.
                    Bitacora::registrar(
                        'usuarios.editar',
                        "Actualizó los datos de {$usuario->nombre} {$usuario->apellido} desde ".($archivo ? "la planilla «{$archivo}»" : 'una planilla'),
                        entidad: $usuario,
                        datos: $fila['cambios'],
                        afectado: $usuario,
                    );
                }
            }

            return [$clasificacion, $creados, $actualizados];
        });

        [$clasificacion, $creados, $actualizados] = $resultado;
        $resumen = $clasificacion['resumen'];
        $habilitados = collect($clasificacion['filas'])->where('estado', 'actualizar')->filter(fn ($f) => isset($f['cambios']['activo']))->count();
        $omitidas = $resumen['duplicados'] + $resumen['invalidos'];

        $hecho = array_filter([
            $creados ? 'cargó '.count($creados).' '.(count($creados) === 1 ? 'usuario nuevo' : 'usuarios nuevos') : null,
            $actualizados ? 'actualizó '.count($actualizados).($habilitados ? " ({$habilitados} ".($habilitados === 1 ? 'quedó habilitado' : 'quedaron habilitados').')' : '') : null,
        ]);
        $notas = array_filter([
            $resumen['sin_cambios'] ? "{$resumen['sin_cambios']} ".($resumen['sin_cambios'] === 1 ? 'ya estaba' : 'ya estaban').' al día' : null,
            $omitidas ? "{$omitidas} ".($omitidas === 1 ? 'fila no se cargó' : 'filas no se cargaron') : null,
        ]);

        Bitacora::registrar(
            'usuarios.importar',
            ucfirst(implode(' y ', $hecho) ?: 'no cargó ni actualizó a nadie').' desde una planilla'.($notas ? ' ('.implode('; ', $notas).')' : ''),
            datos: [
                'archivo' => $archivo,
                'resumen' => $resumen,
                'creados' => collect($creados)->map(fn (Usuario $u) => $u->rut)->all(),
            ],
        );

        return response()->json([
            'creados' => count($creados),
            'actualizados' => count($actualizados),
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

        // «Vigente hasta»: opcional. Si viene, manda; si no, la cuenta se renueva con la fecha de siempre.
        if (($vigente = $texto('vigente_hasta')) !== null) {
            // Si no se entiende se deja tal cual: la validación de la fila la rechaza con su mensaje.
            $datos['vigente_hasta'] = $this->fechaDeVigencia($vigente) ?? $vigente;
        }

        if (array_key_exists('activo', $entrada)) {
            $datos['activo'] = filter_var($entrada['activo'], FILTER_VALIDATE_BOOLEAN);
        }
        if (! empty($entrada['password'])) {
            $datos['password'] = (string) $entrada['password'];
        }

        return $datos;
    }

    /**
     * Los correos adicionales que llegaron en la petición, listos para guardar: sin repetidos, sin
     * el principal y sin que otra persona los tenga. Null si la petición no trae el campo (no se toca).
     *
     * @return list<string>|null
     */
    private function correosAdicionalesDe(Request $request, ?Usuario $ignorar, ?string $principal): ?array
    {
        if (! $request->has('correos_adicionales')) {
            return null;
        }

        $lista = collect((array) $request->input('correos_adicionales'))
            ->map(fn ($c) => mb_strtolower(trim((string) $c)))
            ->filter()
            ->reject(fn ($c) => $c === mb_strtolower((string) $principal))
            ->unique()
            ->values();

        $errores = [];
        foreach ($lista as $correo) {
            if (! filter_var($correo, FILTER_VALIDATE_EMAIL) || mb_strlen($correo) > 255) {
                $errores[] = "«{$correo}» no es un correo válido.";

                continue;
            }
            $duenio = Usuario::whereRaw('lower(email) = ?', [$correo])->when($ignorar, fn ($q) => $q->whereKeyNot($ignorar->id))->first()
                ?? UsuarioCorreo::whereRaw('lower(email) = ?', [$correo])->when($ignorar, fn ($q) => $q->where('usuario_id', '!=', $ignorar->id))->first()?->usuario;
            if ($duenio) {
                $errores[] = "El correo {$correo} ya lo usa {$duenio->nombre} {$duenio->apellido} ({$duenio->rut}).";
            }
        }
        if ($errores) {
            throw ValidationException::withMessages(['correos_adicionales' => $errores]);
        }

        return $lista->all();
    }

    /** Deja exactamente esos correos adicionales: agrega los nuevos y quita los que ya no están. */
    private function sincronizarCorreos(Usuario $usuario, array $lista): void
    {
        $usuario->correos()->whereNotIn(DB::raw('lower(email)'), $lista ?: [''])->delete();
        $actuales = $usuario->correos()->pluck('email')->map(fn ($c) => mb_strtolower($c))->all();
        foreach (array_diff($lista, $actuales) as $correo) {
            $usuario->correos()->create(['email' => $correo]);
        }
    }

    /**
     * La fecha de vencimiento que quien administra eligió para TODA esta carga, en vez de
     * la que dice la política de cada tipo. Solo con el permiso de gestionar la vigencia.
     */
    private function fechaDeLaCarga(Request $request): ?string
    {
        if (! $request->filled('vigente_hasta')) {
            return null;
        }

        if (! $request->user()->puede('vigencia.gestionar')) {
            abort(403, 'Tu rol no tiene el permiso «'.Permisos::etiqueta('vigencia.gestionar').'». Si lo necesitas, pídeselo a un administrador.');
        }

        $fecha = $this->fechaDeVigencia((string) $request->input('vigente_hasta'));
        if ($fecha === null) {
            throw ValidationException::withMessages(['vigente_hasta' => ['La fecha de vencimiento de la carga no es válida.']]);
        }

        return $fecha;
    }

    /** «31/03/2027», «31-03-2027», «31.03.2027» o «2027-03-31» → «2027-03-31»; null si no es una fecha. */
    private function fechaDeVigencia(string $texto): ?string
    {
        $texto = trim($texto);
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y'] as $formato) {
            $fecha = \DateTimeImmutable::createFromFormat('!'.$formato, substr($texto, 0, 10));
            $errores = \DateTimeImmutable::getLastErrors();
            if ($fecha && ! ($errores['warning_count'] ?? 0) && ! ($errores['error_count'] ?? 0) && (int) $fecha->format('Y') >= 1990) {
                return $fecha->format('Y-m-d');
            }
        }

        return null;
    }

    private function reglas(?Usuario $ignorar = null): array
    {
        return [
            'tipo_documento' => ['required', Rule::in(array_keys(Documento::TIPOS))],
            'pais' => ['nullable', 'string', 'max:100'],
            'nombre' => ['required', 'string', 'max:255'],
            'apellido' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('usuarios', 'email')->ignore($ignorar?->id), function (string $atributo, mixed $valor, \Closure $fallar) use ($ignorar) {
                $esAdicionalDeOtro = $valor && UsuarioCorreo::whereRaw('lower(email) = ?', [mb_strtolower((string) $valor)])
                    ->when($ignorar, fn ($q) => $q->where('usuario_id', '!=', $ignorar->id))
                    ->exists();
                if ($esAdicionalDeOtro) {
                    $fallar('Ese correo ya lo usa otro usuario.');
                }
            }],
            'tipo' => ['required', Rule::in(self::TIPOS)],
            'carrera' => ['nullable', 'string', 'max:255'],
            'anio_ingreso' => ['nullable', 'integer', 'min:1950', 'max:'.(now()->year + 1)],
            'sexo' => ['nullable', 'string', 'max:30'],
            'activo' => ['sometimes', 'boolean'],
            'vigente_hasta' => ['nullable', 'date_format:Y-m-d'],
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
            'vigente_hasta.date_format' => 'La fecha de vencimiento no es válida: escríbela como dd/mm/aaaa (ej. 31/03/2027).',
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
    private function clasificar(array $filas, ?string $fechaCarga = null): array
    {
        $resultado = [];
        $vistos = [];
        $emailsVistos = [];

        $carreras = $this->carrerasConocidas();

        // Cada fila como quedaría en el sistema. Contraseñas no viajan en planillas: quien se
        // carga así entra al portal con el login institucional o con la contraseña que se le
        // asigne después al editarlo. Y una carga nunca deja a nadie inactivo.
        $preparadas = array_map(function (array $fila) use ($carreras, $fechaCarga) {
            $errores = [];
            $datos = $this->comoEnElSistema($this->normalizar(array_diff_key($fila, ['password' => 0, 'activo' => 0]), $errores), $carreras);
            // La fecha escogida para toda la carga vale para las filas que no traen la suya.
            if ($fechaCarga && ! isset($datos['vigente_hasta'])) {
                $datos['vigente_hasta'] = $fechaCarga;
            }

            return [$fila, $datos, $errores];
        }, $filas);

        // Una sola consulta para saber quiénes ya existen, en vez de una por fila. Se busca con
        // el documento ya formateado, el mismo que se guardaría: el reporte de matrícula trae
        // los RUT de 7 dígitos con un cero adelante («08733712-K») y se guardan sin él.
        $normalizados = collect($preparadas)->map(fn ($p) => Documento::normalizar($p[1]['rut'] ?? ''))->filter()->unique()->values();
        $existentes = $normalizados->chunk(1000)->flatMap(fn ($lote) => Usuario::whereRaw(
            Documento::sql('rut').' in ('.implode(',', array_fill(0, $lote->count(), '?')).')',
            $lote->values()->all(),
        )->with('correos:id,usuario_id,email')->get(['id', 'vigente_hasta', ...self::CAMPOS]))->keyBy(fn (Usuario $u) => Documento::normalizar($u->rut));

        $emails = collect($filas)->map(fn ($f) => mb_strtolower(trim((string) ($f['email'] ?? ''))))->filter()->unique()->values();
        // Quién tiene cada correo: como principal o como adicional.
        $emailsTomados = $emails->chunk(1000)->flatMap(fn ($lote) => Usuario::whereIn(DB::raw('lower(email)'), $lote->all())
            ->get(['id', 'rut', 'nombre', 'apellido', 'email']))->keyBy(fn (Usuario $u) => mb_strtolower($u->email))
            ->union($emails->chunk(1000)->flatMap(fn ($lote) => UsuarioCorreo::whereIn(DB::raw('lower(email)'), $lote->all())
                ->with('usuario:id,rut,nombre,apellido')->get())->filter(fn (UsuarioCorreo $c) => $c->usuario)
                ->mapWithKeys(fn (UsuarioCorreo $c) => [mb_strtolower($c->email) => $c->usuario]));

        $reglasFila = $this->reglas();
        $reglasFila['email'] = ['nullable', 'email', 'max:255'];
        // A quien ya existe le basta con lo que traiga la planilla (por ejemplo, solo RUT y carrera).
        $reglasExistente = [
            'nombre' => ['nullable', 'string', 'max:255'],
            'apellido' => ['nullable', 'string', 'max:255'],
            'tipo' => ['nullable', Rule::in(self::TIPOS)],
        ] + $reglasFila;

        foreach ($preparadas as $i => [$fila, $datos, $errores]) {
            $numero = (int) ($fila['fila'] ?? $i + 2);
            $clave = Documento::normalizar($datos['rut']);
            $mensajes = [];
            $cambios = [];
            $existente = null;

            if (isset($errores['rut'])) {
                $estado = 'invalido';
                $mensajes = array_merge($errores['rut'], Validator::make($datos, $reglasFila, $this->mensajes())->errors()->all());
            } elseif (isset($vistos[$clave])) {
                $estado = 'duplicado';
                $mensajes[] = "Este {$this->nombreDocumento($datos['tipo_documento'])} ya aparece en la fila {$vistos[$clave]} del archivo. No se cargará dos veces.";
            } elseif ($existente = $existentes->get($clave)) {
                [$estado, $mensajes, $cambios] = $this->compararConExistente($existente, $fila, $datos, $reglasExistente, $emailsTomados, $emailsVistos, $numero);
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
                'cambios' => $cambios,
                'mensajes' => $mensajes,
                'existente' => $existente?->only(['id', 'rut', 'nombre', 'apellido']),
            ];
        }

        $porEstado = collect($resultado)->countBy('estado');

        return [
            'resumen' => [
                'total' => count($resultado),
                'nuevos' => $porEstado->get('nuevo', 0),
                'actualizados' => $porEstado->get('actualizar', 0),
                'sin_cambios' => $porEstado->get('sin_cambios', 0),
                'duplicados' => $porEstado->get('duplicado', 0),
                'invalidos' => $porEstado->get('invalido', 0),
            ],
            'filas' => $resultado,
        ];
    }

    /**
     * Qué cambia en quien ya existe: cada dato que trae la planilla y es distinto del
     * guardado (lo que viene vacío no borra nada) y, si estaba inactivo, que quede activo.
     * El tipo (estudiante, docente…) cambia solo si la planilla tiene esa columna: el
     * reporte de matrícula no la trae, y una docente que además estudia sigue docente.
     *
     * @param  Collection<string, Usuario>  $emailsTomados
     * @param  array<string, int>  $emailsVistos  correo => fila del archivo que ya lo usa
     * @return array{0: string, 1: list<string>, 2: array<string, array{0: mixed, 1: mixed}>}  estado, mensajes y cambios [campo => [antes, después]]
     */
    private function compararConExistente(Usuario $existente, array $fila, array $datos, array $reglas, Collection $emailsTomados, array &$emailsVistos, int $numero): array
    {
        $quien = "{$existente->nombre} {$existente->apellido} ({$existente->rut})";

        // Un RUT y un DNI o pasaporte pueden tener los mismos números sin ser la misma persona.
        if ($existente->tipo_documento !== 'otro' && ($existente->tipo_documento === 'rut') !== ($datos['tipo_documento'] === 'rut')) {
            return ['invalido', ["El número coincide con el {$this->nombreDocumento($existente->tipo_documento)} de {$quien}, pero en la planilla viene como {$this->nombreDocumento($datos['tipo_documento'])}: revisa si es la misma persona. No se cargará."], []];
        }

        if ($errores = Validator::make($datos, $reglas, $this->mensajes())->errors()->all()) {
            return ['invalido', [...$errores, "No se actualizará a {$quien}."], []];
        }

        $campos = ['nombre', 'apellido', 'carrera', 'anio_ingreso', 'sexo', 'pais'];
        if (trim((string) ($fila['tipo'] ?? '')) !== '') {
            $campos[] = 'tipo';
        }

        $cambios = [];
        foreach ($campos as $campo) {
            if ($datos[$campo] !== null && (string) $datos[$campo] !== (string) $existente->{$campo}) {
                $cambios[$campo] = [$existente->{$campo}, $datos[$campo]];
            }
        }

        $avisos = [];
        $email = $datos['email'];
        if ($email !== null && $email !== mb_strtolower((string) $existente->email)) {
            if (($duenio = $emailsTomados->get($email)) && $duenio->id !== $existente->id) {
                $avisos[] = "El correo {$email} ya lo usa {$duenio->nombre} {$duenio->apellido} ({$duenio->rut}): se deja el que tenía.";
            } elseif (isset($emailsVistos[$email])) {
                $avisos[] = "El correo {$email} ya aparece en la fila {$emailsVistos[$email]} del archivo: se deja el que tenía.";
            } elseif ($existente->correos->contains(fn (UsuarioCorreo $c) => mb_strtolower($c->email) === $email)) {
                // Ya lo tiene como adicional: no hay nada que hacer.
                $emailsVistos[$email] = $numero;
            } elseif (! $existente->email) {
                $cambios['email'] = [null, $email];
                $emailsVistos[$email] = $numero;
            } else {
                // Ya tenía un correo y la planilla trae otro: se guarda como segundo correo (+1).
                $cambios['correos_adicionales'] = [null, [$email]];
                $avisos[] = "Ya tenía el correo {$existente->email}: {$email} se agrega como correo adicional (+1).";
                $emailsVistos[$email] = $numero;
            }
        }

        // Venir en la planilla renueva la cuenta: queda vigente hasta el próximo cierre de año
        // académico (quien ya está vigente hasta esa fecha no cambia). Si la planilla trae
        // «Vigente hasta», esa fecha manda, sea más corta o más larga.
        // Cuánto dura depende del tipo de usuario (los docentes y funcionarios pueden tener otra regla).
        $explicita = isset($datos['vigente_hasta']) ? Carbon::parse($datos['vigente_hasta']) : null;
        $tipoDeLaCuenta = trim((string) ($fila['tipo'] ?? '')) !== '' ? $datos['tipo'] : $existente->tipo;
        $vigencia = $explicita ?? Usuario::proximoVencimiento(tipo: $tipoDeLaCuenta);
        // Sin vencimiento ($vigencia null): solo cambia si hoy tiene una fecha.
        $cambiaFecha = match (true) {
            $vigencia === null => $existente->vigente_hasta !== null,
            (bool) $explicita => ! $existente->vigente_hasta || ! $existente->vigente_hasta->equalTo($vigencia),
            default => $existente->vigente_hasta !== null && $existente->vigente_hasta->lessThan($vigencia),
        };

        if (! $existente->activo && ($vigencia === null || $vigencia->copy()->endOfDay()->isFuture())) {
            $cambios['activo'] = [false, true];
        }
        if ($cambiaFecha || ($vigencia && ! $existente->vigente_hasta)) {
            $cambios['vigente_hasta'] = [$existente->vigente_hasta?->toDateString(), $vigencia?->toDateString()];
        }

        return [
            $cambios ? 'actualizar' : 'sin_cambios',
            [($cambios ? 'Ya existe: ' : 'Ya existe y sus datos están al día: ').$quien.'.', ...$avisos],
            $cambios,
        ];
    }
}
