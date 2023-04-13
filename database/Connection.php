<?php

namespace PageAnalyser;

/**
 * Создание класса Connection
 */
final class Connection
{
    /**
     * Connection
     * тип @var
     */
    private static ?Connection $conn = null;

    /**
     * Подключение к базе данных и возврат экземпляра объекта \PDO
     * @return \PDO
     * @throws \Exception
     */
    public function connect()
    {
        if (getenv('DATABASE_URL')) {
            $databaseUrl = parse_url(getenv('DATABASE_URL'));
        }

        $params['user'] = $databaseUrl['user'] ?? null;
        $params['password'] = $databaseUrl['pass'] ?? null;
        $params['host'] = $databaseUrl['host'] ?? null;
        $params['port'] = $databaseUrl['port'] ?? null;
        $params['database'] = isset($databaseUrl['path']) ? ltrim($databaseUrl['path'], '/') : null;

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

    /**
     * тип @return
     */
    public static function get()
    {
        if (null === static::$conn) {
            static::$conn = new self();
        }

        return static::$conn;
    }

    protected function __construct()
    {
    }
}
