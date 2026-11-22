<?php

namespace App\Services\SelfUpdate;

use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

/**
 * Plain-PHP database dump (gzipped SQL), because shared hosts usually block
 * exec()/mysqldump. The file can be imported with phpMyAdmin in cPanel.
 */
class DatabaseBackup
{
    /** @return array{tables: int, rows: int} */
    public function dump(string $file, string $title = 'Database backup made before updating'): array
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $db = $connection->getDatabaseName();
            if ($db === ':memory:' || ! is_file($db)) {
                file_put_contents($file, gzencode('-- in-memory database, nothing to back up'));

                return ['tables' => 0, 'rows' => 0];
            }
            file_put_contents($file, gzencode((string) file_get_contents($db)));

            return ['tables' => 1, 'rows' => 0];
        }

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException("Database backups are not supported for {$driver}.");
        }

        $pdo = $connection->getPdo();
        $out = gzopen($file, 'wb6');
        if (! $out) {
            throw new RuntimeException('Could not write the database backup.');
        }

        $write = fn (string $s) => gzwrite($out, $s);
        $write("-- {$title}, ".now()->toDateTimeString()."\n");
        $write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        $rows = 0;
        $buffered = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);

        try {
            foreach ($tables as [$table]) {
                $q = '`'.str_replace('`', '``', $table).'`';
                $create = $pdo->query("SHOW CREATE TABLE {$q}")->fetch(PDO::FETCH_NUM)[1];
                $write("DROP TABLE IF EXISTS {$q};\n{$create};\n");

                // Stream rows instead of loading whole tables into memory.
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                $stmt = $pdo->query("SELECT * FROM {$q}");
                $batch = [];
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    $batch[] = '('.implode(',', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)).')';
                    $rows++;
                    if (count($batch) === 200) {
                        $write("INSERT INTO {$q} VALUES\n".implode(",\n", $batch).";\n");
                        $batch = [];
                    }
                }
                $stmt->closeCursor();
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
                if ($batch) {
                    $write("INSERT INTO {$q} VALUES\n".implode(",\n", $batch).";\n");
                }
                $write("\n");
            }
            $write("SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
            gzclose($out);
        }

        return ['tables' => count($tables), 'rows' => $rows];
    }
}
