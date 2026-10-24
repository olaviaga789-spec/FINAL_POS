/**
 * Restaurant POS - Main JS
 * Handles: menu loading, cart, checkout, API calls
 */

// ============================================
// CONFIG
// ============================================
const API_BASE = './php';
// Detect if running as local file — skip PHP API calls
const IS_LOCAL = location.protocol === 'file:';

// ============================================
// STATE
// ============================================
let order        = [];
let menuItems    = [];   // currently displayed items
let allMenuItems = [];   // full master list (never filtered)
let categories   = [];
let activeCatId  = 1; // "All"
let selectedPayment = 'cash'; // cash | ewallet | card

// ============================================
// INIT
// ============================================
document.addEventListener('DOMContentLoaded', () => {
  // Always load fallback data first so menu is never empty
  useFallbackCategories();
  useFallbackItems();
  renderOrderPanel();

  // If on a real server, upgrade to PHP data
  if (!IS_LOCAL) {
    loadCategories();
    loadMenuItems(1);
  }
});

// ============================================
// API CALLS
// ============================================
async function apiFetch(url) {
  try {
    const res = await fetch(url);
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return await res.json();
  } catch (err) {
    console.error('API Error:', err);
    return null;
  }
}

async function loadCategories() {
  if (IS_LOCAL) { useFallbackCategories(); return; }
  const data = await apiFetch(`${API_BASE}/menu.php?action=categories`);
  if (!data) return useFallbackCategories();
  // Inject product images from GENERATED_ITEM_IMAGES as placeholder until real photos load
  const catImgIds = { 'Coffee Based':1, 'Non-Coffee':8, 'Matcha Based':16, 'Premium Drinks':21, 'Silog Meals':30, 'Snacks':37, 'Sandwiches':44, 'Buldak':46 };
  const imgs = typeof GENERATED_ITEM_IMAGES !== 'undefined' ? GENERATED_ITEM_IMAGES : {};
  const allTab = { id: 1, name: 'All', icon: '🍽️', catImg: null };
  const hasAll = data.some(c => c.name === 'All' || c.id == 1);
  categories = [allTab, ...data.filter(cat => cat.name !== 'All').map(cat => {
    const imgId = catImgIds[cat.name];
    return { ...cat, catImg: imgId && imgs[imgId] ? imgs[imgId] : null };
  })];
  renderCategories();
}

async function loadMenuItems(categoryId) {
  categoryId = categoryId || 1;
  if (IS_LOCAL) { useFallbackItems(); return; } // use client-side data only
  const url = `${API_BASE}/menu.php?action=items&category_id=${categoryId}`;
  const data = await apiFetch(url);
  if (!data) return useFallbackItems();

  // Merge into master list
  data.forEach(item => {
    if (!allMenuItems.find(i => i.id == item.id)) allMenuItems.push(item);
    else Object.assign(allMenuItems.find(i => i.id == item.id), item);
  });

  // Upgrade category images with real DB photos once items are loaded
  const catImgNames = { 'Coffee Based':'Americano', 'Non-Coffee':'Strawberry Milk', 'Matcha Based':'Classic Matcha', 'Premium Drinks':'White Mocha', 'Silog Meals':'Tocilog', 'Snacks':'Overload Burger', 'Sandwiches':'Tuna Sandwich', 'Buldak':'Regular Buldak' };
  let refreshed = false;
  categories.forEach(cat => {
    const targetName = catImgNames[cat.name];
    if (!targetName) return;
    const item = allMenuItems.find(i => i.name && i.name.trim() === targetName && i.image_url);
    if (item && item.image_url !== cat.catImg) {
      cat.catImg = item.image_url;
      refreshed = true;
    }
  });
  if (refreshed) renderCategories();

  // Re-apply current filter
  if (activeCatId == 1) {
    menuItems = [...allMenuItems];
  } else {
    const cat = categories.find(c => c.id == activeCatId);
    const catName = cat ? cat.name : '';
    menuItems = allMenuItems.filter(item => item.category_name === catName);
  }
  renderMenuGrid();
}

