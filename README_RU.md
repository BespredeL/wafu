# WAFU (Web Application Firewall Universal)

[![Readme EN](https://img.shields.io/badge/README-EN-blue.svg)](https://github.com/BespredeL/wafu/blob/master/README.md)
[![Readme RU](https://img.shields.io/badge/README-RU-blue.svg)](https://github.com/BespredeL/wafu/blob/master/README_RU.md)
[![GitHub license](https://img.shields.io/badge/license-MIT-458a7b.svg)](https://github.com/BespredeL/wafu/blob/master/LICENSE)

WAFU - это **модульный Web Application Firewall** для PHP 8.1+,
управляемый полностью через конфигурацию.

Подходит для **Laravel, Symfony и standalone PHP**.

---

## Возможности

- Управление только через конфиг
- Модульная архитектура
- Защита от SQLi / XSS / RCE / LFI / Path Traversal
- Детекция ботов
- Rate limiting
- Блокировка IPv4 / IPv6 + CIDR
- Pattern sets
- Загрузка удалённых ruleset с кешированием
- Report-only режим
- PSR-3 логирование
- Безопасная интеграция с фреймворками

---

## Установка

```bash
composer require bespredel/wafu
```

---

## Быстрый старт

```php
use Bespredel\Wafu\Core\Kernel;

$kernel = new Kernel(__DIR__ . '/config/wafu.php');

$decision = $kernel->handle($_SERVER, $_GET, $_POST, $_COOKIE, getallheaders());

if ($decision->isBlocked()) {
    http_response_code(403);
    echo 'Запрос заблокирован WAFU';
    exit;
}
```

---

## Режимы

```php
'mode' => 'enforce', // или 'report'
```

- **enforce** - блокировать
- **report** - только логировать (рекомендуется сначала)

---

## Встроенные модули

- RegexMatchModule (SQLi, XSS)
- HeaderModule (боты)
- RateLimitModule
- IpBlocklistModule
- MethodAllowlistModule
- UriAllowDenyModule
- RceModule
- LfiModule
- PathTraversalModule

---

## Pattern sets

Позволяют переиспользовать сигнатуры:

```php
'patterns' => [
  'sql_keywords' => ['/SELECT/i', '/UNION/i'],
]
```

---

## Remote rules

WAFU может загружать правила по HTTP:

```php
'remote_rules' => [
  'enabled' => true,
  'endpoint' => 'https://github.com/BespredeL/wafu/blob/master/ruleset.json',
]
```

Поддерживается:
- TTL
- кеш
- ETag
- безопасный fallback
- подпись ruleset

---

## Laravel

Подключите middleware:

```php
\Bespredel\Wafu\Adapters\Laravel\WafuMiddleware::class
```

---

## Symfony

```yaml
Bespredel\Wafu\Adapters\Symfony\WafuSubscriber:
  tags: [kernel.event_subscriber]
```

---

## Философия безопасности

Сначала используйте `report`.
Проверьте логи.
Затем включайте `enforce`.

---

## Лицензия

MIT
