<?php

namespace Tests\Unit;

use App\Database\PgsqlConnection;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

class PgsqlConnectionFailoverTest extends TestCase
{
    public function test_it_uses_read_replica_when_it_is_available(): void
    {
        $writePdo = new PDO('sqlite::memory:');
        $readPdo = new PDO('sqlite::memory:');

        $connection = $this->makeConnection($writePdo);
        $connection->setReadPdo($readPdo);

        $this->assertSame($readPdo, $connection->getReadPdo());
    }

    public function test_it_falls_back_to_master_when_read_replica_is_unavailable(): void
    {
        $writePdo = new PDO('sqlite::memory:');

        $connection = $this->makeConnection($writePdo);
        $connection->setReadPdo(static fn (): PDO => throw new PDOException('replica is down'));

        $this->assertSame($writePdo, $connection->getReadPdo());
        $this->assertSame($writePdo, $connection->getReadPdo());
    }

    public function test_it_reads_from_master_after_write_when_sticky(): void
    {
        $writePdo = new PDO('sqlite::memory:');
        $readPdo = new PDO('sqlite::memory:');

        $connection = $this->makeConnection($writePdo);
        $connection->setReadPdo($readPdo)->recordsHaveBeenModified();

        $this->assertSame($writePdo, $connection->getReadPdo());
    }

    private function makeConnection(PDO $writePdo): PgsqlConnection
    {
        return new PgsqlConnection($writePdo, ':memory:', '', [
            'driver' => 'pgsql',
            'read' => ['host' => ['pgbouncer-read-1']],
            'sticky' => true,
        ]);
    }
}