// ============================================
// FALLBACK DATA (when PHP not available)
// ============================================
function useFallbackCategories() {
  // Map category name → item ID for image (from GENERATED_ITEM_IMAGES)
  const catImgIds = { 'Coffee Based':1, 'Non-Coffee':8, 'Matcha Based':16, 'Premium Drinks':21, 'Silog Meals':30, 'Snacks':37, 'Sandwiches':44, 'Buldak':46 };
  categories = [
    { id: 1,  name: 'All',            icon: '🍽️' },
    { id: 2,  name: 'Coffee Based',   icon: '☕' },
    { id: 3,  name: 'Non-Coffee',     icon: '🧋' },
    { id: 4,  name: 'Matcha Based',   icon: '🍵' },
    { id: 5,  name: 'Premium Drinks', icon: '✨' },
    { id: 6,  name: 'Silog Meals',    icon: '🍳' },
    { id: 7,  name: 'Snacks',         icon: '🍟' },
    { id: 8,  name: 'Sandwiches',     icon: '🥪' },
    { id: 9,  name: 'Buldak',         icon: '🌶️' },
  ].map(cat => {
    const imgId = catImgIds[cat.name];
    const imgs = typeof GENERATED_ITEM_IMAGES !== 'undefined' ? GENERATED_ITEM_IMAGES : {};
    return { ...cat, catImg: imgId && imgs[imgId] ? imgs[imgId] : null };
  });
  renderCategories();
}

