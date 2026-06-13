# Laravel JSONL - Documentation complète

[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

## Table des matières

1. [Introduction](#introduction)
2. [Installation](#installation)
3. [Architecture et concepts](#architecture-et-concepts)
4. [Les stratégies de chemin](#les-stratégies-de-chemin)
5. [Utilisation de base](#utilisation-de-base)
6. [Opérations avancées](#opérations-avancées)
7. [Buffer d'écriture](#buffer-décriture)
8. [Verrouillage concurrentiel](#verrouillage-concurrentiel)
9. [Nettoyage des données](#nettoyage-des-données)
10. [Configuration](#configuration)
11. [Bonnes pratiques](#bonnes-pratiques)
12. [Exemples complets](#exemples-complets)
13. [API Reference](#api-reference)
14. [Dépannage](#dépannage)

---

## Introduction

**Laravel JSONL** est un package de stockage de données au format [JSON Lines](https://jsonlines.org/) (JSONL) pour PHP 8.1+. Chaque ligne est un JSON valide, ce qui le rend idéal pour :

- **Logs structurés** : Journalisation d'événements avec recherche par date/heure
- **Cache persistant** : Stockage de données avec expiration
- **Métriques** : Collecte de données temps réel
- **Audit trails** : Traçabilité immuable des actions

### Pourquoi JSONL plutôt que JSON classique ?

| Critère | JSON classique | JSONL |
|---------|----------------|-------|
| Ajout de données | Doit réécrire tout le fichier | Simple append |
| Streaming | Impossible (doit tout charger) | Possible ligne par ligne |
| Recherche | Doit parser tout le fichier | Streaming + filtrage |
| Corruption | Fichier complet inutilisable | Une ligne corrompue n'affecte pas les autres |
| Parallélisme | Difficile (lock sur tout le fichier) | Possible par ligne |

---

## Installation

```bash
composer require andydefer/laravel-jsonl
```

**Prérequis :**
- PHP 8.1 ou supérieur
- Composer
- (Optionnel) Laravel 10, 11 ou 12 pour l'intégration automatique

### Sans Laravel (PHP pur)

```php
use AndyDefer\LaravelJsonl\JsonlService;
use AndyDefer\LaravelJsonl\Contexts\JsonlContext;
use AndyDefer\LaravelJsonl\Strategies\TemporalPathStrategy;
use AndyDefer\PhpServices\Services\FileSystemService;

$strategy = new TemporalPathStrategy('/var/logs');
$fileSystem = new FileSystemService();
$context = new JsonlContext();
$service = new JsonlService($strategy, $fileSystem, $context);
```

### Avec Laravel

Le package s'enregistre automatiquement via le Service Provider. Publiez la configuration :

```bash
php artisan vendor:publish --tag=jsonl-config
```

```env
# .env
JSONL_BASE_PATH=storage/logs/structured
JSONL_BUFFER_SIZE=100
JSONL_DIRECTORY_PERMISSION=755
```

---

## Architecture et concepts

### Le pattern Stateless Service

Le `JsonlService` est conçu comme un service **stateless**. Tout l'état (verrous actifs, buffer d'écriture) est déporté dans un `JsonlContext` injecté. Cette architecture offre plusieurs avantages :

- **Testabilité** : On peut isoler et tester chaque composant indépendamment
- **Prévisibilité** : Pas d'effets de bord cachés entre appels
- **Concurrence** : Chaque contexte peut être isolé par thread/requête

```
┌─────────────────────────────────────────────────────────────────┐
│                        JsonlService                              │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ - pathStrategy: JsonlPathStrategyInterface              │   │
│  │ - fileSystem: FileSystemInterface                       │   │
│  │ - context: JsonlContext ◄─── État externalisé           │   │
│  └─────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
                                 │
                                 ▼
┌─────────────────────────────────────────────────────────────────┐
│                         JsonlContext                            │
│  ┌─────────────────────┐  ┌─────────────────────────────────┐  │
│  │ Lock State          │  │ Buffer State                    │  │
│  │ - locks: array      │  │ - buffer: array                 │  │
│  │                     │  │ - bufferSize: int               │  │
│  │                     │  │ - onFlushCallback: callable     │  │
│  └─────────────────────┘  └─────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
```

### Records

Les **Records** sont des objets immutables qui représentent les données à stocker. Ils ne contiennent **aucune logique métier** - uniquement des propriétés.

#### LogJsonlRecord - Pour la journalisation

```php
use AndyDefer\LaravelJsonl\Records\LogJsonlRecord;
use AndyDefer\PhpVo\ValueObjects\DateTimeVO;
use AndyDefer\DomainStructures\Structures\StrictDataObject;

$log = new LogJsonlRecord(
    time: new DateTimeVO(),                    // Date/heure de l'événement
    level: 'info',                             // Niveau (info, warning, error, debug)
    type: 'user_login',                        // Type d'événement
    payload: new StrictDataObject([            // Données métier
        'user_id' => 123,
        'ip' => '192.168.1.100',
    ]),
);
```

#### CacheJsonlRecord - Pour le cache

```php
use AndyDefer\LaravelJsonl\Records\CacheJsonlRecord;

$cache = new CacheJsonlRecord(
    key: 'user_profile_123',                  // Clé unique
    value: json_encode(['name' => 'John']),   // Valeur (JSON string)
    expires_at: new DateTimeVO('+1 hour'),    // Expiration (optionnel)
);
```

### Stratégies de chemin

Les **stratégies** déterminent **où** les fichiers sont stockés. Le service ne sait pas où ranger les données - il délègue cette décision à la stratégie.

```php
// Le service demande à la stratégie : "Où dois-je mettre ce log ?"
$filePath = $strategy->getFilePath($logRecord);
```

---

## Les stratégies de chemin

### Pourquoi des stratégies ?

L'organisation des fichiers dépend du cas d'usage :

| Besoin | Organisation idéale |
|--------|---------------------|
| Logs | Par date/heure (recherche temporelle) |
| Cache | Par hash de clé (accès direct O(1)) |

Le package fournit deux stratégies prêtes à l'emploi, et vous pouvez créer les vôtres.

### TemporalPathStrategy (pour les logs)

**Structure :** `{basePath}/{YYYY-MM-DD}/{HH}.jsonl`

```
storage/logs/
├── 2026-01-15/
│   ├── 00.jsonl   (00h00 - 00h59)
│   ├── 01.jsonl   (01h00 - 01h59)
│   └── 14.jsonl   (14h00 - 14h59)
├── 2026-01-16/
│   └── ...
```

**Avantages :**
- Recherche par intervalle de temps rapide
- Nettoyage facile par date (suppression de dossiers entiers)
- Streaming par jour/heure
- Pas besoin d'index, la structure EST l'index

**Exemple :**
```php
$strategy = new TemporalPathStrategy('/var/logs');

$log = new LogJsonlRecord(
    time: new DateTimeVO('2026-01-15T14:35:00Z'),
    // ...
);

$path = $strategy->getFilePath($log);
// Résultat: /var/logs/2026-01-15/14.jsonl
```

### KeyBasedPathStrategy (pour le cache)

**Structure :** `{basePath}/{hash[0]}/{hash[1]}/{sanitized_key}.jsonl`

```
storage/cache/
├── a/
│   ├── b/
│   │   └── user_123.jsonl
│   └── c/
│       └── session_abc.jsonl
├── f/
│   └── 3/
│       └── product_456.jsonl
```

**Comment ça marche :**
1. `md5('user_123')` = `e10adc3949ba59abbe56e057f20f883e`
2. Niveaux de hash : `e` → `1`
3. Chemin final : `/cache/e/1/user_123.jsonl`

**Avantages :**
- Accès direct O(1) - on sait exactement quel fichier lire
- Répartition équilibrée (le hash disperse naturellement)
- Pas de scanning inutile
- Suppression rapide par clé

**Exemple :**
```php
$strategy = new KeyBasedPathStrategy('/cache', hashLevels: 2);

$cache = new CacheJsonlRecord(key: 'user_123', ...);

$path = $strategy->getFilePath($cache);
// Résultat: /cache/e/1/user_123.jsonl
```

### Créer sa propre stratégie

Implémentez `JsonlPathStrategyInterface` :

```php
use AndyDefer\LaravelJsonl\Contracts\JsonlPathStrategyInterface;

/**
 * Organisation par tenant (multi-entreprise)
 * Structure: /{tenant_id}/{YYYY-MM-DD}/{HH}.jsonl
 */
class TenantBasedPathStrategy implements JsonlPathStrategyInterface
{
    public function __construct(
        private string $basePath,
        private string $tenantId,
    ) {}

    public function getFilePath(AbstractRecord $entity): string
    {
        if (!$entity instanceof LogJsonlRecord) {
            throw new InvalidArgumentException('Expected LogJsonlRecord');
        }

        $date = $entity->time->format('Y-m-d');
        $hour = $entity->time->format('H');

        return implode(DIRECTORY_SEPARATOR, [
            rtrim($this->basePath, DIRECTORY_SEPARATOR),
            $this->tenantId,
            $date,
            $hour . '.jsonl',
        ]);
    }

    public function getFilesToScan(AbstractRecord $query): array
    {
        // Logique pour scanner tous les fichiers du tenant sur la plage demandée
        // ...
    }

    public function getBaseDirectory(): string
    {
        return $this->basePath;
    }
}
```

---

## Utilisation de base

### Écrire un log

```php
use AndyDefer\LaravelJsonl\JsonlService;
use AndyDefer\LaravelJsonl\Contexts\JsonlContext;
use AndyDefer\LaravelJsonl\Records\LogJsonlRecord;
use AndyDefer\LaravelJsonl\Strategies\TemporalPathStrategy;
use AndyDefer\PhpServices\Services\FileSystemService;
use AndyDefer\PhpVo\ValueObjects\DateTimeVO;

$strategy = new TemporalPathStrategy(storage_path('logs/structured'));
$fs = new FileSystemService();
$context = new JsonlContext();
$service = new JsonlService($strategy, $fs, $context);

$log = new LogJsonlRecord(
    time: new DateTimeVO(),
    level: 'info',
    type: 'user_login',
    payload: new StrictDataObject([
        'user_id' => 123,
        'username' => 'john_doe',
        'ip' => '192.168.1.100',
    ]),
);

$service->write($log);
```

**Résultat dans le fichier :**
```json
{"time":"2026-01-15T14:35:00+00:00","level":"info","type":"user_login","payload":{"user_id":123,"username":"john_doe","ip":"192.168.1.100"}}
```

### Écrire une entrée de cache

```php
use AndyDefer\LaravelJsonl\Records\CacheJsonlRecord;
use AndyDefer\LaravelJsonl\Strategies\KeyBasedPathStrategy;

$strategy = new KeyBasedPathStrategy(storage_path('cache'), 2);
$service = new JsonlService($strategy, $fs, $context);

$cache = new CacheJsonlRecord(
    key: 'user_123',
    value: json_encode(['name' => 'John', 'email' => 'john@example.com']),
    expires_at: new DateTimeVO('+1 hour'),
);

$service->write($cache);
```

### Lire un fichier

```php
// Lire toutes les lignes
$lines = $service->readAll('/var/logs/2026-01-15/14.jsonl');
foreach ($lines as $line) {
    echo $line['type'] . "\n";
}

// Lire ligne par ligne (streaming - économique en mémoire)
$service->readLineByLine('/var/logs/2026-01-15/14.jsonl', function ($line) {
    echo $line['time'] . ': ' . $line['level'] . "\n";
});

// Lire uniquement la première ou dernière ligne
$first = $service->getFirstLine('/var/logs/2026-01-15/14.jsonl');
$last = $service->getLastLine('/var/logs/2026-01-15/14.jsonl');
```

### Rechercher dans un fichier

```php
// Trouver toutes les erreurs
$errors = $service->search('/var/logs/2026-01-15/14.jsonl', function ($line) {
    return $line['level'] === 'error';
});

// Trouver les logs d'un utilisateur spécifique
$userLogs = $service->search('/var/logs/2026-01-15/14.jsonl', function ($line) {
    return isset($line['payload']['user_id']) && $line['payload']['user_id'] === 123;
});
```

---

## Opérations avancées

### Écriture par lots (batch)

Plus efficace que des écritures individuelles pour de gros volumes :

```php
$logs = [
    new LogJsonlRecord(/* ... */),
    new LogJsonlRecord(/* ... */),
    // ...
];

$service->writeBatch($logs); // Une seule opération I/O
```

### Écriture avec verrouillage

Par défaut, `write()` et `writeBatch()` utilisent un verrouillage exclusif (`flock`). Désactivez-le pour les environnements mono-processus :

```php
// Pas de verrou (plus rapide mais pas sûr en concurrence)
$service->write($record, lock: false);
```

### Recherche sur plusieurs fichiers

```php
$files = [
    '/var/logs/2026-01-15/14.jsonl',
    '/var/logs/2026-01-15/15.jsonl',
];

$errors = $service->searchMultiple($files, function ($line) {
    return $line['level'] === 'error';
});
```

### Utilisation du contexte de traitement

Le `JsonlProcessingContext` suit l'état d'une opération :

```php
$context = new JsonlProcessingContext();

try {
    $service->write($log, context: $context);
    
    if ($context->isCompleted()) {
        echo "Files processed: " . $context->getProcessedFiles()->count();
        echo "Lines written: " . $context->getTotalLinesProcessed();
        echo "Duration: " . $context->getDuration() . " seconds";
    }
} catch (JsonlException $e) {
    echo "Failed: " . $context->getLastError();
}
```

---

## Buffer d'écriture

Le buffer accumule les entités en mémoire avant de les écrire sur le disque, réduisant ainsi les opérations I/O.

### Activer le buffer

```php
// Buffer de 100 lignes avant écriture automatique
$service->enableBuffer(100);
```

### Utilisation

```php
$service->enableBuffer(50);

for ($i = 0; $i < 100; $i++) {
    $service->writeBuffered($log);  // Stocké en mémoire
    
    // Toutes les 50 écritures, flush automatique
}

$service->flushBuffer();  // Flush manuel des derniers
```

### Callback de flush

```php
$service->enableBuffer(100);
$service->onFlush(function ($filePath, $count) {
    echo "Flushed {$count} lines to {$filePath}\n";
});
```

### Désactiver le buffer

```php
$service->disableBuffer();  // Flush puis désactive
```

---

## Verrouillage concurrentiel

Le package utilise `flock` pour garantir l'intégrité des données en environnement concurrent.

### Verrouillage automatique

Les méthodes `write()` et `writeBatch()` utilisent un verrou par défaut :

```php
// Verrou automatique (par défaut)
$service->write($record);  // lock = true

// Sans verrou (dangereux en concurrence)
$service->write($record, lock: false);
```

### Verrouillage manuel

```php
// Acquérir un verrou
if ($service->acquire('/var/logs/app.jsonl', timeout: 5)) {
    try {
        // Opérations exclusives
        $this->fileSystem->append('/var/logs/app.jsonl', $data);
    } finally {
        $service->release('/var/logs/app.jsonl');
    }
}
```

### Exécution atomique

```php
$result = $service->executeWithLock('/var/logs/app.jsonl', function () use ($service) {
    $content = $service->readAll('/var/logs/app.jsonl');
    $content[] = ['time' => date('c'), 'event' => 'processed'];
    return count($content);
});
```

---

## Nettoyage des données

### Nettoyage par âge (fichiers entiers)

```php
// Supprimer les fichiers de plus de 30 jours
$deleted = $service->cleanOlderThan(30, '/var/logs');
echo "Deleted {$deleted} old log files";
```

### Nettoyage par pattern

```php
// Supprimer tous les fichiers du 15 janvier
$pattern = '/var/logs/2026-01-15/*.jsonl';
$deleted = $service->cleanByPattern($pattern);
```

### Nettoyage des entrées expirées (cache)

```php
$deleted = $service->cleanExpired('/cache', function ($line) {
    if (!isset($line['expires_at'])) {
        return false;
    }
    $expiresAt = new DateTimeVO($line['expires_at']);
    return $expiresAt->isBefore(new DateTimeVO());
});
```

### Dry run - Simulation avant suppression

```php
// Voir ce qui serait supprimé sans rien effacer
$filesToDelete = $service->dryRun('/var/logs', function ($file) {
    return filemtime($file) < strtotime('-30 days');
});

foreach ($filesToDelete as $file) {
    echo "Would delete: {$file}\n";
}

if (count($filesToDelete) > 0) {
    $confirm = readline("Proceed with deletion? (y/n): ");
    if ($confirm === 'y') {
        $service->cleanOlderThan(30, '/var/logs');
    }
}
```

### Vider complètement un répertoire

```php
$deleted = $service->clear('/var/app/cache');
echo "Cleared {$deleted} cache files";
```

---

## Configuration

### Variables d'environnement

```env
# Chemin de base pour les fichiers JSONL
JSONL_BASE_PATH=/custom/log/path

# Taille du buffer (null = désactivé)
JSONL_BUFFER_SIZE=100

# Permissions des dossiers (755, 775, 750, 700, etc.)
JSONL_DIRECTORY_PERMISSION=755
```

### Fichier de configuration Laravel

```php
// config/jsonl.php
return [
    'base_path' => env('JSONL_BASE_PATH', storage_path('jsonl')),
    'buffer_size' => env('JSONL_BUFFER_SIZE', null),
    'directory_permission' => (int) env('JSONL_DIRECTORY_PERMISSION', 755),
];
```

---

## Bonnes pratiques

### 1. Utilisez la stratégie adaptée à votre besoin

| Cas d'usage | Stratégie recommandée |
|-------------|----------------------|
| Logs, événements, audits | `TemporalPathStrategy` |
| Cache, sessions | `KeyBasedPathStrategy` |

### 2. Activez le buffer pour les écritures fréquentes

```php
$service->enableBuffer(100);
// Écritures...
$service->flushBuffer();
```

### 3. Utilisez les queries typées

```php
// Pour les logs
$query = new TemporalLogQueryRecord(
    from: new DateTimeVO('2026-01-15T00:00:00Z'),
    to: new DateTimeVO('2026-01-15T23:59:59Z'),
    type: 'user_login',      // Optionnel
    level: 'info',           // Optionnel
);

// Pour le cache
$query = new CacheKeyQueryRecord(key: 'user_123');
```

### 4. Structurez vos payloads

```php
// ✅ BON - Données structurées
$payload = new StrictDataObject([
    'event' => 'user_login',
    'user_id' => 123,
    'ip' => '192.168.1.100',
]);

// ❌ MAUVAIS - Texte non structuré
$payload = new StrictDataObject(['message' => 'User 123 logged in from 192.168.1.100']);
```

### 5. Gérez les exceptions

```php
try {
    $service->write($record);
} catch (JsonlException $e) {
    // Problème d'écriture
    Log::error('Failed to write JSONL: ' . $e->getMessage());
} catch (JsonlLockException $e) {
    // Timeout de verrou
    Log::warning('Could not acquire lock: ' . $e->getMessage());
}
```

### 6. Nettoyez régulièrement

```php
// Cron daily
$service->cleanOlderThan(30, '/var/logs');
$service->cleanExpired('/cache', $isExpiredCallback);
```

---

## Exemples complets

### Application de logging complète

```php
class UserActivityLogger
{
    private JsonlService $service;

    public function __construct()
    {
        $strategy = new TemporalPathStrategy(storage_path('logs/activities'));
        $fs = new FileSystemService();
        $context = new JsonlContext();
        $this->service = new JsonlService($strategy, $fs, $context);
        $this->service->enableBuffer(50);
    }

    public function logLogin(int $userId, string $ip, bool $success): void
    {
        $log = new LogJsonlRecord(
            time: new DateTimeVO(),
            level: $success ? 'info' : 'warning',
            type: 'user_login',
            payload: new StrictDataObject([
                'user_id' => $userId,
                'ip' => $ip,
                'success' => $success,
                'timestamp' => time(),
            ]),
        );

        $this->service->writeBuffered($log);
    }

    public function getFailedLogins(DateTimeVO $date): array
    {
        $query = new TemporalLogQueryRecord(
            from: $date,
            to: $date,
            type: 'user_login',
            level: 'warning',
        );

        $files = $this->service->getFilesToScan($query);
        $failed = [];

        foreach ($files as $file) {
            $lines = $this->service->search($file, function ($line) {
                return $line['payload']['success'] === false;
            });
            $failed = array_merge($failed, $lines);
        }

        return $failed;
    }

    public function __destruct()
    {
        $this->service->flushBuffer();
    }
}
```

### Service de cache persistant

```php
class PersistentCache
{
    private JsonlService $service;
    private int $ttl;

    public function __construct(int $defaultTtlSeconds = 3600)
    {
        $strategy = new KeyBasedPathStrategy(storage_path('cache/persistent'), 2);
        $fs = new FileSystemService();
        $context = new JsonlContext();
        $this->service = new JsonlService($strategy, $fs, $context);
        $this->ttl = $defaultTtlSeconds;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        $expiresAt = $ttl !== null
            ? new DateTimeVO("+{$ttl} seconds")
            : ($this->ttl ? new DateTimeVO("+{$this->ttl} seconds") : null);

        $cache = new CacheJsonlRecord(
            key: $key,
            value: json_encode($value),
            expires_at: $expiresAt,
        );

        $this->service->write($cache);
    }

    public function get(string $key): mixed
    {
        $query = new CacheKeyQueryRecord(key: $key);
        $files = $this->service->getFilesToScan($query);

        if (empty($files) || !$this->service->fileExists($files[0])) {
            return null;
        }

        $data = $this->service->readAll($files[0]);
        if (empty($data)) {
            return null;
        }

        $record = CacheJsonlRecord::fromArray($data[0]);

        if ($this->service->isExpired($record)) {
            unlink($files[0]);
            return null;
        }

        return json_decode($record->value, true);
    }

    public function delete(string $key): void
    {
        $query = new CacheKeyQueryRecord(key: $key);
        $files = $this->service->getFilesToScan($query);

        if (!empty($files) && $this->service->fileExists($files[0])) {
            unlink($files[0]);
        }
    }

    public function clear(): void
    {
        $this->service->clear(storage_path('cache/persistent'));
    }

    public function cleanExpired(): int
    {
        return $this->service->cleanExpired(storage_path('cache/persistent'), function ($line) {
            if (!isset($line['expires_at'])) {
                return false;
            }
            $expiresAt = new DateTimeVO($line['expires_at']);
            return $expiresAt->isBefore(new DateTimeVO());
        });
    }
}
```

### Intégration Laravel

```php
// AppServiceProvider.php
use AndyDefer\LaravelJsonl\Contexts\JsonlContext;
use AndyDefer\LaravelJsonl\JsonlService;
use AndyDefer\LaravelJsonl\Strategies\TemporalPathStrategy;
use AndyDefer\LaravelJsonl\Strategies\KeyBasedPathStrategy;
use AndyDefer\PhpServices\Services\FileSystemService;

public function register(): void
{
    // Contexte partagé
    $this->app->singleton(JsonlContext::class);

    // Service pour les logs
    $this->app->singleton('jsonl.logs', function ($app) {
        $strategy = new TemporalPathStrategy(storage_path('logs/structured'));
        $fs = new FileSystemService();
        $context = $app->make(JsonlContext::class);
        return new JsonlService($strategy, $fs, $context);
    });

    // Service pour le cache
    $this->app->singleton('jsonl.cache', function ($app) {
        $strategy = new KeyBasedPathStrategy(storage_path('cache/jsonl'), 2);
        $fs = new FileSystemService();
        $context = $app->make(JsonlContext::class);
        $service = new JsonlService($strategy, $fs, $context);
        $service->enableBuffer(100);
        return $service;
    });
}

// Controller
class AnalyticsController extends Controller
{
    public function track(Request $request)
    {
        $jsonl = app('jsonl.logs');
        
        $log = new LogJsonlRecord(
            time: new DateTimeVO(),
            level: 'info',
            type: 'page_view',
            payload: new StrictDataObject([
                'page' => $request->path(),
                'user_id' => auth()->id(),
                'user_agent' => $request->userAgent(),
            ]),
        );
        
        $jsonl->writeBuffered($log);
        
        return response()->json(['status' => 'tracked']);
    }
}
```

---

## API Reference

### Interfaces

| Interface | Méthodes principales |
|-----------|---------------------|
| `JsonlWriterInterface` | `write()`, `writeBatch()`, `writeBuffered()`, `flushBuffer()` |
| `JsonlReaderInterface` | `readAll()`, `readLineByLine()`, `search()`, `searchMultiple()` |
| `JsonlCleanerInterface` | `cleanOlderThan()`, `cleanExpired()`, `cleanByPattern()`, `dryRun()`, `clear()` |
| `JsonlLockInterface` | `acquire()`, `release()`, `executeWithLock()`, `isLocked()` |
| `JsonlPathStrategyInterface` | `getFilePath()`, `getFilesToScan()`, `getBaseDirectory()` |

### Classes principales

| Classe | Rôle |
|--------|------|
| `JsonlService` | Service principal (stateless) |
| `JsonlContext` | État du service (locks, buffer) |
| `LogJsonlRecord` | Record pour logs |
| `CacheJsonlRecord` | Record pour cache |
| `TemporalPathStrategy` | Stratégie temporelle |
| `KeyBasedPathStrategy` | Stratégie par clé |
| `JsonlProcessingContext` | Contexte de traitement |
| `TemporalLogQueryRecord` | Query pour logs temporels |
| `CacheKeyQueryRecord` | Query pour cache par clé |

### Exceptions

| Exception | Description |
|-----------|-------------|
| `JsonlException` | Exception de base |
| `JsonlLockException` | Problème de verrouillage |

---

## Dépannage

### Erreur : "Unsupported record type"

La stratégie attend un type spécifique de Record.

**Solution :** Utilisez le bon Record avec la bonne stratégie :
- `TemporalPathStrategy` → `LogJsonlRecord`
- `KeyBasedPathStrategy` → `CacheJsonlRecord`

### Erreur : "Timeout acquiring lock"

Un autre processus maintient le verrou trop longtemps.

**Solution :**
- Augmentez le timeout : `$service->acquire($path, timeout: 10)`
- Vérifiez les processus zombies
- Nettoyez les fichiers `.lock` orphelins

### Les fichiers ne sont pas trouvés par `cleanExpired()`

Le pattern glob `**` n'est pas supporté sur certains systèmes.

**Solution :** Le package utilise `RecursiveIterator`, compatible tous systèmes.

### Performance lente avec beaucoup de petits fichiers

**Solution :**
- Utilisez le buffer : `$service->enableBuffer(100)`
- Regroupez les écritures : `writeBatch()`
- Ajustez le niveau de hash pour `KeyBasedPathStrategy`

---

## Licence

MIT © [Andy Defer](https://github.com/andydefer)
---