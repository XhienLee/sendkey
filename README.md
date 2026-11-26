# Sendkey

Send a file through a link that works once. Your browser locks the file first, so the server only ever holds scrambled data, and it deletes the file after one download or when the link expires.

Part of the key family, small private tools with one key each and no accounts, next to [Sealkey](https://github.com/XhienLee/sealkey) and [Stowkey](https://github.com/XhienLee/stowkey).

## How it works

1. The sender picks a file (up to 25 MB). The browser makes 6 random words from Sealkey's word list and locks the file with them: Argon2id (3 passes, 64 MiB) into XChaCha20-Poly1305 in 1 MiB pieces. The file name and size are locked inside.
2. The locked file is uploaded and gets an unguessable 128-bit address. The link is `…/?s=<address>`.
3. **Key inside the link:** the 6 words go after a `#`, which browsers never send to a server. **Key sent apart:** the sender shows the words to send another way. Sending them apart is safer.
4. The receiver's browser first reads only the start of the file to check the key. That does not use up the link, so a mistyped key costs nothing. Downloading then claims the file once, deletes it from the server and opens it in the browser.
5. Links expire after the sender's choice of 1 hour, 1 day or 7 days.

## Run it

It needs PHP 8.1+ with `pdo_sqlite`. Try it on your computer:

```bash
FAMILY_DATA_DIR=/tmp/sendkey-data php -d post_max_size=40M -S 127.0.0.1:8800 router.php
```

Then open <http://127.0.0.1:8800>. `router.php` only adds the Content-Security-Policy that `_headers` gives on Netlify (Netlify can't run PHP). For real hosting use Apache or nginx with PHP-FPM and HTTPS. Allow request bodies of about 26 MB (`post_max_size`, `client_max_body_size`), and keep `FAMILY_DATA_DIR` **outside the web root** (the API refuses to run otherwise).

| Setting (environment variable) | Default | Meaning |
|---|---|---|
| `FAMILY_DATA_DIR` | `family_data` next to the repo folder | Database and stored files |
| `FAMILY_MIN_FREE_BYTES` | 1 GiB | Uploads stop when free disk falls below this |
| `SENDKEY_PER_IP_HOUR` | 20 | Links one address can make per hour |
| `SENDKEY_GLOBAL_HOUR` | 500 | Links the whole site can make per hour |

## What it cannot protect

- With the key inside the link, anyone who gets the whole link can open the file, and it is visible to anything that reads the message it travels in.
- The server sees the locked file's size and when it was uploaded and downloaded. It keeps a salted hash of your network address for an hour to limit abuse, and nothing else about you.
- It can't protect you from a hacked device, a changed copy of this website, or a sender who sends the wrong person the link.
- If the first person to open the link isn't the receiver, the receiver finds it used up.

## Tests

```bash
node --test tests/sendkey.test.mjs
php tests/api.php
pip install -r tests/requirements.txt && python3 -m playwright install chromium
python3 tests/e2e.py
```

They check the crypto, every server rule (refused uploads, one-time use, expiry, limits), and the whole flow in Chromium, including that the file's text and the key never reach the server.

## Credits

The word list, file format and crypto in `js/` come from [Sealkey](https://github.com/XhienLee/sealkey), and the encryption library is [libsodium](https://github.com/jedisct1/libsodium.js) in `vendor/`. MIT licensed.
