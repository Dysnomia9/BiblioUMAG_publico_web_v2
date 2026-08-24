<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LoginUnificadoService;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Login con Google para LoginV2View.vue. Sin GOOGLE_CLIENT_ID configurado (ver
 * .env.example), redirect()/callback() mandan de vuelta al frontend con un error
 * en vez de intentar contactar a Google — así el botón puede mostrarse siempre,
 * sin necesitar un endpoint aparte para consultar "¿está configurado?".
 */
class GoogleAuthController extends Controller
{
    public function __construct(private LoginUnificadoService $loginUnificado)
    {
    }

    public function redirect()
    {
        if (! config('services.google.client_id')) {
            return redirect($this->urlFrontend('google_no_configurado'));
        }

        return Socialite::driver('google')->stateless()->redirect();
    }

    public function callback(Request $request)
    {
        if (! config('services.google.client_id')) {
            return redirect($this->urlFrontend('google_no_configurado'));
        }

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (Throwable $e) {
            return redirect($this->urlFrontend('google_fallo'));
        }

        $resultado = $this->loginUnificado->porEmail($googleUser->getEmail());

        if (! $resultado) {
            return redirect($this->urlFrontend('google_sin_cuenta'));
        }

        $frontend = rtrim(config('services.frontend.url'), '/');

        return redirect("{$frontend}/login/v2?token={$resultado['token']}&tipo={$resultado['tipo']}");
    }

    private function urlFrontend(string $error): string
    {
        $frontend = rtrim(config('services.frontend.url'), '/');

        return "{$frontend}/login/v2?error={$error}";
    }
}
