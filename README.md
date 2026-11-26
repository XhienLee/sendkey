# Sendkey

**Send a file through a link that works only once.**

Pick a file, get a link, send it to someone. When they download it, it is deleted from the server. Your browser locks the file before it leaves your computer, so the server only ever holds scrambled data it cannot read.

No account. No app to install. Works on phone and computer.

> **No ready-made website yet.** This is the code for the tool. To use it you (or someone you trust) run it on a web host that supports PHP. Once it is running, anyone can use it from a browser with nothing to install. If you are not technical, ask a friend or a hosting company to set it up, or follow "Run it" below. Running it on your own computer takes one command.

## What is it good for?

- Sending an ID scan, a contract or a password file to one person
- Sharing something you do not want sitting in an email inbox forever
- Handing over a file where "gone after one download" is the whole point

## How to use it

**Sending**
1. Open the site and choose a file (up to 25 MB).
2. Pick how long the link lives: 1 hour, 1 day or 7 days.
3. Choose how the key travels: **inside the link** (easiest) or **sent apart** (safest, you send the 6 words by a different route, like a text message).
4. Copy the link and send it.

**Receiving**
1. Open the link. If the key was sent apart, type the 6 words.
2. Press download. The file opens in your browser and is deleted from the server.

A mistyped key does not use up the link, so you can try again.

## Is it safe? What can it not do?

- **The server cannot read your file.** It is locked in your browser with 6 random words before upload. The server never sees the file, its name or the key.
- **One person, one time.** If someone else opens the link first, the real receiver finds it used up. Send links over a channel you trust.
- Anyone who has the *whole* link can open the file (when the key is inside it). Sending the key apart is safer.
- The server can see roughly how big the file is and when it was uploaded and downloaded. It keeps a scrambled version of your network address for an hour to stop abuse, nothing else about you.
- It cannot protect you from a hacked phone or computer, or from a fake copy of the website. Only use a site you trust.

## Questions

**Can the person running the site read my file?** No. They hold scrambled data and do not have the key.
**What if I lose the link?** The file is gone for good once it expires or is downloaded. Just upload it again.
**Do I need an account?** No.

---

## For developers

### How it works

1. The browser makes 6 random words from Sealkey's 7,772-word list (about 77 bits) and locks the file with Argon2id (3 passes, 64 MiB) into XChaCha20-Poly1305 in 1 MiB pieces. The file name and size are locked inside.
2. The locked blob is uploaded and gets an unguessable 128-bit id. The link is `…/?s=<id>`.
3. Key inside the link: the 6 words go after `#k=`, which browsers never send to a server. Key sent apart: the sender shows the words to pass on separately.
4. The receiver's browser first reads only the first bytes (`head`) to check the key. That does not use up the link. Downloading (`get`) claims the file once, deletes it, and decrypts in the browser.
5. Links expire after 1 hour, 1 day or 7 days.

## Run it

Needs PHP 8.1+ with `pdo_sqlite` and `sodium`. On your own computer:

```bash
FAMILY_DATA_DIR=/tmp/sendkey-data php -d post_max_size=40M -S 127.0.0.1:8800 router.php
```

Open <http://127.0.0.1:8800>. `router.php` only adds the same security headers that `_headers` gives on Netlify (Netlify can't run PHP).

For real hosting use Apache or nginx with PHP-FPM and **HTTPS**. Keep `FAMILY_DATA_DIR` **outside the web root** (the API refuses to run otherwise), and allow request bodies of about 26 MB (`post_max_size`, `client_max_body_size`).

| Setting (environment variable) | Default | Meaning |
|---|---|---|
| `FAMILY_DATA_DIR` | `family_data` next to the repo folder | Database and stored files |
| `FAMILY_MIN_FREE_BYTES` | 1 GiB | Uploads stop when free disk falls below this |
| `SENDKEY_PER_IP_HOUR` | 20 | Links one address can make per hour |
| `SENDKEY_GLOBAL_HOUR` | 500 | Links the whole site can make per hour |

## Tests

```bash
node --test tests/sendkey.test.mjs
php tests/api.php
pip install -r tests/requirements.txt && python3 -m playwright install chromium
python3 tests/e2e.py
```

They check the crypto, every server rule (refused uploads, one-time use, expiry, limits) and the whole flow in Chromium, including that the file's text and the key never reach the server.

## The key family

Small private tools. One key each, no accounts, nothing to sign up for.

| Tool | What it does | Needs a server? |
|---|---|---|
| [Sealkey](https://github.com/XhienLee/sealkey) | Lock a file with a few words, open it later | No |
| [Stowkey](https://github.com/XhienLee/stowkey) | Private online file storage, one key | Yes (PHP) |
| [Sendkey](https://github.com/XhienLee/sendkey) | Send a file through a link that works once | Yes (PHP + SQLite) |
| [Dropkey](https://github.com/XhienLee/dropkey) | A link anyone can use to send *you* a file privately | Yes (PHP + SQLite) |
| [Stashkey](https://github.com/XhienLee/stashkey) | Private notes you open with 6 words | Yes (PHP + SQLite) |
| [Stampkey](https://github.com/XhienLee/stampkey) | Prove a file is yours and unchanged | No |

## Credits

The word list, file format and crypto in `js/` come from [Sealkey](https://github.com/XhienLee/sealkey). The encryption library is [libsodium](https://github.com/jedisct1/libsodium.js) in `vendor/`. MIT licensed, free to use and change.
