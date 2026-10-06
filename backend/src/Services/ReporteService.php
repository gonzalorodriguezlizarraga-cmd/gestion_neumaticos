<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Repositories\ReporteRepository;
use App\Support\CsvTabla;
use App\Support\PdfTabla;
use DateTimeImmutable;
use DateTimeZone;

final class ReporteService
{
    private const PDF_MAX = 500;
    private const CSV_MAX = 20000;

    /** @var array<string, array{titulo:string,roles:list<string>}> */
    private const TIPOS = [
        'inventario-neumaticos' => ['titulo' => 'Inventario de neumáticos', 'roles' => ['ADMIN_GENERAL', 'GESTOR_NEUMATICOS', 'TECNICO_INSPECCION', 'ADMIN_CLIENTE', 'CONSULTA_EJECUTIVA']],
        'estado-neumaticos' => ['titulo' => 'Estado actual de neumáticos', 'roles' => ['ADMIN_GENERAL', 'GESTOR_NEUMATICOS', 'TECNICO_INSPECCION', 'ADMIN_CLIENTE', 'CONSULTA_EJECUTIVA']],
        'movimientos' => ['titulo' => 'Historial de movimientos', 'roles' => ['ADMIN_GENERAL', 'GESTOR_NEUMATICOS', 'ADMIN_CLIENTE', 'CONSULTA_EJECUTIVA']],
        'inspecciones' => ['titulo' => 'Inspecciones', 'roles' => ['ADMIN_GENERAL', 'GESTOR_NEUMATICOS', 'TECNICO_INSPECCION', 'ADMIN_CLIENTE', 'CONSULTA_EJECUTIVA']],
        'alertas' => ['titulo' => 'Alertas', 'roles' => ['ADMIN_GENERAL', 'GESTOR_NEUMATICOS', 'ADMIN_CLIENTE', 'CONSULTA_EJECUTIVA']],
        'mantenimientos' => ['titulo' => 'Mantenimientos', 'roles' => ['ADMIN_GENERAL', 'GESTOR_NEUMATICOS', 'ADMIN_CLIENTE', 'CONSULTA_EJECUTIVA']],
        'vidas' => ['titulo' => 'Vidas y reencauches', 'roles' => ['ADMIN_GENERAL', 'GESTOR_NEUMATICOS', 'ADMIN_CLIENTE', 'CONSULTA_EJECUTIVA']],
        'descartes' => ['titulo' => 'Descartes', 'roles' => ['ADMIN_GENERAL', 'GESTOR_NEUMATICOS', 'ADMIN_CLIENTE', 'CONSULTA_EJECUTIVA']],
    ];

    public function __construct(
        private readonly ReporteRepository $reportes,
        private readonly AlcanceConsulta $alcance,
        private readonly AuthorizationService $authorization,
    ) {
    }

    /** @return array{0:array<string,mixed>,1:int} */
    public function consultar(Request $request, string $tipo): array
    {
        $pack = $this->preparar($request, $tipo, $this->pagina($request), $this->limite($request, 20, 100));
        $datos = [
            'tipo' => $tipo,
            'titulo' => self::TIPOS[$tipo]['titulo'],
            'columnas' => $this->columnas($tipo),
            'filas' => $pack['filas'],
        ];
        if ($tipo === 'mantenimientos') {
            $datos['totales_por_moneda'] = $this->reportes->totalesMoneda($pack['filtros']);
        }

        return [$datos, $pack['total']];
    }

    /** @return array{nombre:string,contenido:string,mime:string} */
    public function csv(Request $request, string $tipo): array
    {
        $pack = $this->preparar($request, $tipo, 0, self::CSV_MAX + 1);
        if ($pack['total'] > self::CSV_MAX) {
            throw new ValidationException('El reporte supera el máximo exportable. Acote los filtros.', [
                'exportacion' => ['Hay más de ' . self::CSV_MAX . ' filas.'],
            ]);
        }
        $matriz = $this->matriz($tipo, $pack['filas']);

        return [
            'nombre' => $this->nombre($tipo, $pack['cliente_nombre'], 'csv'),
            'contenido' => CsvTabla::generar(array_column($this->columnas($tipo), 'label'), $matriz),
            'mime' => 'text/csv; charset=utf-8',
        ];
    }

