<?php

namespace App\Console\Commands;

use App\Models\Autor;
use App\Models\Carrera;
use App\Models\Categoria;
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
use App\Support\Rut;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SeedMockupData extends Command
{
    protected $signature = 'mockup:datos {--fresh : Borra los datos de prueba existentes antes de crear los nuevos}';

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

    /** Catálogo completo de biblioteca (título/autor/área) para el portal de usuario — adaptado de DEPRECATED/catalogo/page.tsx */
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
        $salas = $this->seedSalas();
        $this->seedReservas($salas, $usuarios);
        $this->seedReservasLibro($ejemplares, $usuarios);

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

        $this->line('  · Staff creado (admin@umag.cl / admin123)');
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
                'email' => strtolower($nombre.'.'.$apellido.$i).'@umag.cl',
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
        $horaActual = now()->hour;

        foreach ($usuarios as $i => $usuario) {
            // Cada usuario tiene entre 1 y 4 entradas distribuidas en los últimos 14 días
            $numEntradas = random_int(1, 4);

            for ($j = 0; $j < $numEntradas; $j++) {
                $diasAtras = random_int(0, 13);
                $hora = $diasAtras === 0 ? random_int(8, max(8, $horaActual)) : $this->horaConSesgo();
                $entrada = now()->subDays($diasAtras)->setTime($hora, random_int(0, 59));
                $conSalida = $diasAtras > 0 && random_int(0, 100) < 70;

                Entrada::create([
                    'usuario_id' => $usuario->id,
                    'fecha_hora_entrada' => $entrada,
                    'fecha_hora_salida' => $conSalida ? $entrada->copy()->addMinutes(random_int(30, 180)) : null,
                    'via' => random_int(0, 100) < 35 ? 'qr' : 'manual',
                    'codigo_barras' => config('horizon_barcodes.puesto_generico'),
                ]);
                $total++;
            }
        }

        // Garantiza una porción de entradas de hoy, para que "Registro de Entrada"
        // no se vea vacío justo tras correr el seed en cualquier momento del día.
        foreach ($usuarios->random(min(12, $usuarios->count())) as $usuario) {
            $hora = random_int(8, max(8, $horaActual));
            $entrada = now()->setTime($hora, random_int(0, 59));

            Entrada::create([
                'usuario_id' => $usuario->id,
                'fecha_hora_entrada' => $entrada,
                'fecha_hora_salida' => random_int(0, 100) < 60 ? $entrada->copy()->addMinutes(random_int(30, 180)) : null,
                'via' => random_int(0, 100) < 35 ? 'qr' : 'manual',
                'codigo_barras' => config('horizon_barcodes.puesto_generico'),
            ]);
            $total++;
        }

        // Un par de visitantes externos y de convenio para hoy, para que las etiquetas
        // "Externo"/"Convenio" del historial tengan ejemplos reales tras el seed.
        foreach ([false, false, true, true] as $esConvenio) {
            $hora = random_int(8, max(8, $horaActual));
            $entrada = now()->setTime($hora, random_int(0, 59));
            $rutVisitante = $this->formatearRut(random_int(9000000, 9999999));

            Entrada::create([
                'rut_externo' => $rutVisitante,
                'nombre_externo' => $this->nombres[array_rand($this->nombres)].' '.$this->apellidos[array_rand($this->apellidos)],
                'es_convenio' => $esConvenio,
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
        $horaActual = now()->hour;

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

                $diasAtras = random_int(0, 20);
                $hora = $diasAtras === 0 ? random_int(8, max(8, $horaActual)) : $this->horaConSesgo();
                $fechaPrestamo = now()->subDays($diasAtras)->setTime($hora, random_int(0, 59));
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
                    'fecha_devolucion_real' => $devuelto ? $fechaDevolucion->copy()->subDays(random_int(0, 3)) : null,
                    'estado' => $devuelto ? 'devuelto' : ($atrasado ? 'atrasado' : 'activo'),
                ]);

                if (! $devuelto) {
                    $ejemplar->update(['disponible' => false]);
                }

                $total++;
            }
        }

        // Préstamos de equipos (audífonos, notebooks y cargadores): se identifican por
        // código de barras real (tabla `equipos`), no tienen fecha de vencimiento — se
        // devuelven al término de la estadía en la biblioteca. Se limita el sorteo a
        // equipos realmente disponibles del tipo elegido para no simular dos préstamos
        // activos simultáneos del mismo código físico.
        foreach ($usuarios->random(min(10, $usuarios->count())) as $usuario) {
            $tipo = ['audifonos', 'notebook', 'cargador'][random_int(0, 2)];
            $disponibles = $equipos->where('tipo', $tipo)->where('disponible', true);

            if ($disponibles->isEmpty()) {
                continue;
            }

            $equipo = $disponibles->random();
            $diasAtras = random_int(0, 5);
            $hora = $diasAtras === 0 ? random_int(8, max(8, $horaActual)) : $this->horaConSesgo();
            $fechaPrestamo = now()->subDays($diasAtras)->setTime($hora, random_int(0, 59));
            $devuelto = $diasAtras > 0 || random_int(0, 100) < 50;

            Prestamo::create([
                'usuario_id' => $usuario->id,
                'equipo_id' => $equipo->id,
                'libro_titulo' => $equipo->codigo_inventario,
                'tipo_item' => $tipo,
                'codigo_barras' => $equipo->codigo_barras,
                'fecha_prestamo' => $fechaPrestamo,
                'fecha_devolucion' => null,
                'fecha_devolucion_real' => $devuelto ? $fechaPrestamo->copy()->addHours(random_int(1, 6)) : null,
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
        ];

        $equipos = collect();
        $secuencia = 1;

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

        $this->line("  · {$equipos->count()} equipos creados (audífonos, notebooks y cargadores)");

        return $equipos;
    }

    /** @return \Illuminate\Support\Collection<int, Sala> */
    private function seedSalas()
    {
        // 15 logias de estudio, todas en la misma ubicación (ya no se distingue
        // por piso — ver CLAUDE.md), más 3 salas especiales con nombre propio.
        $capacidades = [2, 3, 4, 2, 3, 4, 2, 3, 4, 2, 3, 4, 2, 3, 4];
        $salas = collect();

        for ($i = 0; $i < 15; $i++) {
            $salas->push(Sala::create([
                'nombre' => 'Logia '.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
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
            ['nombre' => 'Sala AGACI (Apoyo a la Inclusión)', 'capacidad' => 10],
        ];

        foreach ($salasEspeciales as $especial) {
            $salas->push(Sala::create([
                'nombre' => $especial['nombre'],
                'capacidad' => $especial['capacidad'],
                'tipo' => 'sala',
                'codigo_barras' => null,
            ]));
        }

        $this->line('  · '.$salas->count().' salas creadas (15 logias + Seminarios + Postgrado + AGACI)');

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
        // Horario continuo (inicio libre, duración de hasta 2 horas) — ya no son
        // bloques fijos, ver spec "reserva de salas con horario continuo". Cada sala
        // se recorre desde la apertura sorteando si se reserva o no un tramo de
        // duración aleatoria (alineada a la granularidad de config/salas.php).
        $duraciones = [30, 60, 90, 120];
        $ahora = now();
        $total = 0;

        // Reservas para hoy y algunos días recientes (no solo "hoy"), para que la
        // demo siga viéndose poblada aunque pasen días sin volver a correr el seed.
        foreach (range(0, 3) as $diasAtras) {
            $fecha = $ahora->copy()->subDays($diasAtras)->toDateString();
            $esHoy = $diasAtras === 0;

            foreach ($salas as $sala) {
                $cursor = Carbon::parse(config('salas.apertura'));
                $cierre = Carbon::parse(config('salas.cierre'));

                while ($cursor->lessThan($cierre)) {
                    // 25% de probabilidad de ocupar cada tramo candidato — da una ocupación
                    // esperada de ~38% del día (25% de aceptar × duración promedio 75 min,
                    // contra 75% de rechazar × 30 min de la granularidad). A propósito bajo:
                    // con 55% quedaba prácticamente lleno todo el día en las 18 salas, sin
                    // huecos reales para poder probar el flujo de "reservar en un espacio
                    // libre" en la demo.
                    if (random_int(0, 100) > 25) {
                        $cursor->addMinutes(config('salas.granularidad'));

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

        $salasLibres = $salas->reject(
            fn ($sala) => Reserva::where('sala_id', $sala->id)->where('fecha', $fechaHoy)
                ->where('hora_inicio', '<', $finActual->format('H:i:s'))
                ->where('hora_fin', '>', $inicioActual->format('H:i:s'))
                ->exists()
        );

        $porConfirmar = 0;
        foreach ($salasLibres->random(min(3, $salasLibres->count())) as $sala) {
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
            $fechaReserva = now()->subDays(random_int(0, 5));
            $fechaRetiro = $fechaReserva->copy()->addDays(4);
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

    /** Horario biblioteca 8-21 hrs */
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
