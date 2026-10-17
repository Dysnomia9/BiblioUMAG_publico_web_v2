<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LoginUnificadoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Login con Google para LoginV2View.vue. Sin GOOGLE_CLIENT_ID configurado (ver
 * .env.example), redirect()/callback() mandan de vuelta al frontend con un error
 * en vez de intentar contactar a Google — así el botón puede mostrarse siempre,
 * sin necesitar un endpoint aparte para consultar "¿está configurado?".
 *
 * La API no tiene sesión, así que Socialite va en modo stateless y el `state` de
 * OAuth se lleva a mano en una cookie: sin él, cualquiera podría hacer que otra
 * persona termine con la sesión de su cuenta (login CSRF). Al volver, el frontend
 * recibe un código de un solo uso, no el token (ver LoginUnificadoService::codigoDeCanje()).
 */
class GoogleAuthController extends Controller
{
    private const COOKIE_STATE = 'google_oauth_state';

    public function __construct(private LoginUnificadoService $loginUnificado)
    {
    }

    public function redirect(Request $request)
    {
        if (! config('services.google.client_id')) {
            return redirect($this->urlFrontend('google_no_configurado'));
        }

        $state = Str::random(40);
        $parametros = ['state' => $state];
        // Con un solo dominio permitido, Google ofrece solo las cuentas de ese dominio.
        $dominios = config('services.google.dominios', []);
        if (count($dominios) === 1) {
            $parametros['hd'] = $dominios[0];
        }

        $respuesta = Socialite::driver('google')->stateless()->with($parametros)->redirect();
        // Lax: el navegador la manda al volver de Google, que es una navegación normal.
        $respuesta->headers->setCookie(cookie(self::COOKIE_STATE, $state, 10, null, null, $request->isSecure(), true, false, 'lax'));

        return $respuesta;
    }

    public function callback(Request $request)
    {
        if (! config('services.google.client_id')) {
            return redirect($this->urlFrontend('google_no_configurado'));
        }

        $stateEsperado = (string) $request->cookie(self::COOKIE_STATE);
        if ($stateEsperado === '' || ! hash_equals($stateEsperado, (string) $request->query('state'))) {
            return $this->volverConError('google_fallo');
        }

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (Throwable $e) {
            return $this->volverConError('google_fallo');
        }

        // Solo correos que Google confirma como del dueño de la cuenta y, si se
        // configuraron dominios (GOOGLE_DOMINIOS), cuentas de esos dominios.
        $datos = $googleUser->getRaw();
        if (! filter_var($datos['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN) || ! $this->dominioPermitido($datos['hd'] ?? null)) {
            return $this->volverConError('google_no_permitido');
        }

        $cuenta = $this->loginUnificado->cuentaPorEmail((string) $googleUser->getEmail());

        if (! $cuenta) {
            return $this->volverConError('google_sin_cuenta');
        }

        $frontend = rtrim(config('services.frontend.url'), '/');
        $codigo = $this->loginUnificado->codigoDeCanje($cuenta);

        return redirect("{$frontend}/login/v2?codigo={$codigo}")->withCookie(cookie()->forget(self::COOKIE_STATE));
    }

    /** El frontend cambia el código que trajo de Google por el token de sesión (sirve una sola vez). */
    public function canjear(Request $request)
    {
        $data = $request->validate(['codigo' => ['required', 'string', 'size:64']]);

        $resultado = $this->loginUnificado->canjear($data['codigo']);

        if (! $resultado) {
            return response()->json(['message' => 'El inicio de sesión con Google venció o ya se usó. Vuelve a intentarlo.'], 422);
        }

        return response()->json([
            'tipo' => $resultado['tipo'],
            'token' => $resultado['token'],
        ]);
    }

    private function dominioPermitido(?string $dominio): bool
    {
        $permitidos = config('services.google.dominios', []);

        return $permitidos === [] || in_array(mb_strtolower((string) $dominio), $permitidos, true);
    }

    private function volverConError(string $error): RedirectResponse
    {
        return redirect($this->urlFrontend($error))->withCookie(cookie()->forget(self::COOKIE_STATE));
    }

    private function urlFrontend(string $error): string
    {
        $frontend = rtrim(config('services.frontend.url'), '/');

        return "{$frontend}/login/v2?error={$error}";
    }
}
