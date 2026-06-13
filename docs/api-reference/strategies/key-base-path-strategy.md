# KeyBasedPathStrategy - Référence Technique

## Description

Organise les fichiers de cache JSONL en utilisant un hachage MD5 des clés pour répartir les fichiers dans une arborescence de sous-dossiers.

## Hiérarchie

```
JsonlPathStrategyInterface
    └── KeyBasedPathStrategy
```

## Rôle principal

Évite la création de trop nombreux fichiers dans un seul répertoire en divisant le hash MD5 d'une clé en plusieurs niveaux de sous-dossiers. Chaque niveau est un caractère hexadécimal (0-9, a-f).

## Détails

[Voir la classe KeyBasedPathStrategy](https://github.com/andydefer/laravel-jsonl/blob/main/src/Strategies/KeyBasedPathStrategy.php)

## API / Méthodes publiques

### `getFilePath(AbstractRecord $entity): string`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$entity` | `AbstractRecord` | L'entité à stocker (doit être un `CacheJsonlRecord`) |

**Retourne :** `string` - Chemin absolu du fichier

**Exceptions :** `InvalidArgumentException` - Si l'entité n'est pas un `CacheJsonlRecord`

**Exemple :**
```php
$strategy = new KeyBasedPathStrategy('/var/cache', 2);
$record = new CacheJsonlRecord(key: 'user_123', value: '...');

$path = $strategy->getFilePath($record);
// Résultat: /var/cache/a/b/user_123.jsonl
```

---

### `getFilesToScan(AbstractRecord $query): array`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$query` | `AbstractRecord` | La requête (doit être un `CacheKeyQueryRecord`) |

**Retourne :** `array<string>` - Liste des chemins à scanner (toujours un seul fichier)

**Exceptions :** `InvalidArgumentException` - Si la requête n'est pas un `CacheKeyQueryRecord`

**Exemple :**
```php
$strategy = new KeyBasedPathStrategy('/var/cache', 2);
$query = new CacheKeyQueryRecord(key: 'user_123');

$files = $strategy->getFilesToScan($query);
// Résultat: ['/var/cache/a/b/user_123.jsonl']
```

---

### `getBaseDirectory(): string`

| Paramètre | Type | Description |
|-----------|------|-------------|
| Aucun | - | - |

**Retourne :** `string` - Le répertoire racine configuré

**Exemple :**
```php
$strategy = new KeyBasedPathStrategy('/var/cache', 2);
$baseDir = $strategy->getBaseDirectory();
// Résultat: '/var/cache'
```

## Cas d'utilisation

### Cas 1 : Cache utilisateur avec répartition par hash

```php
$strategy = new KeyBasedPathStrategy('/data/cache', 2);

$userRecord = new CacheJsonlRecord(
    key: 'user_profile_12345',
    value: json_encode(['name' => 'John', 'email' => 'john@example.com']),
    value_type: PhpType::STRING,
    expires_at: null,
);

$filePath = $strategy->getFilePath($userRecord);
// Résultat: /data/cache/3/e/user_profile_12345.jsonl

// La stratégie garantit que:
// - Tous les fichiers sont distribués uniformément
// - Maximum 256 dossiers par niveau (16² = 256 combinaisons)
// - Pas de ralentissement lié à un dossier trop volumineux
```

### Cas 2 : Recherche rapide par clé

```php
$strategy = new KeyBasedPathStrategy('/data/cache', 2);
$query = new CacheKeyQueryRecord(key: 'user_profile_12345');

$filesToScan = $strategy->getFilesToScan($query);
// Un seul fichier à lire, pas de scanning de dossiers entiers

if (file_exists($filesToScan[0])) {
    $content = file_get_contents($filesToScan[0]);
    // Traitement du cache
}
```

### Cas 3 : Configuration avec 4 niveaux de hash

```php
// Pour des volumes très importants (> 1 million de clés)
$strategy = new KeyBasedPathStrategy('/data/cache', 4);

$record = new CacheJsonlRecord(
    key: 'session_xyz789',
    value: json_encode(['data' => 'session value']),
    value_type: PhpType::STRING,
    expires_at: null,
);

$filePath = $strategy->getFilePath($record);
// Résultat: /data/cache/3/8/4/2/session_xyz789.jsonl
// 4 niveaux = 16^4 = 65,536 dossiers possibles
```

## Gestion des erreurs

| Situation | Exception | Message |
|-----------|-----------|---------|
| L'entité n'est pas un `CacheJsonlRecord` | `InvalidArgumentException` | `KeyBasedPathStrategy expects CacheJsonlRecord, got {class_name}` |
| La requête n'est pas un `CacheKeyQueryRecord` | `InvalidArgumentException` | `KeyBasedPathStrategy expects CacheKeyQuery, got {class_name}` |

## Intégration

Cette stratégie est conçue pour être utilisée avec `JsonlService`. Elle est typiquement injectée lorsque le service est utilisé pour le cache :

```php
$cachePathStrategy = new KeyBasedPathStrategy('/var/cache', 2);
$cacheService = new JsonlService($cachePathStrategy, $fileSystem);
$cacheService->write($cacheRecord);
```

## Performance

| Opération | Complexité | Explication |
|-----------|------------|-------------|
| `getFilePath()` | O(1) | Calcule MD5 (rapide) + concaténation |
| `getFilesToScan()` | O(1) | Génère un seul chemin |
| Distribution des fichiers | O(1) | Le hash MD5 distribue uniformément |

**Avantages :**
- Pas de scanning de dossiers
- Accès direct à chaque fichier par sa clé
- Distribution naturelle et équilibrée

**Inconvénients :**
- Calcul MD5 à chaque opération (négligeable)
- Impossibilité de rechercher par plage ou pattern

## Compatibilité

| Version PHP | Support | Notes |
|-------------|---------|-------|
| PHP 8.2+ | ✅ Complet | Type hints et readonly properties |
| PHP 8.1 | ✅ Complet | Support des enums et readonly |

**Systèmes d'exploitation :** ✅ Linux, ✅ macOS, ✅ Windows (avec `DIRECTORY_SEPARATOR`)

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\DomainStructures\Enums\PhpType;
use AndyDefer\DomainStructures\Structures\StrictDataObject;
use AndyDefer\LaravelJsonl\Records\CacheJsonlRecord;
use AndyDefer\LaravelJsonl\Records\CacheKeyQueryRecord;
use AndyDefer\LaravelJsonl\Strategies\KeyBasedPathStrategy;
use AndyDefer\PhpServices\Services\FileSystemService;

// Configuration
$cacheDir = '/var/app/cache';
$strategy = new KeyBasedPathStrategy($cacheDir, 2);
$fs = new FileSystemService();

// Écriture d'un cache
$userData = new StrictDataObject(['id' => 123, 'name' => 'John']);
$cacheRecord = new CacheJsonlRecord(
    key: 'user_123',
    value: json_encode($userData->toArray()),
    value_type: PhpType::STRING,
    expires_at: null,
);

$filePath = $strategy->getFilePath($cacheRecord);
$fs->ensureDirectoryExists(dirname($filePath));
$fs->put($filePath, json_encode([
    'key' => $cacheRecord->key,
    'value' => $cacheRecord->value,
    'value_type' => $cacheRecord->value_type->value,
    'expires_at' => $cacheRecord->expires_at?->getValue(),
]) . "\n");

// Lecture du cache
$query = new CacheKeyQueryRecord(key: 'user_123');
$filesToRead = $strategy->getFilesToScan($query);

if ($fs->exists($filesToRead[0])) {
    $content = $fs->get($filesToRead[0]);
    $data = json_decode($content, true);
    echo "Cache found for key: " . $data['key'] . "\n";
}

echo "Base directory: " . $strategy->getBaseDirectory() . "\n";
// Affiche: /var/app/cache
```

## Voir aussi

- `JsonlService` - Service principal de stockage JSONL
- `TemporalPathStrategy` - Stratégie alternative pour logs (organisation par date/heure)
- `CacheJsonlRecord` - Structure de données pour le cache
- `CacheKeyQueryRecord` - Requête pour rechercher par clé
---