export function identitySummary(catalog) {
  if (!Array.isArray(catalog.products)) throw Error('Invalid catalogue');
  const seen = new Set(); let missing = 0; let duplicates = 0; let invalid = 0;
  for (const product of catalog.products) {
    if (!product || Array.isArray(product) || typeof product !== 'object') throw Error('Invalid product');
    const id = product.productId ?? '';
    if (id === '') { missing++; continue; }
    if (typeof id !== 'string' || !/^prd_[a-f0-9]{32}$/.test(id)) { invalid++; continue; }
    if (seen.has(id)) duplicates++;
    seen.add(id);
  }
  return { products: catalog.products.length, missing, duplicates, invalid };
}
export function verifyIdentityOnly(before, after) {
  const summary = identitySummary(after);
  if (summary.products !== before.products.length || summary.missing || summary.duplicates || summary.invalid) throw Error('Identity verification failed');
  const restored = structuredClone(after);
  restored.products.forEach((product, i) => {
    if (Object.hasOwn(before.products[i], 'productId')) product.productId = before.products[i].productId;
    else delete product.productId;
    if (before.products[i].productId && before.products[i].productId !== after.products[i].productId) throw Error('Existing identity changed');
  });
  if (JSON.stringify(restored) !== JSON.stringify(before)) throw Error('Non-identity product data changed');
  return summary;
}
