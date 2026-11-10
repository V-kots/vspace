<?php

namespace App\Database;

use Illuminate\Database\PostgresConnection;
use PDOException;

class PgsqlConnection extends PostgresConnection
{
    /**
     * Whether the connection to a read replica already failed.
     */
    protected bool $readReplicaUnavailable = false;

    /**
     * Получить PDO для чтения.
     *
     * Если реплика недоступна (контейнер остановлен, сеть, таймаут), читаем с
     * мастера: приложение продолжает работать, а не отдаёт 500 на каждый SELECT.
     * Флаг запоминается на время жизни соединения, чтобы не ждать таймаут на
     * каждом запросе.
     */
    public function getReadPdo()
    {
        if ($this->readReplicaUnavailable) {
            return $this->getPdo();
        }

        try {
            return parent::getReadPdo();
        } catch (PDOException) {
            $this->readReplicaUnavailable = true;

            return $this->getPdo();
        }
    }

    /**
     * Сбросить состояние при переподключении.
     */
    public function disconnect()
    {
        $this->readReplicaUnavailable = false;

        parent::disconnect();
    }
}
