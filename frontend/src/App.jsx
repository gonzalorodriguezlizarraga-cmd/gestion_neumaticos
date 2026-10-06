import { Navigate, Route, Routes } from 'react-router-dom';
import { AppLayout } from './layouts/AppLayout';
import { ProtectedRoute } from './components/ProtectedRoute';
import { useAuth } from './auth/AuthContext';
import { canOperateCliente, canReadComercial } from './utils/access';
import { ReportesPage } from './pages/reportes/ReportesPage';
import { DashboardPage } from './pages/DashboardPage';
import { ForbiddenPage } from './pages/ForbiddenPage';
import { LoginPage } from './pages/LoginPage';
import { NotFoundPage } from './pages/NotFoundPage';
import { ClienteDetallePage } from './pages/clientes/ClienteDetallePage';
import { ClienteFormPage } from './pages/clientes/ClienteFormPage';
import { ClientesPage } from './pages/clientes/ClientesPage';
import { ConfiguracionDetallePage } from './pages/configuraciones/ConfiguracionDetallePage';
import { ConfiguracionFormPage } from './pages/configuraciones/ConfiguracionFormPage';
import { ConfiguracionesPage } from './pages/configuraciones/ConfiguracionesPage';
import { TiposUnidadPage } from './pages/configuraciones/TiposUnidadPage';
import { CatalogoNeumaticosPage } from './pages/neumaticos/CatalogoNeumaticosPage';
import { NeumaticoDetallePage } from './pages/neumaticos/NeumaticoDetallePage';
import { NeumaticoFormPage } from './pages/neumaticos/NeumaticoFormPage';
import { NeumaticosPage } from './pages/neumaticos/NeumaticosPage';
import { InspeccionDetallePage } from './pages/inspecciones/InspeccionDetallePage';
import { InspeccionNuevaPage } from './pages/inspecciones/InspeccionNuevaPage';
import { InspeccionesPage } from './pages/inspecciones/InspeccionesPage';
import { AlertaDetallePage } from './pages/alertas/AlertaDetallePage';
import { CotizacionDetallePage } from './pages/comercial/CotizacionDetallePage';
import { CotizacionEditarPage, CotizacionNuevaPage } from './pages/comercial/CotizacionFormPage';
import { CotizacionesPage } from './pages/comercial/CotizacionesPage';
import { OportunidadDetallePage } from './pages/comercial/OportunidadDetallePage';
import { OportunidadDesdeAlertaPage, OportunidadEditarPage, OportunidadNuevaPage } from './pages/comercial/OportunidadFormPage';
import { OportunidadesPage } from './pages/comercial/OportunidadesPage';
import { SeguimientosPage } from './pages/comercial/SeguimientosPage';
import { AlertaNuevaPage } from './pages/alertas/AlertaNuevaPage';
import { AlertasPage } from './pages/alertas/AlertasPage';
import { IndicadoresPage } from './pages/indicadores/IndicadoresPage';
import { MantenimientoDetallePage } from './pages/mantenimientos/MantenimientoDetallePage';
import { MantenimientoNuevoPage } from './pages/mantenimientos/MantenimientoNuevoPage';
import { MantenimientosPage } from './pages/mantenimientos/MantenimientosPage';
import { UnidadDetallePage } from './pages/unidades/UnidadDetallePage';
import { UnidadFormPage } from './pages/unidades/UnidadFormPage';
import { UnidadesPage } from './pages/unidades/UnidadesPage';

function SoloOperador({ children }) {
  const { user } = useAuth();
  if (!canOperateCliente(user)) return <Navigate to="/dashboard" replace />;
  return children;
}

function SoloComercial({ children }) {
  const { user } = useAuth();
  if (!canReadComercial(user)) return <Navigate to="/dashboard" replace />;
  return children;
}

