<?php

declare(strict_types=1);

namespace App\Support;

final class CsvTabla
{
    /**
     * CSV UTF-8 con BOM y separador punto y coma, para abrirlo en Excel.
     * Las celdas que empiezan por =, +, - o @ se prefijan con apóstrofo.
     *
     * @param list<string> $encabezados
     * @param list<list<string|null>> $filas
     */
    public static function generar(array $encabezados, array $filas): string
    {
        $salida = fopen('php://temp', 'r+');
        if ($salida === false) {
            return '';
        }
        fwrite($salida, "\xEF\xBB\xBF");
        fputcsv($salida, $encabezados, ';', '"', '\\');
        foreach ($filas as $fila) {
            fputcsv($salida, array_map(self::celda(...), $fila), ';', '"', '\\');
        }
        rewind($salida);
        $contenido = stream_get_contents($salida);
        fclose($salida);

        return $contenido === false ? '' : $contenido;
    }

    public static function celda(mixed $valor): string
    {
        if ($valor === null) {
            return '';
        }
        $texto = (string) $valor;
        if (preg_match('/^[=+\-@\t\r]/', $texto) === 1) {
            return "'" . $texto;
        }

        return $texto;
    }
}
