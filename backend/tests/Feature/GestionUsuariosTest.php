<?php

namespace Tests\Feature;

use App\Models\Bitacora;
use App\Models\Carrera;
use App\Models\Staff;
use App\Models\Usuario;
use App\Support\Texto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Administración → Estudiantes: crear, editar y la carga masiva desde una planilla. */
class GestionUsuariosTest extends TestCase
{
    use RefreshDatabase;

    private function comoAdmin(): Staff
    {
        $admin = Staff::factory()->admin()->create(['nombre' => 'Admin Biblioteca']);
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_crear_un_estudiante_formatea_el_rut_y_queda_en_la_bitacora(): void
    {
        $this->comoAdmin();

        $this->postJson('/api/usuarios', [
            'tipo_documento' => 'rut',
            'rut' => '150031516',
            'nombre' => ' Sebastián ',
            'apellido' => 'Sánchez',
            'email' => 'Sebastian.Sanchez@UMAG.cl',
            'carrera' => 'Pedagogía en Educación Básica',
            'anio_ingreso' => 2026,
            'sexo' => 'm',
        ])->assertCreated()
            ->assertJsonPath('rut', '15.003.151-6')
            ->assertJsonPath('nombre', 'Sebastián')
            ->assertJsonPath('email', 'sebastian.sanchez@umag.cl')
            ->assertJsonPath('tipo', 'estudiante')
            ->assertJsonPath('sexo', 'Masculino');

        $this->assertDatabaseHas('bitacora', ['accion' => 'usuarios.crear', 'actor_nombre' => 'Admin Biblioteca']);
    }

    public function test_no_se_crea_un_estudiante_que_ya_existe_aunque_el_rut_venga_escrito_distinto(): void
    {
        $this->comoAdmin();
        Usuario::factory()->create(['rut' => '15.003.151-6', 'nombre' => 'Sebastián', 'apellido' => 'Sánchez']);

        $this->postJson('/api/usuarios', ['rut' => '15003151-6', 'nombre' => 'Otro', 'apellido' => 'Nombre'])
            ->assertStatus(422)
            ->assertJsonPath('errors.rut.0', 'Ya existe un usuario con ese RUT: Sebastián Sánchez (15.003.151-6).');

        $this->assertDatabaseCount('usuarios', 1);
    }

    public function test_un_rut_con_digito_verificador_incorrecto_se_rechaza(): void
    {
        $this->comoAdmin();

        $this->postJson('/api/usuarios', ['tipo_documento' => 'rut', 'rut' => '15.003.151-0', 'nombre' => 'A', 'apellido' => 'B'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rut');
    }

    public function test_editar_un_estudiante_guarda_los_cambios_en_la_bitacora(): void
    {
        $this->comoAdmin();
        $usuario = Usuario::factory()->create(['carrera' => 'Derecho', 'tipo_documento' => 'rut']);

        $this->putJson("/api/usuarios/{$usuario->id}", [
            ...$usuario->only(['rut', 'tipo_documento', 'nombre', 'apellido', 'email', 'tipo', 'anio_ingreso', 'sexo']),
            'carrera' => 'Enfermería',
            'activo' => false,
        ])->assertOk()->assertJsonPath('carrera', 'Enfermería')->assertJsonPath('activo', false);

        $registro = \App\Models\Bitacora::where('accion', 'usuarios.editar')->sole();
        $this->assertSame(['Derecho', 'Enfermería'], $registro->datos['carrera']);
        $this->assertSame([true, false], $registro->datos['activo']);
    }

    public function test_sin_el_permiso_no_se_crean_ni_editan_estudiantes(): void
    {
        Sanctum::actingAs(Staff::factory()->asistente()->create());
        $usuario = Usuario::factory()->create();

        $this->postJson('/api/usuarios', ['rut' => '15.003.151-6', 'nombre' => 'A', 'apellido' => 'B'])->assertForbidden();
        $this->putJson("/api/usuarios/{$usuario->id}", ['rut' => $usuario->rut, 'nombre' => 'A', 'apellido' => 'B'])->assertForbidden();
        $this->postJson('/api/usuarios/importar/revisar', ['filas' => [['rut' => '15.003.151-6']]])->assertForbidden();
    }

    private function planilla(): array
    {
        return [
            ['fila' => 2, 'rut' => '15.003.151-6', 'nombre' => 'Sebastián', 'apellido' => 'Sánchez', 'carrera' => 'Derecho'],
            ['fila' => 3, 'rut' => '11.111.111-1', 'nombre' => 'Valentina', 'apellido' => 'Soto'],
            ['fila' => 4, 'rut' => '150031516', 'nombre' => 'Sebastián', 'apellido' => 'Repetido'],
            ['fila' => 5, 'rut' => '12.345.678-9', 'nombre' => 'RUT', 'apellido' => 'Malo'],
            ['fila' => 6, 'rut' => 'FT482913', 'tipo_documento' => 'Pasaporte', 'pais' => 'Brasil', 'nombre' => 'Lucas', 'apellido' => 'Silva'],
            ['fila' => 7, 'rut' => '38456123', 'tipo_documento' => 'DNI', 'pais' => 'Argentina', 'nombre' => 'Martina', 'apellido' => 'Gómez', 'tipo' => 'Estudiante'],
            ['fila' => 8, 'rut' => '17.654.321-3', 'nombre' => '', 'apellido' => 'Sin Nombre'],
        ];
    }

    public function test_revisar_la_planilla_dice_que_pasara_con_cada_fila_sin_escribir_nada(): void
    {
        $this->comoAdmin();
        Usuario::factory()->create(['rut' => '11.111.111-1', 'nombre' => 'Valentina', 'apellido' => 'Soto']);

        $respuesta = $this->postJson('/api/usuarios/importar/revisar', ['filas' => $this->planilla()])->assertOk();

        $respuesta->assertJsonPath('resumen', ['total' => 7, 'nuevos' => 3, 'actualizados' => 0, 'sin_cambios' => 1, 'duplicados' => 1, 'invalidos' => 2]);
        $this->assertSame(
            ['nuevo', 'sin_cambios', 'duplicado', 'invalido', 'nuevo', 'nuevo', 'invalido'],
            collect($respuesta->json('filas'))->pluck('estado')->all(),
        );
        $respuesta->assertJsonPath('filas.4.datos.tipo_documento', 'pasaporte')
            ->assertJsonPath('filas.5.datos.tipo_documento', 'dni')
            ->assertJsonPath('filas.5.datos.pais', 'Argentina');
        $this->assertDatabaseCount('usuarios', 1);
    }

    public function test_cargar_la_planilla_exige_confirmacion_explicita(): void
    {
        $this->comoAdmin();

        $this->postJson('/api/usuarios/importar', ['filas' => $this->planilla()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirmado');

        $this->assertDatabaseCount('usuarios', 0);
    }

    public function test_cargar_la_planilla_crea_los_nuevos_y_volver_a_subirla_no_duplica_a_nadie(): void
    {
        $this->comoAdmin();
        $valentina = Usuario::factory()->create(['rut' => '11.111.111-1', 'nombre' => 'Valentina', 'apellido' => 'Soto', 'carrera' => 'Derecho']);

        $this->postJson('/api/usuarios/importar', ['filas' => $this->planilla(), 'confirmado' => true, 'archivo' => 'estudiantes.xlsx'])
            ->assertCreated()
            ->assertJsonPath('creados', 3)
            ->assertJsonPath('actualizados', 0)
            ->assertJsonPath('resumen.sin_cambios', 1);

        $this->assertDatabaseCount('usuarios', 4);
        // Su fila no trae carrera: lo que viene vacío no borra lo que ya tenía.
        $this->assertSame('Derecho', $valentina->fresh()->carrera);
        $this->assertDatabaseHas('usuarios', ['rut' => 'FT482913', 'tipo_documento' => 'pasaporte', 'pais' => 'Brasil']);
        $this->assertDatabaseHas('usuarios', ['rut' => '38456123', 'tipo_documento' => 'dni']);
        $this->assertSame('Cargó 3 usuarios nuevos desde una planilla (1 ya estaba al día; 3 filas no se cargaron)', Bitacora::where('accion', 'usuarios.importar')->sole()->descripcion);

        // Volver a subir el mismo archivo no duplica a nadie ni cambia nada.
        $this->postJson('/api/usuarios/importar', ['filas' => $this->planilla(), 'confirmado' => true])
            ->assertCreated()
            ->assertJsonPath('creados', 0)
            ->assertJsonPath('actualizados', 0)
            ->assertJsonPath('resumen.sin_cambios', 4)
            ->assertJsonPath('resumen.duplicados', 1);
        $this->assertDatabaseCount('usuarios', 4);
        $this->assertDatabaseMissing('bitacora', ['accion' => 'usuarios.editar']);
    }

    /**
     * Filas del reporte de matrícula de pregrado de la universidad, como las arma la
     * pantalla: los apellidos en dos columnas, la especialidad aparte de la carrera, la
     * nacionalidad en vez del tipo de documento, y todo en mayúsculas. Las columnas que
     * no se usan (fecha de nacimiento, vía de admisión, gratuidad…) no llegan.
     */
    private function planillaMatricula(): array
    {
        $fila = fn (int $n, string $rut, string $nacionalidad, string $carrera, string $especialidad, array $extra = []) => [
            'fila' => $n, 'rut' => $rut, 'nombre' => 'CAMILA ANDREA', 'apellido_paterno' => 'MUÑOZ', 'apellido_materno' => 'DE LA FUENTE',
            'sexo' => 'F', 'nacionalidad' => $nacionalidad, 'carrera' => $carrera, 'especialidad' => $especialidad, 'anio_ingreso' => '2024', ...$extra,
        ];

        return [
            $fila(2, '16245871-K', 'CHILENA', 'INGENIERÍA CIVIL', 'EN COMPUTACIÓN E INFORMÁTICA'),
            $fila(3, '19876543-0', 'CHILENA', 'INGENIERIA CIVIL INDUSTRIAL', 'SIN MODO', ['sexo' => 'M']),
            // DNI argentino de 8 dígitos que por casualidad cuadra como RUT: no es un RUT.
            $fila(4, '30123468', 'ARGENTINA', 'INGENIERÍA', 'PLAN COMUN'),
            $fila(5, 'AB123456', 'COLOMBIANA', 'TÉCNICO DE NIVEL SUPERIOR EN TURISMO SOSTENIBLE', 'HOSPITALITY/HOSPITALIDAD TURÍSTICA'),
            // Extranjero con RUT chileno: se queda como RUT.
            $fila(6, '26512345-7', 'VENEZOLANA', 'DERECHO', 'SIN MODO'),
        ];
    }

    public function test_acepta_el_reporte_de_matricula_y_guarda_solo_lo_que_usa_el_sistema(): void
    {
        $this->comoAdmin();
        // La carrera ya existe en el sistema escrita como corresponde: la planilla la usa así.
        Carrera::create(['nombre' => 'Ingeniería Civil Industrial']);

        $this->postJson('/api/usuarios/importar', ['filas' => $this->planillaMatricula(), 'confirmado' => true, 'archivo' => 'matricula_pregrado.csv'])
            ->assertCreated()
            ->assertJsonPath('creados', 5)
            ->assertJsonPath('resumen.invalidos', 0);

        $this->assertDatabaseHas('usuarios', [
            'rut' => '16.245.871-K', 'tipo_documento' => 'rut', 'pais' => null,
            'nombre' => 'Camila Andrea', 'apellido' => 'Muñoz de la Fuente', 'sexo' => 'Femenino',
            'carrera' => 'Ingeniería Civil en Computación e Informática', 'anio_ingreso' => 2024, 'tipo' => 'estudiante',
        ]);
        $this->assertDatabaseHas('usuarios', ['rut' => '19.876.543-0', 'carrera' => 'Ingeniería Civil Industrial', 'sexo' => 'Masculino']);
        $this->assertDatabaseHas('usuarios', ['rut' => '30123468', 'tipo_documento' => 'dni', 'pais' => 'Argentina', 'carrera' => 'Ingeniería']);
        $this->assertDatabaseHas('usuarios', [
            'rut' => 'AB123456', 'tipo_documento' => 'pasaporte', 'pais' => 'Colombia',
            'carrera' => 'Técnico de Nivel Superior en Turismo Sostenible (Hospitality/Hospitalidad Turística)',
        ]);
        $this->assertDatabaseHas('usuarios', ['rut' => '26.512.345-7', 'tipo_documento' => 'rut', 'pais' => null, 'carrera' => 'Derecho']);
    }

    public function test_los_nombres_en_mayusculas_pasan_a_nombre_propio_y_los_demas_se_respetan(): void
    {
        $this->assertSame('María de los Ángeles', Texto::nombrePropio('MARÍA DE LOS ÁNGELES'));
        $this->assertSame("O'Higgins", Texto::nombrePropio("O'HIGGINS"));
        $this->assertSame('Ingeniería de Ejecución en Administración de Empresas (P.Natales)', Texto::nombrePropio('INGENIERÍA DE EJECUCIÓN EN ADMINISTRACIÓN DE EMPRESAS (P.NATALES)'));
        $this->assertSame('Von Moltke', Texto::nombrePropio('VON MOLTKE'));
        $this->assertSame('McArthur', Texto::nombrePropio('McArthur'));
        $this->assertSame('de la Fuente', Texto::nombrePropio('de la Fuente'));
    }

    public function test_un_rut_con_cero_adelante_se_reconoce_como_el_mismo_ya_cargado(): void
    {
        $this->comoAdmin();
        Usuario::factory()->create(['rut' => '8.733.712-K', 'nombre' => 'Soledad Ximena', 'apellido' => 'Curiñán Guerrero']);
        $filas = [
            // El reporte de matrícula trae los RUT de 7 dígitos con un cero adelante.
            ['fila' => 2, 'rut' => '08733712-K', 'nombre' => 'SOLEDAD XIMENA', 'apellido_paterno' => 'CURIÑÁN', 'apellido_materno' => 'GUERRERO', 'nacionalidad' => 'CHILENA'],
            ['fila' => 3, 'rut' => '07654321-6', 'nombre' => 'ANA', 'apellido_paterno' => 'ROJAS', 'nacionalidad' => 'CHILENA'],
            ['fila' => 4, 'rut' => '7.654.321-6', 'nombre' => 'ANA', 'apellido_paterno' => 'ROJAS', 'nacionalidad' => 'CHILENA'],
        ];

        $this->postJson('/api/usuarios/importar/revisar', ['filas' => $filas])->assertOk()
            ->assertJsonPath('filas.0.estado', 'sin_cambios')
            ->assertJsonPath('filas.1.estado', 'nuevo')
            ->assertJsonPath('filas.1.datos.rut', '7.654.321-6')
            ->assertJsonPath('filas.2.estado', 'duplicado');

        $this->postJson('/api/usuarios/importar', ['filas' => $filas, 'confirmado' => true])
            ->assertCreated()->assertJsonPath('creados', 1);
        $this->assertDatabaseCount('usuarios', 2);

        // Buscarla escribiendo el RUT con el cero también la encuentra.
        $this->getJson('/api/usuarios/rut/08733712-K')->assertOk()->assertJsonPath('nombre', 'Soledad Ximena');
    }

    public function test_la_planilla_completa_los_datos_de_quien_ya_existe_y_lo_deja_activo(): void
    {
        $this->comoAdmin();
        // Como llegó de Horizon: registro vencido, sin carrera, año ni sexo, y el apellido armado sin tildes.
        $camila = Usuario::factory()->create([
            'rut' => '16.245.871-K', 'nombre' => 'Camila Andrea', 'apellido' => 'Munoz De La Fuente', 'email' => 'camila.munoz@gmail.com',
            'tipo' => 'estudiante', 'carrera' => null, 'anio_ingreso' => null, 'sexo' => null, 'activo' => false,
        ]);

        $revision = $this->postJson('/api/usuarios/importar/revisar', ['filas' => $this->planillaMatricula()])->assertOk();

        $revision->assertJsonPath('resumen.actualizados', 1)
            ->assertJsonPath('resumen.nuevos', 4)
            ->assertJsonPath('filas.0.estado', 'actualizar')
            ->assertJsonPath('filas.0.existente.id', $camila->id)
            ->assertJsonPath('filas.0.cambios', [
                'apellido' => ['Munoz De La Fuente', 'Muñoz de la Fuente'],
                'carrera' => [null, 'Ingeniería Civil en Computación e Informática'],
                'anio_ingreso' => [null, 2024],
                'sexo' => [null, 'Femenino'],
                'activo' => [false, true],
            ]);
        $this->assertFalse($camila->fresh()->activo);

        $this->postJson('/api/usuarios/importar', ['filas' => $this->planillaMatricula(), 'confirmado' => true, 'archivo' => 'matricula_pregrado.csv'])
            ->assertCreated()
            ->assertJsonPath('creados', 4)
            ->assertJsonPath('actualizados', 1);

        $camila->refresh();
        $this->assertTrue($camila->activo);
        $this->assertSame('Muñoz de la Fuente', $camila->apellido);
        $this->assertSame('Ingeniería Civil en Computación e Informática', $camila->carrera);
        $this->assertSame(2024, $camila->anio_ingreso);
        $this->assertSame('Femenino', $camila->sexo);
        // La planilla no trae correo ni tipo: se quedan como estaban.
        $this->assertSame('camila.munoz@gmail.com', $camila->email);
        $this->assertDatabaseCount('usuarios', 5);

        $edicion = Bitacora::where('accion', 'usuarios.editar')->sole();
        $this->assertSame('Actualizó los datos de Camila Andrea Muñoz de la Fuente desde la planilla «matricula_pregrado.csv»', $edicion->descripcion);
        $this->assertSame([false, true], $edicion->datos['activo']);
        $this->assertSame([null, 'Ingeniería Civil en Computación e Informática'], $edicion->datos['carrera']);
        $this->assertSame(
            'Cargó 4 usuarios nuevos y actualizó 1 (1 quedó habilitado) desde una planilla',
            Bitacora::where('accion', 'usuarios.importar')->sole()->descripcion,
        );
    }

    public function test_lo_vacio_no_borra_el_tipo_cambia_solo_si_viene_y_una_fila_con_errores_no_toca_a_nadie(): void
    {
        $this->comoAdmin();
        $docente = Usuario::factory()->create(['rut' => '15.003.151-6', 'tipo' => 'docente', 'carrera' => 'Derecho', 'anio_ingreso' => null, 'email' => 'ana@umag.cl']);
        $funcionario = Usuario::factory()->create(['rut' => '11.111.111-1', 'tipo' => 'funcionario']);
        $otro = Usuario::factory()->create(['rut' => '12.345.678-5', 'carrera' => 'Enfermería']);

        $this->postJson('/api/usuarios/importar', ['confirmado' => true, 'filas' => [
            // Solo RUT y año: alcanza para quien ya existe, y no le cambia el tipo.
            ['fila' => 2, 'rut' => '15003151-6', 'nombre' => '', 'apellido' => '', 'email' => '', 'carrera' => '', 'anio_ingreso' => '2020'],
            ['fila' => 3, 'rut' => '11.111.111-1', 'tipo' => 'Estudiante'],
            ['fila' => 4, 'rut' => '12.345.678-5', 'carrera' => 'Kinesiología', 'anio_ingreso' => '1900'],
        ]])->assertCreated()
            ->assertJsonPath('actualizados', 2)
            ->assertJsonPath('filas.0.cambios', ['anio_ingreso' => [null, 2020]])
            ->assertJsonPath('filas.1.cambios', ['tipo' => ['funcionario', 'estudiante']])
            ->assertJsonPath('filas.2.estado', 'invalido')
            ->assertJsonPath('filas.2.mensajes', ['El año de ingreso no es válido.', 'No se actualizará a '.$otro->nombre.' '.$otro->apellido.' (12.345.678-5).']);

        $docente->refresh();
        $this->assertSame(['docente', 'Derecho', 2020, 'ana@umag.cl'], [$docente->tipo, $docente->carrera, $docente->anio_ingreso, $docente->email]);
        $this->assertSame('estudiante', $funcionario->fresh()->tipo);
        $this->assertSame('Enfermería', $otro->fresh()->carrera);
    }

    public function test_un_correo_que_ya_usa_otra_persona_no_impide_actualizar_lo_demas(): void
    {
        $this->comoAdmin();
        $inactiva = Usuario::factory()->create(['rut' => '11.111.111-1', 'email' => null, 'carrera' => 'Derecho', 'activo' => false]);
        Usuario::factory()->create(['rut' => '15.003.151-6', 'nombre' => 'Matías', 'apellido' => 'Rojas', 'email' => 'mrojas@umag.cl']);

        $this->postJson('/api/usuarios/importar', ['confirmado' => true, 'filas' => [
            ['fila' => 2, 'rut' => '11.111.111-1', 'email' => 'MRojas@umag.cl', 'carrera' => 'Enfermería'],
        ]])->assertCreated()
            ->assertJsonPath('filas.0.estado', 'actualizar')
            ->assertJsonPath('filas.0.cambios', ['carrera' => ['Derecho', 'Enfermería'], 'activo' => [false, true]])
            ->assertJsonPath('filas.0.mensajes.1', 'El correo mrojas@umag.cl ya lo usa Matías Rojas (15.003.151-6): se deja el que tenía.');

        $inactiva->refresh();
        $this->assertNull($inactiva->email);
        $this->assertTrue($inactiva->activo);
        $this->assertSame('Enfermería', $inactiva->carrera);
    }

    public function test_un_rut_que_coincide_con_un_dni_extranjero_no_mezcla_a_dos_personas(): void
    {
        $this->comoAdmin();
        // 3.012.346-8 y el DNI argentino 30123468 tienen los mismos números.
        $pedro = Usuario::factory()->create(['rut' => '3.012.346-8', 'tipo_documento' => 'rut', 'nombre' => 'Pedro', 'apellido' => 'Pérez', 'activo' => false]);

        $this->postJson('/api/usuarios/importar', ['filas' => $this->planillaMatricula(), 'confirmado' => true])
            ->assertCreated()
            ->assertJsonPath('filas.2.estado', 'invalido')
            ->assertJsonPath('filas.2.mensajes.0', 'El número coincide con el RUT de Pedro Pérez (3.012.346-8), pero en la planilla viene como dni / cédula extranjera: revisa si es la misma persona. No se cargará.');

        $pedro->refresh();
        $this->assertSame(['Pedro', 'Pérez', false], [$pedro->nombre, $pedro->apellido, $pedro->activo]);
    }

    public function test_si_alguien_que_ya_existe_aparece_dos_veces_se_usa_solo_la_primera_fila(): void
    {
        $this->comoAdmin();
        $valentina = Usuario::factory()->create(['rut' => '11.111.111-1', 'carrera' => null]);

        $this->postJson('/api/usuarios/importar', ['confirmado' => true, 'filas' => [
            ['fila' => 2, 'rut' => '11.111.111-1', 'carrera' => 'Derecho'],
            ['fila' => 3, 'rut' => '111111111', 'carrera' => 'Enfermería'],
        ]])->assertCreated()
            ->assertJsonPath('filas.0.estado', 'actualizar')
            ->assertJsonPath('filas.1.estado', 'duplicado');

        $this->assertSame('Derecho', $valentina->fresh()->carrera);
    }

    public function test_la_carga_nunca_deja_a_nadie_inactivo(): void
    {
        $this->comoAdmin();
        $activo = Usuario::factory()->create(['rut' => '11.111.111-1', 'nombre' => 'Valentina', 'apellido' => 'Soto']);

        $this->postJson('/api/usuarios/importar', ['confirmado' => true, 'filas' => [
            ['fila' => 2, 'rut' => '11.111.111-1', 'nombre' => 'Valentina', 'apellido' => 'Soto', 'activo' => 'no'],
            ['fila' => 3, 'rut' => '15.003.151-6', 'nombre' => 'Sebastián', 'apellido' => 'Sánchez', 'activo' => 'false'],
        ]])->assertCreated()
            ->assertJsonPath('filas.0.estado', 'sin_cambios')
            ->assertJsonPath('creados', 1);

        $this->assertTrue($activo->fresh()->activo);
        $this->assertTrue(Usuario::where('rut', '15.003.151-6')->sole()->activo);
    }

    public function test_una_fila_con_documento_dudoso_avisa_todo_lo_que_le_falta(): void
    {
        $this->comoAdmin();
        $filas = [
            // Solo números, no cuadra como RUT y la fila no dice que sea extranjero.
            ['fila' => 893, 'rut' => '473609075', 'nombre' => '', 'apellido_paterno' => '', 'carrera' => 'Kinesiología'],
            // Con la nacionalidad, un número así se reconoce como DNI: falta solo el nombre.
            ['fila' => 894, 'rut' => '473609076', 'nombre' => '', 'apellido_paterno' => 'PÉREZ', 'nacionalidad' => 'ARGENTINA'],
        ];

        $respuesta = $this->postJson('/api/usuarios/importar/revisar', ['filas' => $filas])->assertOk();

        $this->assertSame('invalido', $respuesta->json('filas.0.estado'));
        $this->assertStringStartsWith('«473609075» no es un RUT válido', $respuesta->json('filas.0.mensajes.0'));
        $this->assertStringContainsString('«Nacionalidad»', $respuesta->json('filas.0.mensajes.0'));
        $this->assertSame(['Falta el nombre.', 'Falta el apellido.'], array_slice($respuesta->json('filas.0.mensajes'), 1));

        $this->assertSame('dni', $respuesta->json('filas.1.datos.tipo_documento'));
        $this->assertSame(['Falta el nombre.'], $respuesta->json('filas.1.mensajes'));
    }

    public function test_el_listado_de_usuarios_viene_por_paginas(): void
    {
        $this->comoAdmin();
        Usuario::factory()->count(30)->create(['tipo' => 'estudiante']);
        Usuario::factory()->count(3)->create(['tipo' => 'docente']);

        $this->getJson('/api/usuarios')->assertOk()
            ->assertJsonCount(25, 'data')
            ->assertJsonPath('total', 33)
            ->assertJsonPath('last_page', 2);
        $this->getJson('/api/usuarios?page=2')->assertOk()->assertJsonCount(8, 'data')->assertJsonPath('from', 26);
        // Los filtros cuentan sobre todo el listado, no solo sobre la página.
        $this->getJson('/api/usuarios?tipo=docente')->assertOk()->assertJsonPath('total', 3);
        $this->getJson('/api/usuarios?por_pagina=500')->assertOk()->assertJsonPath('per_page', 100);
    }

    public function test_desactivar_a_un_usuario_cierra_sus_sesiones_del_portal(): void
    {
        $usuario = Usuario::factory()->create(['tipo_documento' => 'rut']);
        $token = $usuario->createToken('usuario-token')->plainTextToken;
        $ficha = $usuario->only(['rut', 'tipo_documento', 'nombre', 'apellido', 'email', 'tipo', 'carrera', 'anio_ingreso', 'sexo']);

        // Con la contraseña nueva se cierran las sesiones abiertas con la anterior.
        $this->comoAdmin();
        $this->putJson("/api/usuarios/{$usuario->id}", $ficha + ['activo' => true, 'password' => 'nueva-clave'])->assertOk();
        $this->assertSame(0, $usuario->tokens()->count());

        $token = $usuario->createToken('usuario-token')->plainTextToken;
        $this->putJson("/api/usuarios/{$usuario->id}", $ficha + ['activo' => false])->assertOk();
        $this->assertSame(0, $usuario->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/mi/estado')->assertUnauthorized();
    }

    public function test_un_usuario_inactivo_no_usa_el_portal_aunque_tenga_un_token(): void
    {
        // Desactivado por otro lado (sin pasar por Administración): igual queda fuera.
        $usuario = Usuario::factory()->create();
        $token = $usuario->createToken('usuario-token')->plainTextToken;
        $usuario->update(['activo' => false]);

        $this->withToken($token)->getJson('/api/mi/estado')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Tu cuenta se encuentra inactiva. Contacta a biblioteca.');
        $this->withToken($token)->postJson('/api/mi/entrada', ['via' => 'manual', 'rut' => $usuario->rut])->assertUnauthorized();
        $this->assertSame(0, $usuario->entradas()->count());
    }
}
