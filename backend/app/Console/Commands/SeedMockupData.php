<?php

namespace App\Console\Commands;

use App\Models\Autor;
use App\Models\Carrera;
use App\Models\Categoria;
use App\Models\CategoriaRecurso;
use App\Models\Ejemplar;
use App\Models\Entrada;
use App\Models\Equipo;
use App\Models\Libro;
use App\Models\Prestamo;
use App\Models\Reserva;
use App\Models\ReservaLibro;
use App\Models\Sala;
use App\Models\Staff;
use App\Models\TipoMaterial;
use App\Models\Ubicacion;
use App\Models\Usuario;
use App\Services\MultaService;
use App\Services\ReservaSalaService;
use App\Support\HorarioBiblioteca;
use App\Support\Rut;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SeedMockupData extends Command
{
    protected $signature = 'mockup:datos
        {--fresh : Borra los datos de prueba existentes antes de crear los nuevos}
        {--force : Permite correrlo aunque APP_ENV sea production}';

    protected $description = 'Carga datos de prueba (mockup) para desarrollo: staff, usuarios con carrera, salas, entradas, préstamos y reservas.';

    private array $carreras = [
        'Ingeniería Civil Informática',
        'Ingeniería Comercial',
        'Derecho',
        'Enfermería',
        'Trabajo Social',
        'Pedagogía en Educación Básica',
        'Medicina Veterinaria',
        'Construcción Civil',
    ];

    private array $aniosIngreso = [2021, 2022, 2023, 2024, 2025, 2026];

    private array $sexos = ['Femenino', 'Masculino'];

    private array $tiposUsuario = ['estudiante', 'estudiante', 'estudiante', 'docente', 'funcionario'];

    private array $nombres = [
        'Camila', 'Matías', 'Francisca', 'Benjamín', 'Valentina', 'Diego', 'Javiera', 'Andrés',
        'Sofía', 'Felipe', 'Antonia', 'Carlos', 'Fernanda', 'Ignacio', 'Catalina', 'Pedro',
        'Josefa', 'Rodrigo', 'Isidora', 'Tomás', 'Constanza', 'Sebastián', 'Trinidad', 'Nicolás',
        'Florencia', 'Vicente', 'Amanda', 'Gabriel', 'Emilia', 'Joaquín',
    ];

    private array $apellidos = [
        'Soto', 'Vera', 'Muñoz', 'Alvarado', 'Ríos', 'Morales', 'Espinoza', 'Vargas',
        'Contreras', 'Rojas', 'Bravo', 'Díaz', 'Torres', 'Herrera', 'Fernández', 'Pérez',
        'Gómez', 'Sánchez', 'Reyes', 'Castro',
    ];

    /** Catálogo completo de biblioteca (título/autor/área) para el portal de usuario. */
    private array $catalogoLibros = [
        ['codigo' => '9789561228351', 'titulo' => 'Introducción a la Programación en Python', 'autor' => 'John V. Guttag', 'categoria' => 'Computación'],
        ['codigo' => '9789706868824', 'titulo' => 'Cálculo Diferencial e Integral - Stewart', 'autor' => 'James Stewart', 'categoria' => 'Matemáticas'],
        ['codigo' => '9786073237826', 'titulo' => 'Física Universitaria - Sears & Zemansky', 'autor' => 'Hugh D. Young', 'categoria' => 'Física'],
        ['codigo' => '9786071509789', 'titulo' => 'Álgebra Lineal - Grossman', 'autor' => 'Stanley Grossman', 'categoria' => 'Matemáticas'],
        ['codigo' => '9786071513939', 'titulo' => 'Química General - Chang', 'autor' => 'Raymond Chang', 'categoria' => 'Química'],
        ['codigo' => '9789563160215', 'titulo' => 'Historia de la Patagonia', 'autor' => 'Mateo Martinic', 'categoria' => 'Historia'],
        ['codigo' => '9789562827164', 'titulo' => 'Botánica Austral', 'autor' => 'Carlos Zöllner', 'categoria' => 'Biología'],
        ['codigo' => '9789561224100', 'titulo' => 'Derecho Constitucional Chileno', 'autor' => 'Humberto Nogueira', 'categoria' => 'Derecho'],
        ['codigo' => '9789562014533', 'titulo' => 'Enfermería Comunitaria', 'autor' => 'Marcia Padilla', 'categoria' => 'Enfermería'],
        ['codigo' => '9789561420311', 'titulo' => 'Ecología y Medio Ambiente', 'autor' => 'Eugene Odum', 'categoria' => 'Biología'],
        ['titulo' => 'Estructuras de Datos y Algoritmos', 'autor' => 'Robert Sedgewick', 'categoria' => 'Computación'],
        ['titulo' => 'Redes de Computadores', 'autor' => 'Andrew Tanenbaum', 'categoria' => 'Computación'],
        ['titulo' => 'Ingeniería de Software', 'autor' => 'Ian Sommerville', 'categoria' => 'Computación'],
        ['titulo' => 'Bases de Datos', 'autor' => 'Abraham Silberschatz', 'categoria' => 'Computación'],
        ['titulo' => 'Cálculo I', 'autor' => 'Michael Spivak', 'categoria' => 'Matemáticas'],
        ['titulo' => 'Probabilidad y Estadística', 'autor' => 'Ronald Walpole', 'categoria' => 'Matemáticas'],
        ['titulo' => 'Macroeconomía', 'autor' => 'Gregory Mankiw', 'categoria' => 'Economía'],
        ['titulo' => 'Microeconomía Intermedia', 'autor' => 'Hal Varian', 'categoria' => 'Economía'],
        ['titulo' => 'Contabilidad General', 'autor' => 'Charles Horngren', 'categoria' => 'Economía'],
        ['titulo' => 'Introducción al Derecho Civil', 'autor' => 'Arturo Alessandri', 'categoria' => 'Derecho'],
        ['titulo' => 'Derecho Procesal Penal', 'autor' => 'Cristian Maturana', 'categoria' => 'Derecho'],
        ['titulo' => 'Anatomía y Fisiología', 'autor' => 'Gerard Tortora', 'categoria' => 'Enfermería'],
        ['titulo' => 'Farmacología Clínica', 'autor' => 'Bertram Katzung', 'categoria' => 'Enfermería'],
        ['titulo' => 'Fundamentos de Trabajo Social', 'autor' => 'Ezequiel Ander-Egg', 'categoria' => 'Trabajo Social'],
        ['titulo' => 'Psicología Social', 'autor' => 'David Myers', 'categoria' => 'Psicología'],
        ['titulo' => 'Didáctica General', 'autor' => 'Alicia Camilloni', 'categoria' => 'Educación'],
        ['titulo' => 'Psicología del Desarrollo', 'autor' => 'Jean Piaget', 'categoria' => 'Educación'],
        ['titulo' => 'Producción Animal', 'autor' => 'Ricardo Bocco', 'categoria' => 'Medicina Veterinaria'],
        ['titulo' => 'Patología Veterinaria', 'autor' => 'M. Donald McGavin', 'categoria' => 'Medicina Veterinaria'],
        ['titulo' => 'Resistencia de Materiales', 'autor' => 'Ferdinand Beer', 'categoria' => 'Construcción Civil'],
        ['titulo' => 'Hormigón Armado', 'autor' => 'Jack McCormac', 'categoria' => 'Construcción Civil'],
        ['titulo' => 'Historia de Chile Contemporáneo', 'autor' => 'Alfredo Jocelyn-Holt', 'categoria' => 'Historia'],
        ['titulo' => 'Geografía de Magallanes', 'autor' => 'Mateo Martinic', 'categoria' => 'Historia'],
        ['titulo' => 'Biología Molecular de la Célula', 'autor' => 'Bruce Alberts', 'categoria' => 'Biología'],
    ];

    /** Rango Dewey base por categoría, para inventar una clasificación plausible de los libros del mockup. */
    private array $deweyPorCategoria = [
        'Computación' => '005',
        'Matemáticas' => '510',
        'Física' => '530',
        'Química' => '540',
        'Biología' => '570',
        'Historia' => '980',
        'Derecho' => '340',
        'Enfermería' => '610',
        'Economía' => '330',
        'Trabajo Social' => '360',
        'Psicología' => '150',
        'Educación' => '370',
        'Medicina Veterinaria' => '636',
        'Construcción Civil' => '690',
    ];

    private array $editoriales = [
        'Pearson', 'McGraw-Hill', 'Cengage Learning', 'Alfaomega', 'Prentice Hall',
        'Addison-Wesley', 'Editorial Universitaria', 'LOM Ediciones', 'Ediciones UC',
        'Editorial Jurídica de Chile',
    ];

    public function handle(): int
    {
        // Crea cuentas con contraseñas conocidas (admin@umag.cl / admin123 tiene
        // acceso total) y 30 personas ficticias: nunca en producción por descuido.
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('mockup:datos carga datos ficticios y cuentas con contraseñas conocidas: no se corre en producción.');
            $this->line('Para crear la primera cuenta real: php artisan personal:crear-admin <correo> --nombre="Nombre Apellido"');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->info('Eliminando datos de prueba existentes...');
            // Orden importa: prestamos/entradas/reservas_libro/ejemplares tienen FK
            // RESTRICT hacia usuarios/libros/equipos/staff (ver migración
            // restrict_delete_on_historial_foreign_keys y el split Libro/Ejemplar), así
            // que deben borrarse antes que sus padres, no después. autores/categorias/
            // carreras/estados_libro_personalizados NO se borran a propósito: son
            // catálogos que se reutilizan entre corridas (firstOrCreate por nombre), no
            // datos de prueba transaccionales — carreras además solo se siembra una vez
            // desde su migración, borrarla aquí la dejaría vacía para siempre.
            DB::table('multas')->delete();
            DB::table('ejemplar_estado_historial')->delete();
            DB::table('reservas_libro')->delete();
            DB::table('prestamos')->delete();
            DB::table('reserva_participantes')->delete();
            DB::table('reservas')->delete();
            DB::table('entradas')->delete();
            DB::table('libro_autor')->delete();
            DB::table('libro_categoria')->delete();
            DB::table('libro_carrera')->delete();
            DB::table('ejemplares')->delete();
            DB::table('libros')->delete();
            DB::table('equipos')->delete();
            DB::table('usuarios')->delete();
            DB::table('salas')->delete();
            DB::table('staff')->delete();
        } elseif (Staff::count() > 0) {
            $this->warn('Ya existen datos de prueba. Usa --fresh para regenerarlos desde cero.');

            return self::SUCCESS;
        }

        $this->seedStaff();
        $usuarios = $this->seedUsuarios(30);
        $this->seedEntradas($usuarios);
        $equipos = $this->seedEquipos();
        $ejemplares = $this->seedLibros();
        $this->seedPrestamos($usuarios, $equipos, $ejemplares);
        $this->seedMultas($usuarios);
        // Después de las multas, para que estas salgan de los préstamos recientes.
        $this->seedPrestamosHistoricos($usuarios, $equipos, $ejemplares);
        $salas = $this->seedSalas();
        $this->seedReservas($salas, $usuarios);
        $this->seedReservasLibro($ejemplares, $usuarios);
        $this->seedEscenarioDemostracion();
        $this->seedHistorialEstado();

        $this->info('Datos de prueba cargados correctamente.');

        return self::SUCCESS;
    }

    private function seedStaff(): void
    {
        Staff::create([
            'email' => 'admin@umag.cl',
            'password' => Hash::make('admin123'),
            'nombre' => 'Ignacio Contreras',
            'rol' => 'admin',
        ]);

        // Una cuenta por cada rol por defecto, para poder probar qué ve cada uno.
        Staff::create([
            'email' => 'bibliotecaria@umag.cl',
            'password' => Hash::make('biblio123'),
            'nombre' => 'Carolina Muñoz',
            'rol' => 'bibliotecario',
        ]);

        Staff::create([
            'email' => 'meson@umag.cl',
            'password' => Hash::make('meson123'),
            'nombre' => 'Pedro Soto',
            'rol' => 'asistente',
        ]);

        $this->line('  · Staff creado: admin@umag.cl / admin123 (Administrador/a), bibliotecaria@umag.cl / biblio123 (Bibliotecario/a), meson@umag.cl / meson123 (Atención de público)');
    }

    /**
     * Multas de ejemplo: algunas por atraso (a partir de préstamos devueltos tarde,
     * calculadas con la misma tarifa que usa la devolución real) y un par cursadas a
     * mano, en distintos estados.
     */
    private function seedMultas($usuarios): void
    {
        $multaService = app(MultaService::class);
        $staff = Staff::where('rol', 'admin')->first();
        $total = 0;

        // Solo préstamos cuyo plazo ya venció hace al menos un día: la devolución
        // "tardía" simulada no puede quedar en el futuro.
        $devueltos = Prestamo::where('tipo_item', 'libro')
            ->where('estado', 'devuelto')
            ->where('fecha_devolucion', '<', now()->subDay())
            ->inRandomOrder()
            ->limit(6)
            ->get();

        foreach ($devueltos as $i => $prestamo) {
            $diasDisponibles = max(1, (int) floor($prestamo->fecha_devolucion->diffInDays(now())));
            $fechaReal = $this->dentroDelHorario($prestamo->fecha_devolucion->copy()->addDays(random_int(1, min(9, $diasDisponibles))), haciaAtras: false);
            if ($fechaReal->isFuture()) {
                $fechaReal = $this->momentoEnHorario(0);
            }
            $prestamo->update(['fecha_devolucion_real' => $fechaReal]);

            $multa = $multaService->generarPorAtraso($prestamo, $fechaReal, $staff);
            if (! $multa) {
                continue;
            }
            $multa->forceFill(['created_at' => $fechaReal])->save();
            if ($i >= 4) {
                $multaService->registrarPago($multa, $staff);
            }
            $total++;
        }

        $conDeuda = $usuarios->random(2)->values();
        $multaService->crearManual($conDeuda[0], [
            'tipo' => 'perdida',
            'monto' => 25000,
            'detalle' => 'Pérdida de "Cálculo de una variable" — reposición del ejemplar',
        ], $staff);
        $anulada = $multaService->crearManual($conDeuda[1], [
            'tipo' => 'deterioro',
            'monto' => 4000,
            'detalle' => 'Páginas rayadas con destacador',
        ], $staff);
        $multaService->anular($anulada, 'El daño ya venía registrado de un préstamo anterior', $staff);
        $total += 2;

        $this->line("  · {$total} multas creadas (atraso, pagadas, manuales y una anulada)");
    }

    /** @return \Illuminate\Support\Collection<int, Usuario> */
    private function seedUsuarios(int $cantidad)
    {
        $usuarios = collect();
        $rutBase = 15000000;

        for ($i = 0; $i < $cantidad; $i++) {
            $nombre = $this->nombres[array_rand($this->nombres)];
            $apellido = $this->apellidos[array_rand($this->apellidos)];
            $rutNum = $rutBase + ($i * 137);

            $usuarios->push(Usuario::create([
                'rut' => $this->formatearRut($rutNum),
                'nombre' => $nombre,
                'apellido' => $apellido,
                'email' => Str::lower(Str::ascii("{$nombre}.{$apellido}{$i}")).'@umag.cl',
                'password' => Hash::make('umag123'),
                'tipo' => $this->tiposUsuario[array_rand($this->tiposUsuario)],
                'carrera' => $this->carreras[array_rand($this->carreras)],
                'anio_ingreso' => $this->aniosIngreso[array_rand($this->aniosIngreso)],
                'sexo' => $this->sexos[array_rand($this->sexos)],
                'activo' => true,
                'qr_code' => strtoupper(uniqid('QR')),
            ]));
        }

        $this->line("  · {$cantidad} usuarios creados (con carrera, año de ingreso, sexo y clave de portal: umag123)");

        return $usuarios;
    }

    private function seedEntradas($usuarios): void
    {
        $total = 0;

        // Cómo entró cada uno: solo desde el portal (QR o su RUT, sin personal de por
        // medio) o en el mesón, donde queda quién lo atendió.
        $meson = Staff::where('email', 'meson@umag.cl')->first();
        $via = function () use ($meson): array {
            $azar = random_int(0, 99);

            return match (true) {
                $azar < 35 => ['via' => 'qr'],
                $azar < 60 => ['via' => 'portal'],
                default => ['via' => 'manual', 'registrado_por_staff_id' => $meson?->id],
            };
        };

        $diasConEntrada = [];

        foreach ($usuarios as $i => $usuario) {
            // Cada usuario tiene entre 1 y 4 entradas distribuidas en los últimos 14 días,
            // en días distintos: una persona queda presente una sola vez por día.
            $dias = collect(range(0, 13))->shuffle()->take(random_int(1, 4));

            foreach ($dias as $dia) {
                $entrada = $this->momentoEnHorario($dia);
                if (isset($diasConEntrada[$usuario->id][$entrada->toDateString()])) {
                    continue; // momentoEnHorario() corre al último día con atención: dos días pueden caer en el mismo.
                }
                $diasConEntrada[$usuario->id][$entrada->toDateString()] = true;
                $conSalida = ! $entrada->isToday() && random_int(0, 100) < 70;

                Entrada::create([
                    'usuario_id' => $usuario->id,
                    'fecha_hora_entrada' => $entrada,
                    'fecha_hora_salida' => $conSalida ? $entrada->copy()->addMinutes(random_int(30, 180)) : null,
                    ...$via(),
                    'codigo_barras' => config('horizon_barcodes.puesto_generico'),
                ]);
                $total++;
            }
        }

        // Garantiza una porción de entradas de hoy, para que "Registro de Entrada"
        // no se vea vacío justo tras correr el seed (si hoy la biblioteca no atiende o
        // todavía no abre, quedan en el último día con atención).
        foreach ($usuarios->random(min(12, $usuarios->count())) as $usuario) {
            $entrada = $this->momentoEnHorario(0);
            if (isset($diasConEntrada[$usuario->id][$entrada->toDateString()])) {
                continue;
            }
            $diasConEntrada[$usuario->id][$entrada->toDateString()] = true;

            Entrada::create([
                'usuario_id' => $usuario->id,
                'fecha_hora_entrada' => $entrada,
                'fecha_hora_salida' => random_int(0, 100) < 60 ? $entrada->copy()->addMinutes(random_int(30, 180)) : null,
                ...$via(),
                'codigo_barras' => config('horizon_barcodes.puesto_generico'),
            ]);
            $total++;
        }

        // Un par de visitantes externos y de convenio para hoy, para que las etiquetas
        // "Externo"/"Convenio" del historial tengan ejemplos reales tras el seed.
        foreach ([false, false, true, true] as $esConvenio) {
            $entrada = $this->momentoEnHorario(0);
            $rutVisitante = $this->formatearRut(random_int(9000000, 9999999));

            Entrada::create([
                'rut_externo' => $rutVisitante,
                'nombre_externo' => $this->nombres[array_rand($this->nombres)].' '.$this->apellidos[array_rand($this->apellidos)],
                'es_convenio' => $esConvenio,
                'registrado_por_staff_id' => $meson?->id,
                'fecha_hora_entrada' => $entrada,
                'fecha_hora_salida' => random_int(0, 100) < 60 ? $entrada->copy()->addMinutes(random_int(30, 180)) : null,
                'via' => 'manual',
                'codigo_barras' => config('horizon_barcodes.puesto_generico'),
            ]);
            $total++;
        }

        $this->line("  · {$total} entradas creadas (últimos 14 días, con refuerzo para hoy)");
    }

    /** @param  \Illuminate\Support\Collection<int, Ejemplar>  $ejemplares */
    private function seedPrestamos($usuarios, $equipos, $ejemplares): void
    {
        $total = 0;

        // Préstamos de libro: se identifican por un ejemplar real (código de barras),
        // igual que exige PrestamoController::store() — se limita el sorteo a
        // ejemplares realmente disponibles para no simular dos préstamos activos
        // simultáneos de la misma copia física (mismo criterio que equipos, más abajo).
        foreach ($usuarios as $i => $usuario) {
            if (random_int(0, 100) > 60) {
                continue; // no todos los usuarios tienen préstamos
            }

            $numPrestamos = random_int(1, 2);

            for ($j = 0; $j < $numPrestamos; $j++) {
                $disponibles = $ejemplares->where('disponible', true);

                if ($disponibles->isEmpty()) {
                    break;
                }

                $ejemplar = $disponibles->random();

                $fechaPrestamo = $this->momentoEnHorario(random_int(0, 20));
                $fechaDevolucion = $fechaPrestamo->copy()->addDays(7);

                $devuelto = random_int(0, 100) < 40;
                $atrasado = ! $devuelto && $fechaDevolucion->isPast();

                Prestamo::create([
                    'usuario_id' => $usuario->id,
                    'ejemplar_id' => $ejemplar->id,
                    'libro_titulo' => $ejemplar->tituloConCopia(),
                    'codigo_barras' => $ejemplar->codigo_barras,
                    'fecha_prestamo' => $fechaPrestamo,
                    'fecha_devolucion' => $fechaDevolucion,
                    'fecha_devolucion_real' => $devuelto ? $this->dentroDelHorario($fechaDevolucion->copy()->subDays(random_int(0, 3))) : null,
                    'estado' => $devuelto ? 'devuelto' : ($atrasado ? 'atrasado' : 'activo'),
                ]);

                if (! $devuelto) {
                    $ejemplar->update(['disponible' => false]);
                }

                $total++;
            }
        }

        // Préstamos de recursos de apoyo (audífonos, notebooks, cargadores y bicicletas): se identifican por
        // código de barras real (tabla `equipos`), no tienen fecha de vencimiento — se
        // devuelven al término de la estadía en la biblioteca. Se limita el sorteo a
        // equipos realmente disponibles del tipo elegido para no simular dos préstamos
        // activos simultáneos del mismo código físico.
        foreach ($usuarios->random(min(10, $usuarios->count())) as $usuario) {
            $tipo = ['audifonos', 'notebook', 'cargador', 'bicicleta'][random_int(0, 3)];
            $disponibles = $equipos->where('tipo', $tipo)->where('disponible', true);

            if ($disponibles->isEmpty()) {
                continue;
            }

            $equipo = $disponibles->random();
            $fechaPrestamo = $this->momentoEnHorario(random_int(0, 5));
            $devuelto = ! $fechaPrestamo->isToday() || random_int(0, 100) < 50;
            $cierre = $fechaPrestamo->copy()->setTimeFromTimeString(HorarioBiblioteca::semana()[$fechaPrestamo->dayOfWeekIso][1]);
            $vuelta = $fechaPrestamo->copy()->addMinutes(random_int(60, 360));
            if ($vuelta->greaterThanOrEqualTo($cierre)) {
                $vuelta = $fechaPrestamo->copy()->addMinutes((int) floor($fechaPrestamo->diffInMinutes($cierre) / 2));
            }
            if ($vuelta->isFuture()) {
                $vuelta = now();
            }

            Prestamo::create([
                'usuario_id' => $usuario->id,
                'equipo_id' => $equipo->id,
                'libro_titulo' => $equipo->codigo_inventario,
                'tipo_item' => $tipo,
                'codigo_barras' => $equipo->codigo_barras,
                'fecha_prestamo' => $fechaPrestamo,
                'fecha_devolucion' => null,
                'fecha_devolucion_real' => $devuelto ? $vuelta : null,
                'estado' => $devuelto ? 'devuelto' : 'activo',
            ]);

            if (! $devuelto) {
                $equipo->update(['disponible' => false]);
            }

            $total++;
        }

        $this->line("  · {$total} préstamos creados (libros y equipos)");
    }

    /**
     * Historial de estado de las copias, coherente con el estado de cada una: la
     * catalogación inicial de la colección en tres cambios masivos (febrero de 2023,
     * antes de los préstamos históricos), el proceso de las copias de la demostración,
     * reparaciones que ya volvieron al estante, una reubicación por lote y, hoy fuera del
     * estante, dos copias dadas de baja y una en reparación. Los cambios recientes solo
     * tocan copias que no estaban prestadas en esas fechas.
     */
    private function seedHistorialEstado(): void
    {
        $bibliotecaria = Staff::where('email', 'bibliotecaria@umag.cl')->firstOrFail();
        $admin = Staff::where('email', 'admin@umag.cl')->firstOrFail();
        [$demo, $coleccion] = Ejemplar::orderBy('id')->get()
            ->partition(fn (Ejemplar $e) => str_starts_with((string) $e->codigo_barras, '3000000000090'));
        $filas = [];
        $cambio = function (Ejemplar $copia, string $de, string $a, Carbon $cuando, Staff $quien, ?string $motivo = null, ?string $lote = null) use (&$filas) {
            $filas[] = [
                'ejemplar_id' => $copia->id,
                'estado_anterior' => $de,
                'estado_nuevo' => $a,
                'staff_id' => $quien->id,
                'lote_id' => $lote,
                'motivo' => $motivo,
                'created_at' => $cuando->copy(),
                'updated_at' => $cuando->copy(),
            ];
        };
        $sinPrestamo = fn (Ejemplar $copia, Carbon $desde, Carbon $hasta) => ! Prestamo::where('ejemplar_id', $copia->id)
            ->where('fecha_prestamo', '<=', $hasta)
            ->where(fn ($q) => $q->whereNull('fecha_devolucion_real')->orWhere('fecha_devolucion_real', '>=', $desde))
            ->exists();
        $dia = fn (int $diasAtras) => $this->dentroDelHorario(now()->subDays($diasAtras), haciaAtras: false);

        // 1. Catalogación inicial de la colección, en tres cambios masivos.
        foreach ([
            ['2023-02-06 10:15', 'inventario', 'procesos_tecnicos', 'Catalogación inicial de la colección'],
            ['2023-02-15 11:40', 'procesos_tecnicos', 'por_colocar', 'Etiquetado y forrado terminados'],
            ['2023-02-22 09:30', 'por_colocar', 'en_estante', 'Colección ubicada en la sala general'],
        ] as [$cuando, $de, $a, $motivo]) {
            $lote = (string) Str::uuid();
            foreach ($coleccion as $copia) {
                $cambio($copia, $de, $a, Carbon::parse($cuando), $bibliotecaria, $motivo, $lote);
            }
        }

        // 2. Las copias de la demostración, una por una, antes de sus préstamos y reservas.
        foreach ($demo->values() as $i => $copia) {
            $inicio = $dia(62 - 2 * $i);
            $cambio($copia, 'inventario', 'procesos_tecnicos', $inicio, $bibliotecaria);
            $cambio($copia, 'procesos_tecnicos', 'por_colocar', $this->dentroDelHorario($inicio->copy()->addDays(3), haciaAtras: false), $bibliotecaria);
            $cambio($copia, 'por_colocar', 'en_estante', $this->dentroDelHorario($inicio->copy()->addDays(5), haciaAtras: false), $bibliotecaria);
        }

        // 3. Reparaciones que ya volvieron al estante.
        $reparaciones = ['Reparación de lomo', 'Cambio de etiqueta de código de barras', 'Limpieza por humedad', 'Refuerzo de tapas'];
        foreach ($coleccion->shuffle() as $copia) {
            if (! $reparaciones) {
                break;
            }
            $sale = $dia(random_int(25, 110));
            $vuelve = $this->dentroDelHorario($sale->copy()->addDays(random_int(2, 6)), haciaAtras: false);
            if (! $sinPrestamo($copia, $sale, $vuelve)) {
                continue;
            }
            $cambio($copia, 'en_estante', 'procesos_tecnicos', $sale, $bibliotecaria, array_shift($reparaciones));
            $cambio($copia, 'procesos_tecnicos', 'en_estante', $vuelve, $bibliotecaria, 'Reparación terminada');
        }

        // 4. Reubicación por lote: cuatro copias salen del estante y vuelven dos días después.
        [$sale, $vuelve] = [$dia(12), $dia(10)];
        $reubicadas = $coleccion->shuffle()->filter(fn (Ejemplar $e) => $sinPrestamo($e, $sale, $vuelve))->take(4);
        foreach ([[$sale, 'en_estante', 'por_colocar', 'Cambio de estanterías en la sala general'], [$vuelve, 'por_colocar', 'en_estante', 'Reubicación terminada']] as [$cuando, $de, $a, $motivo]) {
            $lote = (string) Str::uuid();
            foreach ($reubicadas as $copia) {
                $cambio($copia, $de, $a, $cuando, $bibliotecaria, $motivo, $lote);
            }
        }

        // 5. Hoy fuera del estante, sin préstamo ni reserva: dos dadas de baja, de títulos
        //    con más de una copia (así siguen en el catálogo), y una en reparación.
        $copiasPorLibro = $coleccion->countBy('libro_id');
        $libres = $coleccion->where('disponible', true)->filter(fn (Ejemplar $e) => $sinPrestamo($e, now()->subDays(9), now()))->shuffle();
        $deBaja = $libres->filter(fn (Ejemplar $e) => $copiasPorLibro[$e->libro_id] > 1)->unique('libro_id')->take(2)->values();
        $enReparacion = $libres->whereNotIn('libro_id', $deBaja->pluck('libro_id'))->first();
        foreach ([
            [$deBaja[0] ?? null, 9, 'de_baja', 'Deterioro irreparable: páginas sueltas y daño por humedad', $bibliotecaria],
            [$deBaja[1] ?? null, 4, 'de_baja', 'Extraviado: no se encontró en el inventario de la sala', $admin],
            [$enReparacion, 2, 'procesos_tecnicos', 'Reparación de encuadernación', $bibliotecaria],
        ] as [$copia, $diasAtras, $estado, $motivo, $quien]) {
            if ($copia) {
                $cambio($copia, 'en_estante', $estado, $dia($diasAtras), $quien, $motivo);
                $copia->update(['estado_proceso' => $estado]);
            }
        }

        foreach (array_chunk($filas, 500) as $bloque) {
            DB::table('ejemplar_estado_historial')->insert($bloque);
        }

        $total = count($filas);
        $this->line("  · {$total} cambios de estado de copias (catalogación, reparaciones, una reubicación por lote y bajas)");
    }

    /**
     * Préstamos ya devueltos desde marzo de 2023 hasta donde empiezan los recientes
     * (seedPrestamos(), las últimas tres semanas), para que los reportes por mes,
     * semestre y año tengan historia con qué compararse. Siguen el calendario
     * académico: poco en enero, febrero y julio; más en abril–junio y octubre–noviembre,
     * y algo más cada año. Uno de cada cuatro es de un equipo. Son miles, así que se
     * insertan por bloques.
     */
    private function seedPrestamosHistoricos($usuarios, $equipos, $ejemplares): void
    {
        $temporada = [1 => 0.25, 2 => 0.15, 3 => 0.9, 4 => 1.2, 5 => 1.3, 6 => 1.15, 7 => 0.45, 8 => 1.0, 9 => 0.85, 10 => 1.25, 11 => 1.3, 12 => 0.55];
        $hasta = now()->subDays(21)->startOfDay();
        // Cuándo vuelve cada copia o equipo, para no prestar dos veces lo mismo a la vez.
        $vuelveEl = [];
        $filas = [];

        for ($dia = Carbon::create(2023, 3, 1); $dia->lt($hasta); $dia->addDay()) {
            $horario = HorarioBiblioteca::delDia($dia);
            if (! $horario) {
                continue;
            }
            $apertura = $dia->copy()->setTimeFromTimeString($horario[0]);
            $cierre = $dia->copy()->setTimeFromTimeString($horario[1])->subMinute();
            $esperado = 2.4 * $temporada[$dia->month] * (0.85 + 0.07 * ($dia->year - 2023)) * ($dia->isSaturday() ? 0.4 : 1);

            for ($i = (int) round($esperado * random_int(60, 140) / 100); $i > 0; $i--) {
                $momento = $dia->copy()->setTime($this->horaConSesgo(), random_int(0, 59));
                if (! $momento->between($apertura, $cierre)) {
                    $momento = $apertura->copy()->addMinutes(random_int(0, (int) $apertura->diffInMinutes($cierre)));
                }

                $esEquipo = random_int(1, 100) <= 25;
                $item = $esEquipo ? $equipos->random() : $ejemplares->random();
                $clave = ($esEquipo ? 'equipo-' : 'copia-').$item->id;
                if (isset($vuelveEl[$clave]) && $vuelveEl[$clave]->gt($momento)) {
                    continue;
                }

                if ($esEquipo) {
                    // Los equipos vuelven el mismo día, antes del cierre.
                    $vence = null;
                    $vuelta = $momento->copy()->addMinutes(random_int(60, 300))->min($cierre);
                } else {
                    // Plazo de 7 días; uno de cada siete vuelve con atraso.
                    $vence = $momento->copy()->addDays(7);
                    $vuelta = $this->dentroDelHorario($momento->copy()->addDays(random_int(1, 100) <= 15 ? random_int(8, 14) : random_int(1, 7)), haciaAtras: false);
                }
                $vuelveEl[$clave] = $vuelta;

                $filas[] = [
                    'usuario_id' => $usuarios->random()->id,
                    'ejemplar_id' => $esEquipo ? null : $item->id,
                    'equipo_id' => $esEquipo ? $item->id : null,
                    'libro_titulo' => $esEquipo ? $item->codigo_inventario : $item->tituloConCopia(),
                    'tipo_item' => $esEquipo ? $item->tipo : 'libro',
                    'codigo_barras' => $item->codigo_barras,
                    'fecha_prestamo' => $momento,
                    'fecha_devolucion' => $vence,
                    'fecha_devolucion_real' => $vuelta,
                    'estado' => 'devuelto',
                    'created_at' => $momento,
                    'updated_at' => $vuelta,
                ];
            }
        }

        foreach (array_chunk($filas, 500) as $bloque) {
            DB::table('prestamos')->insert($bloque);
        }

        $total = count($filas);
        $this->line("  · {$total} préstamos históricos devueltos (desde marzo de 2023), para los reportes por mes, semestre y año");
    }

    /**
     * El préstamo real de un equipo se hace escaneando su código de barras físico
     * (codigo_barras) — codigo_inventario queda como el nombre legible que el staff
     * ve en pantalla ("Notebook 01"), no es lo que se escanea. Los códigos de barras
     * reales de los equipos todavía no están disponibles, así que se genera un
     * placeholder largo (mismo criterio que el resto de los códigos de barras
     * inventados de este seeder).
     *
     * @return \Illuminate\Support\Collection<int, Equipo>
     */
    private function seedEquipos()
    {
        $nombres = [
            'audifonos' => ['Audífonos 01', 'Audífonos 02', 'Audífonos 03', 'Audífonos 04'],
            'notebook' => ['Notebook 01', 'Notebook 02', 'Notebook 03'],
            'cargador' => ['Cargador 01', 'Cargador 02', 'Cargador 03'],
            'bicicleta' => ['Bicicleta 01', 'Bicicleta 02', 'Bicicleta 03'],
        ];

        // Las categorías son un catálogo (no se borran con --fresh): la de bicicletas la
        // crea la migración, pero alguien pudo desactivarla.
        CategoriaRecurso::where('clave', 'bicicleta')->update(['activo' => true]);

        $equipos = collect();
        // Desde 101: los códigos 7510000000011 en adelante son los recursos fijos del
        // escenario de demostración (docs/recorrido-presentacion.md).
        $secuencia = 101;

        foreach ($nombres as $tipo => $nombresTipo) {
            foreach ($nombresTipo as $nombre) {
                $equipos->push(Equipo::create([
                    'codigo_inventario' => $nombre,
                    'codigo_barras' => '751'.str_pad((string) $secuencia++, 10, '0', STR_PAD_LEFT),
                    'tipo' => $tipo,
                    'disponible' => true,
                    'activo' => true,
                ]));
            }
        }

        $this->line("  · {$equipos->count()} recursos de apoyo creados (audífonos, notebooks, cargadores y bicicletas)");

        return $equipos;
    }

    /** @return \Illuminate\Support\Collection<int, Sala> */
    private function seedSalas()
    {
        // 15 logias de estudio, todas en la misma ubicación, más 3 salas
        // especiales con nombre propio.
        $capacidades = [2, 3, 4, 2, 3, 4, 2, 3, 4, 2, 3, 4, 2, 3, 4];
        $salas = collect();

        // Por nombre: en una base nueva las salas ya las creó la migración
        // crear_salas_iniciales (sin códigos de barras); acá solo se completan.
        for ($i = 0; $i < 15; $i++) {
            $salas->push(Sala::updateOrCreate(['nombre' => 'Logia '.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)], [
                'capacidad' => $capacidades[$i],
                'tipo' => 'logia',
                // Código de barras hardcodeado por logia (Horizon aún no entrega los
                // reales) — cada logia tiene el suyo, a diferencia del puesto de
                // trabajo que reutiliza uno genérico.
                'codigo_barras' => (string) (90001 + $i),
            ]));
        }

        // Salas especiales: no son logias de estudio individuales, no tienen
        // código de barras Horizon asignado (tipo 'sala', quedan fuera del
        // escaneo de logias en ReservaSalaService::escanearLogia()) pero se
        // reservan con el mismo flujo de bloques horarios.
        $salasEspeciales = [
            ['nombre' => 'Sala de Seminarios', 'capacidad' => 30],
            ['nombre' => 'Sala de Postgrado', 'capacidad' => 20],
            ['nombre' => 'Sala AGACI', 'capacidad' => 10],
        ];

        foreach ($salasEspeciales as $especial) {
            $salas->push(Sala::updateOrCreate(['nombre' => $especial['nombre']], [
                'capacidad' => $especial['capacidad'],
                'tipo' => 'sala',
                'codigo_barras' => null,
            ]));
        }

        $this->line('  · '.$salas->count().' salas listas (15 logias + Seminarios + Postgrado + AGACI)');

        return $salas;
    }

    /**
     * Sin esto, toda reserva nace 'activa' sin hora_prestamo_real — y
     * Reserva::plazoConfirmacion()/ReservaSalaService::liberarSiVencida() la
     * convierte sola en 'no_show' (oculta en SalaController::index()) apenas
     * alguien lee /salas después de que el bloque ya pasó. Como el seed genera
     * fechas relativas a hoy pero corre una sola vez, el historial de "días
     * anteriores" y los bloques de hoy que ya pasaron quedaban invisibles a los
     * pocos minutos de sembrar — se veía "pelado" sin haber cambiado nada. Ahora
     * los bloques ya terminados se simulan como asistencia real (85% llegó y
     * devolvió la llave, 15% no-show real, para que reportería tenga algo que
     * mostrar) en vez de dejarlos 'activa' camino a esconderse solos.
     */
    private function seedReservas($salas, $usuarios): void
    {
        // Horario continuo (inicio libre, duración de hasta 2 horas). Cada sala
        // se recorre desde la apertura sorteando si se reserva o no un tramo de
        // duración aleatoria (alineada a la granularidad de config/salas.php).
        $duraciones = [30, 60, 90, 120];
        $ahora = now();
        $total = 0;

        // Reservas para hoy y algunos días recientes (no solo "hoy"), para que la
        // demo siga viéndose poblada aunque pasen días sin volver a correr el seed.
        $granularidad = config('salas.granularidad');
        $servicioSalas = app(ReservaSalaService::class);
        foreach (range(0, 3) as $diasAtras) {
            $ventana = $servicioSalas->ventanaDelDia($ahora->copy()->subDays($diasAtras));
            if (! $ventana) {
                continue;
            }
            $fecha = $ahora->copy()->subDays($diasAtras)->toDateString();
            $esHoy = $diasAtras === 0;

            foreach ($salas as $sala) {
                // Desde el primer inicio alineado a la granularidad: con apertura 08:45, las 09:00.
                $cursor = Carbon::parse($ventana[0]);
                $cursor->addMinutes(($granularidad - $cursor->minute % $granularidad) % $granularidad);
                $cierre = Carbon::parse($ventana[1]);

                while ($cursor->lessThan($cierre)) {
                    // 25% de probabilidad de ocupar cada tramo candidato — da una ocupación
                    // esperada de ~38% del día (25% de aceptar × duración promedio 75 min,
                    // contra 75% de rechazar × 30 min de la granularidad). A propósito bajo:
                    // con 55% quedaba prácticamente lleno todo el día en las 18 salas, sin
                    // huecos reales para poder probar el flujo de "reservar en un espacio
                    // libre" en la demo.
                    if (random_int(0, 100) > 25) {
                        $cursor->addMinutes($granularidad);

                        continue;
                    }

                    $duracion = min($duraciones[array_rand($duraciones)], $cursor->diffInMinutes($cierre));
                    $fin = $cursor->copy()->addMinutes($duracion);

                    // 'terminada': el tramo ya pasó (o es de un día anterior) — se simula
                    // asistencia real o no-show, como siempre. 'en_curso': el tramo empezó
                    // pero no ha terminado — mayoría con llegada ya confirmada, para que la
                    // demo muestre salas realmente en uso, no solo "por confirmar". 'futura':
                    // todavía no empieza, se deja intacta.
                    $fase = match (true) {
                        ! $esHoy || $fin->format('H:i:s') <= $ahora->format('H:i:s') => 'terminada',
                        $cursor->format('H:i:s') <= $ahora->format('H:i:s') => 'en_curso',
                        default => 'futura',
                    };
                    $this->crearReservaMockup($sala, $usuarios, $fecha, $cursor->format('H:i:s'), $fin->format('H:i:s'), $fase);
                    $total++;
                    $cursor = $fin->copy();
                }
            }
        }

        // Unas pocas reservas de HOY corriendo ahora mismo, deliberadamente sin
        // confirmar y a pocos minutos de vencer el plazo de 15 minutos — para poder
        // abrir el menú de confirmación de asistencia en SalasView.vue y ver el caso
        // "por confirmar" sin esperar 15 minutos de verdad.
        $fechaHoy = $ahora->toDateString();
        $inicioActual = $ahora->copy()->subMinutes(random_int(5, 20));
        $finActual = $inicioActual->copy()->addMinutes($duraciones[array_rand($duraciones)]);
        if ($finActual->lessThanOrEqualTo($ahora)) {
            $finActual = $ahora->copy()->addMinutes(60);
        }
        // Solo si la biblioteca atiende a esta hora, y sin pasar del cierre. Pasada la
        // medianoche el inicio caería en el día anterior (un tramo 23:50-00:50 de hoy).
        $ventanaHoy = $servicioSalas->ventanaDelDia($ahora);
        $dentroDelHorario = $ventanaHoy
            && $inicioActual->isSameDay($ahora)
            && $inicioActual->format('H:i') >= $ventanaHoy[0]
            && $ahora->format('H:i') < $ventanaHoy[1];
        if ($dentroDelHorario && $finActual->format('H:i') > $ventanaHoy[1]) {
            $finActual = Carbon::parse($ventanaHoy[1]);
        }

        $salasLibres = $salas->reject(
            fn ($sala) => Reserva::where('sala_id', $sala->id)->where('fecha', $fechaHoy)
                ->where('hora_inicio', '<', $finActual->format('H:i:s'))
                ->where('hora_fin', '>', $inicioActual->format('H:i:s'))
                ->exists()
        );

        $porConfirmar = 0;
        $cuantas = $dentroDelHorario ? min(3, $salasLibres->count()) : 0;
        foreach ($salasLibres->random($cuantas) as $sala) {
            $this->crearReservaMockup($sala, $usuarios, $fechaHoy, $inicioActual->format('H:i:s'), $finActual->format('H:i:s'), fase: 'en_curso', minutosParaVencer: random_int(2, 5));
            $porConfirmar++;
            $total++;
        }

        $detalle = $porConfirmar > 0 ? " ({$porConfirmar} por confirmar, a pocos minutos de vencer)" : '';
        $this->line("  · {$total} reservas de logia creadas (hoy y últimos 3 días){$detalle}");
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Usuario>  $usuarios
     * @param  string  $fase  'terminada' (el tramo ya pasó — se simula asistencia real
     *   o no-show, 85/15), 'en_curso' (el tramo ya empezó y no ha terminado — mayoría
     *   con llegada ya confirmada, para que la demo no muestre todo como "por
     *   confirmar"), o 'futura' (todavía no empieza, se deja intacta sin simular nada).
     * @param  int|null  $minutosParaVencer  Si viene, la reserva queda 'activa' sin
     *   confirmar con el plazo de 15 minutos venciendo en ese lapso (backdatea
     *   created_at) — para el caso "por confirmar, a punto de vencer" de
     *   seedReservas(), y hace que $fase se ignore por completo.
     */
    private function crearReservaMockup($sala, $usuarios, string $fecha, string $inicio, string $fin, string $fase, ?int $minutosParaVencer = null): Reserva
    {
        $cantidadPersonas = random_int(2, 5);
        $participantes = $usuarios->random(min($cantidadPersonas, $usuarios->count()))->values();

        $reserva = Reserva::create([
            'sala_id' => $sala->id,
            'usuario_id' => $participantes->first()->id,
            'rut_usuario' => $participantes->first()->rut,
            'cantidad_personas' => $cantidadPersonas,
            'fecha' => $fecha,
            'hora_inicio' => $inicio,
            'hora_fin' => $fin,
            'estado' => 'activa',
        ]);

        $reserva->participantes()->attach($participantes->pluck('id'));

        if ($minutosParaVencer !== null) {
            $reserva->created_at = now()->subMinutes(config('salas.plazo_confirmacion', 15) - $minutosParaVencer);
            $reserva->save();

            return $reserva;
        }

        if ($fase === 'terminada') {
            if (random_int(0, 100) < 85) {
                $duracionMin = Carbon::parse($inicio)->diffInMinutes(Carbon::parse($fin));
                $horaLlegada = Carbon::parse($fecha.' '.$inicio)->addMinutes(random_int(0, 10));
                $reserva->update([
                    'prestado_por' => 'Sistema (mockup)',
                    'hora_prestamo_real' => $horaLlegada,
                    'devuelto_por' => 'Sistema (mockup)',
                    'hora_devolucion_real' => $horaLlegada->copy()->addMinutes($duracionMin)->subMinutes(random_int(0, 15)),
                    'estado' => 'finalizada',
                    'via' => random_int(0, 1) ? 'manual' : 'BC',
                ]);
            } else {
                $reserva->update(['estado' => 'no_show']);
            }
        } elseif ($fase === 'en_curso') {
            $plazo = config('salas.plazo_confirmacion', 15);
            $minutosDesdeInicio = Carbon::parse($fecha.' '.$inicio)->diffInMinutes(now());
            // Si ya pasó el plazo de 15 min sin confirmar, en el sistema real ya habría
            // quedado 'no_show' por la expiración perezosa — casi nunca debería seguir
            // 'activa' sin confirmar a esta altura. Dentro del plazo es normal que
            // todavía no todos hayan pasado por mesón, así que la probabilidad es menor.
            $probabilidadConfirmada = $minutosDesdeInicio > $plazo ? 90 : 55;

            if (random_int(0, 100) < $probabilidadConfirmada) {
                $maxOffset = max(0, min(10, $minutosDesdeInicio));
                $horaLlegada = Carbon::parse($fecha.' '.$inicio)->addMinutes(random_int(0, $maxOffset));
                $reserva->update([
                    'prestado_por' => 'Sistema (mockup)',
                    'hora_prestamo_real' => $horaLlegada,
                    'via' => random_int(0, 1) ? 'manual' : 'BC',
                ]);
            } elseif ($minutosDesdeInicio > $plazo) {
                $reserva->update(['estado' => 'no_show']);
            }
        }

        return $reserva;
    }

    /**
     * Crea el registro bibliográfico (Libro) + sus copias físicas (Ejemplar) — los
     * primeros 6 títulos reciben 2-3 copias a propósito, para poder probar el flujo
     * real de "múltiples copias del mismo título" apenas se corre el seed.
     *
     * @return \Illuminate\Support\Collection<int, Ejemplar>
     */
    private function seedLibros()
    {
        $ejemplares = collect();
        $contadorCodigo = 1;

        // Todos los libros del mockup son eso, libros (no hay revistas/tesis/DVD en
        // $catalogoLibros) — se les asigna el tipo "Libro" del catálogo administrable.
        $tipoLibro = TipoMaterial::firstOrCreate(['nombre' => 'Libro']);

        // ubicaciones migra con "Biblioteca Central" ya sembrada, pero firstOrCreate
        // igual por si alguna vez se corre este seeder contra una BD sin esa migración
        // (o si el catálogo de ubicaciones quedó vacío por algún motivo).
        $ubicacionCentral = Ubicacion::firstOrCreate(['nombre' => 'Biblioteca Central']);

        $colecciones = ['General', 'General', 'General', 'Referencia', 'Hemeroteca'];

        foreach ($this->catalogoLibros as $i => $item) {
            $anio = random_int(2005, 2023);
            $dewey = $this->deweyPorCategoria[$item['categoria']] ?? '000';
            $cutter = strtoupper(substr($item['autor'], 0, 1)).random_int(100, 999);
            $inicialTitulo = mb_strtolower(mb_substr($item['titulo'], 0, 1));

            $libro = Libro::create([
                'titulo' => $item['titulo'],
                // Los primeros 10 ya traían un ISBN real de ejemplo — al resto se le
                // inventa uno con formato válido (dígito verificador incluido) en vez de
                // dejarlo en null, para que la ficha del libro no se vea a medio llenar.
                'isbn' => $item['codigo'] ?? fake()->isbn13(),
                'tipo_material_id' => $tipoLibro->id,
                'editorial' => $this->editoriales[array_rand($this->editoriales)],
                'anio_publicacion' => $anio,
                'clasificacion' => "{$dewey}.".random_int(10, 99)." {$cutter}{$inicialTitulo} {$anio}",
                'coleccion' => $colecciones[array_rand($colecciones)],
            ]);

            $autor = Autor::firstOrCreate(['nombre' => $item['autor']]);
            $libro->autores()->attach($autor->id);

            $categoria = Categoria::firstOrCreate(['nombre' => $item['categoria']]);
            $libro->categorias()->attach($categoria->id);

            // 1-2 carreras al azar del catálogo, para poblar el multi-select de
            // "carrera(s) asignadas" con datos reales desde el primer arranque.
            $carreras = Carrera::inRandomOrder()->limit(random_int(1, 2))->get();
            $libro->carreras()->attach($carreras->pluck('id'));

            $numeroCopias = $i < 5 ? 2 : ($i === 5 ? 3 : 1);

            for ($copia = 1; $copia <= $numeroCopias; $copia++) {
                $ejemplares->push(Ejemplar::create([
                    'libro_id' => $libro->id,
                    'numero_copia' => $copia,
                    // Numérico de 14 dígitos, mismo formato que EjemplarController::
                    // siguienteCodigoBarras() genera para copias reales (heredado de
                    // Horizon, ej. 30000003227565) — cada copia tiene su propio código,
                    // nunca comparte el del libro ni el de otra copia.
                    'codigo_barras' => (string) (30000000000000 + $contadorCodigo++),
                    'disponible' => random_int(0, 100) < 78,
                    // Los ejemplares de prueba ya están catalogados y en estante: si no,
                    // ninguno sería prestable/reservable (PrestamoController/
                    // ReservaLibroController exigen estado_proceso = 'en_estante') y las
                    // demos/otros seeds quedarían rotos.
                    'estado_proceso' => 'en_estante',
                    'ubicacion_id' => $ubicacionCentral->id,
                ])->setRelation('libro', $libro));
            }
        }

        $totalLibros = count($this->catalogoLibros);
        $this->line("  · {$totalLibros} libros creados en el catálogo ({$ejemplares->count()} ejemplares, con autor/categoría/carrera, ISBN, clasificación, editorial, año, ubicación y disponibilidad)");

        return $ejemplares;
    }

    /** @param  \Illuminate\Support\Collection<int, Ejemplar>  $ejemplares */
    private function seedReservasLibro($ejemplares, $usuarios): void
    {
        $total = 0;

        foreach ($ejemplares->where('disponible', true)->random(min(4, $ejemplares->where('disponible', true)->count())) as $ejemplar) {
            $usuario = $usuarios->random();
            $fechaReserva = $this->momentoEnHorario(random_int(0, 5));
            $fechaRetiro = $this->dentroDelHorario($fechaReserva->copy()->addDays(4), haciaAtras: false);
            $retirado = $fechaRetiro->isPast();

            ReservaLibro::create([
                'usuario_id' => $usuario->id,
                'libro_id' => $ejemplar->libro_id,
                'ejemplar_id' => $ejemplar->id,
                'fecha_reserva' => $fechaReserva->toDateString(),
                'fecha_retiro' => $fechaRetiro->toDateString(),
                'estado' => $retirado ? 'retirado' : 'pendiente',
            ]);

            // Una reserva 'pendiente' tiene la copia apartada — mantiene la disponibilidad
            // del ejemplar consistente con lo que vería PrestamoController/ReservaLibroController.
            if (! $retirado) {
                $ejemplar->update(['disponible' => false]);
            }

            $total++;
        }

        $this->line("  · {$total} reservas de libro creadas");
    }

    /** Genera un RUT chileno válido con dígito verificador a partir de un número base */
    private function formatearRut(int $numero): string
    {
        return Rut::formatear($numero);
    }

    /**
     * Casos fijos para mostrar cada función, con RUT, títulos y códigos que no
     * cambian entre corridas (el resto de los datos es aleatorio). Los usa
     * docs/recorrido-presentacion.md paso a paso.
     */
    private function seedEscenarioDemostracion(): void
    {
        $bibliotecaria = Staff::where('email', 'bibliotecaria@umag.cl')->firstOrFail();
        $meson = Staff::where('email', 'meson@umag.cl')->firstOrFail();
        $ubicacion = Ubicacion::firstOrCreate(['nombre' => 'Biblioteca Central']);

        $persona = fn (int $rut, string $nombre, string $apellido, string $tipo, string $carrera, string $sexo) => Usuario::create([
            'rut' => $this->formatearRut($rut),
            'nombre' => $nombre,
            'apellido' => $apellido,
            'email' => Str::lower(Str::ascii("{$nombre}.{$apellido}")).'@umag.cl',
            'password' => Hash::make('umag123'),
            'tipo' => $tipo,
            'carrera' => $carrera,
            'anio_ingreso' => 2023,
            'sexo' => $sexo,
            'activo' => true,
            'qr_code' => strtoupper(uniqid('QR')),
        ]);

        $valentina = $persona(11111111, 'Valentina', 'Soto', 'estudiante', 'Ingeniería Civil Informática', 'Femenino');
        $matias = $persona(22222222, 'Matías', 'Rojas', 'estudiante', 'Derecho', 'Masculino');
        $francisca = $persona(33333333, 'Francisca', 'Díaz', 'estudiante', 'Enfermería', 'Femenino');
        $benjamin = $persona(44444444, 'Benjamín', 'Torres', 'funcionario', 'Construcción Civil', 'Masculino');
        $camila = $persona(55555555, 'Camila', 'Fernández', 'docente', 'Trabajo Social', 'Femenino');
        $diego = $persona(66666666, 'Diego', 'Herrera', 'estudiante', 'Ingeniería Civil Informática', 'Masculino');

        // Estudiantes extranjeros: se identifican con su DNI o pasaporte en vez de RUT
        // (en el mesón, el portal y las salas basta con escribir ese número).
        $extranjero = fn (string $documento, string $tipoDocumento, string $pais, string $nombre, string $apellido, string $carrera, string $sexo) => Usuario::create([
            'rut' => $documento,
            'tipo_documento' => $tipoDocumento,
            'pais' => $pais,
            'nombre' => $nombre,
            'apellido' => $apellido,
            'email' => Str::lower(Str::ascii("{$nombre}.{$apellido}")).'@umag.cl',
            'password' => Hash::make('umag123'),
            'tipo' => 'estudiante',
            'carrera' => $carrera,
            'anio_ingreso' => 2026,
            'sexo' => $sexo,
            'activo' => true,
            'qr_code' => strtoupper(uniqid('QR')),
        ]);
        $extranjero('38456123', 'dni', 'Argentina', 'Martina', 'Gómez', 'Pedagogía en Educación Básica', 'Femenino');
        $extranjero('FT482913', 'pasaporte', 'Brasil', 'Lucas', 'Silva', 'Ingeniería Comercial', 'Masculino');

        [, $flora] = $this->libroDemostracion('libro', [
            'titulo' => 'Flora de la Patagonia austral',
            'titulo_alternativo' => 'Plantas de Magallanes y Tierra del Fuego',
            'isbn' => '978-956-7189-50-1',
            'clasificacion' => '581.9836 P678f 2019',
            'edicion' => '2a ed.',
            'lugar_publicacion' => 'Punta Arenas',
            'editorial' => 'Ediciones Universidad de Magallanes',
            'anio_publicacion' => 2019,
            'descripcion_fisica' => '312 p. : il. col. ; 24 cm',
            'materias_geograficas' => 'Magallanes (Chile)',
            'url_acceso' => 'https://www.umag.cl',
            'idioma' => 'Español',
            'serie' => 'Colección Patagonia',
            'nota_interna' => 'Donación de la Facultad de Ciencias',
            'nota_bibliografia' => 'Bibliografía: p. 301-310',
            'coleccion' => 'General',
        ], ['Pisano, Edmundo', 'Domínguez, Erwin'], ['Botánica', 'Flora'], ['30000000000901', '30000000000902'], $bibliotecaria, $ubicacion);

        [$tesis] = $this->libroDemostracion('trabajo_titulacion', [
            'titulo' => 'Sistema web para la gestión de la biblioteca de la Universidad de Magallanes',
            'clasificacion' => 'T 005.1 V297s 2025',
            'lugar_publicacion' => 'Punta Arenas',
            'editorial' => 'Universidad de Magallanes',
            'anio_publicacion' => 2025,
            'descripcion_fisica' => '98 h. : il. ; 30 cm',
            'nota_tesis' => 'Tesis (Ingeniero Civil en Computación e Informática) -- Universidad de Magallanes, 2025',
            'profesor_guia' => 'Pérez, Ana',
            'entidad_secundaria' => 'Universidad de Magallanes. Departamento de Ingeniería en Computación',
            'url_acceso' => 'https://www.umag.cl',
            'idioma' => 'Español',
            'nota_bibliografia' => 'Bibliografía: h. 92-98',
            'coleccion' => 'Tesis',
        ], ['Vargas Soto, Catalina'], ['Sistemas de información', 'Bibliotecas universitarias'], ['30000000000903'], $bibliotecaria, $ubicacion);

        [$juego, [$copiaJuego]] = $this->libroDemostracion('juego_educativo', [
            'titulo' => 'Memorice de la fauna magallánica',
            'titulo_alternativo' => 'Memorice austral',
            'clasificacion' => 'JE 591.9836 M533 2022',
            'lugar_publicacion' => 'Punta Arenas',
            'editorial' => 'Didácticos Australes',
            'anio_publicacion' => 2022,
            'descripcion_fisica' => '1 caja (48 cartas) : col. ; 20 x 15 cm',
            'resumen' => 'Juego de memoria con 24 pares de cartas ilustradas de animales de la región de Magallanes, con una ficha informativa por especie.',
            'audiencia' => 'Niños de 6 a 10 años',
            'idioma' => 'Español',
            'coleccion' => 'Material didáctico',
        ], [], ['Zoología', 'Material didáctico'], ['30000000000904'], $bibliotecaria, $ubicacion);

        [$revista, [$copiaRevista]] = $this->libroDemostracion('publicacion_seriada', [
            'titulo' => 'Cuadernos de Estudios Australes',
            'issn' => '0718-5480',
            'clasificacion' => 'R 918.36 C961 2024',
            'lugar_publicacion' => 'Punta Arenas',
            'editorial' => 'Ediciones Universidad de Magallanes',
            'anio_publicacion' => 2024,
            'descripcion_fisica' => '180 p. : il. ; 27 cm',
            'designacion_volumen' => 'Vol. 12, n.º 2 (jul.-dic. 2024)',
            'url_acceso' => 'https://www.umag.cl',
            'idioma' => 'Español',
            'frecuencia' => 'Semestral',
            'nota_interna' => 'Número especial de aniversario',
            'nota_idioma' => 'Resúmenes en español e inglés',
            'nota_local' => 'Canje con la Universidad de Chile',
            'coleccion' => 'Hemeroteca',
        ], [], ['Historia regional', 'Patagonia'], ['30000000000905'], $bibliotecaria, $ubicacion);

        // Matías tiene un libro atrasado: al devolverlo se genera la multa sola.
        $copiaAtrasada = $flora[1];
        Prestamo::create([
            'usuario_id' => $matias->id,
            'ejemplar_id' => $copiaAtrasada->id,
            'libro_titulo' => $copiaAtrasada->tituloConCopia(),
            'tipo_item' => 'libro',
            'codigo_barras' => $copiaAtrasada->codigo_barras,
            'fecha_prestamo' => $this->momentoEnHorario(17),
            'fecha_devolucion' => $this->dentroDelHorario(now()->subDays(10), haciaAtras: false),
            'estado' => 'atrasado',
            'prestado_por' => $meson->nombre,
            'prestado_por_staff_id' => $meson->id,
        ]);
        $copiaAtrasada->update(['disponible' => false]);

        // Francisca debe una multa: no puede llevarse ni reservar material hasta pagar.
        app(MultaService::class)->crearManual($francisca, [
            'tipo' => 'deterioro',
            'monto' => 3500,
            'detalle' => 'Libro devuelto con páginas rayadas y la tapa doblada',
        ], $bibliotecaria);

        // Benjamín tiene el juego reservado y listo para retirar.
        ReservaLibro::create([
            'usuario_id' => $benjamin->id,
            'libro_id' => $juego->id,
            'ejemplar_id' => $copiaJuego->id,
            'fecha_reserva' => now()->toDateString(),
            'fecha_retiro' => $this->dentroDelHorario(now()->addDays(3), haciaAtras: false)->toDateString(),
            'estado' => 'pendiente',
            'registrado_por_staff_id' => $meson->id,
        ]);
        $copiaJuego->update(['disponible' => false]);

        // Diego tiene la única copia de la revista y Camila espera su turno.
        Prestamo::create([
            'usuario_id' => $diego->id,
            'ejemplar_id' => $copiaRevista->id,
            'libro_titulo' => $copiaRevista->tituloConCopia(),
            'tipo_item' => 'libro',
            'codigo_barras' => $copiaRevista->codigo_barras,
            'fecha_prestamo' => $this->momentoEnHorario(2),
            'fecha_devolucion' => $this->dentroDelHorario(now()->addDays(5), haciaAtras: false),
            'estado' => 'activo',
            'prestado_por' => $meson->nombre,
            'prestado_por_staff_id' => $meson->id,
        ]);
        $copiaRevista->update(['disponible' => false]);
        ReservaLibro::create([
            'usuario_id' => $camila->id,
            'libro_id' => $revista->id,
            'ejemplar_id' => null,
            'fecha_reserva' => now()->toDateString(),
            'fecha_retiro' => null,
            'estado' => 'en_cola',
        ]);

        // Recursos de apoyo que siempre parten disponibles, para prestar durante la demostración.
        foreach ([['Audífonos 05', 'audifonos', '7510000000011'], ['Notebook 04', 'notebook', '7510000000012'], ['Bicicleta 04', 'bicicleta', '7510000000013']] as [$nombre, $tipo, $codigo]) {
            Equipo::create(['codigo_inventario' => $nombre, 'codigo_barras' => $codigo, 'tipo' => $tipo, 'disponible' => true, 'activo' => true]);
        }

        $this->line("  · Escenario de demostración: {$valentina->rut} a {$diego->rut}, 4 títulos con ficha completa ({$tesis->titulo}...) y 3 recursos de apoyo");
    }

    /**
     * Crea un título completo de la plantilla indicada, con sus copias en estante.
     *
     * @return array{0: Libro, 1: array<int, Ejemplar>}
     */
    private function libroDemostracion(string $plantilla, array $campos, array $autores, array $materias, array $codigos, Staff $catalogador, Ubicacion $ubicacion): array
    {
        $tipo = TipoMaterial::where('plantilla', $plantilla)->orderBy('id')->firstOrFail();

        $libro = Libro::create([...$campos, 'tipo_material_id' => $tipo->id, 'catalogado_por_staff_id' => $catalogador->id]);
        $libro->autores()->sync(collect($autores)->values()->mapWithKeys(
            fn ($nombre, $i) => [Autor::firstOrCreate(['nombre' => $nombre])->id => ['orden' => $i + 1]]
        )->all());
        $libro->categorias()->sync(collect($materias)->map(fn ($nombre) => Categoria::firstOrCreate(['nombre' => $nombre])->id)->all());

        $copias = collect($codigos)->values()->map(fn ($codigo, $i) => Ejemplar::create([
            'libro_id' => $libro->id,
            'numero_copia' => $i + 1,
            'codigo_barras' => $codigo,
            'ubicacion_id' => $ubicacion->id,
            'estado_proceso' => 'en_estante',
            'disponible' => true,
        ]))->all();

        return [$libro, $copias];
    }

    /**
     * Un momento dentro del horario de atención (config/biblioteca.php), $diasAtras días
     * antes de hoy. Si ese día la biblioteca no atiende, o es hoy y todavía no abre, se
     * usa el último día con atención; hoy nunca pasa de la hora actual.
     */
    private function momentoEnHorario(int $diasAtras): Carbon
    {
        $dia = now()->subDays($diasAtras)->startOfDay();
        for ($intento = 0; $intento < 7; $intento++, $dia->subDay()) {
            $horario = HorarioBiblioteca::semana()[$dia->dayOfWeekIso] ?? null;
            if (! $horario) {
                continue;
            }
            $apertura = $dia->copy()->setTimeFromTimeString($horario[0]);
            $cierre = $dia->copy()->setTimeFromTimeString($horario[1])->subMinute();
            if ($dia->isToday() && $cierre->greaterThan(now())) {
                $cierre = now();
            }
            if ($cierre->lessThanOrEqualTo($apertura)) {
                continue;
            }
            $momento = $dia->copy()->setTime($this->horaConSesgo(), random_int(0, 59));

            return $momento->between($apertura, $cierre)
                ? $momento
                : $apertura->copy()->addMinutes(random_int(0, (int) $apertura->diffInMinutes($cierre)));
        }

        return now();
    }

    /**
     * Lleva un momento a un día con atención (hacia atrás o hacia adelante) y deja la hora
     * dentro del horario de ese día.
     */
    private function dentroDelHorario(Carbon $momento, bool $haciaAtras = true): Carbon
    {
        $dia = $momento->copy();
        for ($i = 0; $i < 7 && HorarioBiblioteca::cerrado($dia); $i++) {
            $haciaAtras ? $dia->subDay() : $dia->addDay();
        }
        [$apertura, $cierre] = HorarioBiblioteca::semana()[$dia->dayOfWeekIso];
        $desde = $dia->copy()->setTimeFromTimeString($apertura);
        $hasta = $dia->copy()->setTimeFromTimeString($cierre)->subMinute();

        return $dia->between($desde, $hasta) ? $dia : $desde->copy()->addMinutes(random_int(0, (int) $desde->diffInMinutes($hasta)));
    }

    /** Más movimiento a media mañana y a media tarde; momentoEnHorario() lo ajusta al horario de cada día. */
    private function horaConSesgo(): int
    {
        $r = random_int(0, 100) / 100;

        if ($r < 0.45) {
            return random_int(10, 12);
        }

        if ($r < 0.8) {
            return random_int(15, 17);
        }

        return random_int(8, 19);
    }
}
