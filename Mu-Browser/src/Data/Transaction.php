<?php

declare(strict_types=1);

namespace Mu\Data;

use PDO;
use Throwable;

/**
 * Ejecuta varios cambios como una sola operación. Al indicar la cuenta, además la bloquea hasta terminar:
 * dos pedidos simultáneos del mismo jugador (doble clic, dos pestañas) se procesan uno después del otro
 * y no pueden, por ejemplo, gastar dos veces el mismo zen ni poner dos ítems en el mismo lugar.
 */
final class Transaction
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function run(int $accountId, callable $work): mixed
    {
        return $this->runMany([$accountId], $work);
    }

    /**
     * Igual que run(), bloqueando varias cuentas (comprador y vendedor). Siempre en el mismo orden,
     * para que dos compras cruzadas no se queden esperándose una a la otra.
     *
     * @template T
     *
     * @param list<int>     $accountIds
     * @param callable(): T $work
     *
     * @return T
     */
    public function runMany(array $accountIds, callable $work): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $work();
        }

        $this->pdo->beginTransaction();
        try {
            // SQLite (tests) no tiene bloqueo por fila: ahí alcanza con la transacción.
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $ids = array_values(array_unique($accountIds));
                sort($ids);
                $lock = $this->pdo->prepare('SELECT id FROM accounts WHERE id = ? FOR UPDATE');
                foreach ($ids as $id) {
                    $lock->execute([$id]);
                    $lock->closeCursor();
                }
            }
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
