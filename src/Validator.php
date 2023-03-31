<?php

namespace Page\Analyser\Validator;

use Valitron\Validator;

function validate(array $data)
{
    $validator = new Validator($data);
    $validator->rule('required', 'name')->message("URL не должен быть пустым");
    $validator->rule('url', 'name')->message("Некорректный URL");
    $validator->rule('lengthMax', 'name', 255)->message("URL не должен превышать 255 символов");
    return $validator;
}
