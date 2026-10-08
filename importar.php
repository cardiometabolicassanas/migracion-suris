<?php

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/JsonReader.php';
require_once __DIR__ . '/src/Importer/InventarioJurisdiccionImporter.php';
require_once __DIR__ . '/src/Importer/DistribucionImporter.php';
require_once __DIR__ . '/src/Importer/UsoImporter.php';

$config = require __DIR__ . '/config/database.php';

try {
    $database = new Database($config);
    $db = $database->getConnection();

    $datos = JsonReader::read(__DIR__ . "/data/inventario_jurisdiccion/glucosa.json");
    $importer = new InventarioJurisdiccionImporter($db);
    $cantidades = $importer->importar($datos);

    // $datos = JsonReader::read(__DIR__ . "/data/distribuciones_jurisdiccion_municipios/celaya/distribucion_glucosa.json");
    // $importer = new DistribucionImporter($db);
    // $cantidades = $importer->importar($datos);

    // $datos = JsonReader::read(__DIR__ . "/data/distribuciones_municipios_unidades/celaya/distribucion_glucosa.json");
    // $importer = new DistribucionImporter($db);
    // $cantidades = $importer->importar($datos);

    // $datos = JsonReader::read(__DIR__ . "/data/usos/celaya/agosto/usos_glucosa.json");
    // $importer = new UsoImporter($db);
    // $cantidades = $importer->importar($datos);

    echo "Importación completada correctamente." . PHP_EOL;
    echo "Insertados: {$cantidades["insertados"]}"  . PHP_EOL;
    echo "Omitidos: {$cantidades["omitidos"]}" . PHP_EOL;
} catch (Throwable $e) {
    echo "ERROR: {$e->getMessage()}\n";
}
