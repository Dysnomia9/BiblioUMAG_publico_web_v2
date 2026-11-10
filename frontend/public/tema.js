// Pone el modo oscuro y el fondo del modo día antes de dibujar la página, para que no
// aparezca un instante en claro ni con otro fondo. Va en un archivo aparte porque la
// política de seguridad (nginx-cabeceras.conf) no deja correr código escrito dentro de la
// página. El resto está en useTema.ts y useFondo.ts.
;(function () {
  var raiz = document.documentElement
  try {
    if (localStorage.getItem('tema') === 'oscuro') raiz.classList.add('dark')
  } catch (e) {
    // Sin acceso a localStorage (modo privado estricto): queda en claro.
  }
  // El portal usa siempre Neblina; el panel, el último fondo que eligió el personal en este
  // navegador (lo confirma la sesión al cargar) o, si no hay, el predeterminado: Patagonia
  // (FONDO_PREDETERMINADO en utils/fondos.ts).
  var portal = location.pathname.indexOf('/portal') === 0
  var fondo = portal ? 'neblina' : 'patagonia'
  try {
    var recordado = localStorage.getItem('fondo')
    if (!portal && /^[a-z-]+$/.test(recordado || '')) fondo = recordado
  } catch (e) {
    // Sin localStorage: el predeterminado.
  }
  raiz.setAttribute('data-fondo', fondo)
})()
