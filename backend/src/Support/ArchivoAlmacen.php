<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ValidationException;
use RuntimeException;

/**
 * Guarda fotografías fuera del directorio público.
 * La baja lógica de metadata no borra el archivo físico: la ruta sigue
 * siendo evidencia de la inspección. Si la operación falla después de
 * escribir el archivo, el servicio intenta eliminar ese archivo nuevo.
 * Un rollback externo posterior puede dejar el archivo físico huérfano.
 */
final class ArchivoAlmacen
{
    public function __construct(private readonly string $raiz)
    {
    }

    /** @return array{ruta:string,nombre:string,tamano:int,checksum:string} */
    public function guardar(int $clienteId, string $entidad, int $entidadId, string $contenido, string $extension): array
    {
        $nombre = bin2hex(random_bytes(16)) . '.' . $extension;
        $relativa = 'archivos/' . $clienteId . '/' . $entidad . '/' . $entidadId . '/' . $nombre;
        $directorio = $this->raiz . DIRECTORY_SEPARATOR . 'archivos'
            . DIRECTORY_SEPARATOR . $clienteId
            . DIRECTORY_SEPARATOR . $entidad
            . DIRECTORY_SEPARATOR . $entidadId;
        if (!is_dir($directorio) && !mkdir($directorio, 0775, true) && !is_dir($directorio)) {
            throw new RuntimeException('No se pudo preparar el almacenamiento de archivos.');
        }
        $destino = $directorio . DIRECTORY_SEPARATOR . $nombre;
        if (file_put_contents($destino, $contenido) === false) {
            throw new RuntimeException('No se pudo guardar el archivo.');
        }

        return [
            'ruta' => $relativa,
            'nombre' => $nombre,
            'tamano' => strlen($contenido),
            'checksum' => hash('sha256', $contenido),
        ];
    }

    public function leer(string $ruta): string
    {
        $absoluta = $this->resolver($ruta);
        $contenido = file_get_contents($absoluta);
        if ($contenido === false) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'archivo' => ['El archivo no está disponible.'],
            ]);
        }

        return $contenido;
    }

    public function existe(string $ruta): bool
    {
        try {
            return is_file($this->resolver($ruta));
        } catch (ValidationException) {
            return false;
        }
    }

    private function resolver(string $ruta): string
    {
        $foto = '#^archivos/[1-9][0-9]*/(?:INSPECCION|INSPECCION_DETALLE)/[1-9][0-9]*/[a-f0-9]{32}\.(?:jpg|png|webp)$#';
        $mantenimiento = '#^archivos/[1-9][0-9]*/MANTENIMIENTO/[1-9][0-9]*/[a-f0-9]{32}\.(?:jpg|png|webp|pdf)$#';
        if (preg_match($foto, $ruta) !== 1 && preg_match($mantenimiento, $ruta) !== 1) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'archivo' => ['La ruta del archivo no es válida.'],
            ]);
        }
        $absoluta = $this->raiz . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $ruta);
        $real = realpath($absoluta);
        $raiz = realpath($this->raiz);
        if ($real === false || $raiz === false || !str_starts_with($real, $raiz . DIRECTORY_SEPARATOR)) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'archivo' => ['La ruta del archivo no es válida.'],
            ]);
        }

        return $real;
    }
}
