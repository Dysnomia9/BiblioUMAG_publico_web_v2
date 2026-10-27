// Pone el modo oscuro antes de dibujar la página, para que no aparezca un instante en
// claro. Va en un archivo aparte porque la política de seguridad (nginx-cabeceras.conf)
// no deja correr código escrito dentro de la página. El resto está en useTema.ts.
try {
  if (localStorage.getItem('tema') === 'oscuro') document.documentElement.classList.add('dark')
} catch (e) {
  // Sin acceso a localStorage (modo privado estricto): queda en claro.
}
