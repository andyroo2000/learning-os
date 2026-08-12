<?php

return [
    'archive_expansion' => [
        // The largest locally observed collection database is 1.52 MB. Keep ample headroom for large decks.
        'max_collection_database_bytes' => env('STUDY_IMPORT_MAX_COLLECTION_DATABASE_BYTES', 256 * 1024 * 1024),

        // The largest locally observed manifest is 368 KB across 8,474 archive entries.
        'max_media_manifest_bytes' => env('STUDY_IMPORT_MAX_MEDIA_MANIFEST_BYTES', 16 * 1024 * 1024),

        // The largest locally observed media entry is 8.09 MB.
        'max_individual_media_bytes' => env('STUDY_IMPORT_MAX_INDIVIDUAL_MEDIA_BYTES', 100 * 1024 * 1024),

        // The largest locally observed .colpkg expands to 1.13 GB of media. This budget applies only
        // to media referenced by the selected deck; unrelated package entries are never expanded.
        'max_total_media_bytes' => env('STUDY_IMPORT_MAX_TOTAL_MEDIA_BYTES', 4 * 1024 * 1024 * 1024),
    ],
];
