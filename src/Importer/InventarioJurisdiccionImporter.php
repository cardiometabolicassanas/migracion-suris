<?php

class InventarioJurisdiccionImporter
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function importar(array $datos): array
    {
        if (!isset($datos["inventario"]) || !is_array($datos["inventario"])) {
            throw new InvalidArgumentException("El JSON no tiene un arreglo 'inventario'.");
        }

        $sql = "
            INSERT INTO farmacia_inventario (
                clave,
                unidad,
                cantidad,
                lote,
                caducidad,
                fecha_captura,
                usuario_captura,
                observaciones
            )
            VALUES (
                :clave,
                :unidad,
                :cantidad,
                :lote,
                :caducidad,
                :fecha_captura,
                :usuario_captura,
                :observaciones
            )
        ";

        $stmt = $this->db->prepare($sql);

        $registrosInsertados = 0;
        $registrosOmitidos = 0;

        try {
            $this->db->beginTransaction();

            foreach ($datos["inventario"] as $index => $item) {
                $this->validarRegistro($item, $index);

                $existente = $this->buscarRegistroExistente($item);

                if ($existente !== null) {
                    if ($this->esMismoRegistro($existente, $item)) {
                        $registrosOmitidos++;
                        continue;
                    }

                    throw new RuntimeException("Conflicto en {$item["clave"]} / lote {$item["lote"]}: " .
                        "ya existe una entrada con la misma nota '{$item["observaciones"]}', " .
                        "pero los datos no coinciden.");
                }


                $stmt->execute([
                    "clave" => $item["clave"],
                    "unidad" => $item["unidad"],
                    "cantidad" => $item["cantidad"],
                    "lote" => $item["lote"],
                    "caducidad" => $item["caducidad"],
                    "fecha_captura" => $item["fecha_captura"],
                    "usuario_captura" => $item["usuario_captura"],
                    "observaciones" => $item["observaciones"] ?? "",
                ]);

                $registrosInsertados++;
            }

            $this->db->commit();

            return [
                "insertados" => $registrosInsertados,
                "omitidos" => $registrosOmitidos,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    private function validarRegistro(array $item, int $index): void
    {
        $camposRequeridos = [
            "clave",
            "unidad",
            "cantidad",
            "lote",
            "caducidad",
            "fecha_captura",
            "usuario_captura"
        ];

        foreach ($camposRequeridos as $campo) {
            if (!array_key_exists($campo, $item)) {
                throw new InvalidArgumentException("Registro {$index}: Falta el campo '{$campo}'.");
            }
        }

        if ((int) $item["cantidad"] <= 0) {
            throw new InvalidArgumentException("Registro {$index}: La cantidad debe ser mayor a 0.");
        }
    }

    private function buscarRegistroExistente(array $item): ?array
    {
        $sql = "
            SELECT
                id,
                clave,
                unidad,
                cantidad,
                lote,
                caducidad,
                fecha_captura,
                usuario_captura,
                observaciones
            FROM farmacia_inventario
            WHERE clave = :clave
                AND unidad = :unidad
                AND lote = :lote
                AND caducidad = :caducidad
                AND observaciones = :observaciones
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            "clave" => $item["clave"],
            "unidad" => $item["unidad"],
            "lote" => $item["lote"],
            "caducidad" => $item["caducidad"],
            "observaciones" => $item["observaciones"] ?? "",
        ]);

        $registro = $stmt->fetch();

        return $registro ?: null;
    }

    private function esMismoRegistro(array $existente, array $item): bool
    {
        return
            $existente['clave'] === $item['clave'] &&
            $existente['unidad'] === $item['unidad'] &&
            (int) $existente['cantidad'] === (int) $item['cantidad'] &&
            $existente['lote'] === $item['lote'] &&
            $existente['caducidad'] === $item['caducidad'] &&
            $existente['fecha_captura'] === $item['fecha_captura'] &&
            (int) $existente['usuario_captura'] === (int) $item['usuario_captura'] &&
            ($existente['observaciones'] ?? '') === ($item['observaciones'] ?? '');
    }
}
