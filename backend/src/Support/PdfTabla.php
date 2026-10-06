<?php

declare(strict_types=1);

namespace App\Support;

use Dompdf\Dompdf;
use Dompdf\Options;

final class PdfTabla
{
    /**
     * @param list<string> $encabezados
     * @param list<list<string>> $filas
     * @param list<string> $filtros
     */
    public static function generar(string $titulo, string $fecha, array $filtros, array $encabezados, array $filas, bool $recorte): string
    {
        $opciones = new Options();
        $opciones->set('defaultFont', 'DejaVu Sans');
        $opciones->set('isRemoteEnabled', false);
        $opciones->set('isHtml5ParserEnabled', true);
        $dompdf = new Dompdf($opciones);
        $dompdf->loadHtml(self::html($titulo, $fecha, $filtros, $encabezados, $filas, $recorte));
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $canvas->page_text(680, 560, 'Página {PAGE_NUM} de {PAGE_COUNT}', null, 9, [0.35, 0.35, 0.35]);

        return $dompdf->output();
    }

    /**
     * @param list<string> $encabezados
     * @param list<list<string>> $filas
     * @param list<string> $filtros
     */
    public static function html(string $titulo, string $fecha, array $filtros, array $encabezados, array $filas, bool $recorte): string
    {
        $filtro = $filtros === [] ? 'Sin filtros adicionales' : implode(' · ', array_map(self::texto(...), $filtros));
        $aviso = $recorte ? '<p>Se muestran los primeros 500 registros. Acote los filtros o exporte CSV para el conjunto completo.</p>' : '';
        $cabecera = '';
        foreach ($encabezados as $nombre) {
            $cabecera .= '<th>' . self::texto($nombre) . '</th>';
        }
        $cuerpo = '';
        if ($filas === []) {
            $cuerpo = '<tr><td colspan="' . count($encabezados) . '">Sin resultados para los filtros indicados.</td></tr>';
        }
        foreach ($filas as $fila) {
            $cuerpo .= '<tr>';
            foreach ($fila as $valor) {
                $cuerpo .= '<td>' . self::texto($valor) . '</td>';
            }
            $cuerpo .= '</tr>';
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1c2430; }
            h1 { font-size: 16px; margin: 0 0 4px; }
            p { margin: 0 0 8px; color: #445; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #d5dbe3; padding: 4px; vertical-align: top; }
            th { background: #1e3a5f; color: #fff; text-align: left; }
            </style></head><body><h1>' . self::texto($titulo) . '</h1><p>Generado: ' . self::texto($fecha) . '</p><p>'
            . $filtro . '</p>' . $aviso . '<table><thead><tr>' . $cabecera . '</tr></thead><tbody>' . $cuerpo . '</tbody></table></body></html>';
    }

    public static function texto(string $valor): string
    {
        return htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
