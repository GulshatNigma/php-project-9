<?php

namespace PageAnalyser;

class Connection
{
    /**
     * @return \PDO
     * @throws \Exception
     */
    public static function connect()
    {
        if (getenv('DATABASE_URL')) {
            $databaseUrl = parse_url(getenv('DATABASE_URL'));
        }

        if (isset($databaseUrl['host'])) {
            $params['user'] = $databaseUrl['user'] ?? null;
            $params['password'] = $databaseUrl['pass'] ?? null;
            $params['host'] = $databaseUrl['host'];
            $params['port'] = $databaseUrl['port'] ?? null;
            $params['database'] = isset($databaseUrl['path']) ? ltrim($databaseUrl['path'], '/') : null;
        } else {
            $params = parse_ini_file('database.ini');
        }

        if ($params === false) {
            throw new \Exception("Error reading database configuration file");
        }

        $conStr = sprintf(
            "pgsql:host=%s;port=%d;dbname=%s;user=%s;password=%s",
            $params['host'],
            $params['port'],
            $params['database'],
            $params['user'],
            $params['password']
        );

        $pdo = new \PDO($conStr);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }
}