function useFallbackItems() {
  allMenuItems = [
    // ── COFFEE BASED (16oz ₱89 / 22oz ₱109) ──
    { id:1,  name:'Americano',        price:89,  category_name:'Coffee Based',   description:'Espresso shots topped with cold water. 16oz', icon:'☕' },
    { id:2,  name:'Spanish Latte',    price:89,  category_name:'Coffee Based',   description:'Signature shot of espresso with creamy condensed milk. 16oz', icon:'☕' },
    { id:3,  name:'Cappuccino',       price:89,  category_name:'Coffee Based',   description:'The subtle sweetness of our espresso and goodness of fresher milk. 16oz', icon:'☕' },
    { id:4,  name:'Chocnut Latte',    price:89,  category_name:'Coffee Based',   description:'Espresso-based and milk, topped with our all time favorite chocnut. 16oz', icon:'☕' },
    { id:5,  name:'White Coffee',     price:89,  category_name:'Coffee Based',   description:'Smooth shots of espresso with just the right amount of whole milk. 16oz', icon:'☕' },
    { id:6,  name:'Mocha',            price:89,  category_name:'Coffee Based',   description:'Our rich, espresso combined with a bittersweet mocha flavored syrup. 16oz', icon:'☕' },
    { id:7,  name:'Barista Drink',    price:89,  category_name:'Coffee Based',   description:'Shots of espresso with sweetened milk and Chocnut drizzle. 16oz', icon:'☕' },

    // ── NON-COFFEE (16oz ₱89 / 22oz ₱109) ──
    { id:8,  name:'Strawberry Milk',      price:89, category_name:'Non-Coffee', description:'A blend of ice and milk with layered strawberry puree.', icon:'🍓' },
    { id:9,  name:'Blueberry Fruit Fizz', price:89, category_name:'Non-Coffee', description:'Blueberry powder with sparkling fizz.', icon:'🫐' },
    { id:10, name:'Classic Chocolate',    price:89, category_name:'Non-Coffee', description:'Steamed milk with chocolate-flavored syrup.', icon:'🍫' },
    { id:11, name:'Strawberry Fruit Fizz',price:89, category_name:'Non-Coffee', description:'Strawberry powder with sparkling fizz.', icon:'🍓' },
    { id:12, name:'Choco Berry',          price:89, category_name:'Non-Coffee', description:'Steamed milk with ice and strawberry puree.', icon:'🍫' },
    { id:13, name:'Kiwi Fruit Fizz',      price:89, category_name:'Non-Coffee', description:'Kiwi syrup with sparkling fizz.', icon:'🥝' },
    { id:14, name:'Berry Yakult',         price:89, category_name:'Non-Coffee', description:'Steamed milk with ice, yakult, and strawberry puree.', icon:'🫐' },
    { id:15, name:'Lychee Fruit Fizz',    price:89, category_name:'Non-Coffee', description:'Lychee syrup with sparkling fizz.', icon:'🍈' },

    // ── MATCHA BASED (16oz ₱89 / 22oz ₱109) ──
    { id:16, name:'Classic Matcha',   price:89,  category_name:'Matcha Based',   description:'Smooth and creamy, unsweetened matcha, milk, and classic syrup. 16oz', icon:'🍵' },
    { id:17, name:'Berry Matcha',     price:89,  category_name:'Matcha Based',   description:'Steamed milk with matcha powder, ice, and strawberry puree. 16oz', icon:'🍵' },
    { id:18, name:'Dirty Matcha',     price:109, category_name:'Matcha Based',   description:'Combined our signature espresso with fine matcha powder, ice, and milk. ICED', icon:'🍵' },
    { id:19, name:'Blue Matcha',      price:109, category_name:'Matcha Based',   description:'Blueberry tea, ice, matcha shot, and steamed milk. ICED', icon:'🍵' },
    { id:20, name:'Green Tablea',     price:109, category_name:'Matcha Based',   description:'Earthy matcha meets rich tablea chocolate in a bold, creamy blend.', icon:'🍵' },

    // ── PREMIUM DRINKS (16oz ₱109 / 22oz ₱139) ──
    { id:21, name:'White Mocha',      price:109, category_name:'Premium Drinks', description:'A special espresso meets white chocolate sauce and steamed milk.', icon:'✨' },
    { id:22, name:'Salted Caramel',   price:109, category_name:'Premium Drinks', description:'Rich, buttery caramel perfectly balanced with a hint of sea salt. ICED', icon:'✨' },
    { id:23, name:'Biscoff Latte',    price:109, category_name:'Premium Drinks', description:'A creamy latte infused with warm Biscoff spice and cookie sweetness.', icon:'✨' },
    { id:24, name:'Hazelnut Latte',   price:109, category_name:'Premium Drinks', description:'Coffee blended with toffee nut syrup, milk and ice.', icon:'✨' },
    { id:25, name:'Ube Latte',        price:109, category_name:'Premium Drinks', description:'A smooth, velvety latte with the sweet charm of purple ube.', icon:'✨' },
    { id:26, name:'Caramel Macchiato',price:109, category_name:'Premium Drinks', description:'A blend of bold espresso and creamy milk with caramel hints.', icon:'✨' },

    // ── SILOG MEALS ──
    { id:27, name:'Tapsilog',         price:109, category_name:'Silog Meals',    description:'Tapa, sinangag, itlog.', icon:'🍳' },
    { id:28, name:'Bangsilog',        price:109, category_name:'Silog Meals',    description:'Bangus, sinangag, itlog.', icon:'🍳' },
    { id:29, name:'Porksilog',        price:109, category_name:'Silog Meals',    description:'Pork, sinangag, itlog.', icon:'🍳' },
    { id:30, name:'Tocilog',          price:89,  category_name:'Silog Meals',    description:'Tocino, sinangag, itlog.', icon:'🍳' },
    { id:31, name:'Hungsilog',        price:89,  category_name:'Silog Meals',    description:'Hotdog, sinangag, itlog.', icon:'🍳' },
    { id:32, name:'Nuggetssilog',     price:89,  category_name:'Silog Meals',    description:'Nuggets, sinangag, itlog.', icon:'🍳' },
    { id:33, name:'Spamsilog',        price:79,  category_name:'Silog Meals',    description:'Spam, sinangag, itlog.', icon:'🍳' },
    { id:34, name:'Hotsilog',         price:79,  category_name:'Silog Meals',    description:'Hotsilog meal.', icon:'🍳' },
    { id:35, name:'Skinsilog',        price:79,  category_name:'Silog Meals',    description:'Chicken skin, sinangag, itlog.', icon:'🍳' },

    // ── SNACKS ──
    { id:36, name:'Overload Snack',   price:99,  category_name:'Snacks',         description:'Overload snack platter.', icon:'🍟' },
    { id:37, name:'Overload Burger',  price:35,  category_name:'Snacks',         description:'Overload burger.', icon:'🍔' },
    { id:38, name:'Burger',           price:25,  category_name:'Snacks',         description:'Classic burger.', icon:'🍔' },
    { id:39, name:'Egg',              price:25,  category_name:'Snacks',         description:'Fried egg.', icon:'🥚' },
    { id:40, name:'Big Siomai (1 Order)', price:120, category_name:'Snacks',     description:'Big siomai, 1 order.', icon:'🥟' },
    { id:41, name:'Big Siomai (Per Pc)', price:12,  category_name:'Snacks',      description:'Big siomai, per piece.', icon:'🥟' },
    { id:42, name:'Fries',            price:69,  category_name:'Snacks',         description:'Crispy fries.', icon:'🍟' },
    { id:43, name:'Hash Brown',       price:25,  category_name:'Snacks',         description:'Golden hash brown.', icon:'🥔' },

    // ── SANDWICHES ──
    { id:44, name:'Tuna Sandwich',    price:99,  category_name:'Sandwiches',     description:'Tuna, mayo spread, tomato, cucumber, lettuce, cheese, fries.', icon:'🥪' },
    { id:45, name:'Ham & Egg Sandwich',price:99, category_name:'Sandwiches',     description:'Egg mayo spread, ham, lettuce, tomato, cheese, cucumber, fries.', icon:'🥪' },

    // ── BULDAK ──
    { id:46, name:'Regular Buldak',   price:129, category_name:'Buldak',         description:'Cheesy Buldak Overload. Choose spicy level: No Spicy, Mild, Medium, Extra Spicy.', icon:'🌶️' },
    { id:47, name:'Overload Buldak',  price:169, category_name:'Buldak',         description:'Cheesy Buldak Overload — extra loaded! Choose spicy level: No Spicy, Mild, Medium, Extra Spicy.', icon:'🌶️' },
  ];
  menuItems = [...allMenuItems];
  renderMenuGrid();
}

