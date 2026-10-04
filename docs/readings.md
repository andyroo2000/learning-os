# Private readings

Readings are imported by a trusted operator into one existing account. There is
no browser upload or editing endpoint. First-party session authentication and
owner checks protect the document, illustrations, and sentence audio; `viewAs`
is prohibited.

```sh
php artisan readings:import /private/path/book.json \
  --user=owner@example.com --slug=stable-book-name \
  --illustration=/private/path/cover.jpg
```

The same owner and slug update the existing book. JSON `imageFile` values are
trusted local paths read by the operator command. Keep the source JSON and
photos outside source control and public web directories. Files are copied
into the `local` private disk, under `readings/<id>/`; production persists that
disk through the existing `storage/app` volume. Content-addressed old images
and audio remain after re-import, so operators can recover previous content;
the command does not automatically prune them.

Pages carry their printed page number, kind, and exact vertical columns. Each
column contains `[sentenceIndex, rubyText]` pairs. Ruby uses the existing
`漢字[かんじ]` notation. Sentences carry display `text`, English `translation`,
and optional `speechText` pronunciation overrides. Page numbers are distinct,
pages and sentences are lists, and every segment must reference a sentence.

Audio uses Sato and is cached by voice and spoken text. Changing `speechText`
invalidates that sentence's cache without changing the printed text. The
generation quota counts provider attempts, including failures, to bound
retries and provider load. Cached playback does not consume this quota.

This initial library is designed for a small, operator-curated private
collection. Listing currently reads each document to derive its summary;
larger collections should add summary columns and pagination before opening
imports to a broader audience.
