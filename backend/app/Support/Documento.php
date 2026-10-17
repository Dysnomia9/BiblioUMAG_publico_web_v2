<?php

namespace App\Support;

/**
 * Identificación de una persona: RUT chileno o, para extranjeros, pasaporte, DNI
 * (argentino u otro país) o cualquier otro documento. El sistema compara siempre el
 * identificador "normalizado" —solo letras y números, en mayúscula—, así que da lo
 * mismo si alguien escribe «12.345.678-5», «12345678-5» o «123456785», o un
 * pasaporte con o sin espacios.
 */
final class Documento
{
    public const TIPOS = [
        'rut' => 'RUT',
        'pasaporte' => 'Pasaporte',
        'dni' => 'DNI / cédula extranjera',
        'otro' => 'Otro documento',
    ];

    /**
     * Expresión SQL equivalente a normalizar() sobre una columna (MySQL 8: REGEXP_REPLACE
     * reemplaza todas las coincidencias). Para usuarios.rut está ya calculada en la
     * columna generada `rut_normalizado`, que tiene índice.
     */
    public static function sql(string $columna): string
    {
        return "UPPER(REGEXP_REPLACE({$columna}, '[^0-9A-Za-z]', ''))";
    }

    public static function normalizar(?string $valor): string
    {
        return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) $valor));
    }

    /** Cómo se guarda: el RUT con puntos y guion; el resto, normalizado. */
    public static function formatear(string $valor, string $tipo): string
    {
        if ($tipo === 'rut') {
            $limpio = self::normalizar($valor);

            return Rut::formatear((int) substr($limpio, 0, -1));
        }

        return self::normalizar($valor);
    }

    /** Mensaje de error si el valor no sirve para ese tipo de documento, o null si está bien. */
    public static function validar(?string $valor, string $tipo): ?string
    {
        $normalizado = self::normalizar($valor);

        if ($normalizado === '') {
            return 'Falta el RUT o documento.';
        }

        if ($tipo === 'rut') {
            return Rut::validar($normalizado) ? null : 'El RUT no es válido: revisa los números y el dígito verificador.';
        }

        if (strlen($normalizado) < 5 || strlen($normalizado) > 20 || ! preg_match('/\d/', $normalizado)) {
            return 'El número de '.mb_strtolower(self::TIPOS[$tipo] ?? 'documento').' debe tener entre 5 y 20 letras o números.';
        }

        return null;
    }

    /**
     * Para cuando no viene el tipo (carga masiva sin columna de tipo): con letras es
     * pasaporte; solo números, RUT. Un DNI o cédula extranjera, que también son solo
     * números, hay que declararlo como tal: si no, un RUT mal escrito pasaría
     * calladamente como documento extranjero en vez de avisar el error.
     *
     * La excepción es cuando la planilla dice que la persona es extranjera (el reporte
     * de matrícula trae la nacionalidad): ahí un RUT viene escrito como tal, con guion
     * y dígito verificador, y lo demás es su documento — pasaporte si tiene letras, DNI
     * si son solo números. Así el DNI argentino de 8 dígitos no se rechaza como RUT
     * malo, ni pasa por RUT cuando por casualidad cuadra el dígito verificador.
     */
    public static function detectarTipo(?string $valor, bool $extranjero = false): string
    {
        $normalizado = self::normalizar($valor);

        if ($extranjero) {
            if (str_contains((string) $valor, '-') && Rut::validar($normalizado)) {
                return 'rut';
            }

            return preg_match('/[A-Z]/', $normalizado) ? 'pasaporte' : 'dni';
        }

        if (Rut::validar($normalizado)) {
            return 'rut';
        }

        return preg_match('/[A-Z]/', substr($normalizado, 0, -1)) ? 'pasaporte' : 'rut';
    }

    /** Acepta lo que se escribe en una planilla: «RUT», «Pasaporte», «DNI argentino», «Cédula»… */
    public static function tipoDesdeTexto(?string $texto): ?string
    {
        $t = strtolower(trim(strtr((string) $texto, ['é' => 'e', 'É' => 'e', 'í' => 'i', 'Í' => 'i'])));

        return match (true) {
            $t === '' => null,
            str_starts_with($t, 'rut'), str_starts_with($t, 'run') => 'rut',
            str_starts_with($t, 'pasaporte'), str_starts_with($t, 'passport') => 'pasaporte',
            str_starts_with($t, 'dni'), str_starts_with($t, 'cedula'), $t === 'ci' => 'dni',
            str_starts_with($t, 'otro') => 'otro',
            default => null,
        };
    }

    /** La nacionalidad como la escriben los reportes de la universidad, y su país. */
    private const PAIS_POR_NACIONALIDAD = [
        'argentina' => 'Argentina', 'argentino' => 'Argentina',
        'boliviana' => 'Bolivia', 'boliviano' => 'Bolivia',
        'brasilena' => 'Brasil', 'brasileno' => 'Brasil', 'brasilera' => 'Brasil', 'brasilero' => 'Brasil',
        'china' => 'China', 'chino' => 'China',
        'colombiana' => 'Colombia', 'colombiano' => 'Colombia',
        'cubana' => 'Cuba', 'cubano' => 'Cuba',
        'dominicana' => 'República Dominicana', 'dominicano' => 'República Dominicana',
        'ecuatoriana' => 'Ecuador', 'ecuatoriano' => 'Ecuador',
        'espanola' => 'España', 'espanol' => 'España',
        'estadounidense' => 'Estados Unidos', 'norteamericana' => 'Estados Unidos', 'norteamericano' => 'Estados Unidos',
        'haitiana' => 'Haití', 'haitiano' => 'Haití',
        'hondurena' => 'Honduras', 'hondureno' => 'Honduras',
        'mexicana' => 'México', 'mexicano' => 'México',
        'paraguaya' => 'Paraguay', 'paraguayo' => 'Paraguay',
        'peruana' => 'Perú', 'peruano' => 'Perú',
        'uruguaya' => 'Uruguay', 'uruguayo' => 'Uruguay',
        'venezolana' => 'Venezuela', 'venezolano' => 'Venezuela',
    ];

    /** Vacía o chilena: la planilla no dice que la persona sea extranjera. */
    public static function esNacionalidadChilena(?string $nacionalidad): bool
    {
        return in_array(Texto::clave($nacionalidad), ['', 'chilena', 'chileno', 'chile'], true);
    }

    /** «COLOMBIANA» → «Colombia». Null si no dice cuál («EXTRANJERA»). */
    public static function paisDesdeNacionalidad(?string $nacionalidad): ?string
    {
        $clave = Texto::clave($nacionalidad);

        if (in_array($clave, ['', 'extranjera', 'extranjero', 'otra', 'otro'], true)) {
            return null;
        }

        return self::PAIS_POR_NACIONALIDAD[$clave] ?? Texto::nombrePropio(trim((string) $nacionalidad));
    }

    /** «Pasaporte AB123456 (Argentina)» — para mensajes y la bitácora. */
    public static function describir(string $identificador, ?string $tipo, ?string $pais = null): string
    {
        if (! $tipo || $tipo === 'rut') {
            return "RUT {$identificador}";
        }

        $nombre = $tipo === 'dni' ? 'DNI' : (self::TIPOS[$tipo] ?? 'Documento');

        return trim("{$nombre} {$identificador}".($pais ? " ({$pais})" : ''));
    }
}
