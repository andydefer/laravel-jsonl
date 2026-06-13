# JsonlService - Référence Technique

## Description

Service principal de stockage et de manipulation de fichiers JSONL (JSON Lines). Il gère l'écriture, la lecture, la recherche, le nettoyage et le verrouillage de fichiers JSONL.

## Hiérarchie / Implémentations

```
JsonlWriterInterface
JsonlReaderInterface
JsonlCleanerInterface
JsonlLockInterface
    └── JsonlService
```

## Rôle principal

Centralise toutes les opérations sur les fichiers JSONL en s'appuyant sur une stratégie de chemin (`JsonlPathStrategyInterface`) pour déterminer l'emplacement des fichiers. Il offre des fonctionnalités avancées comme l'écriture bufferisée, le verrouillage concurrentiel et le nettoyage automatique des données expirées.

## Détails

[Voir la classe JsonlService](https://github.com/andydefer/laravel-jsonl/blob/main/src/JsonlService.php)

## API / Méthodes publiques

### `write(AbstractRecord $entity, bool $lock = true, ?JsonlProcessingContext $context = null): void`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$entity` | `AbstractRecord` | L'entité à écrire (LogJsonlRecord ou CacheJsonlRecord) |
| `$lock` | `bool` | Active le verrouillage exclusif du fichier |
| `$context` | `JsonlProcessingContext|null` | Contexte optionnel pour suivre l'opération |

**Retourne :** `void`

**Exceptions :** `JsonlException` - Si l'écriture échoue

**Exemple :**
```php
$service = new JsonlService($strategy, $fileSystem);
$record = new LogJsonlRecord(/* ... */);
$service->write($record);
```

---

### `writeBatch(array $entities, bool $lock = true, ?JsonlProcessingContext $context = null): void`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$entities` | `array<AbstractRecord>` | Liste des entités à écrire |
| `$lock` | `bool` | Active le verrouillage exclusif |
| `$context` | `JsonlProcessingContext|null` | Contexte optionnel |

**Retourne :** `void`

**Exceptions :** `JsonlException` - Si l'écriture échoue

**Exemple :**
```php
$records = [/* ... */];
$service->writeBatch($records);
```

---

### `writeBuffered(AbstractRecord $entity, ?JsonlProcessingContext $context = null): void`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$entity` | `AbstractRecord` | Entité à mettre en buffer |
| `$context` | `JsonlProcessingContext|null` | Contexte optionnel |

**Retourne :** `void`

**Exemple :**
```php
$service->enableBuffer(100);
$service->writeBuffered($record); // Stocké en mémoire
$service->flushBuffer(); // Écriture disque
```

---

### `flushBuffer(?string $filePath = null, ?JsonlProcessingContext $context = null): void`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$filePath` | `string|null` | Chemin spécifique ou null pour tout vider |
| `$context` | `JsonlProcessingContext|null` | Contexte optionnel |

**Retourne :** `void`

---

### `enableBuffer(int $size = 100): void`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$size` | `int` | Nombre d'entités avant écriture automatique |

**Retourne :** `void`

---

### `disableBuffer(): void`

| Paramètre | Type | Description |
|-----------|------|-------------|
| Aucun | - | - |

**Retourne :** `void`

---

### `onFlush(callable $callback): void`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$callback` | `callable(string $filePath, int $count): void` | Fonction exécutée à chaque flush |

**Retourne :** `void`

**Exemple :**
```php
$service->onFlush(function ($filePath, $count) {
    echo "Flushed $count lines to $filePath";
});
```

---

### `readAll(string $filePath, ?JsonlProcessingContext $context = null): array`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$filePath` | `string` | Chemin du fichier à lire |
| `$context` | `JsonlProcessingContext|null` | Contexte optionnel |

**Retourne :** `array<array<string, mixed>>` - Toutes les lignes du fichier

**Exceptions :** `JsonlException` - Si le fichier n'existe pas

**Exemple :**
```php
$lines = $service->readAll('/logs/2026-01-15/14.jsonl');
foreach ($lines as $line) {
    echo $line['level'] . ': ' . $line['type'];
}
```

---

### `readLineByLine(string $filePath, callable $callback, ?JsonlProcessingContext $context = null): void`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$filePath` | `string` | Chemin du fichier |
| `$callback` | `callable(array<string, mixed> $line): void` | Fonction appelée pour chaque ligne |
| `$context` | `JsonlProcessingContext|null` | Contexte optionnel |

**Retourne :** `void`

**Exceptions :** `JsonlException` - Si le fichier n'existe pas

**Exemple :**
```php
$service->readLineByLine('/logs/file.jsonl', function ($line) {
    echo $line['time'] . ' - ' . $line['level'];
});
```

---

### `search(string $filePath, callable $filter, ?JsonlProcessingContext $context = null): array`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$filePath` | `string` | Chemin du fichier |
| `$filter` | `callable(array<string, mixed> $line): bool` | Fonction de filtrage |
| `$context` | `JsonlProcessingContext|null` | Contexte optionnel |

**Retourne :** `array<array<string, mixed>>` - Lignes qui satisfont le filtre

**Exemple :**
```php
$errors = $service->search('/logs/14.jsonl', function ($line) {
    return $line['level'] === 'error';
});
```

---

### `searchMultiple(array $filePaths, callable $filter, ?JsonlProcessingContext $context = null): array`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$filePaths` | `array<string>` | Liste des chemins à scanner |
| `$filter` | `callable(array<string, mixed> $line): bool` | Fonction de filtrage |
| `$context` | `JsonlProcessingContext|null` | Contexte optionnel |

**Retourne :** `array<array<string, mixed>>` - Lignes qui satisfont le filtre

---

### `getLastLine(string $filePath, ?JsonlProcessingContext $context = null): ?array`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$filePath` | `string` | Chemin du fichier |
| `$context` | `JsonlProcessingContext|null` | Contexte optionnel |

**Retourne :** `array<string, mixed>|null` - Dernière ligne ou null

---

### `getFirstLine(string $filePath, ?JsonlProcessingContext $context = null): ?array`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$filePath` | `string` | Chemin du fichier |
| `$context` | `JsonlProcessingContext|null` | Contexte optionnel |

**Retourne :** `array<string, mixed>|null` - Première ligne ou null

---

### `cleanOlderThan(int $days, string $basePath, ?JsonlProcessingContext $context = null): int`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$days` | `int` | Âge maximum en jours |
| `$basePath` | `string` | Répertoire racine |
| `$context` | `JsonlProcessingContext|null` | Contexte optionnel |

**Retourne :** `int` - Nombre de fichiers supprimés

**Exemple :**
```php
$deleted = $service->cleanOlderThan(30, '/var/logs');
```

---

### `cleanExpired(string $basePath, callable $isExpired, ?JsonlProcessingContext $context = null): int`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$basePath` | `string` | Répertoire racine |
| `$isExpired` | `callable(array<string, mixed> $line): bool` | Fonction déterminant si une ligne est expirée |
| `$context` | `JsonlProcessingContext|null` | Contexte optionnel |

**Retourne :** `int` - Nombre d'entrées supprimées

**Exemple :**
```php
$deleted = $service->cleanExpired('/cache', function ($line) {
    return new DateTimeVO($line['expires_at'])->isBefore(new DateTimeVO());
});
```

---

### `cleanByPattern(string $pattern, ?JsonlProcessingContext $context = null): int`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$pattern` | `string` | Pattern glob (ex: `/logs/*.jsonl`) |
| `$context` | `JsonlProcessingContext|null` | Contexte optionnel |

**Retourne :** `int` - Nombre de fichiers supprimés

**Exemple :**
```php
// Supprimer tous les fichiers JSONL du 15 janvier 2026
$pattern = '/var/logs/2026-01-15/*.jsonl';
$deletedCount = $service->cleanByPattern($pattern);
echo "Deleted {$deletedCount} files";
```

---

### `dryRun(string $basePath, callable $filter, ?JsonlProcessingContext $context = null): array`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$basePath` | `string` | Répertoire racine à scanner |
| `$filter` | `callable(string $filePath): bool` | Fonction qui détermine quels fichiers supprimer |
| `$context` | `JsonlProcessingContext|null` | Contexte optionnel |

**Retourne :** `array<string>` - Liste des fichiers qui seraient supprimés (sans les supprimer réellement)

**Exemple :**
```php
// Simuler la suppression des fichiers de plus de 30 jours
$filesToDelete = $service->dryRun('/var/logs', function ($file) {
    return filemtime($file) < strtotime('-30 days');
});

foreach ($filesToDelete as $file) {
    echo "Would delete: {$file}\n";
}
echo "Total files that would be deleted: " . count($filesToDelete);
```

---

### `clear(string $basePath, ?JsonlProcessingContext $context = null): int`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$basePath` | `string` | Répertoire racine à vider |
| `$context` | `JsonlProcessingContext|null` | Contexte optionnel |

**Retourne :** `int` - Nombre de fichiers supprimés

**Exemple :**
```php
// Vider complètement le cache
$cacheDir = '/var/app/cache';
$deletedCount = $service->clear($cacheDir);
echo "Cleared {$deletedCount} cache files";
```

---

### `acquire(string $filePath, int $timeout = 5): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$filePath` | `string` | Chemin du fichier à verrouiller |
| `$timeout` | `int` | Timeout maximum en secondes (défaut: 5) |

**Retourne :** `bool` - True si le verrou a été acquis avec succès

**Exceptions :** `JsonlLockException` - Si le timeout est dépassé sans pouvoir acquérir le verrou

**Exemple :**
```php
// Acquérir un verrou manuellement
if ($service->acquire('/var/logs/app.jsonl', 3)) {
    try {
        // Effectuer des opérations exclusives
        file_put_contents('/var/logs/app.jsonl', $data, FILE_APPEND);
    } finally {
        $service->release('/var/logs/app.jsonl');
    }
}
```

---

### `release(string $filePath): void`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$filePath` | `string` | Chemin du fichier à déverrouiller |

**Retourne :** `void`

**Exemple :**
```php
$service->acquire('/var/logs/app.jsonl');
// ... opérations exclusives ...
$service->release('/var/logs/app.jsonl');
```

---

### `executeWithLock(string $filePath, callable $callback): mixed`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$filePath` | `string` | Chemin du fichier à verrouiller |
| `$callback` | `callable(): mixed` | Fonction à exécuter pendant que le verrou est actif |

**Retourne :** `mixed` - Le résultat de la fonction callback

**Exemple :**
```php
// Exécution automatique avec verrouillage/déverrouillage
$result = $service->executeWithLock('/var/logs/app.jsonl', function () use ($service) {
    $content = $service->readAll('/var/logs/app.jsonl');
    $content[] = ['time' => date('c'), 'event' => 'processed'];
    return count($content);
});

echo "Total lines after atomic operation: {$result}";
```

---

### `isLocked(string $filePath): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$filePath` | `string` | Chemin du fichier à vérifier |

**Retourne :** `bool` - True si un verrou est actif sur le fichier

**Exemple :**
```php
if ($service->isLocked('/var/logs/app.jsonl')) {
    echo "File is currently locked, try again later";
} else {
    $service->acquire('/var/logs/app.jsonl');
    // ... opérations ...
}
```

---

### `getFilePath(AbstractRecord $entity): string`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$entity` | `AbstractRecord` | L'entité (LogJsonlRecord ou CacheJsonlRecord) |

**Retourne :** `string` - Chemin absolu du fichier où l'entité serait stockée

**Exemple :**
```php
$logRecord = new LogJsonlRecord(
    time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
    level: 'info',
    type: 'test',
    payload: new StrictDataObject(),
);

$filePath = $service->getFilePath($logRecord);
echo "Log will be stored in: {$filePath}";
// Affiche: /var/logs/2026-01-15/14.jsonl
```

---

### `getFilesToScan(AbstractRecord $query): array`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$query` | `AbstractRecord` | La requête (TemporalLogQueryRecord ou CacheKeyQueryRecord) |

**Retourne :** `array<string>` - Liste des chemins à scanner pour répondre à la requête

**Exemple :**
```php
// Pour une recherche de logs sur une plage horaire
$query = new TemporalLogQueryRecord(
    from: new DateTimeVO('2026-01-15T10:00:00+00:00'),
    to: new DateTimeVO('2026-01-15T14:00:00+00:00'),
);

$filesToScan = $service->getFilesToScan($query);
echo "Files to scan: " . count($filesToScan); // 24 fichiers

foreach ($filesToScan as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        // Traitement...
    }
}
```

---

### `setPathStrategy(JsonlPathStrategyInterface $pathStrategy): void`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$pathStrategy` | `JsonlPathStrategyInterface` | La nouvelle stratégie de chemin à utiliser |

**Retourne :** `void`

**Exemple :**
```php
// Changer dynamiquement de stratégie
$service = new JsonlService($temporalStrategy, $fileSystem);

// Passer du mode logs (temporel) au mode cache (par clé)
$service->setPathStrategy($keyBasedStrategy);

$cacheRecord = new CacheJsonlRecord(key: 'user_123', ...);
$filePath = $service->getFilePath($cacheRecord);
// Maintenant utilise l'organisation par hash
```

---

### `isExpired(CacheJsonlRecord $record): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `CacheJsonlRecord` | L'enregistrement de cache à vérifier |

**Retourne :** `bool` - True si l'enregistrement est expiré (expires_at < maintenant)

**Exemple :**
```php
$cachedValue = $service->readAll('/cache/a/b/user_123.jsonl');

if (!empty($cachedValue)) {
    $record = CacheJsonlRecord::fromArray($cachedValue[0]);
    
    if ($service->isExpired($record)) {
        echo "Cache expired, need to refresh";
        // Recalculer la valeur
    } else {
        echo "Cache valid: " . $record->value;
    }
}
```

---

### `decodeCacheValue(string $encodedValue, string $typeString): StrictDataObject`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$encodedValue` | `string` | Valeur JSON encodée |
| `$typeString` | `string` | Type original (non utilisé, conservé pour compatibilité API) |

**Retourne :** `StrictDataObject` - Données décodées

**Exemple :**
```php
$cachedData = $service->readAll('/cache/a/b/user_123.jsonl');
$decoded = $service->decodeCacheValue($cachedData[0]['value'], $cachedData[0]['value_type']);

echo $decoded->name; // 'John'
echo $decoded->email; // 'john@example.com'
```

## Cas d'utilisation

### Cas 1 : Journalisation d'événements utilisateur

```php
$strategy = new TemporalPathStrategy('/var/logs');
$service = new JsonlService($strategy, $fileSystem);

$log = new LogJsonlRecord(
    time: new DateTimeVO(),
    level: 'info',
    type: 'user_login',
    payload: new StrictDataObject(['user_id' => 12345]),
);

$service->write($log);
```

### Cas 2 : Cache avec expiration

```php
$strategy = new KeyBasedPathStrategy('/cache', 2);
$service = new JsonlService($strategy, $fileSystem);

$cache = new CacheJsonlRecord(
    key: 'user_123',
    value: json_encode(['name' => 'John']),
    value_type: PhpType::STRING,
    expires_at: new DateTimeVO('+1 hour'),
);

$service->write($cache);
```

### Cas 3 : Nettoyage des entrées expirées

```php
$deleted = $service->cleanExpired('/cache', function ($line) {
    if (!isset($line['expires_at'])) {
        return false;
    }
    $expiresAt = new DateTimeVO($line['expires_at']);
    return $expiresAt->isBefore(new DateTimeVO());
});
```

### Cas 4 : Écriture bufferisée haute performance

```php
$service->enableBuffer(100);
$service->onFlush(function ($path, $count) {
    echo "Written $count lines to $path\n";
});

for ($i = 0; $i < 1000; $i++) {
    $service->writeBuffered($record);
}

$service->flushBuffer();
```

### Cas 5 : Nettoyage sécurisé avec dry run

```php
// Vérifier d'abord quels fichiers seront supprimés
$filesToDelete = $service->dryRun('/var/logs', function ($file) {
    return filemtime($file) < strtotime('-90 days');
});

if (count($filesToDelete) > 0) {
    echo "WARNING: About to delete " . count($filesToDelete) . " files\n";
    
    // Confirmation manuelle
    $confirm = readline("Proceed with deletion? (y/n): ");
    if ($confirm === 'y') {
        $deleted = $service->cleanOlderThan(90, '/var/logs');
        echo "Deleted {$deleted} files\n";
    }
}
```

### Cas 6 : Opération atomique avec verrouillage automatique

```php
// Lecture + écriture atomique
$result = $service->executeWithLock('/shared/data.jsonl', function () use ($service) {
    $existing = $service->readAll('/shared/data.jsonl');
    $newData = ['timestamp' => time(), 'value' => rand(1, 100)];
    $existing[] = $newData;
    
    // Réécriture complète
    $content = '';
    foreach ($existing as $item) {
        $content .= json_encode($item) . "\n";
    }
    file_put_contents('/shared/data.jsonl', $content);
    
    return count($existing);
});

echo "Total entries after atomic operation: {$result}";
```

## Gestion des erreurs

| Situation | Exception | Message |
|-----------|-----------|---------|
| Type d'entité non supporté | `JsonlException` | `Unsupported record type: {class}` |
| Échec d'encodage JSON | `JsonlException` | `Failed to encode JSON: {error}` |
| Fichier inexistant en lecture | `JsonlException` | `File does not exist: {path}` |
| Timeout d'acquisition de verrou | `JsonlLockException` | `Timeout acquiring lock for: {path}` |
| Erreur lors de l'écriture | `JsonlException` | Message de l'exception originale |

## Intégration

`JsonlService` est le point d'entrée principal du package. Il nécessite :

- Une `JsonlPathStrategyInterface` (ex: `TemporalPathStrategy` ou `KeyBasedPathStrategy`)
- Une `FileSystemInterface` (ex: `FileSystemService` de `php-services`)

```php
$strategy = new TemporalPathStrategy('/logs');
$fileSystem = new FileSystemService();
$service = new JsonlService($strategy, $fileSystem);
```

## Performance

| Opération | Complexité | Notes |
|-----------|------------|-------|
| `write()` | O(1) | Plus verrouillage fichier |
| `writeBatch()` | O(n) | n = nombre d'entités |
| `writeBuffered()` | O(1) | Par écriture, flush à taille atteinte |
| `readAll()` | O(l) | l = nombre de lignes |
| `search()` | O(l) | Parcourt toutes les lignes |
| `searchMultiple()` | O(f × l) | f = fichiers, l = lignes par fichier |
| `cleanOlderThan()` | O(f) | f = fichiers trouvés par glob |
| `cleanExpired()` | O(f × l) | f = fichiers, l = lignes par fichier |
| `cleanByPattern()` | O(f) | f = fichiers correspondant au pattern |
| `clear()` | O(f) | f = tous les fichiers JSONL |
| `dryRun()` | O(f) | f = fichiers trouvés par glob |
| `getFirstLine()` / `getLastLine()` | O(1) | Lecture partielle du fichier |
| `acquire()` / `release()` | O(1) | Verrouillage fichier |
| `executeWithLock()` | O(1) + callback | Gère automatiquement lock/unlock |
| `getFilePath()` | O(1) | Délégation à la stratégie |
| `getFilesToScan()` | O(jours × 24) | Pour stratégie temporelle |
| `isExpired()` | O(1) | Comparaison de dates |

**Optimisations :**
- Buffer d'écriture pour réduire les I/O
- Verrouillage (`flock`) pour la concurrence
- Streaming ligne par ligne pour les gros fichiers
- Pattern glob pour les recherches rapides

## Compatibilité

| Version PHP | Support | Notes |
|-------------|---------|-------|
| PHP 8.2+ | ✅ Complet | readonly properties, types stricts |
| PHP 8.1 | ✅ Complet | Enums, readonly properties |

**Dépendances :**
- `php-services` (FileSystemInterface)
- `php-vo` (DateTimeVO, AbstractValueObject)
- `domain-structures` (AbstractRecord, StrictDataObject)

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\DomainStructures\Enums\PhpType;
use AndyDefer\DomainStructures\Structures\StrictDataObject;
use AndyDefer\LaravelJsonl\JsonlService;
use AndyDefer\LaravelJsonl\Records\CacheJsonlRecord;
use AndyDefer\LaravelJsonl\Records\LogJsonlRecord;
use AndyDefer\LaravelJsonl\Strategies\KeyBasedPathStrategy;
use AndyDefer\LaravelJsonl\Strategies\TemporalPathStrategy;
use AndyDefer\PhpServices\Services\FileSystemService;
use AndyDefer\PhpVo\ValueObjects\DateTimeVO;

// 1. Initialisation
$logStrategy = new TemporalPathStrategy('/var/app/logs');
$cacheStrategy = new KeyBasedPathStrategy('/var/app/cache', 2);
$fs = new FileSystemService();

$logService = new JsonlService($logStrategy, $fs);
$cacheService = new JsonlService($cacheStrategy, $fs);

// 2. Écrire des logs
$logService->write(new LogJsonlRecord(
    time: new DateTimeVO(),
    level: 'info',
    type: 'user_action',
    payload: new StrictDataObject(['user_id' => 123, 'action' => 'login']),
));

// 3. Lire des logs
$lastLine = $logService->getLastLine('/var/app/logs/2026-01-15/14.jsonl');
echo "Last log: " . json_encode($lastLine);

// 4. Mettre en cache avec expiration
$cacheService->write(new CacheJsonlRecord(
    key: 'user_profile_123',
    value: json_encode(['name' => 'John', 'email' => 'john@example.com']),
    value_type: PhpType::STRING,
    expires_at: new DateTimeVO('+1 hour'),
));

// 5. Vérifier l'expiration
$cachedData = $cacheService->readAll('/var/app/cache/a/b/user_profile_123.jsonl');
if (!empty($cachedData)) {
    $record = CacheJsonlRecord::fromArray($cachedData[0]);
    if ($cacheService->isExpired($record)) {
        echo "Cache expired, refreshing...";
        // Recalculer...
    }
}

// 6. Décoder une valeur de cache
$decoded = $cacheService->decodeCacheValue($cachedData[0]['value'], $cachedData[0]['value_type']);
echo $decoded->name; // 'John'

// 7. Nettoyage des vieux logs (simulation d'abord)
$toDelete = $logService->dryRun('/var/app/logs', function ($file) {
    return filemtime($file) < strtotime('-30 days');
});
echo "Would delete " . count($toDelete) . " old log files";

// 8. Suppression réelle des vieux logs
$deleted = $logService->cleanOlderThan(30, '/var/app/logs');
echo "Deleted {$deleted} old log files";

// 9. Suppression par pattern
$deleted = $logService->cleanByPattern('/var/app/logs/2026-01-15/*.jsonl');
echo "Deleted {$deleted} files from January 15";

// 10. Vider complètement le cache
$cleared = $cacheService->clear('/var/app/cache');
echo "Cleared {$cleared} cache files";

// 11. Opération atomique sur fichier partagé
$count = $logService->executeWithLock('/var/app/logs/shared.jsonl', function () use ($logService) {
    $lines = $logService->readAll('/var/app/logs/shared.jsonl');
    return count($lines);
});
echo "Shared log has {$count} entries";

// 12. Écriture bufferisée haute performance
$logService->enableBuffer(100);
$logService->onFlush(function ($path, $count) {
    echo "Flushed {$count} lines to {$path}\n";
});

for ($i = 0; $i < 1000; $i++) {
    $logService->writeBuffered(new LogJsonlRecord(/* ... */));
}
$logService->flushBuffer();
```

## Voir aussi

- `TemporalPathStrategy` - Stratégie pour logs (organisation par date/heure)
- `KeyBasedPathStrategy` - Stratégie pour cache (organisation par hash)
- `JsonlProcessingContext` - Suivi d'état des opérations
- `FileSystemInterface` - Abstraction des opérations fichier
- `JsonlLockVO` - Value object représentant un verrou
- `JsonlException` - Exception de base du package
- `JsonlLockException` - Exception spécifique aux verrous
---