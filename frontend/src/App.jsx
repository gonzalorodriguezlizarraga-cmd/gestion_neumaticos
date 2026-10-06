import { Navigate, Route, Routes } from 'react-router-dom';
import { AppLayout } from './layouts/AppLayout';
import { ProtectedRoute } from './components/ProtectedRoute';
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
import { MantenimientoDetallePage } from './pages/mantenimientos/MantenimientoDetallePage';
import { MantenimientoNuevoPage } from './pages/mantenimientos/MantenimientoNuevoPage';
import { MantenimientosPage } from './pages/mantenimientos/MantenimientosPage';
import { UnidadDetallePage } from './pages/unidades/UnidadDetallePage';
import { UnidadFormPage } from './pages/unidades/UnidadFormPage';
import { UnidadesPage } from './pages/unidades/UnidadesPage';

export function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route element={<ProtectedRoute />}>
        <Route element={<AppLayout />}>
          <Route path="/" element={<DashboardPage />} />
          <Route path="/dashboard" element={<DashboardPage />} />
          <Route path="/clientes" element={<ClientesPage />} />
          <Route path="/clientes/nuevo" element={<ClienteFormPage />} />
          <Route path="/clientes/:id" element={<ClienteDetallePage />} />
          <Route path="/clientes/:id/editar" element={<ClienteFormPage />} />
          <Route path="/unidades" element={<UnidadesPage />} />
          <Route path="/unidades/nueva" element={<UnidadFormPage />} />
          <Route path="/unidades/:id" element={<UnidadDetallePage />} />
          <Route path="/unidades/:id/editar" element={<UnidadFormPage />} />
          <Route path="/inspecciones" element={<InspeccionesPage />} />
          <Route path="/inspecciones/nueva" element={<InspeccionNuevaPage />} />
          <Route path="/inspecciones/:id" element={<InspeccionDetallePage />} />
          <Route path="/mantenimientos" element={<MantenimientosPage />} />
          <Route path="/mantenimientos/nuevo" element={<MantenimientoNuevoPage />} />
          <Route path="/mantenimientos/:id" element={<MantenimientoDetallePage />} />
          <Route path="/neumaticos" element={<NeumaticosPage />} />
          <Route path="/neumaticos/nuevo" element={<NeumaticoFormPage />} />
          <Route path="/neumaticos/catalogo" element={<CatalogoNeumaticosPage />} />
          <Route path="/neumaticos/:id" element={<NeumaticoDetallePage />} />
          <Route path="/neumaticos/:id/editar" element={<NeumaticoFormPage />} />
          <Route path="/configuraciones-unidad" element={<ConfiguracionesPage />} />
          <Route path="/configuraciones-unidad/nueva" element={<ConfiguracionFormPage />} />
          <Route path="/configuraciones-unidad/:id" element={<ConfiguracionDetallePage />} />
          <Route path="/configuraciones-unidad/:id/editar" element={<ConfiguracionFormPage />} />
          <Route path="/tipos-unidad" element={<TiposUnidadPage />} />
        </Route>
      </Route>
      <Route path="/403" element={<ForbiddenPage />} />
      <Route path="/404" element={<NotFoundPage />} />
      <Route path="*" element={<NotFoundPage />} />
      <Route path="/home" element={<Navigate to="/dashboard" replace />} />
    </Routes>
  );
}
