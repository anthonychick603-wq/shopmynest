/**
 * Staging API smoke test for the highest-risk buyer checkout contracts.
 * Creates a pending PaymentIntent/order but DOES NOT present/confirm payment.
 *
 * Required env:
 *   MNU_BASE_URL=https://staging.example.com
 *   MNU_EMAIL=buyer@example.com
 *   MNU_PASSWORD=...
 *   MNU_PRODUCT_ID=123
 * Optional shipping fields use safe US defaults below.
 */
const base = String(process.env.MNU_BASE_URL || '').replace(/\/$/, '');
const email = process.env.MNU_EMAIL || '';
const password = process.env.MNU_PASSWORD || '';
const productId = Number(process.env.MNU_PRODUCT_ID || 0);
if (!base || !email || !password || !productId) {
  console.error('Set MNU_BASE_URL, MNU_EMAIL, MNU_PASSWORD, and MNU_PRODUCT_ID.');
  process.exit(2);
}
async function call(path, { method='GET', body, token }={}) {
  const r = await fetch(base + path, { method, headers: { 'Content-Type':'application/json', ...(token ? {Authorization:`Bearer ${token}`} : {}) }, body: body ? JSON.stringify(body) : undefined });
  const data = await r.json().catch(()=>({}));
  if (!r.ok) throw new Error(`${method} ${path} -> ${r.status}: ${JSON.stringify(data)}`);
  return data;
}
const login = await call('/wp-json/the-nest/v1/auth/login', {method:'POST', body:{login:email,password}});
const token = login.token;
const addr = {
  first_name: process.env.MNU_FIRST_NAME || 'Staging', last_name: process.env.MNU_LAST_NAME || 'Buyer',
  address_1: process.env.MNU_ADDRESS_1 || '1 Market St', address_2:'', city: process.env.MNU_CITY || 'San Francisco',
  state: process.env.MNU_STATE || 'CA', postcode: process.env.MNU_ZIP || '94105', country:'US',
  phone: process.env.MNU_PHONE || '4155550100'
};
const quote = await call('/wp-json/nest-native/v1/checkout/quote', {method:'POST', token, body:{items:[{product_id:productId,quantity:1}], shipping_address:addr}});
if (!quote.quote_token) throw new Error('quote_token missing');
const chosen = Array.isArray(quote.shipping_rates) && quote.shipping_rates[0] ? quote.shipping_rates[0] : null;
const intent = await call('/wp-json/nest-native/v1/checkout/create-intent', {method:'POST', token, body:{
  items:[{product_id:productId,quantity:1}], shipping_address:addr,
  shipping_method_id: chosen?.id, quote_token:quote.quote_token,
  checkout_token:`staging_${Date.now()}`
}});
if (typeof intent.tax_total !== 'number') throw new Error('final tax_total missing');
if (typeof intent.amount !== 'number' || intent.amount <= 0) throw new Error('final amount invalid');
if (chosen && Math.abs(Number(intent.shipping_total) - Number(chosen.amount)) >= 0.01) {
  throw new Error(`shipping drift: quote ${chosen.amount}, intent ${intent.shipping_total}`);
}
console.log('PASS - quote/create-intent shipping matches');
console.log('PASS - final tax_total returned:', intent.tax_total);
console.log('PASS - final amount returned:', intent.amount);
console.log('Created pending staging order:', intent.order_id, '(not charged)');
