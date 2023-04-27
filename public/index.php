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
use Illuminate\Support;

session_start();

$container = new Container();

$container->set('renderer', function () {
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});

$container->set('flash', function () {
    return new \Slim\Flash\Messages();
});

$container->set('connection', function () {
    $pdo = Connection::connect();
    return $pdo;
});

AppFactory::setContainer($container);
$app = AppFactory::create();
$responseFactory = $app->getResponseFactory();
$app->add(MethodOverrideMiddleware::class);

$errorMiddleware = $app->addErrorMiddleware(true, true, true);

$customErrorHandler = function () use ($responseFactory) {
    $response = $responseFactory->createResponse();
    return $this->get('renderer')->render($response, "error.phtml");
};
$errorMiddleware->setDefaultErrorHandler($customErrorHandler);

$router = $app->getRouteCollector()->getRouteParser();

$app->get('/', function ($request, $response) {
    $params = ['url' => []];
    return $this->get("renderer")->render($response, 'main.phtml', $params);
})->setName('main');

$app->post('/urls', function ($request, $response) use ($router) {
    $urlParams = $request->getParsedBodyParam('url');

    $validator = new Validator($urlParams);
    $validator->rule('required', 'name')->message("URL не должен быть пустым");
    $validator->rule('urlActive', 'name')->message("Некорректный URL");
    $validator->rule('lengthMax', 'name', 255)->message("URL не должен превышать 255 символов");

    if (!$validator->validate()) {
        $params = [
            'url' => $urlParams,
            'errors' => $validator->errors()
        ];
        return $this->get('renderer')->render($response->withStatus(422), 'main.phtml', $params);
    }

    $parsedUrl = parse_url($urlParams['name']);
    $urlName = $parsedUrl['scheme'] . "://" . $parsedUrl['host'];

    $sth = $this->get('connection')->prepare("SELECT id FROM urls WHERE name=:urlName");
    $sth->execute(['urlName' => $urlName]);
    $row = $sth->fetch();

    if ($row) {
        $urlId = $row['id'];
        $this->get('flash')->addMessage('success', "Страница уже существует");
        return $response->withRedirect($router->urlFor('urls.show', ['id' => $urlId]));
    }

    $sth = $this->get('connection')->prepare("INSERT INTO urls (name, created_at) VALUES(:name, :createdAt)");
    $sth->execute(['name' => $urlName, 'createdAt' => Carbon::now()]);
    $params = [
        'url' => $urlName,
    ];
    $this->get('flash')->addMessage('success', "Страница успешно добавлена");

    $sth = $this->get('connection')->prepare("SELECT id FROM urls WHERE name=:urlName");
    $sth->execute(['urlName' => $urlName]);
    $urlId = $this->get('connection')->lastInsertId();

    return $response->withRedirect($router->urlFor('urls.show', ['id' => $urlId]));
})->setName('urls.store');

$app->get('/urls', function ($request, $response) {
    $sth = $this->get('connection')->prepare("SELECT urls.id, urls.name,
                                            url_checks.status_code, MAX(url_checks.created_at) FROM url_checks
                                            LEFT JOIN urls ON url_checks.url_id = urls.id 
                                            GROUP BY urls.name, urls.id, url_checks.status_code, url_checks.url_id
                                            ORDER BY urls.id DESC;");
    $sth->execute();
    $urls = $sth->fetchAll();

    $params = [
        'urls' => $urls
    ];
    return $this->get('renderer')->render($response, 'index.phtml', $params);
})->setName('urls.index');

$app->get('/urls/{id}', function ($request, $response, $args) {
    $urlId = $args['id'];
    $flash = $this->get('flash')->getMessages();

    $sql = $this->get('connection')->prepare("SELECT name, created_at FROM urls WHERE id=:id");
    $sql->execute(['id' => $urlId]);
    $row = $sql->fetch();

    $sql = "SELECT id, created_at, status_code, h1, title, description FROM url_checks WHERE url_id = :id";
    $sth = $this->get('connection')->prepare($sql);
    $sth->execute(['id' => $urlId]);
    $urlChecks = $sth->fetchAll();

    $params = ['flash' => $flash,
                'id' => $urlId, 'name' => $row['name'], 'created_at' => $row['created_at'],
                'urlChecks' => array_reverse($urlChecks)];
    return $this->get('renderer')->render($response, 'show.phtml', $params);
})->setName('urls.show');

$app->post('/urls/{url_id}/checks', function ($request, $response, $args) use ($router) {
    $urlId = $args['url_id'];

    $sth = $this->get('connection')->prepare("SELECT name FROM urls WHERE id=:id");
    $sth->execute(['id' => $urlId]);
    $row = $sth->fetch();
    $name = $row['name'];

    $client = new Client(['base_url' => '$name']);

    try {
        $pageResponse = $client->request('GET', $name);
    } catch (Exception) {
        $this->get('flash')->addMessage('danger', "Произошла ошибка при проверке, не удалось подключиться");
        return $response->withStatus(404)->withRedirect($router->urlFor('urls.show', ['id' => $urlId]));
    }

    $this->get('flash')->addMessage('success', "Страница успешно проверена");

    $pageBody = (string) ($pageResponse->getBody());
    $document = new Document($pageBody);

    $h1 = optional($document->first('h1'))->text();
    $title = optional($document->first('title'))->text();
    $description = optional($document->first('meta[name=description]'))->content;


    $sql = "INSERT INTO url_checks (url_id, created_at, status_code, h1, title, description)
            VALUES(:url_id, :created_at, :status_code, :h1, :title, :description)";
    $sth = $this->get('connection')->prepare($sql);
    $sth->execute(['url_id' => $urlId,
                    'created_at' => Carbon::now(),
                    'status_code' => $pageResponse->getStatusCode(),
                    'h1' => $h1, 'title' => $title, 'description' => $description]);
    return $response->withRedirect($router->urlFor('urls.show', ['id' => $urlId]));
})->setName('urls.checks.store');

$app->run();