// ============================================
// RENDER: CATEGORIES
// ============================================
function renderCategories() {
  const tabs = document.getElementById('categoryTabs');
  if (!tabs) return;
  tabs.innerHTML = categories.map(cat => {
    const isActive = cat.id == activeCatId;
    const circle = cat.catImg
      ? `<div class="cat-img-wrap"><img src="${cat.catImg}" style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;" alt=""></div>`
      : `<div class="cat-img-wrap" style="display:flex;align-items:center;justify-content:center;font-size:22px;">${cat.icon}</div>`;
    return `<button class="cat-tab ${isActive ? 'active' : ''}" onclick="selectCategory(${cat.id})">${circle}<span class="cat-label">${cat.name}</span></button>`;
  }).join('');
}

function selectCategory(id) {
  activeCatId = id;
  renderCategories();

  // Filter client-side from the full master list
  if (id == 1) {
    // "All" — show everything
    menuItems = [...allMenuItems];
  } else {
    const cat = categories.find(c => c.id == id);
    const catName = cat ? cat.name : '';
    menuItems = allMenuItems.filter(item => item.category_name === catName);
  }
  renderMenuGrid();

  // Also try PHP in background to get server data
  loadMenuItems(id);
}

// ============================================
// ITEM IMAGE MAP — Generated product illustrations
// ============================================
function imgError(el) {
  el.onerror = null;
  el.style.display = 'none';
  var ph = document.createElement('div');
  ph.className = 'food-card-img-placeholder';
  ph.textContent = '🍽️';
  el.parentNode.insertBefore(ph, el.nextSibling);
}