export function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route element={<ProtectedRoute />}>
        <Route element={<AppLayout />}>
          <Route path="/" element={<DashboardPage />} />
          <Route path="/dashboard" element={<DashboardPage />} />
          <Route path="/reportes" element={<ReportesPage />} />
          <Route path="/clientes" element={<SoloOperador><ClientesPage /></SoloOperador>} />
          <Route path="/clientes/nuevo" element={<SoloOperador><ClienteFormPage /></SoloOperador>} />
          <Route path="/clientes/:id" element={<SoloOperador><ClienteDetallePage /></SoloOperador>} />
          <Route path="/clientes/:id/editar" element={<SoloOperador><ClienteFormPage /></SoloOperador>} />
          <Route path="/unidades" element={<UnidadesPage />} />
          <Route path="/unidades/nueva" element={<UnidadFormPage />} />
          <Route path="/unidades/:id" element={<UnidadDetallePage />} />
          <Route path="/unidades/:id/editar" element={<UnidadFormPage />} />
          <Route path="/inspecciones" element={<InspeccionesPage />} />
          <Route path="/inspecciones/nueva" element={<InspeccionNuevaPage />} />
          <Route path="/inspecciones/:id" element={<InspeccionDetallePage />} />
          <Route path="/alertas" element={<AlertasPage />} />
          <Route path="/alertas/nueva" element={<AlertaNuevaPage />} />
          <Route path="/alertas/:id" element={<AlertaDetallePage />} />
          <Route path="/comercial/oportunidades" element={<SoloComercial><OportunidadesPage /></SoloComercial>} />
          <Route path="/comercial/oportunidades/nueva" element={<SoloComercial><OportunidadNuevaPage /></SoloComercial>} />
          <Route path="/comercial/oportunidades/desde-alerta/:alertaId" element={<SoloComercial><OportunidadDesdeAlertaPage /></SoloComercial>} />
          <Route path="/comercial/oportunidades/:id/editar" element={<SoloComercial><OportunidadEditarPage /></SoloComercial>} />
          <Route path="/comercial/oportunidades/:id" element={<SoloComercial><OportunidadDetallePage /></SoloComercial>} />
          <Route path="/comercial/seguimientos" element={<SoloComercial><SeguimientosPage /></SoloComercial>} />
          <Route path="/comercial/cotizaciones" element={<SoloComercial><CotizacionesPage /></SoloComercial>} />
          <Route path="/comercial/cotizaciones/nueva" element={<SoloComercial><CotizacionNuevaPage /></SoloComercial>} />
          <Route path="/comercial/cotizaciones/:id/editar" element={<SoloComercial><CotizacionEditarPage /></SoloComercial>} />
          <Route path="/comercial/cotizaciones/:id" element={<SoloComercial><CotizacionDetallePage /></SoloComercial>} />
          <Route path="/indicadores" element={<SoloOperador><IndicadoresPage /></SoloOperador>} />
          <Route path="/mantenimientos" element={<MantenimientosPage />} />
          <Route path="/mantenimientos/nuevo" element={<MantenimientoNuevoPage />} />
          <Route path="/mantenimientos/:id" element={<MantenimientoDetallePage />} />
          <Route path="/neumaticos" element={<NeumaticosPage />} />
          <Route path="/neumaticos/nuevo" element={<NeumaticoFormPage />} />
          <Route path="/neumaticos/catalogo" element={<SoloOperador><CatalogoNeumaticosPage /></SoloOperador>} />
          <Route path="/neumaticos/:id" element={<NeumaticoDetallePage />} />
          <Route path="/neumaticos/:id/editar" element={<NeumaticoFormPage />} />
          <Route path="/configuraciones-unidad" element={<SoloOperador><ConfiguracionesPage /></SoloOperador>} />
          <Route path="/configuraciones-unidad/nueva" element={<SoloOperador><ConfiguracionFormPage /></SoloOperador>} />
          <Route path="/configuraciones-unidad/:id" element={<SoloOperador><ConfiguracionDetallePage /></SoloOperador>} />
          <Route path="/configuraciones-unidad/:id/editar" element={<SoloOperador><ConfiguracionFormPage /></SoloOperador>} />
          <Route path="/tipos-unidad" element={<SoloOperador><TiposUnidadPage /></SoloOperador>} />
        </Route>
      </Route>
      <Route path="/403" element={<ForbiddenPage />} />
      <Route path="/404" element={<NotFoundPage />} />
      <Route path="*" element={<NotFoundPage />} />
      <Route path="/home" element={<Navigate to="/dashboard" replace />} />
    </Routes>
  );
}
