<?php

namespace Page\Analyser;

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
        if (array_key_exists('DATABASE_URL', $_ENV)) {
            $databaseUrl = parse_url($_ENV['DATABASE_URL']);
        }

        if (isset($databaseUrl['port'])) {
            $params['user'] = $databaseUrl['user'];
            $params['password'] = $databaseUrl['password'];
            $params['host'] = $databaseUrl['host'];
            $params['port'] = $databaseUrl['port'];
            $params['database'] = ltrim($databaseUrl['path'], '/');
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
