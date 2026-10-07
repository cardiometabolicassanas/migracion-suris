<?php

class UsoImporter
{
    private PDO $db;

    private const MOTIVOS_PERMITIDOS = [
        "SIN USO",
        "UTILIZADOS",
        "DESPERDICIO",
        "APOYO ECMB"
    ];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function importar(array $datos): array
    {
        if (!isset($datos["usos"]) || !is_array($datos["usos"])) {
            throw new InvalidArgumentException("El JSON no contiene un arreglo 'usos'.");
        }

        $insertados = 0;
        $omitidos = 0;

        try {
            $this->db->beginTransaction();

            $this->validarReporte($datos["usos"]);

            $usosNuevos = [];

            foreach ($datos["usos"] as $index => $uso) {
                $insumo = $uso["insumos"][0];

                $existente = $this->buscarUsoExistente($uso, $insumo);

                if ($existente !== null) {
                    if ($this->esMismoUso($existente, $uso, $insumo)) {
                        $omitidos++;
                        continue;
                    }

                    throw new RuntimeException("Conflicto en uso {$index}: {$uso["unidad_baja"]} / {$insumo["clave"]} / {$insumo["lote"]} / {$uso["motivo_baja"]}", 1);
                }

                $usosNuevos[] = $uso;
            }

            $this->validarInventarioDisponible($usosNuevos);

            foreach ($usosNuevos as $uso) {
                $this->registrarUso($uso);

                $insertados++;
            }

            $this->db->commit();

            return [
                "insertados" => $insertados,
                "omitidos" => $omitidos
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    private function validarUso(array $uso, int $index): void
    {
        $campos = [
            "fecha_baja",
            "datetime_inicio",
            "datetime_termino",
            "motivo_baja",
            "unidad_baja",
            "unidad_destino",
            "usuario_registro_baja",
            "fecha_registro_baja",
            "insumos"
        ];

        foreach ($campos as $campo) {
            if (!array_key_exists($campo, $uso)) {
                throw new InvalidArgumentException("Uso {$index}: falta el campo '{$campo}'.");
            }
        }

        if (!in_array($uso["motivo_baja"], self::MOTIVOS_PERMITIDOS, true)) {
            throw new InvalidArgumentException("Uso {$index}: motivo '{$uso["motivo_baja"]}' no válido.");
        }

        if (empty($uso["unidad_baja"])) {
            throw new InvalidArgumentException("Uso {$index}: unidad_baja está vacía.");
        }

        if (!is_array($uso["insumos"]) || count($uso["insumos"]) !== 1) {
            throw new InvalidArgumentException("Uso {$index}: debe contener exactamente un insumo.");
        }

        $this->validarInsumo($uso["insumos"][0], $uso["motivo_baja"], $index);

        $motivo = $uso["motivo_baja"];

        if ($motivo === "APOYO ECMB") {
            if (empty($uso["unidad_destino"])) {
                throw new InvalidArgumentException("Uso {$index}: APOYO ECMB requiere unidad_destino.");
            }

            if ($uso["unidad_destino"] === $uso["unidad_baja"]) {
                throw new InvalidArgumentException("Uso {$index}: origen y destino del apoyo no pueden ser iguales.");
            }
        } else {
            if (!empty($uso["unidad_destino"])) {
                throw new InvalidArgumentException("Uso {$index}: {$motivo} no debe tener unidad_destino.");
            }
        }
    }

    private function validarInsumo(array $insumo, string $motivo, int $index): void
    {
        $campos = [
            "clave",
            "lote",
            "caducidad",
            "cantidad"
        ];

        foreach ($campos as $campo) {
            if (!array_key_exists($campo, $insumo)) {
                throw new InvalidArgumentException("Uso {$index}: falta '{$campo}' en el insumo.");
            }
        }

        $cantidad = (int) $insumo["cantidad"];

        if ($motivo === "SIN USO") {
            if ($cantidad !== 0) {
                throw new InvalidArgumentException("Uso {$index}: SIN USO debe tener cantidad 0.");
            }
        }

        if ($cantidad <= 0) {
            throw new InvalidArgumentException("Uso {$index}: la cantidad debe ser mayor a 0.");
        }
    }

    private function validarReporte(array $usos): void
    {
        $grupos = [];

        foreach ($usos as $index => $uso) {
            $this->validarUso($uso, $index);

            $insumo = $uso["insumos"][0];

            $claveGrupo = implode("|", [
                $insumo["clave"],
                $insumo["lote"],
                $uso["unidad_baja"],
                $uso["datetime_inicio"],
                $uso["datetime_termino"]
            ]);

            $grupos[$claveGrupo][] = [
                "index" => $index,
                "uso" => $uso
            ];
        }

        foreach ($grupos as $grupo) {
            $this->validarGrupo($grupo);
        }
    }

    private function validarGrupo(array $grupo): void
    {
        $motivos = [];
        $destinosApoyo = [];

        foreach ($grupo as $item) {
            $uso = $item["uso"];
            $motivo = $uso["motivo_baja"];

            if ($motivo === "SIN USO") {
                if (count($grupo) !== 1) {
                    throw new InvalidArgumentException("SIN USO no puede combinarse con otros movimientos para la misma unidad, lote y periodo.");
                }

                continue;
            }

            if ($motivo !== "APOYO ECMB") {
                if (in_array($motivo, $motivos, true)) {
                    throw new InvalidArgumentException("El motivo '{$motivo}' está repetido para la misma unidad, lote y periodo.");
                }

                $motivos[] = $motivo;
            }

            if ($motivo === "APOYO ECMB") {
                $destino = $uso["unidad_destino"];

                if (in_array($destino, $destinosApoyo, true)) {
                    throw new InvalidArgumentException("El destino '{$destino}' aparece más de una vez como APOYO ECMB.");
                }

                $destinosApoyo[] = $destino;
            }
        }
    }

    private function buscarUsoExistente(array $uso, array $insumo): ?array
    {
        $unidadDestino = !empty($uso["unidad_destino"]) ? $uso["unidad_destino"] : null;

        $sql = "
            SELECT 
                b.id,
                b.folio_baja,
                b.fecha_baja,
                b.motivo_baja,
                b.unidad_baja,
                b.unidad_destino,
                b.usuario_registro_baja,
                b.fecha_registro_baja,
                b.datetime_inicio,
                b.datetime_termino,
                bmx.clave,
                bmx.lote,
                bmx.caducidad,
                bmx.cantidad
            FROM bajas b
            INNER JOIN bajas_mx bmx
                ON bmx.id_bajas = b.id
            WHERE b.unidad_baja = :unidad_baja
                AND bmx.clave = :clave
                AND bmx.lote = :lote
                AND b.motivo_baja = :motivo_baja
                AND b.datetime_inicio = :datetime_inicio
                AND b.datetime_termino = :datetime_termino
        ";

        $params = [
            "unidad_baja" => $uso["unidad_baja"],
            "clave" => $insumo["clave"],
            "lote" => $insumo["lote"],
            "motivo_baja" => $uso["motivo_baja"],
            "datetime_inicio" => $uso["datetime_inicio"],
            "datetime_termino" => $uso["datetime_termino"],
        ];

        if ($uso["motivo_baja"] === "APOYO ECMB") {
            $sql .= " AND b.unidad_destino = :unidad_destino";

            $params["unidad_destino"] = $uso["unidad_destino"];
        } else {
            $sql .= " AND b.unidad_Destino IS NULL";
        }

        $sql .= " LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $registro = $stmt->fetch();

        return $registro ?: null;
    }

    private function esMismoUso(array $existente, array $uso, array $insumo): bool
    {
        $destinoExistente = $existente["unidad_destino"] ?: null;
        $destinoJson = !empty($uso["unidad_destino"]) ? $uso["unidad_destino"] : null;

        return $existente['fecha_baja'] === $uso['fecha_baja'] &&
            $existente['motivo_baja'] === $uso['motivo_baja'] &&
            $existente['unidad_baja'] === $uso['unidad_baja'] &&
            $destinoExistente === $destinoJson &&
            (int) $existente['usuario_registro_baja'] ===
            (int) $uso['usuario_registro_baja'] &&
            $existente['fecha_registro_baja'] ===
            $uso['fecha_registro_baja'] &&
            $existente['datetime_inicio'] ===
            $uso['datetime_inicio'] &&
            $existente['datetime_termino'] ===
            $uso['datetime_termino'] &&
            $existente['clave'] === $insumo['clave'] &&
            $existente['lote'] === $insumo['lote'] &&
            $existente['caducidad'] === $insumo['caducidad'] &&
            (int) $existente['cantidad'] ===
            (int) $insumo['cantidad'];
    }

    private function generarFolio(array $uso): string
    {
        $fecha = new DateTime($uso["fecha_registro_baja"]);

        $base = sprintf("%s-%s", $uso["unidad_baja"], $fecha->format("ymdHis"));

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

    private function crearBaja(array $uso, string $folio): int
    {
        $unidadDestino = !empty($uso["unidad_destino"]) ? $uso["unidad_destino"] : null;

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
                :datetime_inicio,
                :datetime_termino,
                NULL,
                NULL,
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
            "fecha_baja" => $uso["fecha_baja"],
            "motivo_baja" => $uso["motivo_baja"],
            "unidad_baja" => $uso["unidad_baja"],
            "unidad_destino" => $unidadDestino,
            "datetime_inicio" => $uso["datetime_inicio"],
            "datetime_termino" => $uso["datetime_termino"],
            "usuario_registro_baja" => $uso["usuario_registro_baja"],
            "fecha_registro_baja" => $uso["fecha_registro_baja"],
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
                AND cantidad >= :cantidad_minima
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            "cantidad" => $cantidad,
            "cantidad_minima" => $cantidad,
            "id" => $idInventario,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException("No fue posible descontar el inventario.");
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

    private function crearInventarioDestino(array $uso, array $insumo): void
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
            "unidad" => $uso["unidad_destino"],
            "cantidad" => $insumo["cantidad"],
            "lote" => $insumo["lote"],
            "caducidad" => $insumo["caducidad"],
            "fecha_captura" => $uso["fecha_registro_baja"],
            "usuario_captura" => $uso["usuario_registro_baja"],
            "observaciones" => ""
        ]);
    }

    private function registrarUso(array $uso): void
    {
        $insumo = $uso["insumos"][0];

        $motivo = $uso["motivo_baja"];
        $cantidad = (int) $insumo["cantidad"];

        if ($motivo === "SIN USO") {
            $folio = $this->generarFolio($uso);

            $idBaja = $this->crearBaja($uso, $folio);

            $this->crearDetalleBaja($idBaja, $insumo);

            return;
        }

        $inventarioOrigen = $this->obtenerInventarioOrigen($insumo, $uso["unidad_baja"]);

        $existencia = (int) $inventarioOrigen["cantidad"];

        if ($cantidad > $existencia) {
            throw new RuntimeException(
                "Existencia insuficiente para {$insumo["clave"]} / lote {$insumo["lote"]}. " .
                    "Disponible: {$existencia}. " .
                    "Solicitado: {$cantidad}"
            );
        }

        $folio = $this->generarFolio($uso);

        $idBaja = $this->crearBaja($uso, $folio);

        $this->crearDetalleBaja($idBaja, $insumo);

        $this->descontarInventario((int) $inventarioOrigen["id"], $cantidad);

        if ($motivo !== "APOYO ECMB") {
            return;
        }

        $inventarioDestino = $this->obtenerInventarioDestino($insumo, $uso["unidad_destino"]);

        if ($inventarioDestino !== null) {
            $this->aumentarInventario((int) $inventarioDestino["id"], $cantidad);
        } else {
            $this->crearInventarioDestino($uso, $insumo);
        }
    }

    private function calcularCantidadesRequeridas(array $usos): array
    {
        $requeridas = [];

        foreach ($usos as $uso) {
            if ($uso["motivo_baja"] === "SIN USO") {
                continue;
            }

            $insumo = $uso["insumos"][0];

            $claveInventario = implode("|", [
                $uso["unidad_baja"],
                $insumo["clave"],
                $insumo["lote"],
                $insumo["caducidad"]
            ]);

            if (!isset($requeridas[$claveInventario])) {
                $requeridas[$claveInventario] = [
                    "unidad" => $uso["unidad_baja"],
                    "clave" => $insumo["clave"],
                    "lote" => $insumo["lote"],
                    "caducidad" => $insumo["caducidad"],
                    "cantidad" => 0
                ];
            }

            $requeridas[$claveInventario]["cantidad"] += (int) $insumo["cantidad"];
        }

        return $requeridas;
    }

    private function validarInventarioDisponible(array $usos): void
    {
        $requeridas = $this->calcularCantidadesRequeridas($usos);

        $sql = "
            SELECT  
                id,
                cantidad
            FROM farmacia_inventario
            WHERE unidad = :unidad
                AND clave = :clave
                AND lote = :lote
                AND caducidad = :caducidad
                AND status = 1
            FOR UPDATE
        ";

        $stmt = $this->db->prepare($sql);

        foreach ($requeridas as $requerida) {
            $stmt->execute([
                "unidad" => $requerida["unidad"],
                "clave" => $requerida["clave"],
                "lote" => $requerida["lote"],
                "caducidad" => $requerida["caducidad"]
            ]);

            $inventario = $stmt->fetch();

            if (!$inventario) {
                throw new RuntimeException("No se encontró inventario para {$requerida["clave"]} / lote {$requerida["lote"]} en {$requerida["unidad"]}.", 1);
            }

            $disponible = (int) $inventario["cantidad"];
            $requerido = (int) $requerida["cantidad"];

            if ($requerido > $disponible) {
                throw new RuntimeException("Inventario insuficiente para {$requerida["clave"]} / lote {$requerida["lote"]} en {$requerida["unidad"]}. " .
                    "Disponible: {$disponible}. " .
                    "Total reportado: {$requerido}.");
            }
        }
    }
}
