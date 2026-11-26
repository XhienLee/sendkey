import { test } from 'node:test';
import assert from 'node:assert/strict';
import { sodium } from './helpers.mjs';
import { makeKeyWords } from '../js/key.js';
import { sealFile, openFile, peek } from '../family/lib.js';

const file = (text, name = 'note.txt') => new File([text], name);

test('sendkey: a file sealed with 6 words opens with them, and the key can be checked first', async () => {
    const s = await sodium();
    const keyText = makeKeyWords().join('-');
    const params = { passes: 1, memKiB: 64, chunkBytes: 1024 };
    const bytes = await sealFile(s, file('secret plan', 'plan.txt'), { keyText }, params);
    const head = bytes.slice(0, 700);
    assert.equal(peek(s, head, { keyText }).meta.name, 'plan.txt');
    assert.throws(() => peek(s, head, { keyText: makeKeyWords().join('-') }), { code: 'wrong_key' });
    const opened = await openFile(s, bytes, { keyText });
    assert.equal(new TextDecoder().decode(opened.data), 'secret plan');
});