    /** @return array{nombre:string,contenido:string,mime:string} */
    public function pdf(Request $request, string $tipo): array
    {
        $pack = $this->preparar($request, $tipo, 0, self::PDF_MAX);
        $recorte = $pack['total'] > self::PDF_MAX;
        $matriz = $this->matriz($tipo, $pack['filas']);
        $fecha = (new DateTimeImmutable('now', new DateTimeZone('America/Lima')))->format('d/m/Y H:i');

        return [
            'nombre' => $this->nombre($tipo, $pack['cliente_nombre'], 'pdf'),
            'contenido' => PdfTabla::generar(
                self::TIPOS[$tipo]['titulo'],
                $fecha,
                $pack['rotulos'],
                array_column($this->columnas($tipo), 'label'),
                $matriz,
                $recorte,
            ),
            'mime' => 'application/pdf',
        ];
    }

    /**
     * @return array{filtros:array<string,mixed>,filas:list<array<string,mixed>>,total:int,cliente_nombre:?string,rotulos:list<string>}
     */
    private function preparar(Request $request, string $tipo, int $offset, int $limit): array
    {
        if (!isset(self::TIPOS[$tipo])) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'tipo' => ['El reporte no existe.'],
            ]);
        }
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...self::TIPOS[$tipo]['roles']);
        $base = $this->alcance->resolver($request);
        $query = $request->queryParams();
        $portal = (bool) $base['es_portal'];
        if ($portal && $tipo === 'inspecciones' && ($query['estado'] ?? '') === 'BORRADOR') {
            throw new AuthorizationException('No puede consultar inspecciones en borrador.');
        }
        if ($portal && $tipo === 'alertas' && ($query['estado'] ?? '') === 'DESCARTADA') {
            throw new AuthorizationException('No puede consultar alertas descartadas.');
        }
        $filtros = $base + [
            'estado' => $this->texto($query['estado'] ?? null, 'estado', 40),
            'marca_id' => $this->entero($query['marca_id'] ?? null, 'marca_id'),
            'modelo_id' => $this->entero($query['modelo_id'] ?? null, 'modelo_id'),
            'medida_id' => $this->entero($query['medida_id'] ?? null, 'medida_id'),
            'neumatico_id' => $this->alcance->exigirNeumatico($base, $this->entero($query['neumatico_id'] ?? null, 'neumatico_id')),
            'unidad_id' => $this->alcance->exigirUnidad($base, $this->entero($query['unidad_id'] ?? null, 'unidad_id')),
            'tipo' => $this->texto($query['tipo'] ?? null, 'tipo', 60),
            'fecha_inicio' => $this->fecha($query['fecha_inicio'] ?? null, 'fecha_inicio'),
            'fecha_fin' => $this->fecha($query['fecha_fin'] ?? null, 'fecha_fin'),
            'tecnico_id' => $this->entero($query['tecnico_id'] ?? null, 'tecnico_id'),
            'nivel' => $this->opcion($query['nivel'] ?? null, 'nivel', ['INFORMATIVA', 'ATENCION', 'CRITICA']),
            'automatica' => $this->bandera($query['generada_automaticamente'] ?? null),
            'motivo_id' => $this->entero($query['motivo_id'] ?? null, 'motivo_id'),
            'vida_final' => $this->entero($query['vida_final'] ?? null, 'vida_final'),
            'ocultar_descartadas' => $portal,
            'solo_finalizadas' => $portal && $tipo === 'inspecciones',
        ];
        $resultado = $this->reportes->consultar($tipo, $filtros, $offset, $limit);
        $filas = array_map(fn (array $fila): array => $this->presentar($tipo, $fila), $resultado['filas']);

        return [
            'filtros' => $filtros,
            'filas' => $filas,
            'total' => $resultado['total'],
            'cliente_nombre' => $this->nombreCliente($filtros),
            'rotulos' => $this->rotulos($filtros),
        ];
    }

    /** @param array<string, mixed> $fila @return array<string, mixed> */
    private function presentar(string $tipo, array $fila): array
    {
        $medicion = $this->medicion(isset($fila['medicion']) ? (string) $fila['medicion'] : null);
        if ($tipo === 'inventario-neumaticos') {
            return [
                'codigo' => $fila['codigo'],
                'serie' => $fila['numero_serie'],
                'cliente' => $fila['cliente'],
                'marca' => $fila['marca'],
                'modelo' => $fila['modelo'],
                'medida' => $fila['medida'],
                'estado' => $fila['estado'],
                'vida' => (int) $fila['vida_actual'],
                'unidad' => $fila['unidad'],
                'posicion' => $fila['posicion'],
                'profundidad' => $medicion['profundidad'],
                'criticidad' => $fila['criticidad'],
            ];
        }
        if ($tipo === 'estado-neumaticos') {
            $minima = $fila['profundidad_minima_mm'] === null ? null : number_format((float) $fila['profundidad_minima_mm'], 2, '.', '');
            $margen = $medicion['profundidad'] === null || $minima === null ? null : number_format(((float) $medicion['profundidad']) - ((float) $minima), 2, '.', '');

            return [
                'neumatico' => $fila['codigo'],
                'cliente' => $fila['cliente'],
                'estado' => $fila['estado'],
                'vida' => (int) $fila['vida_actual'],
                'profundidad_actual' => $medicion['profundidad'],
                'profundidad_minima' => $minima,
                'margen' => $margen,
                'criticidad' => $fila['criticidad'],
                'ultima_inspeccion' => $medicion['fecha'],
                'alertas_activas' => (int) $fila['alertas_activas'],
                'unidad' => $fila['unidad'],
                'posicion' => $fila['posicion'],
            ];
        }
        if ($tipo === 'movimientos') {
            return [
                'fecha' => $fila['fecha'],
                'neumatico' => $fila['neumatico'],
                'tipo' => $fila['tipo'],
                'unidad_origen' => $fila['unidad_origen'],
                'posicion_origen' => $fila['posicion_origen'],
                'unidad_destino' => $fila['unidad_destino'],
                'posicion_destino' => $fila['posicion_destino'],
                'usuario' => trim((string) $fila['usuario']),
                'observacion' => $fila['observacion'],
            ];
        }
        if ($tipo === 'inspecciones') {
            return [
                'fecha' => $fila['fecha'],
                'unidad' => $fila['unidad'],
                'tecnico' => trim((string) $fila['tecnico']),
                'estado' => $fila['estado'],
                'inspeccionados' => (int) $fila['inspeccionados'],
                'criticos' => (int) $fila['criticos'],
                'atencion' => (int) $fila['atencion'],
                'danos' => (int) $fila['danos'],
            ];
        }
        if ($tipo === 'alertas') {
            return [
                'fecha' => $fila['fecha'],
                'tipo' => $fila['tipo'],
                'nivel' => $fila['nivel'],
                'estado' => $fila['estado'],
                'unidad' => $fila['unidad'],
                'neumatico' => $fila['neumatico'],
                'titulo' => $fila['titulo'],
                'origen' => (int) $fila['automatica'] === 1 ? 'Automática' : 'Manual',
            ];
        }
        if ($tipo === 'mantenimientos') {
            return [
                'neumatico' => $fila['neumatico'],
                'tipo' => $fila['tipo'],
                'estado' => $fila['estado'],
                'solicitud' => $fila['fecha_solicitud'],
                'envio' => $fila['fecha_envio'],
                'retorno' => $fila['fecha_retorno'],
                'tercero' => $fila['tercero_nombre'],
                'costo' => $fila['costo'] === null ? null : number_format((float) $fila['costo'], 2, '.', ''),
                'moneda' => $fila['moneda'],
                'profundidad_antes' => $this->decimal($fila['profundidad_antes_mm']),
                'profundidad_despues' => $this->decimal($fila['profundidad_despues_mm']),
            ];
        }
        if ($tipo === 'vidas') {
            return [
                'codigo' => $fila['codigo'],
                'cliente' => $fila['cliente'],
                'vida_actual' => (int) $fila['vida_actual'],
                'numero_vida' => (int) $fila['numero_vida'],
                'reencauches' => max(0, (int) $fila['vida_actual'] - 1),
                'fecha_inicio' => $fila['fecha_inicio'],
                'fecha_fin' => $fila['fecha_fin'],
                'profundidad_inicial' => $this->decimal($fila['profundidad_inicial_mm']),
                'profundidad_final' => $this->decimal($fila['profundidad_final_mm']),
                'km_inicio' => $fila['km_inicio'] === null ? null : (int) $fila['km_inicio'],
                'km_fin' => $fila['km_fin'] === null ? null : (int) $fila['km_fin'],
                'mantenimiento_origen' => $fila['mantenimiento_origen'],
            ];
        }

        return [
            'neumatico' => $fila['neumatico'],
            'fecha' => $fila['fecha'],
            'motivo' => $fila['motivo'],
            'vida_final' => (int) $fila['vida_final'],
            'profundidad_final' => $this->decimal($fila['profundidad_final_mm']),
            'km_totales' => $fila['km_totales'] === null ? null : (int) $fila['km_totales'],
            'observacion' => $fila['observacion'],
        ];
    }

    /** @return list<array{key:string,label:string}> */
    private function columnas(string $tipo): array
    {
        $mapa = [
            'inventario-neumaticos' => ['codigo' => 'Código', 'serie' => 'Serie', 'cliente' => 'Cliente', 'marca' => 'Marca', 'modelo' => 'Modelo', 'medida' => 'Medida', 'estado' => 'Estado', 'vida' => 'Vida', 'unidad' => 'Unidad actual', 'posicion' => 'Posición actual', 'profundidad' => 'Profundidad actual', 'criticidad' => 'Criticidad'],
            'estado-neumaticos' => ['neumatico' => 'Neumático', 'cliente' => 'Cliente', 'estado' => 'Estado', 'vida' => 'Vida', 'profundidad_actual' => 'Profundidad actual', 'profundidad_minima' => 'Profundidad mínima', 'margen' => 'Margen', 'criticidad' => 'Criticidad', 'ultima_inspeccion' => 'Última inspección', 'alertas_activas' => 'Alertas activas', 'unidad' => 'Unidad', 'posicion' => 'Posición'],
            'movimientos' => ['fecha' => 'Fecha', 'neumatico' => 'Neumático', 'tipo' => 'Tipo', 'unidad_origen' => 'Unidad origen', 'posicion_origen' => 'Posición origen', 'unidad_destino' => 'Unidad destino', 'posicion_destino' => 'Posición destino', 'usuario' => 'Usuario', 'observacion' => 'Observación'],
            'inspecciones' => ['fecha' => 'Fecha', 'unidad' => 'Unidad', 'tecnico' => 'Técnico', 'estado' => 'Estado', 'inspeccionados' => 'Inspeccionados', 'criticos' => 'Críticos', 'atencion' => 'Atención', 'danos' => 'Daños'],
            'alertas' => ['fecha' => 'Fecha', 'tipo' => 'Tipo', 'nivel' => 'Nivel', 'estado' => 'Estado', 'unidad' => 'Unidad', 'neumatico' => 'Neumático', 'titulo' => 'Título', 'origen' => 'Origen'],
            'mantenimientos' => ['neumatico' => 'Neumático', 'tipo' => 'Tipo', 'estado' => 'Estado', 'solicitud' => 'Solicitud', 'envio' => 'Envío', 'retorno' => 'Retorno', 'tercero' => 'Tercero', 'costo' => 'Costo', 'moneda' => 'Moneda', 'profundidad_antes' => 'Profundidad antes', 'profundidad_despues' => 'Profundidad después'],
            'vidas' => ['codigo' => 'Código', 'cliente' => 'Cliente', 'vida_actual' => 'Vida actual', 'numero_vida' => 'Número de vida', 'reencauches' => 'Reencauches', 'fecha_inicio' => 'Inicio', 'fecha_fin' => 'Fin', 'profundidad_inicial' => 'Profundidad inicial', 'profundidad_final' => 'Profundidad final', 'km_inicio' => 'Km inicio', 'km_fin' => 'Km fin', 'mantenimiento_origen' => 'Mantenimiento origen'],
            'descartes' => ['neumatico' => 'Neumático', 'fecha' => 'Fecha', 'motivo' => 'Motivo', 'vida_final' => 'Vida final', 'profundidad_final' => 'Profundidad final', 'km_totales' => 'Km totales', 'observacion' => 'Observación'],
        ];
        $columnas = [];
        foreach ($mapa[$tipo] as $key => $label) {
            $columnas[] = ['key' => $key, 'label' => $label];
        }

        return $columnas;
    }

    /** @param list<array<string, mixed>> $filas @return list<list<string>> */
    private function matriz(string $tipo, array $filas): array
    {
        $claves = array_column($this->columnas($tipo), 'key');
        $salida = [];
        foreach ($filas as $fila) {
            $linea = [];
            foreach ($claves as $clave) {
                $valor = $fila[$clave] ?? null;
                $linea[] = $valor === null || $valor === '' ? 'No disponible' : (string) $valor;
            }
            $salida[] = $linea;
        }

        return $salida;
    }

    /** @param array<string, mixed> $filtros @return list<string> */
    private function rotulos(array $filtros): array
    {
        $rotulos = [];
        foreach ([
            'cliente_id' => 'Cliente',
            'sede_id' => 'Sede',
            'flota_id' => 'Flota',
            'estado' => 'Estado',
            'marca_id' => 'Marca',
            'modelo_id' => 'Modelo',
            'medida_id' => 'Medida',
            'unidad_id' => 'Unidad',
            'neumatico_id' => 'Neumático',
            'tipo' => 'Tipo',
            'nivel' => 'Nivel',
            'fecha_inicio' => 'Desde',
            'fecha_fin' => 'Hasta',
        ] as $clave => $etiqueta) {
            if ($filtros[$clave] !== null && $filtros[$clave] !== '') {
                $rotulos[] = $etiqueta . ': ' . $filtros[$clave];
            }
        }
        if ($filtros['ocultar_descartadas'] === true) {
            $rotulos[] = 'Sin alertas descartadas';
        }
        if ($filtros['solo_finalizadas'] === true) {
            $rotulos[] = 'Solo inspecciones finalizadas';
        }

        return $rotulos;
    }

    /** @param array<string, mixed> $filtros */
    private function nombreCliente(array $filtros): ?string
    {
        if ($filtros['cliente_id'] === null) {
            return null;
        }

        return $this->reportes->nombreCliente((int) $filtros['cliente_id']);
    }

    private function nombre(string $tipo, ?string $cliente, string $extension): string
    {
        $fecha = (new DateTimeImmutable('now', new DateTimeZone('America/Lima')))->format('Y-m-d');
        $base = $tipo . ($cliente !== null ? '-cliente-' . $this->slug($cliente) : '') . '-' . $fecha;

        return $base . '.' . $extension;
    }

    private function slug(string $valor): string
    {
        $limpio = strtolower(trim($valor));
        $limpio = preg_replace('/[^a-z0-9]+/', '-', $limpio) ?: 'cliente';

        return trim($limpio, '-');
    }

    /** @return array{profundidad:?string,fecha:?string} */
    private function medicion(?string $crudo): array
    {
        if ($crudo === null || $crudo === '') {
            return ['profundidad' => null, 'fecha' => null];
        }
        $partes = explode('|', $crudo);
        $numeros = [];
        foreach (array_slice($partes, 0, 3) as $parte) {
            if ($parte !== '') {
                $numeros[] = (float) $parte;
            }
        }

        return [
            'profundidad' => $numeros === [] ? null : number_format(min($numeros), 2, '.', ''),
            'fecha' => ($partes[3] ?? '') !== '' ? $partes[3] : null,
        ];
    }

    private function decimal(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return number_format((float) $valor, 2, '.', '');
    }

    private function pagina(Request $request): int
    {
        $page = $this->entero($request->queryParams()['page'] ?? null, 'page') ?? 1;

        return ($page - 1) * $this->limite($request, 20, 100);
    }

    private function limite(Request $request, int $defecto, int $maximo): int
    {
        $limite = $this->entero($request->queryParams()['limit'] ?? null, 'limit') ?? $defecto;

        return min($maximo, max(1, $limite));
    }

    private function entero(mixed $valor, string $campo): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        if (preg_match('/^[1-9][0-9]*$/', (string) $valor) !== 1) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                $campo => ['El identificador no es válido.'],
            ]);
        }

        return (int) $valor;
    }

    private function texto(mixed $valor, string $campo, int $maximo): ?string
    {
        if ($valor === null || trim((string) $valor) === '') {
            return null;
        }
        $texto = trim((string) $valor);
        if (strlen($texto) > $maximo || preg_match('/^[A-Za-z0-9_-]+$/', $texto) !== 1) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                $campo => ['El valor no es válido.'],
            ]);
        }

        return $texto;
    }

    /** @param list<string> $permitidos */
    private function opcion(mixed $valor, string $campo, array $permitidos): ?string
    {
        $texto = $this->texto($valor, $campo, 40);
        if ($texto !== null && !in_array($texto, $permitidos, true)) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                $campo => ['El valor no es válido.'],
            ]);
        }

        return $texto;
    }

    private function fecha(mixed $valor, string $campo): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $valor) !== 1) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                $campo => ['La fecha no es válida.'],
            ]);
        }

        return (string) $valor;
    }

    private function bandera(mixed $valor): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        if (!in_array((string) $valor, ['0', '1'], true)) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'generada_automaticamente' => ['El valor no es válido.'],
            ]);
        }

        return (int) $valor;
    }
}