function getItemImage(item) {
  // Priority: 1) DB image_url (real uploaded file), 2) generated illustration, 3) emoji
  if (item.image_url) {
    return '<img class="food-card-img" src="' + escHtml(item.image_url) + '" alt="' + escHtml(item.name) + '" loading="lazy" onerror="imgError(this)">';
  }
  if (typeof GENERATED_ITEM_IMAGES !== 'undefined' && GENERATED_ITEM_IMAGES[item.id]) {
    return '<img class="food-card-img" src="' + GENERATED_ITEM_IMAGES[item.id] + '" alt="' + escHtml(item.name) + '" loading="lazy">';
  }
  var emoji = item.icon || '🍽️';
  return '<div class="food-card-img-placeholder">' + emoji + '</div>';
}

function getEmoji(item, index) {
  return item.icon || FOOD_EMOJIS[index % FOOD_EMOJIS.length];
}

function renderMenuGrid() {
  const grid = document.getElementById('menuGrid');

  if (!menuItems.length) {
    grid.innerHTML = `
      <div class="menu-empty">
        <div class="menu-empty-icon">🍽️</div>
        <h3>No items found</h3>
        <p>Try a different category</p>
      </div>`;
    return;
  }

  grid.innerHTML = menuItems.map((item, i) => {
    const inOrder    = order.find(o => o.id == item.id);
    const qty        = inOrder ? inOrder.qty : 0;
    const emoji      = getEmoji(item, i);
    const price      = parseFloat(item.price);
    const imgHTML    = getItemImage(item);

    const safeEmoji = (item.icon || '').replace(/'/g, '');
    return `
      <div class="food-card ${qty > 0 ? 'in-order' : ''}" id="card-${item.id}"
           onclick="addItem(${item.id}, '${escHtml(item.name)}', ${price}, '${safeEmoji}')">
        <div class="food-card-qty-badge">${qty}</div>
        ${imgHTML}
        <div class="food-card-body">
          <div class="food-card-label">${item.category_name || ''}</div>
          <div class="food-card-name">${escHtml(item.name)}</div>
          <div class="food-card-desc">${escHtml(item.description || 'Freshly prepared')}</div>
          <div class="food-card-footer">
            <span class="food-card-price">₱${formatPrice(price)}</span>
            <div class="add-btn">+</div>
          </div>
        </div>
      </div>`;
  }).join('');
}

// ============================================
// CART LOGIC
// ============================================
function addItem(id, name, price, emoji) {
  let item = order.find(i => i.id == id);
  if (item) {
    item.qty++;
  } else {
    order.push({ id, name, price, emoji, qty: 1 });
  }
  updateCardBadge(id);
  renderOrderPanel();
  showToast(`${name} added!`, 'info');
}

function changeQty(id, delta) {
  let idx  = order.findIndex(i => i.id == id);
  if (idx === -1) return;

  order[idx].qty += delta;
  if (order[idx].qty <= 0) {
    order.splice(idx, 1);
  }

  updateCardBadge(id);
  renderOrderPanel();
}

function clearOrder() {
  if (!order.length) return;
  order.forEach(item => updateCardBadge(item.id, true));
  order = [];
  renderOrderPanel();
}

function updateCardBadge(id, clear = false) {
  const card  = document.getElementById(`card-${id}`);
  if (!card) return;
  const badge = card.querySelector('.food-card-qty-badge');
  const item  = order.find(i => i.id == id);
  const qty   = (clear || !item) ? 0 : item.qty;

  if (qty > 0) {
    card.classList.add('in-order');
    if (badge) badge.textContent = qty;
  } else {
    card.classList.remove('in-order');
  }
}

// ============================================
// RENDER: ORDER PANEL
// ============================================
function renderOrderPanel() {
  const list        = document.getElementById('orderItemsList');
  const subtotalEl  = document.getElementById('subtotal');
  const taxEl       = document.getElementById('tax');
  const grandEl     = document.getElementById('grandTotal');
  const checkoutBtn = document.getElementById('checkoutBtn');
  const itemCountEl = document.getElementById('orderItemCount');

  // Item count badge
  const totalQty = order.reduce((s, i) => s + i.qty, 0);
  if (itemCountEl) itemCountEl.textContent = totalQty || '';

  if (!order.length) {
    list.innerHTML = `
      <div class="order-empty">
        <div class="order-empty-icon">🛒</div>
        <p>No items yet.<br>Tap a menu item to add it.</p>
      </div>`;
    subtotalEl.textContent = '0.00';
    taxEl.textContent      = '0.00';
    grandEl.textContent    = '0.00';
    checkoutBtn.disabled   = true;
    return;
  }

  checkoutBtn.disabled = false;

  list.innerHTML = order.map(item => `
    <div class="order-item">
      <div class="order-item-emoji">${item.emoji || '🍽️'}</div>
      <div class="order-item-info">
        <div class="order-item-name">${escHtml(item.name)}</div>
        <div class="order-item-price">₱${formatPrice(item.price)} each</div>
      </div>
      <div class="qty-controls">
        <button class="qty-btn remove" onclick="changeQty(${item.id}, -1)">−</button>
        <span class="qty-num">${item.qty}</span>
        <button class="qty-btn" onclick="changeQty(${item.id}, 1)">+</button>
      </div>
      <div class="order-item-subtotal">₱${formatPrice(item.price * item.qty)}</div>
    </div>
  `).join('');

  const subtotal = order.reduce((s, i) => s + (i.price * i.qty), 0);
  const tax      = subtotal * 0.12;
  const grand    = subtotal + tax;

  subtotalEl.textContent = formatPrice(subtotal);
  taxEl.textContent      = formatPrice(tax);
  grandEl.textContent    = formatPrice(grand);
}

// ============================================
// CHECKOUT FLOW
// ============================================
function selectPayment(method, btn) {
  selectedPayment = method;
  document.querySelectorAll('.pay-opt').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  const cashRow = document.getElementById('cashTenderedRow');
  const changeRow = document.getElementById('changeRow');
  if (method === 'cash') {
    cashRow.style.display = 'flex';
    document.getElementById('cashTendered').value = '';
    if (changeRow) changeRow.style.display = 'none';
  } else {
    cashRow.style.display = 'none';
  }
}

function calcChange() {
  const grand = order.reduce((s, i) => s + (i.price * i.qty), 0) * 1.12;
  const tendered = parseFloat(document.getElementById('cashTendered').value) || 0;
  const changeRow = document.getElementById('changeRow');
  const changeAmt = document.getElementById('changeAmt');
  if (tendered >= grand) {
    const change = tendered - grand;
    changeAmt.textContent = '₱' + formatPrice(change);
    changeAmt.style.color = change > 0 ? '#2D6A4F' : 'var(--text-mid)';
    changeRow.style.display = 'flex';
  } else if (tendered > 0) {
    changeAmt.textContent = '₱' + formatPrice(grand - tendered) + ' short';
    changeAmt.style.color = '#DC2626';
    changeRow.style.display = 'flex';
  } else {
    changeRow.style.display = 'none';
  }
}

function openCheckoutModal() {
  if (!order.length) return;

  const subtotal = order.reduce((s, i) => s + (i.price * i.qty), 0);
  const tax      = subtotal * 0.12;
  const grand    = subtotal + tax;

  const receiptItems = order.map(item => `
    <div class="receipt-row">
      <span>${escHtml(item.name)} × ${item.qty}</span>
      <span>₱${formatPrice(item.price * item.qty)}</span>
    </div>`).join('');

  document.getElementById('receiptItems').innerHTML   = receiptItems;
  document.getElementById('receiptSubtotal').textContent = formatPrice(subtotal);
  document.getElementById('receiptTax').textContent      = formatPrice(tax);
  document.getElementById('receiptGrand').textContent    = formatPrice(grand);
  document.getElementById('modalOrderType').textContent  =
    document.getElementById('orderType').value || 'dine-in';
  document.getElementById('modalCustomer').textContent   =
    document.getElementById('customerName').value || 'Guest';
  document.getElementById('modalTable').textContent      =
    document.getElementById('tableNumber').value || '—';

  document.getElementById('checkoutModal').classList.add('open');
  // Reset payment to cash
  selectedPayment = 'cash';
  document.querySelectorAll('.pay-opt').forEach(b => b.classList.remove('active'));
  const cashBtn = document.getElementById('pay-cash');
  if (cashBtn) cashBtn.classList.add('active');
  const cashRow = document.getElementById('cashTenderedRow');
  if (cashRow) cashRow.style.display = 'flex';
  const cashInput = document.getElementById('cashTendered');
  if (cashInput) cashInput.value = '';
  const changeRow = document.getElementById('changeRow');
  if (changeRow) changeRow.style.display = 'none';
}

function closeCheckoutModal() {
  document.getElementById('checkoutModal').classList.remove('open');
}

async function confirmCheckout() {
  const btn = document.querySelector('.modal-btn.confirm');
  btn.disabled    = true;
  btn.textContent = 'Processing…';

  // Validate cash payment
  if (selectedPayment === 'cash') {
    const grand = order.reduce((s, i) => s + (i.price * i.qty), 0) * 1.12;
    const tendered = parseFloat(document.getElementById('cashTendered')?.value) || 0;
    if (tendered > 0 && tendered < grand) {
      showToast('Cash tendered is less than grand total', 'error');
      btn.disabled = false; btn.textContent = 'Confirm Order';
      return;
    }
  }

  const payload = {
    customer_name:   document.getElementById('customerName').value.trim() || 'Guest',
    customer_id:     parseInt(document.getElementById('linkedCustomerId')?.value) || 0,
    table_number:    document.getElementById('tableNumber').value  || '',
    order_type:      document.getElementById('orderType').value    || 'dine-in',
    payment_method:  selectedPayment,
    cash_tendered:   selectedPayment === 'cash' ? (parseFloat(document.getElementById('cashTendered')?.value) || 0) : 0,
    items: order.map(i => ({ id: i.id, name: i.name, price: i.price, qty: i.qty })),
  };

  try {
    if (IS_LOCAL) throw new Error('local'); // skip straight to demo mode

    const res = await fetch(`${API_BASE}/orders.php?action=create`, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(payload),
    });
    const data = await res.json();

    if (data.success) {
      closeCheckoutModal();
      order.forEach(item => updateCardBadge(item.id, true));
      order = [];
      renderOrderPanel();
      document.getElementById('customerName').value = '';
      document.getElementById('tableNumber').value  = '';
      clearLinkedCustomer();
      const payLabel={'cash':'Cash','ewallet':'E-Wallet','card':'Credit Card'};
      showToast(`✅ Order #${data.order_id} placed! ${payLabel[selectedPayment]||''} · ₱${formatPrice(data.total)}`, 'success');
    } else {
      showToast(data.error || 'Failed to place order', 'error');
    }
  } catch (err) {
    // PHP not available — simulate success
    closeCheckoutModal();
    const fakeId = Math.floor(Math.random() * 900) + 100;
    const grand  = order.reduce((s, i) => s + (i.price * i.qty), 0) * 1.12;
    order.forEach(item => updateCardBadge(item.id, true));
    order = [];
    renderOrderPanel();
    showToast(`✅ Order #${fakeId} placed! (Demo mode) Total: ₱${formatPrice(grand)}`, 'success');
  }

  btn.disabled    = false;
  btn.textContent = 'Confirm Order';
}

