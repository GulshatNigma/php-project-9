<?php

require __DIR__ . "/../vendor/autoload.php";

use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use Slim\Middleware\MethodOverrideMiddleware;
use DI\Container;
use Carbon\Carbon;
use Valitron\Validator;
use Postgresqlphpconnect\Page\Analyzer\Connection;
use Illuminate\Support\Arr;

session_start();

try {
    $pdo = Connection::get()->connect();
} catch (\PDOException $e) {
    echo $e->getMessage();
}

$container = new Container();
$container->set('renderer', function () {
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});

$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});


AppFactory::setContainer($container);
$app = AppFactory::create();
$app->addErrorMiddleware(true, true, true);
$app->add(MethodOverrideMiddleware::class);

$router = $app->getRouteCollector()->getRouteParser();

$app->get('/', function ($request, $response) use ($router) {
    $params = ['url' => []];
    return $this->get("renderer")->render($response, 'main.phtml', $params);
})->setName('main');

$app->post('/urls', function ($request, $response) use ($router) {
    $urlData = $request->getParsedBodyParam('url');
    $urlName = $urlData['name'];

    $validator = new Validator($urlData);
    $validator->rule('required', 'name')->message("URL не должен быть пустым");
    $validator->rule('url', 'name')->message("Некорректный URL");
    $validator->rule('lengthMax', 'name', 255)->message("URL не должен превышать 255 символов");
    $validator->validate();

    if (count($validator->errors()) !== 0) {
        $error = $validator->errors();
        $params = [
            'url' => $urlName,
            'errors' => $validator->errors()
        ];
        return $this->get('renderer')->render($response->withStatus(422), 'main.phtml', $params);
    }
    $carbon = new Carbon();
    $createdAt = $carbon->now();
    $pdo = Connection::get()->connect();

    $sql = "SELECT * FROM urls";
    $data = $pdo->query($sql);
    $row = $data->fetchAll(PDO::FETCH_ASSOC);
    if ($row === false) {
        $row = [];
    }

    if (in_array($urlName, Arr::flatten($row))) {
        $sql = "SELECT id FROM urls WHERE name='$urlName'";
        $result = $pdo->query($sql);
        $row = $result->fetch();
        $id = $row['id'];
        $this->get('flash')->addMessage('success', "Страница уже существует");
        return $response->withRedirect($router->urlFor('get user', ['id' => $id]));
    }

    $result = $pdo->query($sql);

    $sql = "INSERT INTO urls (name, created_at) VALUES(:name, :created_at)";
    $sth = $pdo->prepare($sql);
    $sth->execute(['name' => $urlName, 'created_at' => $createdAt]);
    $params = [
        'url' => $urlName,
    ];
    $this->get('flash')->addMessage('success', "Страница успешно добавлена");

    $pdo = Connection::get()->connect();
    $sql = "SELECT id FROM urls WHERE name='$urlName'";
    $result = $pdo->query($sql);
    $row = $result->fetch(PDO::FETCH_ASSOC);
    $id = $row['id'];
    return $response->withRedirect($router->urlFor('get user', ['id' => $id]));
});

$app->get('/urls', function ($request, $response) use ($router) {
    $sql = "SELECT * FROM urls";
    $pdo = Connection::get()->connect();
    $result = $pdo->query($sql);
    $urls = $result->fetchAll(PDO::FETCH_ASSOC);

    $lastChecks = [];
    foreach ($urls as $url) {
        $id = $url['id'];
        $sql = "SELECT created_at FROM url_checks WHERE url_id = $id";
        $pdo = Connection::get()->connect();
        $result = $pdo->query($sql);
        $dateOfCheck = $result->fetchAll(PDO::FETCH_ASSOC);
        $lastChecks[$id] = end($dateOfCheck)['created_at'];
    }
    $params = [
        'lastChecks' => $lastChecks,
        'urls' => array_reverse($urls),
        'dateOfCheck' => $dateOfCheck
    ];
    return $this->get('renderer')->render($response, 'sites.phtml', $params);
});

$app->get('/urls/{id}', function ($request, $response, $args) {
    $pdo = Connection::get()->connect();
    $id = $args['id'];
    $flash = $this->get('flash')->getMessages();
    $sql = "SELECT name, created_at FROM urls WHERE id='$id'";
    $result = $pdo->query($sql);
    $row = $result->fetch(PDO::FETCH_ASSOC);

    $pdo = Connection::get()->connect();
    $sql = "SELECT id, created_at FROM url_checks WHERE url_id = $id";
    $result = $pdo->query($sql);
    $urlChecks = $result->fetchAll(PDO::FETCH_ASSOC);

    $params = ['flash' => $flash,
                'id' => $id, 'name' => $row['name'], 'created_at' => $row['created_at'],
                 'urlChecks' => array_reverse($urlChecks)];
    return $this->get('renderer')->render($response, 'show.phtml', $params);
})->setName('get user');

$app->post('/urls/{url_id}/checks', function ($request, $response, $args) use ($router) {
    $pdo = Connection::get()->connect();
    $id = $args['url_id'];
    $carbon = new Carbon();
    $createdAt = $carbon->now();

    $sql = "INSERT INTO url_checks (url_id, created_at) VALUES(:url_id, :created_at)";
    $sth = $pdo->prepare($sql);
    $sth->execute(['url_id' => $id, 'created_at' => $createdAt]);
    return $response->withRedirect($router->urlFor('get user', ['id' => $id]));
});

$app->run();
