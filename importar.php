<?php

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/JsonReader.php';
require_once __DIR__ . '/src/Importer/InventarioJurisdiccionImporter.php';

$config = require __DIR__ . '/config/database.php';

try {
    $database = new Database($config);
    $db = $database->getConnection();

    $datos = JsonReader::read(__DIR__ . "/data/inventario_jurisdiccion/antigeno_prostatico.json");

    $importer = new InventarioJurisdiccionImporter($db);

    $cantidades = $importer->importar($datos);

    echo "Importación completada correctamente." . PHP_EOL;
    echo "Insertados: {$cantidades["insertados"]}"  . PHP_EOL;
    echo "Omitidos: {$cantidades["omitidos"]}" . PHP_EOL;
} catch (Throwable $e) {
    echo "ERROR: {$e->getMessage()}\n";
}