// ============================================
// TOAST
// ============================================
function showToast(msg, type = '') {
  const container = document.getElementById('toastContainer');
  const toast     = document.createElement('div');
  toast.className = `toast ${type}`;
  toast.textContent = msg;
  container.appendChild(toast);
  setTimeout(() => toast.remove(), 3100);
}

// ============================================
// UTILITIES
// ============================================
function formatPrice(num) {
  return parseFloat(num).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function escHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

// Close modal when clicking overlay
document.getElementById('checkoutModal').addEventListener('click', function(e) {
  if (e.target === this) closeCheckoutModal();
});
// ============================================
// CUSTOMER DIRECTORY LINK — POS ORDER PANEL
// ============================================

let _custSearchTimer = null;

/**
 * Fired on every keystroke in the customer name field.
 * If ≥2 chars, searches customers.php and shows a dropdown.
 * Cashier can pick an existing customer to link, or just type a name for walk-ins.
 */
function onCustomerInput(val) {
  clearTimeout(_custSearchTimer);
  const dropdown = document.getElementById('customerDropdown');
  if (!dropdown) return;

  // If already linked, don't re-search
  const linkedId = parseInt(document.getElementById('linkedCustomerId')?.value) || 0;
  if (linkedId > 0) { dropdown.style.display = 'none'; return; }

  if (!val || val.trim().length < 2) {
    dropdown.style.display = 'none';
    return;
  }

  _custSearchTimer = setTimeout(async () => {
    try {
      const res  = await fetch(`./php/customers.php?action=search&q=${encodeURIComponent(val.trim())}`);
      const data = await res.json();

      if (!data || !data.length) {
        dropdown.style.display = 'none';
        return;
      }

      dropdown.innerHTML = data.map(c => `
        <div onclick="linkCustomer(${c.id}, '${escHtml(c.name)}')"
          style="padding:10px 14px;cursor:pointer;display:flex;align-items:center;gap:10px;border-bottom:1px solid #f0ebe3;transition:background 0.15s"
          onmouseover="this.style.background='#faf7f2'" onmouseout="this.style.background=''">
          <div style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#C8A96E,#D4BC8A);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#1A1A1A;flex-shrink:0">
            ${(c.name||'?').slice(0,2).toUpperCase()}
          </div>
          <div style="flex:1;min-width:0">
            <div style="font-size:13px;font-weight:700;color:#2D1A0A;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHtml(c.name)}</div>
            <div style="font-size:11px;color:#999;margin-top:1px">
              ${c.phone || c.email || 'No contact'} &nbsp;·&nbsp; ⭐ ${c.loyalty_pts || 0} pts
            </div>
          </div>
          <div style="font-size:10px;color:#C8A96E;font-weight:700;flex-shrink:0">${getTierLabel(parseInt(c.loyalty_pts)||0)}</div>
        </div>
      `).join('') +
      `<div onclick="document.getElementById('customerDropdown').style.display='none'"
        style="padding:8px 14px;font-size:11.5px;color:#999;text-align:center;cursor:pointer;background:#fafafa">
        Walk-in (no account) — just type a name
      </div>`;

      dropdown.style.display = 'block';
    } catch(e) {
      // customers.php not reachable — silent fail, just use typed name
      dropdown.style.display = 'none';
    }
  }, 280);
}

/** Called when cashier picks a customer from the dropdown */
function linkCustomer(id, name) {
  document.getElementById('customerName').value    = name;
  document.getElementById('linkedCustomerId').value = id;
  document.getElementById('customerDropdown').style.display = 'none';

  const badge = document.getElementById('linkedCustomerBadge');
  if (badge) {
    badge.style.display = 'block';
    badge.innerHTML = `✓ Linked: <strong>${escHtml(name)}</strong> — points &amp; visit will be recorded on completion`;
  }

  const clearBtn = document.getElementById('clearCustomerBtn');
  if (clearBtn) clearBtn.style.display = 'block';
}

/** Unlinks the customer — reverts to walk-in mode */
function clearLinkedCustomer() {
  document.getElementById('linkedCustomerId').value = '0';

  const badge = document.getElementById('linkedCustomerBadge');
  if (badge) badge.style.display = 'none';

  const clearBtn = document.getElementById('clearCustomerBtn');
  if (clearBtn) clearBtn.style.display = 'none';

  const dropdown = document.getElementById('customerDropdown');
  if (dropdown) dropdown.style.display = 'none';
}

/** Close dropdown on outside click */
document.addEventListener('click', function(e) {
  const dd = document.getElementById('customerDropdown');
  const input = document.getElementById('customerName');
  if (dd && input && !dd.contains(e.target) && e.target !== input) {
    dd.style.display = 'none';
  }
});

function getTierLabel(pts) {
  if (pts >= 500) return '💎 Platinum';
  if (pts >= 200) return '🥇 Gold';
  if (pts >= 50)  return '🥈 Silver';
  return '🥉 Bronze';
}

function escHtml(str) {
  return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
