<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

final class Transaction
{
    private int $nivel = 0;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function run(callable $callback): mixed
    {
        $this->nivel++;
        $punto = 'app_tx_' . $this->nivel;
        $raiz = $this->nivel === 1 && !$this->pdo->inTransaction();
        if ($raiz) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT ' . $punto);
        }

        try {
            $result = $callback();
            if ($this->pdo->inTransaction()) {
                if ($raiz) {
                    $this->pdo->commit();
                } else {
                    $this->pdo->exec('RELEASE SAVEPOINT ' . $punto);
                }
            }
            $this->nivel--;

            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                if ($raiz) {
                    $this->pdo->rollBack();
                } else {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $punto);
                }
            }
            $this->nivel--;
            throw $error;
        }
    }
}
