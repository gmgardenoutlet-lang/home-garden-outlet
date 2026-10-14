import assert from 'node:assert/strict';
import {mkdir, writeFile} from 'node:fs/promises';

const base = process.env.HGO_MERCHANT_VERIFY_BASE || 'https://mgoutlet.pl';
const get = async path => {
  const response = await fetch(path.startsWith('https://') ? path : base + path, {cache:'no-store',signal:AbortSignal.timeout(30000)});
  assert.equal(response.status,200,`${path}: HTTP ${response.status}`);
  return response;
};
const catalogue = await (await get('/products-public.php')).json();
const figures = catalogue.products.filter(p=>p.saleType==='garden_figure');
const shopHtml = await (await get('/sklep/figury-ogrodowe')).text();
const match = shopHtml.match(/window\.HGO_SHOP_PRODUCTS\s*=\s*(\[[^]*?\]);/);
assert.ok(match,'No public shop products');
const shop = JSON.parse(match[1]);
const bySlug = new Map(shop.map(p=>[p.slug,p]));
const results = [];
let cursor=0;
await Promise.all(Array.from({length:6},async()=>{
  while(cursor<figures.length){
    const product=figures[cursor++];
    const html=await (await get('/sklep/figury-ogrodowe/produkt/'+product.slug)).text();
    const publicProduct=bySlug.get(product.slug);
    assert.ok(!html.includes('internalNote'),product.slug+': private field');
    const ld=[...html.matchAll(/<script type="application\/ld\+json">([^]*?)<\/script>/g)].map(m=>JSON.parse(m[1]));
    const schema=ld.find(s=>s['@type']==='Product');
    assert.ok(schema,product.slug+': Product JSON-LD');
    if(publicProduct) assert.equal(Number(schema.offers.price),publicProduct.price,product.slug+': inconsistent price');
    const image=product.image.startsWith('https://')?product.image:'https://mgoutlet.pl/'+product.image.replace(/^\/+/, '');
    const imageResponse=await get(image);
    assert.match(imageResponse.headers.get('content-type')||'',/^image\//);
    await imageResponse.arrayBuffer();
    results.push({slug:product.slug,price:schema.offers.price,availability:schema.offers.availability,
      condition:schema.offers.itemCondition||null,leadTime:publicProduct?.leadTime||null,
      delivery:publicProduct?.deliveryMethods||[],imageHttp:200,canBuy:publicProduct?.canBuy||false});
  }
}));
await mkdir('.local-cache/merchant',{recursive:true});
await writeFile('.local-cache/merchant/live-products.json',JSON.stringify({total:catalogue.products.length,figures:figures.length,products:results},null,2));
console.log(`PASS: ${figures.length} real product URLs, images, public fields, Product/Offer and shop prices`);
