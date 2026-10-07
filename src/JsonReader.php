<?php

class JsonReader
{
    public static function read(string $ruta): array
    {
        if (!file_exists($ruta)) {
            throw new RuntimeException("No se encontró el archivo JSON: {$ruta}");
        }

        if (!is_readable($ruta)) {
            throw new RuntimeException("No se puede leer el archivo JSON: {$ruta}");
        }

        $contenido = file_get_contents($ruta);

        if ($contenido === false) {
            throw new RuntimeException("No fue posible leer el archivo JSON: {$ruta}");
        }

        try {
            return json_decode($contenido, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("El archivo contiene un JSON inválido: {$e->getMessage()}");
        }
    }
}
