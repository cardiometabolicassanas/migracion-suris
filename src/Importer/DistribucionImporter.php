<?php

class DistribucionImporter
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function importar(array $datos): array
    {
        if (!isset($datos["distribuciones"]) || !is_array($datos["distribuciones"])) {
            throw new InvalidArgumentException("El JSON no contiene un arreglo 'distribuciones'.");
        }

        $insertadas = 0;
        $omitidas = 0;

        try {
            $this->db->beginTransaction();

            foreach ($datos["distribuciones"] as $index => $distribucion) {
                $this->validarDistribucion($distribucion, $index);

                $insumo = $distribucion["insumos"][0];

                $existente = $this->buscarDistribucionExistente($distribucion, $insumo);

                if ($existente !== null) {
                    if ($this->esMismaDistribucion($existente, $distribucion, $insumo)) {
                        $omitidas++;
                        continue;
                    }

                    throw new RuntimeException("Conflicto en la distribución {$index}: {$distribucion["documento_declarante"]} / {$insumo["clave"]} / {$insumo["lote"]}");
                }

                $this->registrarDistribucion($distribucion);

                $insertadas++;
            }

            $this->db->commit();

            return [
                "insertados" => $insertadas,
                "omitidos" => $omitidas
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    private function buscarDistribucionExistente(array $distribucion, array $insumo): ?array
    {
        $sql = "
        SELECT
            b.id,
            b.folio_baja,
            b.fecha_baja,
            b.motivo_baja,
            b.unidad_baja,
            b.unidad_destino,
            b.documento_declarante,
            b.usuario_registro_baja,
            b.fecha_registro_baja,
            bmx.clave,
            bmx.lote,
            bmx.caducidad,
            bmx.cantidad
        FROM bajas b
        INNER JOIN bajas_mx bmx
            ON bmx.id_bajas = b.id
        WHERE b.unidad_baja = :unidad_baja
            AND b.unidad_destino = :unidad_destino
            AND b.documento_declarante = :documento_declarante
            AND bmx.clave = :clave
            AND bmx.lote = :lote
        LIMIT 1
    ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            "unidad_baja" => $distribucion["unidad_baja"],
            "unidad_destino" => $distribucion["unidad_destino"],
            "documento_declarante" => $distribucion["documento_declarante"],
            "clave" => $insumo["clave"],
            "lote" => $insumo["lote"]
        ]);

        $registro = $stmt->fetch();

        return $registro ?: null;
    }

    private function esMismaDistribucion(array $existente, array $distribucion, array $insumo): bool
    {
        return $existente["fecha_baja"] === $distribucion["fecha_baja"] &&
            $existente["motivo_baja"] === $distribucion["motivo_baja"] &&
            $existente["unidad_baja"] === $distribucion["unidad_baja"] &&
            $existente["unidad_destino"] === $distribucion["unidad_destino"] &&
            $existente["documento_declarante"] === $distribucion["documento_declarante"] &&
            (int) $existente["usuario_registro_baja"] === (int) $distribucion["usuario_registro_baja"] &&
            $existente["fecha_registro_baja"] === $distribucion["fecha_registro_baja"] &&
            $existente["clave"] === $insumo["clave"] &&
            $existente["lote"] === $insumo["lote"] &&
            $existente["caducidad"] === $insumo["caducidad"] &&
            (int) $existente["cantidad"] === (int) $insumo["cantidad"];
    }

    private function generarFolio(array $distribucion): string
    {
        $fecha = new DateTime($distribucion["fecha_registro_baja"]);

        $base = sprintf("%s-%s", $distribucion["unidad_baja"], $fecha->format("ymdHis"));

        $sql = "
            SELECT folio_baja
            FROM bajas
            WHERE folio_baja LIKE :folio
            ORDER BY folio_baja DESC
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            "folio" => $base . "-%"
        ]);

        $ultimo = $stmt->fetchColumn();

        $consecutivo = 1;

        if ($ultimo) {
            $partes = explode("-", $ultimo);
            $consecutivo = ((int) end($partes)) + 1;
        }

        return sprintf("%s-%02d", $base, $consecutivo);
    }

    private function validarDistribucion(array $distribucion, int $index): void
    {
        $campos = [
            "fecha_baja",
            "motivo_baja",
            "unidad_baja",
            "unidad_destino",
            "documento_declarante",
            "usuario_registro_baja",
            "fecha_registro_baja",
            "insumos",
        ];

        foreach ($campos as $campo) {
            if (!array_key_exists($campo, $distribucion)) {
                throw new InvalidArgumentException("Distribución {$index}: falta '{$campo}'.");
            }
        }

        if ($distribucion["motivo_baja"] !== "DISTRIBUCIÓN") {
            throw new InvalidArgumentException("Distribución {$index}: motivo_baja no válido.");
        }

        if (empty($distribucion["unidad_baja"])) {
            throw new InvalidArgumentException("Distribución {$index}: unidad_baja está vacía.");
        }

        if (empty($distribucion["unidad_destino"])) {
            throw new InvalidArgumentException("Distribución {$index}: unidad_destino está vacía.");
        }

        if ($distribucion["unidad_baja"] === $distribucion["unidad_destino"]) {
            throw new InvalidArgumentException("Distribución {$index}: origen y destino no pueden ser iguales.");
        }

        // if (empty($distribucion["documento_declarante"])) {
        //     throw new InvalidArgumentException("Distribución {$index}: falta la nota de salida.");
        // }

        if (!is_array($distribucion["insumos"])) {
            throw new InvalidArgumentException("Distribución {$index}: 'insumos' debe ser un arreglo.");
        }

        if (count($distribucion["insumos"]) !== 1) {
            throw new InvalidArgumentException("Distribución {$index}: debe contener exactamente un insumo.", 1);
        }

        $this->validarInsumo($distribucion["insumos"][0], $index, 0);
    }

    private function validarInsumo(array $insumo, int $distribucionIndex, int $insumoIndex): void
    {
        $campos = [
            "clave",
            "lote",
            "caducidad",
            "cantidad"
        ];

        foreach ($campos as $campo) {
            if (!array_key_exists($campo, $insumo)) {
                throw new InvalidArgumentException("Distribución {$distribucionIndex}, insumo {$insumoIndex}: falta '{$campo}'");
            }
        }

        if ((int) $insumo["cantidad"] <= 0) {
            throw new InvalidArgumentException("Distribución {$distribucionIndex}, insumo {$insumoIndex}: cantidad inválida.");
        }
    }

    private function obtenerInventarioOrigen(array $insumo, string $unidad): array
    {
        $sql = "
            SELECT
                id,
                cantidad
            FROM farmacia_inventario
            WHERE clave = :clave
                AND unidad = :unidad
                AND lote = :lote
                AND caducidad = :caducidad
                AND status = 1
            FOR UPDATE
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            "clave" => $insumo["clave"],
            "unidad" => $unidad,
            "lote" => $insumo["lote"],
            "caducidad" => $insumo["caducidad"],
        ]);

        $inventario = $stmt->fetch();

        if (!$inventario) {
            throw new RuntimeException("No se encontró inventario para {$insumo["clave"]} / lote {$insumo["lote"]} en {$unidad}.");
        }

        return $inventario;
    }

    private function crearBaja(array $distribucion, string $folio): int
    {
        $sql = "
            INSERT INTO bajas (
                folio_baja,
                fecha_baja,
                motivo_baja,
                unidad_baja,
                unidad_destino,
                otra_dependencia,
                responsable,
                datetime_inicio,
                datetime_termino,
                declarante,
                documento_declarante,
                folio_documento,
                testigo1,
                testigo2,
                testigo3,
                usuario_registro_baja,
                fecha_registro_baja
            )
            VALUES (
                :folio_baja,
                :fecha_baja,
                :motivo_baja,
                :unidad_baja,
                :unidad_destino,
                NULL,
                NULL,
                NULL,
                NULL,
                NULL,
                :documento_declarante,
                NULL,
                NULL,
                NULL,
                NULL,
                :usuario_registro_baja,
                :fecha_registro_baja
            )
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            "folio_baja" => $folio,
            "fecha_baja" => $distribucion["fecha_baja"],
            "motivo_baja" => $distribucion["motivo_baja"],
            "unidad_baja" => $distribucion["unidad_baja"],
            "unidad_destino" => $distribucion["unidad_destino"],
            "documento_declarante" => $distribucion["documento_declarante"],
            "usuario_registro_baja" => $distribucion["usuario_registro_baja"],
            "fecha_registro_baja" => $distribucion["fecha_registro_baja"],
        ]);

        return (int) $this->db->lastInsertId();
    }

    private function crearDetalleBaja(int $idBaja, array $insumo): void
    {
        $sql = "
            INSERT INTO bajas_mx (
                id_bajas, 
                clave, 
                lote, 
                caducidad, 
                cantidad
            )
            VALUES (
                :id_bajas,
                :clave,
                :lote,
                :caducidad,
                :cantidad
            )
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            "id_bajas" => $idBaja,
            "clave" => $insumo["clave"],
            "lote" => $insumo["lote"],
            "caducidad" => $insumo["caducidad"],
            "cantidad" => $insumo["cantidad"]
        ]);
    }

    private function descontarInventario(int $idInventario, int $cantidad): void
    {
        $sql = "
            UPDATE farmacia_inventario
            SET cantidad = cantidad - :cantidad
            WHERE id = :id
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            "cantidad" => $cantidad,
            "id" => $idInventario,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException("No fue posible actualizar el inventario de origen.");
        }
    }

    private function obtenerInventarioDestino(array $insumo, string $unidad): ?array
    {
        $sql = "
            SELECT
                id,
                cantidad
            FROM farmacia_inventario
            WHERE clave = :clave
                AND unidad = :unidad
                AND lote = :lote
                AND status = 1
            FOR UPDATE
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            "clave" => $insumo["clave"],
            "unidad" => $unidad,
            "lote" => $insumo["lote"]
        ]);

        $inventario = $stmt->fetch();

        return $inventario ?: null;
    }

    private function aumentarInventario(int $idInventario, int $cantidad): void
    {
        $sql = "
            UPDATE farmacia_inventario
            SET cantidad = cantidad + :cantidad
            WHERE id = :id
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            "cantidad" => $cantidad,
            "id" => $idInventario
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException("No fue posible actualizar el inventario destino.");
        }
    }

    private function crearInventarioDestino(array $distribucion, array $insumo): void
    {
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

        $stmt->execute([
            "clave" => $insumo["clave"],
            "unidad" => $distribucion["unidad_destino"],
            "cantidad" => $insumo["cantidad"],
            "lote" => $insumo["lote"],
            "caducidad" => $insumo["caducidad"],
            "fecha_captura" => $distribucion["fecha_registro_baja"],
            "usuario_captura" => $distribucion["usuario_registro_baja"],
            "observaciones" => ""
        ]);
    }

    private function registrarDistribucion(array $distribucion): void
    {
        $insumo = $distribucion["insumos"][0];

        $inventarioOrigen = $this->obtenerInventarioOrigen($insumo, $distribucion["unidad_baja"]);

        $existencia = (int) $inventarioOrigen["cantidad"];
        $cantidad = (int) $insumo["cantidad"];


        if ($cantidad > $existencia) {
            throw new RuntimeException("Existencia insuficiente para {$insumo["clave"]} / lote {$insumo["lote"]}. \nDisponible: {$existencia} \nSolicitado: {$cantidad}");
        }

        $folio = $this->generarFolio($distribucion);

        $idBaja = $this->crearBaja($distribucion, $folio);

        $this->crearDetalleBaja($idBaja, $insumo);

        $this->descontarInventario((int) $inventarioOrigen["id"], $cantidad);

        $inventarioDestino = $this->obtenerInventarioDestino($insumo, $distribucion["unidad_destino"]);

        if ($inventarioDestino !== null) {
            $this->aumentarInventario((int) $inventarioDestino["id"], $cantidad);
        } else {
            $this->crearInventarioDestino($distribucion, $insumo);
        }
    }
}
