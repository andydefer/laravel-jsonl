<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Chemin de base pour les fichiers JSONL
    |--------------------------------------------------------------------------
    */
    'base_path' => env('JSONL_BASE_PATH', storage_path('jsonl')),

    /*
    |--------------------------------------------------------------------------
    | Taille du buffer d'écriture
    |--------------------------------------------------------------------------
    | Nombre de lignes à accumuler avant écriture disque.
    | Laisser null pour désactiver le buffer.
    */
    'buffer_size' => env('JSONL_BUFFER_SIZE', null),

    /*
    |--------------------------------------------------------------------------
    | Permissions des dossiers
    |--------------------------------------------------------------------------
    | 755 = directory, 775 = directory_group, 750 = directory_restricted, 700 = directory_private
    */
    'directory_permission' => (int) (env('JSONL_DIRECTORY_PERMISSION', 755)),
];
