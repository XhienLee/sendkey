import { sodium, sealFile, peek, openFile, saveFile, call, copyText, messageFor, sizeText, HEAD_BYTES } from './family/lib.js';
import { makeKeyWords, normalizeKey, isKeyValid } from './js/key.js';
import { $, fileButton, notice, renderWords, busy } from './family/ui.js';

const id = new URLSearchParams(location.search).get('s');
const hashKey = new URLSearchParams(location.hash.slice(1)).get('k') || '';

if (id) receive(); else send();

function send() {
    fileButton('pick', 'file', 'pick-name');
    $('send').addEventListener('click', () => busy($('send'), 'Locking…', async () => {
        notice('send-alert', '');
        const file = $('file').files[0];
        if (!file) return notice('send-alert', 'Choose a file first.');
        try {
            const s = await sodium();
            const words = makeKeyWords();
            const bytes = await sealFile(s, file, { keyText: words.join('-') });
            const put = await call(`api.php?action=put&ttl=${$('ttl').value}`, { method: 'POST', body: bytes });
            const result = await put.json();
            const inLink = document.querySelector('input[name="mode"]:checked').value === 'link';
            const base = `${location.origin}${location.pathname}?s=${result.id}`;
            $('link').value = inLink ? `${base}#k=${words.join('-')}` : base;
            $('apart').hidden = inLink;
            if (!inLink) {
                renderWords($('words'), words);
                $('copy-key').onclick = (event) => copyText(words.join('-'), event.currentTarget);
            }
            $('expires').textContent = `It expires ${new Date(result.expires_at * 1000).toLocaleString()}, or when it is used.`;
            $('sent').hidden = false;
        } catch (error) {
            notice('send-alert', messageFor(error));
        }
    }));
    $('copy-link').addEventListener('click', (event) => copyText($('link').value, event.currentTarget));
}

function receive() {
    $('view-send').hidden = true;
    $('view-get').hidden = false;
    let keyText = '';
    if (hashKey) {
        $('need-key').hidden = true;
        $('check').textContent = 'Open the link';
    }
    $('check').addEventListener('click', () => busy($('check'), 'Checking the key…', async () => {
        notice('get-alert', '');
        keyText = normalizeKey(hashKey || $('key').value);
        if (!isKeyValid(keyText)) return notice('get-alert', 'The key should be 6 words.');
        try {
            const head = new Uint8Array(await (await call(`api.php?action=head&id=${encodeURIComponent(id)}`)).arrayBuffer());
            const { meta } = peek(await sodium(), head.subarray(0, HEAD_BYTES), { keyText });
            $('found-name').textContent = meta.name;
            $('found-size').textContent = sizeText(meta.size);
            $('found').hidden = false;
            $('check').hidden = true;
        } catch (error) {
            notice('get-alert', messageFor(error));
        }
    }));
    $('download').addEventListener('click', () => busy($('download'), 'Downloading…', async () => {
        notice('get-alert', '');
        try {
            const bytes = new Uint8Array(await (await call(`api.php?action=get&id=${encodeURIComponent(id)}`)).arrayBuffer());
            const { meta, data } = await openFile(await sodium(), bytes, { keyText });
            saveFile(meta.name, data);
            $('found').hidden = true;
            $('got').textContent = `${meta.name} was saved. The link has been used up.`;
            $('got').hidden = false;
            history.replaceState(null, '', location.pathname);
        } catch (error) {
            notice('get-alert', messageFor(error));
        }
    }));
}
