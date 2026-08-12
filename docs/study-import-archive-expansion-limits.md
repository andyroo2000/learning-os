# Study import archive expansion limits

Study import uploads are ZIP archives. The upload byte limit constrains the compressed object, while the reader also needs independent limits for entries it decompresses and hashes.

The default limits were chosen against a local August 2026 corpus of six real Anki exports without committing those private archives:

| Measurement | Largest observed | Default limit |
| --- | ---: | ---: |
| Collection database | 1,519,616 bytes | 268,435,456 bytes |
| Media manifest | 367,984 bytes | 16,777,216 bytes |
| Individual media entry | 8,087,550 bytes | 104,857,600 bytes |
| Total expanded `.colpkg` content | 1,125,626,326 bytes | 4,294,967,296 media bytes |
| ZIP entries | 8,474 | Constrained indirectly by the manifest and media-byte budgets |

The corpus included one 1.13 GB `.colpkg` and five smaller Anki package exports. The defaults deliberately leave substantial compatibility headroom while bounding temporary disk use and the work performed by the 512 MB import worker.

The reader checks the ZIP central-directory size before opening an entry. It then reads at most the declared size plus one byte and rejects entries whose streamed size disagrees, so forged metadata cannot bypass the expansion limits. Individual oversized media is skipped without decompression or hashing; oversized collection databases, manifests, and cumulative hash-eligible media fail the import with an explicit preview error. Preview and processing both use the same archive reader and therefore apply the same policy.

Operators can override the defaults with:

- `STUDY_IMPORT_MAX_COLLECTION_DATABASE_BYTES`
- `STUDY_IMPORT_MAX_MEDIA_MANIFEST_BYTES`
- `STUDY_IMPORT_MAX_INDIVIDUAL_MEDIA_BYTES`
- `STUDY_IMPORT_MAX_TOTAL_MEDIA_BYTES`

All configured values must be positive integers. Changes should be evaluated against real export sizes, available temporary disk, worker memory, and processing time.
