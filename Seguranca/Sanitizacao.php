<?php

declare(strict_types=1);

class ValidationException extends InvalidArgumentException {}

function validate_input(array $input, array $rules): array
{
    $validated = [];

    foreach ($rules as $field => $rule) {
        $type = is_array($rule) ? ($rule['type'] ?? 'string') : $rule;
        $required = is_array($rule) ? ($rule['required'] ?? false) : false;

        $value = $input[$field] ?? null;

        if ($value === null || $value === '') {
            if ($required) {
                throw new ValidationException("Campo obrigatório ausente ou vazio: {$field}.");
            }
            $validated[$field] = $value;
            continue;
        }

        if (is_array($value)) {
            throw new ValidationException("Formato inválido para {$field}: arrays não são permitidos.");
        }

        $validated[$field] = validate_value((string) $value, $type, $field);
    }

    return $validated;
}

function validate_value(string $value, string $type, string $fieldName = 'campo'): string
{
    switch ($type) {
        case 'email':
            if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                throw new ValidationException("E-mail inválido em {$fieldName}.");
            }
            return $value;

        case 'int':
        case 'integer':
            if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                throw new ValidationException("Número inteiro inválido em {$fieldName}.");
            }
            return $value;

        case 'number':
        case 'float':
            if (filter_var($value, FILTER_VALIDATE_FLOAT) === false) {
                throw new ValidationException("Número inválido em {$fieldName}.");
            }
            return $value;

        case 'id':
            if (!preg_match('/^[A-Za-z0-9_-]+$/u', $value)) {
                throw new ValidationException("ID contém caracteres não permitidos em {$fieldName}.");
            }
            return $value;

        case 'url':
            if (filter_var($value, FILTER_VALIDATE_URL) === false) {
                throw new ValidationException("URL inválida em {$fieldName}.");
            }
            return $value;

        case 'string':
        default:
            if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new ValidationException("O valor de {$fieldName} contém caracteres de controle.");
            }
            return $value;
    }
}

function require_validated_request(array $getRules = [], array $postRules = []): array
{
    try {
        return [
            'get' => validate_input($_GET, $getRules),
            'post' => validate_input($_POST, $postRules),
        ];
    } catch (ValidationException $e) {
        http_response_code(400);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function escape_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function escape_attribute(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function escape_url(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

?>
