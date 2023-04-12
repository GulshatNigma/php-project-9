<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Middleware\MethodOverrideMiddleware;
use DI\Container;
use Carbon\Carbon;
use Valitron\Validator;
use GuzzleHttp\Client;
use DiDom\Document;
use PageAnalyser\Connection;
use illuminate\support;

session_start();

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

$app->get('/', function ($request, $response) {
    $params = ['url' => []];
    return $this->get("renderer")->render($response, 'main.phtml', $params);
});

$app->post('/urls', function ($request, $response) use ($router) {
    $urlData = $request->getParsedBodyParam('url');

    $validator = new Validator($urlData);
    $validator->rule('required', 'name')->message("URL не должен быть пустым");
    $validator->rule('urlActive', 'name')->message("Некорректный URL");
    $validator->rule('lengthMax', 'name', 255)->message("URL не должен превышать 255 символов");

    if (!$validator->validate()) {
        $params = [
            'url' => $urlData,
            'errors' => $validator->errors()
        ];
        return $this->get('renderer')->render($response->withStatus(422), 'main.phtml', $params);
    }

    $parseUrl = parse_url($urlData['name']);
    $urlName = $parseUrl['scheme'] . "://";
    $urlName .= $parseUrl['host'];

    $pdo = Connection::get()->connect();

    $sth = $pdo->prepare("SELECT id FROM urls WHERE name=:urlName");
    $sth->execute(['urlName' => $urlName]);
    $row = $sth->fetch();

    if ($row) {
        $id = $row['id'];
        $this->get('flash')->addMessage('success', "Страница уже существует");
        return $response->withRedirect($router->urlFor('urls.show', ['id' => $id]));
    }

    $createdAt = Carbon::now();

    $sth = $pdo->prepare("INSERT INTO urls (name, created_at) VALUES(:name, :created_at)");
    $sth->execute(['name' => $urlName, 'created_at' => $createdAt]);
    $params = [
        'url' => $urlName,
    ];
    $this->get('flash')->addMessage('success', "Страница успешно добавлена");

    $pdo = Connection::get()->connect();
    $sth = $pdo->prepare("SELECT id FROM urls WHERE name=:urlName");
    $sth->execute(['urlName' => $urlName]);
    $row = $sth->fetch(PDO::FETCH_ASSOC);
    $id = $row['id'];
    return $response->withRedirect($router->urlFor('urls.show', ['id' => $id]));
})->setName('urls.store');

$app->get('/urls', function ($request, $response) {
    $sql = "SELECT * FROM urls";
    $pdo = Connection::get()->connect();
    $result = $pdo->query($sql);
    $urls = $result->fetchAll(PDO::FETCH_ASSOC);

    $lastChecks = [];
    foreach ($urls as $url) {
        $id = $url['id'];
        $sth = $pdo->prepare("SELECT created_at, status_code FROM url_checks WHERE url_id = :id");
        $sth->execute(['id' => $id]);
        $dateOfCheck = $sth->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($dateOfCheck)) {
            $lastChecks[$id] = end($dateOfCheck)['created_at'];
            $lastStatusCode[$id] = end($dateOfCheck)['status_code'];
        }
    }
    $params = [
        'lastChecks' => $lastChecks,
        'urls' => array_reverse($urls),
        'dateOfCheck' => $dateOfCheck ?? null,
        'statusCode' => $lastStatusCode ?? null,
    ];
    return $this->get('renderer')->render($response, 'sites.phtml', $params);
})->setName('urls.index');

$app->get('/urls/{id}', function ($request, $response, $args) {
    $pdo = Connection::get()->connect();
    $id = $args['id'];
    $flash = $this->get('flash')->getMessages();

    $sql = $pdo->prepare("SELECT name, created_at FROM urls WHERE id=:id");
    $sql->execute(['id' => $id]);
    $row = $sql->fetch(PDO::FETCH_ASSOC);

    $sql = "SELECT id, created_at, status_code, h1, title, description FROM url_checks WHERE url_id = :id";
    $sth = $pdo->prepare($sql);
    $sth->execute(['id' => $id]);
    $urlChecks = $sth->fetchAll(PDO::FETCH_ASSOC);

    $params = ['flash' => $flash,
                'id' => $id, 'name' => $row['name'], 'created_at' => $row['created_at'],
                 'urlChecks' => array_reverse($urlChecks)];
    return $this->get('renderer')->render($response, 'show.phtml', $params);
})->setName('urls.show');

$app->post('/urls/{url_id}/checks', function ($request, $response, $args) use ($router) {
    $pdo = Connection::get()->connect();
    $id = $args['url_id'];

    $sth = $pdo->prepare("SELECT name FROM urls WHERE id=:id");
    $sth->execute(['id' => $id]);
    $row = $sth->fetch(PDO::FETCH_ASSOC);
    $name = $row['name'];

    $client = new Client(['base_url' => '$name']);

    try {
        $res = $client->request('GET', $name);
    } catch (Exception) {
        $this->get('flash')->addMessage('danger', "Произошла ошибка при проверке, не удалось подключиться");
        return $response->withStatus(404)->withRedirect($router->urlFor('urls.show', ['id' => $id]));
    }

    $this->get('flash')->addMessage('success', "Страница успешно проверена");

    $body = (string) ($res->getBody());
    $document = new Document($body);

    $h1Array = $document->first('h1');
    $h1 = optional($h1Array)->text();

    $titleArray = $document->first('title');
    $title = optional($titleArray)->text();

    $descriptionArray = $document->first('meta[name=description]');
    $description = optional($descriptionArray)->content;

    $createdAt = Carbon::now();
    $statusCode = $res->getStatusCode();

    $sql = "INSERT INTO url_checks (url_id, created_at, status_code, h1, title, description)
            VALUES(:url_id, :created_at, :status_code, :h1, :title, :description)";
    $sth = $pdo->prepare($sql);
    $sth->execute(['url_id' => $id, 'created_at' => $createdAt,
                    'status_code' => $statusCode, 'h1' => $h1,
                    'title' => $title, 'description' => $description]);
    return $response->withRedirect($router->urlFor('urls.show', ['id' => $id]));
})->setName('urls.checks.store');

$app->run();
