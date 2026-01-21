# WAFU (Web Application Firewall Universal)

[![Readme EN](https://img.shields.io/badge/README-EN-blue.svg)](https://github.com/BespredeL/wafu/blob/master/README.md)
[![Readme RU](https://img.shields.io/badge/README-RU-blue.svg)](https://github.com/BespredeL/wafu/blob/master/README_RU.md)
[![GitHub license](https://img.shields.io/badge/license-MIT-458a7b.svg)](https://github.com/BespredeL/wafu/blob/master/LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-777BB4.svg)](https://www.php.net/)

WAFU - это **модульный Web Application Firewall** для PHP 8.1+, полностью управляемый через конфигурацию.

Подходит для **Laravel, Symfony и standalone PHP приложений**.

Поддерживает **локальные и удалённые наборы правил**, кеширование, наборы паттернов и безопасное применение в production с гибкой архитектурой, позволяющей настраивать правила безопасности без изменения кода.

---

## Содержание

- [Возможности](#возможности)
- [Требования](#требования)
- [Установка](#установка)
- [Быстрый старт](#быстрый-старт)
- [Конфигурация](#конфигурация)
- [Режимы работы](#режимы-работы)
- [Встроенные модули](#встроенные-модули)
- [Наборы паттернов](#наборы-паттернов)
- [Удалённые правила](#удалённые-правила)
- [Интеграция с фреймворками](#интеграция-с-фреймворками)
- [Философия безопасности](#философия-безопасности)
- [Решение проблем](#решение-проблем)
- [Лицензия](#лицензия)

---

## Возможности

- ✅ **Полностью через конфиг** - Никаких хардкодных правил, всё настраивается
- ✅ **Модульная архитектура** - Легко расширяется пользовательскими модулями
- ✅ **Комплексная защита** - Обнаружение SQLi, XSS, RCE, LFI, Path Traversal
- ✅ **Детекция ботов** - Определение и проверка подозрительных User-Agent
- ✅ **Rate limiting** - Защита от брутфорса и DDoS атак
- ✅ **Блокировка IP** - Поддержка IPv4/IPv6 + CIDR для гибкой блокировки
- ✅ **Наборы паттернов** - Переиспользуемые паттерны обнаружения
- ✅ **Удалённые правила** - Загрузка наборов правил с удалённых endpoints с кешированием
- ✅ **Режим только логирования** - Безопасное тестирование правил перед применением
- ✅ **PSR-3 логирование** - Интеграция с любым PSR-3 совместимым логгером
- ✅ **Безопасно для фреймворков** - Без принудительного exit, работает с Laravel/Symfony
- ✅ **Доверенные прокси** - Корректная обработка заголовков X-Forwarded-For
- ✅ **Поддержка ETag** - Эффективные обновления удалённых наборов правил

---

## Требования

- PHP >= 8.1
- Расширение `ext-mbstring`
- PSR-3 совместимый логгер (опционально, но рекомендуется)

---

## Установка

Установка через Composer:

```bash
composer require bespredel/wafu
```

---

## Быстрый старт

### Standalone PHP

```php
<?php

use Bespredel\Wafu\Core\Kernel;

// Инициализация ядра с конфигурационным файлом
$kernel = new Kernel(__DIR__ . '/config/wafu.php');

// Обработка запроса
$result = $kernel->handle(
    $_SERVER,
    $_GET,
    $_POST,
    $_COOKIE,
    getallheaders()
);

// Проверка, был ли запрос заблокирован
if ($result->isBlocked()) {
    http_response_code($result->getStatus() ?: 403);
    echo $result->getBody() ?: 'Запрос заблокирован WAFU';
    exit;
}

// Продолжение работы приложения
```

### Laravel

1. **Публикация конфигурации** (опционально):

```bash
php artisan vendor:publish --tag=wafu-config
```

2. **Регистрация middleware** в `app/Http/Kernel.php`:

```php
protected $middleware = [
    // ... другие middleware
    \Bespredel\Wafu\Adapters\Laravel\WafuMiddleware::class,
];
```

Или применение к конкретным маршрутам в `routes/web.php`:

```php
Route::middleware([\Bespredel\Wafu\Adapters\Laravel\WafuMiddleware::class])
    ->group(function () {
        // Ваши защищённые маршруты
    });
```

3. **Настройка** в `config/wafu.php` (см. [Конфигурация](#конфигурация))

### Symfony

1. **Регистрация сервиса** в `config/services.yaml`:

```yaml
services:
    Bespredel\Wafu\Adapters\Symfony\WafuSubscriber:
        arguments:
            $wafKernel: '@Bespredel\Wafu\Core\Kernel'
            $logger: '@logger'
        tags:
            - { name: kernel.event_subscriber }
    
    Bespredel\Wafu\Core\Kernel:
        arguments:
            $configPath: '%kernel.project_dir%/config/wafu.php'
```

2. **Создание конфигурационного файла** `config/wafu.php` (скопируйте из `vendor/bespredel/wafu/src/config/wafu.php`)

---

## Конфигурация

WAFU полностью настраивается через PHP массив. Основные опции конфигурации:

### Главный переключатель

```php
'enabled' => true, // Включить/выключить WAFU глобально
```

### Режим

```php
'mode' => 'enforce', // или 'report'
```

- **`enforce`**: Блокировать вредоносные запросы (режим production)
- **`report`**: Только логировать совпадения без блокировки (рекомендуется для начального тестирования)

### Доверенные прокси

Если ваше приложение за прокси/балансировщиком (Nginx, Cloudflare и т.д.):

```php
'trusted_proxies' => [
    '127.0.0.1',
    '10.0.0.0/8',
    '172.16.0.0/12',
    '192.168.0.0/16',
],
'trust_forwarded_headers' => true, // Включить доверие к X-Forwarded-For
```

**⚠️ Важно для безопасности**: Включайте `trust_forwarded_headers` только если доверяете прокси. Иначе оставьте `false` для предотвращения подделки XFF.

### Pipeline (Порядок выполнения)

Определите порядок выполнения модулей:

```php
'pipeline' => [
    'ip_blocklist',      // Быстрые проверки первыми
    'method_allowlist',
    'uri_rules',
    'path_traversal',    // Проверки по паттернам
    'lfi',
    'rce',
    'bad_bots',
    'not_found_abuse',
    'rate_limit',        // Ресурсоёмкие проверки последними
    'sql_injection',
    'xss',
],
```

**Рекомендация**: Размещайте лёгкие проверки (блокировка IP, валидация методов) первыми, а ресурсоёмкие regex проверки - последними.

### Действия (Actions)

Действия определяют, что происходит при обнаружении угрозы модулем:

```php
'actions' => [
    'block' => [
        'class'     => \Bespredel\Wafu\Actions\BlockAction::class,
        'status'    => 403,
        'message'   => 'Заблокировано WAFU',
        'terminate' => true, // true для standalone, false для фреймворков
    ],
    
    'challenge' => [
        'class'       => \Bespredel\Wafu\Actions\ChallengeAction::class,
        'status'      => 429,
        'message'     => 'Слишком много запросов. Попробуйте позже.',
        'retry_after' => 15,
        'terminate'   => true,
    ],
    
    'log' => [
        'class'   => \Bespredel\Wafu\Actions\LogAction::class,
        'channel' => 'security',
        'level'   => 'warning',
    ],
],
```

---

## Режимы работы

### Режим Enforce

Немедленно блокирует вредоносные запросы. Используйте в production после тестирования.

```php
'mode' => 'enforce',
```

### Режим Report

Логирует угрозы без блокировки. Идеально для:
- Первоначального развёртывания
- Тестирования новых правил
- Мониторинга ложных срабатываний

```php
'mode' => 'report',
```

**Лучшая практика**: Начните с режима `report`, отслеживайте логи несколько дней, затем переключитесь на `enforce`.

---

## Встроенные модули

### IpBlocklistModule

Блокировка запросов с определённых IP или CIDR диапазонов:

```php
'ip_blocklist' => [
    'class'     => \Bespredel\Wafu\Modules\IpBlocklistModule::class,
    'blocklist' => [
        '1.2.3.4',
        '10.0.0.0/8',
        '2001:db8::/32', // Поддержка IPv6
    ],
    'on_match'  => 'block',
    'reason'    => 'IP заблокирован',
],
```

### MethodAllowlistModule

Разрешить только определённые HTTP методы:

```php
'method_allowlist' => [
    'class'   => \Bespredel\Wafu\Modules\MethodAllowlistModule::class,
    'allow'   => ['GET', 'POST', 'HEAD'],
    'on_deny' => 'block',
    'reason'  => 'HTTP метод не разрешён',
],
```

### UriAllowDenyModule

Контроль доступа к определённым URI:

```php
'uri_rules' => [
    'class'        => \Bespredel\Wafu\Modules\UriAllowDenyModule::class,
    'allow_prefix' => ['/api', '/login'],
    'allow_regex'  => ['/^\/api\/v\d+\/.*$/'],
    'deny_prefix'  => ['/.env', '/.git', '/vendor'],
    'deny_regex'   => ['uri_deny'], // Ссылка на набор паттернов
    'on_deny'      => 'block',
    'reason'       => 'URI не разрешён',
],
```

### RegexMatchModule

Обнаружение паттернов в данных запроса (SQLi, XSS и т.д.):

```php
'sql_injection' => [
    'class'    => \Bespredel\Wafu\Modules\RegexMatchModule::class,
    'targets'  => ['query', 'body', 'cookies'],
    'patterns' => ['sql_keywords'], // Ссылка на набор паттернов
    'on_match' => 'block',
    'reason'   => 'Обнаружена SQL инъекция',
],
```

### HeaderModule

Обнаружение ботов и сканеров по User-Agent:

```php
'bad_bots' => [
    'class'    => \Bespredel\Wafu\Modules\HeaderModule::class,
    'headers'  => ['User-Agent'],
    'patterns' => ['bad_bots_ua'],
    'on_match' => 'challenge',
    'reason'   => 'Обнаружен бот/сканер',
],
```

### RateLimitModule

Защита от брутфорса и DDoS:

```php
'rate_limit' => [
    'class'          => \Bespredel\Wafu\Modules\RateLimitModule::class,
    'limit'          => 120,        // Запросов за интервал
    'interval'       => 60,          // Секунд
    'key_by'         => 'ip',        // или 'ip+uri'
    'on_exceed'      => 'challenge',
    'reason'         => 'Превышен лимит запросов',
    'gc_probability' => 100,         // 1% вероятность запуска GC
    'ttl_multiplier' => 5,           // TTL = interval * multiplier
],
```

### NotFoundAbuseModule

Обнаружение сканирования/брутфорса путём мониторинга ошибок 404:

```php
'not_found_abuse' => [
    'class'          => \Bespredel\Wafu\Modules\NotFoundAbuseModule::class,
    'threshold'      => 20,          // Макс. 404 за интервал
    'interval'       => 300,          // 5 минут
    'key_by'         => 'ip',
    'on_exceed'      => 'block',
    'reason'         => 'Слишком много ошибок 404',
    'gc_probability' => 100,
    'ttl_multiplier' => 5,
],
```

### PathTraversalModule, LfiModule, RceModule

Защита от path traversal, локального включения файлов и удалённого выполнения кода:

```php
'path_traversal' => [
    'class'    => \Bespredel\Wafu\Modules\PathTraversalModule::class,
    'targets'  => ['uri', 'query', 'body', 'cookies'],
    'patterns' => ['path_traversal'],
    'on_match' => 'block',
],

'lfi' => [
    'class'    => \Bespredel\Wafu\Modules\LfiModule::class,
    'targets'  => ['query', 'body', 'cookies', 'uri'],
    'patterns' => ['lfi_files'],
    'on_match' => 'block',
],

'rce' => [
    'class'    => \Bespredel\Wafu\Modules\RceModule::class,
    'targets'  => ['query', 'body', 'cookies', 'uri'],
    'patterns' => ['rce_signatures'],
    'on_match' => 'block',
],
```

---

## Наборы паттернов

Наборы паттернов позволяют определять переиспользуемые паттерны обнаружения:

```php
'patterns' => [
    'sql_keywords' => [
        '/\bUNION\b/i',
        '/\bSELECT\b/i',
        '/\bINSERT\b/i',
        // ... больше паттернов
    ],
    
    'xss_basic' => [
        '/<\s*script\b/i',
        '/javascript\s*:/i',
        '/document\.cookie/i',
    ],
],
```

Модули могут ссылаться на наборы паттернов по имени:

```php
'patterns' => ['sql_keywords', 'xss_basic'],
```

Или использовать прямые regex паттерны:

```php
'patterns' => [
    '/\bSELECT\b/i',
    '/<\s*script\b/i',
],
```

---

## Удалённые правила

WAFU может загружать наборы правил с удалённых endpoints, что позволяет:
- Централизованное управление правилами
- Динамические обновления без развёртывания
- A/B тестирование правил

### Конфигурация

```php
'remote_rules' => [
    'enabled'            => true,
    'endpoint'           => 'https://example.com/api/ruleset.json',
    
    // Опциональная аутентификация
    'headers'            => [
        'X-WAF-KEY' => 'ваш-секретный-ключ',
    ],
    
    // Настройки кеша
    'cache_dir'          => sys_get_temp_dir() . '/wafu-cache',
    'cache_file'         => 'ruleset.json',
    'use_cache_on_error' => true, // Использовать кеш при ошибке удалённого запроса
    'max_ttl'            => 86400, // Макс. TTL кеша (24 часа)
    
    // Проверка подписи (опционально, требует ext-sodium)
    'signature'          => [
        'enabled'           => false,
        'public_key_base64' => '',
        'field'             => 'signature',
        'algo'              => 'ed25519',
    ],
    
    // Стратегия слияния
    'merge_strategy'     => 'remote_wins', // или 'local_wins'
],
```

### Возможности

- **Кеширование на основе TTL**: Наборы правил кешируются локально с поддержкой TTL
- **Поддержка ETag**: Эффективные обновления с использованием HTTP ETags
- **Безопасный fallback**: Возврат к локальной конфигурации при ошибках
- **Проверка подписи**: Опциональная проверка подписи Ed25519
- **Стратегии слияния**: Выбор способа слияния удалённой и локальной конфигураций

### Формат набора правил

Удалённые наборы правил должны быть JSON файлами с той же структурой, что и локальная PHP конфигурация:

```json
{
    "patterns": {
        "sql_keywords": ["/\\bSELECT\\b/i"]
    },
    "modules": {
        "sql_injection": {
            "patterns": ["sql_keywords"],
            "on_match": "block"
        }
    }
}
```

---

## Интеграция с фреймворками

### Laravel

WAFU бесшовно интегрируется с Laravel через service provider и middleware.

**Service Provider** (автоматически регистрируется):

`WafuServiceProvider` автоматически:
- Регистрирует `Kernel` как singleton
- Объединяет конфигурацию пакета с конфигурацией приложения
- Публикует конфигурационный файл через `php artisan vendor:publish --tag=wafu-config`

**Middleware**:

```php
// app/Http/Kernel.php
protected $middleware = [
    \Bespredel\Wafu\Adapters\Laravel\WafuMiddleware::class,
];
```

Middleware автоматически:
- Извлекает данные запроса
- Выполняет проверки WAFU
- Возвращает соответствующие ответы
- Интегрируется с системой логирования Laravel

### Symfony

WAFU интегрируется через event subscriber:

```yaml
# config/services.yaml
services:
    Bespredel\Wafu\Adapters\Symfony\WafuSubscriber:
        arguments:
            $wafKernel: '@Bespredel\Wafu\Core\Kernel'
            $logger: '@logger'
        tags:
            - { name: kernel.event_subscriber }
    
    Bespredel\Wafu\Core\Kernel:
        arguments:
            $configPath: '%kernel.project_dir%/config/wafu.php'
```

Subscriber слушает `KernelEvents::REQUEST` и обрабатывает запросы до их попадания в контроллеры.

---

## Философия безопасности

### Начните с режима Report

1. **Разверните в режиме `report`** для наблюдения за поведением
2. **Мониторьте логи** на предмет ложных срабатываний
3. **Настройте правила** на основе реального трафика
4. **Переключитесь на режим `enforce`** когда будете уверены

### Защита в глубину

WAFU - это один слой безопасности. Комбинируйте с:
- Валидацией входных данных
- Очисткой вывода
- Подготовленными SQL запросами
- Защитой от CSRF
- HTTPS/TLS
- Регулярными обновлениями безопасности

### Соображения производительности

- Размещайте лёгкие проверки первыми в pipeline
- Используйте rate limiting для предотвращения исчерпания ресурсов
- Мониторьте влияние на производительность
- Рассмотрите кеширование для дорогих операций

---

## Решение проблем

### Запросы блокируются некорректно

1. **Проверьте логи**: Включите PSR-3 логирование для просмотра совпадений
2. **Используйте режим report**: Переключитесь на `report` для просмотра совпадений без блокировки
3. **Проверьте паттерны**: Убедитесь, что ваши паттерны не слишком агрессивны
4. **Whitelist**: Используйте URI allowlists для легитимных endpoints

### Проблемы с производительностью

1. **Оптимизируйте pipeline**: Переместите сложные проверки в конец
2. **Упростите паттерны**: Упростите regex паттерны
3. **Проверьте rate limits**: Убедитесь, что rate limiting не слишком агрессивен
4. **Мониторьте хранилище**: Периодически очищайте файлы хранилища rate limit

### Удалённые правила не загружаются

1. **Проверьте подключение**: Убедитесь, что endpoint доступен
2. **Проверьте формат**: Убедитесь, что JSON валиден
3. **Проверьте кеш**: Очистите директорию кеша при необходимости
4. **Проверьте логи**: Ищите HTTP ошибки
5. **Тестируйте локально**: Проверьте структуру JSON набора правил

### Проблемы с определением IP

1. **Проверьте доверенные прокси**: Настройте, если за прокси
2. **Проверьте заголовки**: Убедитесь, что X-Forwarded-For доверен
3. **Тестируйте извлечение IP**: Логируйте определённый IP для проверки

---

## Лицензия

Этот пакет представляет собой программное обеспечение с открытым исходным кодом, лицензированное по [лицензии MIT](LICENSE).

---

## Вклад в проект

Вклад приветствуется! Пожалуйста, не стесняйтесь отправлять Pull Request.

---

## Поддержка

По вопросам, проблемам или вкладу в проект, пожалуйста, посетите [репозиторий GitHub](https://github.com/BespredeL/wafu).
