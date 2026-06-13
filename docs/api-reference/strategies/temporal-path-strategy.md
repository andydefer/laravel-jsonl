# TemporalPathStrategy - Référence Technique

## Description

Organise les fichiers de logs JSONL par date et heure, en créant une arborescence hiérarchique : année/mois/jour/heure.

## Hiérarchie

```
JsonlPathStrategyInterface
    └── TemporalPathStrategy
```

## Rôle principal

Permet une recherche efficace des logs par plage temporelle en regroupant les entrées par heure dans des fichiers distincts. Chaque heure produit un fichier JSONL contenant toutes les entrées de log pour cette période.

## Détails

[Voir la classe TemporalPathStrategy](https://github.com/andydefer/laravel-jsonl/blob/main/src/Strategies/TemporalPathStrategy.php)

## API / Méthodes publiques

### `getFilePath(AbstractRecord $entity): string`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$entity` | `AbstractRecord` | L'entité à stocker (doit être un `LogJsonlRecord`) |

**Retourne :** `string` - Chemin absolu du fichier (ex: `/logs/2026-01-15/14.jsonl`)

**Exceptions :** `InvalidArgumentException` - Si l'entité n'est pas un `LogJsonlRecord`

**Exemple :**
```php
$strategy = new TemporalPathStrategy('/var/logs');
$record = new LogJsonlRecord(
    time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
    level: 'info',
    type: 'user_login',
    payload: new StrictDataObject(['user_id' => 123]),
);

$path = $strategy->getFilePath($record);
// Résultat: /var/logs/2026-01-15/14.jsonl
```

---

### `getFilesToScan(AbstractRecord $query): array`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$query` | `AbstractRecord` | La requête (doit être un `TemporalLogQueryRecord`) |

**Retourne :** `array<string>` - Liste de tous les chemins à scanner pour couvrir la plage demandée

**Exceptions :** `InvalidArgumentException` - Si la requête n'est pas un `TemporalLogQueryRecord`

**Exemple :**
```php
$strategy = new TemporalPathStrategy('/var/logs');
$query = new TemporalLogQueryRecord(
    from: new DateTimeVO('2026-01-15T10:00:00+00:00'),
    to: new DateTimeVO('2026-01-15T14:00:00+00:00'),
);

$files = $strategy->getFilesToScan($query);
// Résultat: Tous les fichiers de 00h à 23h du 15 janvier
// (car la stratégie scanne toutes les heures d'un jour, pas seulement la plage)
```

---

### `getBaseDirectory(): string`

| Paramètre | Type | Description |
|-----------|------|-------------|
| Aucun | - | - |

**Retourne :** `string` - Le répertoire racine configuré

**Exemple :**
```php
$strategy = new TemporalPathStrategy('/var/logs');
$baseDir = $strategy->getBaseDirectory();
// Résultat: '/var/logs'
```

## Cas d'utilisation

### Cas 1 : Logging d'événements utilisateur

```php
$strategy = new TemporalPathStrategy('/data/logs');
$now = new DateTimeVO();

$loginLog = new LogJsonlRecord(
    time: $now,
    level: 'info',
    type: 'user_login',
    payload: new StrictDataObject([
        'user_id' => 12345,
        'ip' => '192.168.1.100',
        'user_agent' => 'Mozilla/5.0...',
    ]),
);

$filePath = $strategy->getFilePath($loginLog);
// Résultat: /data/logs/2026-01-15/14.jsonl

// Tous les logs de la même heure (14h) iront dans le même fichier
```

### Cas 2 : Consultation des logs d'une journée

```php
$strategy = new TemporalPathStrategy('/data/logs');
$query = new TemporalLogQueryRecord(
    from: new DateTimeVO('2026-01-15T00:00:00+00:00'),
    to: new DateTimeVO('2026-01-15T23:59:59+00:00'),
    type: 'user_login',
);

$filesToScan = $strategy->getFilesToScan($query);
// Retourne 24 fichiers (00.jsonl à 23.jsonl)

foreach ($filesToScan as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        // Filtrer par type 'user_login'
    }
}
```

### Cas 3 : Nettoyage automatique des vieux logs

```php
$strategy = new TemporalPathStrategy('/data/logs');
$baseDir = $strategy->getBaseDirectory();

// Supprimer les logs de plus de 30 jours
$cutoffDate = (new DateTime())->modify('-30 days');

$directory = new RecursiveDirectoryIterator($baseDir);
$iterator = new RecursiveIteratorIterator($directory);
$regex = new RegexIterator($iterator, '/\d{4}-\d{2}-\d{2}/');

foreach ($regex as $file) {
    $dateStr = basename(dirname($file->getPathname()));
    $fileDate = DateTime::createFromFormat('Y-m-d', $dateStr);
    
    if ($fileDate < $cutoffDate) {
        unlink($file->getPathname()); // Supprimer le fichier
    }
}
```

### Cas 4 : Export des logs pour analyse

```php
$strategy = new TemporalPathStrategy('/data/logs');
$query = new TemporalLogQueryRecord(
    from: new DateTimeVO('2026-01-01T00:00:00+00:00'),
    to: new DateTimeVO('2026-01-31T23:59:59+00:00'),
);

$filesToExport = $strategy->getFilesToScan($query);
// Retourne 31 × 24 = 744 fichiers

$allLogs = [];
foreach ($filesToExport as $file) {
    if (file_exists($file)) {
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        foreach ($lines as $line) {
            $allLogs[] = json_decode($line, true);
        }
    }
}

// Sauvegarder pour analyse externe
file_put_contents('logs_january.json', json_encode($allLogs));
```

## Gestion des erreurs

| Situation | Exception | Message |
|-----------|-----------|---------|
| L'entité n'est pas un `LogJsonlRecord` | `InvalidArgumentException` | `TemporalPathStrategy expects LogJsonlRecord, got {class_name}` |
| La requête n'est pas un `TemporalLogQueryRecord` | `InvalidArgumentException` | `TemporalPathStrategy expects TemporalLogQuery, got {class_name}` |

## Intégration

Cette stratégie est conçue pour être utilisée avec `JsonlService`. Elle est typiquement injectée lorsque le service est utilisé pour les logs :

```php
$logPathStrategy = new TemporalPathStrategy('/var/logs/structured');
$logService = new JsonlService($logPathStrategy, $fileSystem);
$logService->write($logRecord);

// Pour requêter
$query = new TemporalLogQueryRecord($from, $to);
$files = $logPathStrategy->getFilesToScan($query);
```

## Performance

| Opération | Complexité | Explication |
|-----------|------------|-------------|
| `getFilePath()` | O(1) | Simple concaténation de chaînes |
| `getFilesToScan()` | O(jours × 24) | Génère tous les fichiers horaires pour chaque jour |
| Accès aux logs | O(n) | Dépend du nombre de jours dans la plage |

**Optimisations :**
- Les logs sont regroupés par heure → moins de fichiers ouverts
- Le pattern est prévisible → permet du prefetching
- Pas d'index supplémentaire nécessaire

**Inconvénients :**
- `getFilesToScan()` peut retourner des centaines de fichiers pour une large plage
- Impossibilité de requêter par type ou niveau sans scanner tous les fichiers

## Compatibilité

| Version PHP | Support | Notes |
|-------------|---------|-------|
| PHP 8.2+ | ✅ Complet | Type hints et readonly properties |
| PHP 8.1 | ✅ Complet | Support des enums |

**Systèmes d'exploitation :** ✅ Linux, ✅ macOS, ✅ Windows (avec `DIRECTORY_SEPARATOR`)

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\DomainStructures\Structures\StrictDataObject;
use AndyDefer\LaravelJsonl\Records\LogJsonlRecord;
use AndyDefer\LaravelJsonl\Records\TemporalLogQueryRecord;
use AndyDefer\LaravelJsonl\Strategies\TemporalPathStrategy;
use AndyDefer\PhpServices\Services\FileSystemService;
use AndyDefer\PhpVo\ValueObjects\DateTimeVO;

// Configuration
$logDir = '/var/app/logs';
$strategy = new TemporalPathStrategy($logDir);
$fs = new FileSystemService();

// Écriture d'un log
$logRecord = new LogJsonlRecord(
    time: new DateTimeVO('2026-01-15T14:35:00+00:00'),
    level: 'info',
    type: 'payment_success',
    payload: new StrictDataObject([
        'user_id' => 12345,
        'amount' => 99.99,
        'currency' => 'EUR',
    ]),
);

$filePath = $strategy->getFilePath($logRecord);
$fs->ensureDirectoryExists(dirname($filePath));
$fs->append($filePath, json_encode([
    'time' => $logRecord->time->getValue(),
    'level' => $logRecord->level,
    'type' => $logRecord->type,
    'payload' => $logRecord->payload->toArray(),
]) . "\n");

// Recherche des logs
$query = new TemporalLogQueryRecord(
    from: new DateTimeVO('2026-01-15T00:00:00+00:00'),
    to: new DateTimeVO('2026-01-15T23:59:59+00:00'),
    type: 'payment_success',
);

$filesToScan = $strategy->getFilesToScan($query);
echo "Fichiers à scanner: " . count($filesToScan) . "\n";
// Affiche: 24

foreach ($filesToScan as $file) {
    if ($fs->exists($file)) {
        $content = $fs->get($file);
        $lines = explode("\n", trim($content));
        foreach ($lines as $line) {
            $data = json_decode($line, true);
            if ($data['type'] === 'payment_success') {
                echo "Payment: " . $data['payload']['amount'] . " " . $data['payload']['currency'] . "\n";
            }
        }
    }
}

echo "Base directory: " . $strategy->getBaseDirectory() . "\n";
// Affiche: /var/app/logs
```

## Voir aussi

- `JsonlService` - Service principal de stockage JSONL
- `KeyBasedPathStrategy` - Stratégie alternative pour cache (organisation par hash)
- `LogJsonlRecord` - Structure de données pour les logs
- `TemporalLogQueryRecord` - Requête pour rechercher par plage de dates
---