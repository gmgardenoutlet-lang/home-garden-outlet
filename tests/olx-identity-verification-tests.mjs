import assert from 'node:assert/strict';
import { identitySummary, verifyIdentityOnly } from '../scripts/olx-identity-verification.mjs';
const id = 'prd_' + '1'.repeat(32), other = 'prd_' + '2'.repeat(32);
const before = { meta: { keep: true }, products: [{ name: 'One', slug: 'one', price: '100', gallery: ['a.jpg'] }, { name: 'Two', productId: id }] };
const after = structuredClone(before); after.products[0].productId = other;
assert.equal(verifyIdentityOnly(before, after).missing, 0);
assert.equal(identitySummary(before).missing, 1);
for (const mutate of [
  data => { data.products[0].price = '101'; },
  data => { data.products.reverse(); },
  data => { data.products[1].productId = 'prd_' + '3'.repeat(32); },
  data => { data.products[0].productId = id; },
  data => { data.products[0].slug = 'changed'; },
  data => { data.products[0].gallery = []; },
  data => { delete data.meta; },
]) { const changed = structuredClone(after); mutate(changed); assert.throws(() => verifyIdentityOnly(before, changed)); }
console.log('PASS: production identity verification rejects data edits, reorder, duplicate/replaced IDs and metadata changes');
