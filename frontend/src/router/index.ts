import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useUsuarioAuthStore } from '@/stores/usuarioAuth'
import { useToast } from '@/composables/useToast'
import { itemPorRuta } from '@/navegacion'

const router = createRouter({
  history: createWebHistory(),
  scrollBehavior: () => ({ top: 0 }),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginView.vue'),
      meta: { public: true },
    },
    {
      path: '/login/v2',
      name: 'login-v2',
      component: () => import('@/views/LoginV2View.vue'),
      meta: { public: true },
    },
    {
      path: '/portal/login',
      name: 'portal-login',
      component: () => import('@/views/portal/PortalLoginView.vue'),
      meta: { public: true, portal: true },
    },
    {
      path: '/portal',
      name: 'portal-home',
      component: () => import('@/views/portal/PortalHomeView.vue'),
      meta: { portal: true },
    },
    {
      path: '/portal/entrada',
      name: 'portal-entrada',
      component: () => import('@/views/portal/PortalEntradaView.vue'),
      meta: { portal: true },
    },
    {
      path: '/portal/catalogo',
      name: 'portal-catalogo',
      component: () => import('@/views/portal/PortalCatalogoView.vue'),
      meta: { portal: true },
    },
    {
      path: '/portal/salas',
      name: 'portal-salas',
      component: () => import('@/views/portal/PortalSalasView.vue'),
      meta: { portal: true },
    },
    {
      path: '/portal/perfil',
      name: 'portal-perfil',
      component: () => import('@/views/portal/PortalPerfilView.vue'),
      meta: { portal: true },
    },
    {
      path: '/portal/ingresos',
      name: 'portal-ingresos',
      component: () => import('@/views/portal/PortalIngresosView.vue'),
      meta: { portal: true },
    },
    { path: '/', redirect: '/dashboard' },
    { path: '/dashboard', name: 'dashboard', component: () => import('@/views/DashboardView.vue') },
    { path: '/entrada', name: 'entrada', component: () => import('@/views/EntradaView.vue') },
    { path: '/prestamo', name: 'prestamo', component: () => import('@/views/PrestamoView.vue') },
    { path: '/usuarios', name: 'usuarios', component: () => import('@/views/UsuariosView.vue') },
    { path: '/salas', name: 'salas', component: () => import('@/views/SalasView.vue') },
    { path: '/multas', name: 'multas', component: () => import('@/views/MultasView.vue') },
    { path: '/reportes/multas-pendientes', redirect: '/multas' },
    { path: '/reportes', name: 'reportes', component: () => import('@/views/ReportesView.vue') },
    { path: '/codigo-qr', name: 'codigo-qr', component: () => import('@/views/CodigoQrView.vue') },
    { path: '/prestamos/listado', name: 'listado-prestamos', component: () => import('@/views/ListadoPrestamosView.vue') },
    { path: '/salas/reservas', name: 'listado-reservas', component: () => import('@/views/ListadoReservasView.vue') },
    { path: '/catalogo/listado', name: 'listado-libros', component: () => import('@/views/ListadoLibrosView.vue') },
    { path: '/catalogo/catalogacion', name: 'catalogacion-libros', component: () => import('@/views/CatalogacionLibrosView.vue') },
    { path: '/catalogo/estado', name: 'estado-libro', component: () => import('@/views/EstadoLibroView.vue') },
    { path: '/catalogo/cambio-masivo', name: 'cambio-masivo-estado', component: () => import('@/views/CambioMasivoEstadoView.vue') },
    { path: '/catalogo/etiquetas', name: 'etiquetas', component: () => import('@/views/EtiquetasView.vue') },
    { path: '/catalogo/historial-estado', name: 'historial-estado-libro', component: () => import('@/views/HistorialEstadoLibroView.vue') },
    { path: '/catalogo/historial-prestamos', name: 'historial-prestamos-libro', component: () => import('@/views/HistorialPrestamosLibroView.vue') },
    // Las rutas de antes (/libros/...) siguen funcionando: favoritos y atajos guardados.
    { path: '/libros/listado', redirect: '/catalogo/listado' },
    { path: '/libros/catalogacion', redirect: '/catalogo/catalogacion' },
    { path: '/libros/estado', redirect: '/catalogo/estado' },
    { path: '/libros/cambio-masivo', redirect: '/catalogo/cambio-masivo' },
    { path: '/libros/etiquetas', redirect: '/catalogo/etiquetas' },
    { path: '/libros/historial-estado', redirect: '/catalogo/historial-estado' },
    { path: '/libros/historial-prestamos', redirect: '/catalogo/historial-prestamos' },
    { path: '/equipos', name: 'equipos', component: () => import('@/views/EquiposView.vue') },
    { path: '/administracion', name: 'administracion', component: () => import('@/views/AdministracionView.vue') },
    { path: '/personal', name: 'personal', component: () => import('@/views/PersonalView.vue') },
    { path: '/registro-absoluto', name: 'registro-absoluto', component: () => import('@/views/RegistroAbsolutoView.vue') },
    { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
  ],
})

router.beforeEach(async (to) => {
  if (to.meta.portal) {
    const usuarioAuth = useUsuarioAuthStore()
    if (!to.meta.public && !usuarioAuth.token) {
      return { name: 'portal-login' }
    }
    // El token existe en localStorage, pero eso no garantiza que el backend
    // lo siga reconociendo (pudo vencer o haberse revocado) — se confirma
    // contra la API antes de dejar pasar a una ruta protegida del portal.
    if (!to.meta.public && usuarioAuth.token) {
      const sesionValida = await usuarioAuth.validar()
      if (!sesionValida) {
        return { name: 'portal-login' }
      }
    }
    if (to.name === 'portal-login' && usuarioAuth.token) {
      return { name: 'portal-home' }
    }
    return
  }

  const auth = useAuthStore()
  if (!to.meta.public && !auth.token) {
    return { name: 'login' }
  }
  // Misma verificación que en el portal, para el panel de personal.
  if (!to.meta.public && auth.token) {
    const sesionValida = await auth.validar()
    if (!sesionValida) {
      return { name: 'login' }
    }
  }
  if (to.name === 'login' && auth.token) {
    return { name: 'dashboard' }
  }

  // Cada módulo exige el mismo permiso que su entrada en el menú (navegacion.ts).
  const modulo = itemPorRuta(to.name)
  if (modulo && auth.staff && !auth.puedeAlguno(modulo.permiso)) {
    useToast().error(`Tu rol no tiene acceso a «${modulo.label}». Si lo necesitas, pídeselo a un administrador.`)
    return { name: 'dashboard' }
  }
})

export default router
