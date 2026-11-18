(function () {
  var bootEl = document.getElementById('kontur-boot');
  if (!bootEl) return;
  var boot = JSON.parse(bootEl.textContent);
  var state = {
    cart: boot.cart || [],
    gross: Number(boot.gross) || 0,
    tax: Number(boot.tax) || 0,
    ship: boot.shipping && boot.shipping[0] ? boot.shipping[0].id : '',
    pay: '',
    promo: null,
    gift: false,
    slot: 'Сьогодні, 14:00 – 20:00',
    accOpen: false,
    promoTab: 'coupon',
    couponDraft: '',
    voucherDraft: '',
    seconds: 15 * 60,
    branchTimer: null
  };

  function $(id) { return document.getElementById(id); }
  function money(num) {
    var n = Math.round(Number(num) * 100) / 100;
    var neg = n < 0;
    var abs = Math.abs(n);
    var intPart = Math.floor(abs + 1e-8);
    var frac = Math.round((abs - intPart) * 100);
    if (frac === 100) { intPart += 1; frac = 0; }
    var s = String(intPart).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    if (frac) s += '.' + String(frac).padStart(2, '0');
    return (neg ? '−' : '') + s + ' ' + (boot.symbol || '₴');
  }
  function noun(n) {
    n = Math.abs(n) % 100;
    if (n >= 5 && n <= 20) return 'товарів';
    n = n % 10;
    if (n === 1) return 'товар';
    if (n >= 2 && n <= 4) return 'товари';
    return 'товарів';
  }
  function toast(msg) {
    var box = $('kontur-toast');
    if (!box) return;
    var el = document.createElement('div');
    el.className = 't';
    el.textContent = msg;
    box.appendChild(el);
    setTimeout(function () { el.remove(); }, 2600);
  }
  function shipById(id) {
    var list = boot.shipping || [];
    for (var i = 0; i < list.length; i++) if (list[i].id === id) return list[i];
    return list[0] || null;
  }
  function activeShip() { return boot.hasShipping ? shipById(state.ship) : null; }
  function allowedPayments() {
    var ship = activeShip();
    var out = [];
    (boot.payments || []).forEach(function (pm) {
      if (!pm.enabled) return;
      if (!ship) { out.push(pm); return; }
      var row = boot.matrix && boot.matrix[ship.id];
      if (row && row[pm.id]) out.push(pm);
    });
    return out;
  }
  function calc() {
    var gross = Math.max(0, state.gross);
    var discount = 0;
    if (state.promo && gross > 0) {
      discount = state.promo.type === 'percent'
        ? Math.round(gross * (state.promo.value / 100) * 100) / 100
        : Math.min(gross, Number(state.promo.value));
    }
    var after = Math.max(0, gross - discount);
    var gift = (boot.showGift && state.gift) ? Number(boot.giftPrice) : 0;
    var ship = activeShip();
    var shipping = 0;
    if (boot.hasShipping && ship && gross > 0) {
      var free = Number(ship.freeFrom) || 0;
      shipping = (free > 0 && after >= free) ? 0 : Number(ship.cost) || 0;
    }
    return { gross: gross, discount: discount, after: after, gift: gift, shipping: shipping, total: after + gift + shipping, ship: ship };
  }
  function itemsHtml(compact) {
    if (!state.cart.length) {
      return '<div style="padding:16px 0;text-align:center;font-size:12px;color:#737373;">Кошик порожній</div>';
    }
    return state.cart.map(function (item) {
      var img = item.image
        ? '<img src="' + item.image + '" alt="">'
        : '<img src="https://placehold.co/120x150/F4F4F4/111111?text=ITEM" alt="">';
      return '<div class="k-item">' + img +
        '<div style="flex:1;min-width:0;">' +
          '<div class="top"><div><div class="nm">' + escapeHtml(item.name) + '</div><div class="k-mono" style="font-size:10px;color:#a3a3a3;">' + escapeHtml(item.model || '') + '</div></div>' +
          '<button type="button" class="k-x" data-remove="' + escapeHtml(item.key) + '">×</button></div>' +
          '<div class="meta">' + escapeHtml(item.options || '') + '</div>' +
          '<div class="k-qty"><div class="box">' +
            (item.voucher ? '<span>' + item.qty + '</span>' :
              '<button type="button" data-qty="' + escapeHtml(item.key) + '" data-d="-1">−</button><span>' + item.qty + '</span><button type="button" data-qty="' + escapeHtml(item.key) + '" data-d="1">+</button>') +
          '</div><b class="k-mono" style="font-size:13px;">' + money(item.price * item.qty) + '</b></div>' +
        '</div></div>';
    }).join('');
  }
  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&': '&', '<': '<', '>': '>', '"': '"', "'": '&#39;' })[c];
    });
  }
  function extraHtml() {
    var c = calc();
    var ship = c.ship;
    var promo = '';
    if (boot.showPromo) {
      promo = '<div class="k-promo">' +
        '<div class="k-promo-tabs">' +
          '<button type="button" data-ptab="coupon" class="' + (state.promoTab === 'coupon' ? 'on' : '') + '">Промокод</button>' +
          '<button type="button" data-ptab="voucher" class="' + (state.promoTab === 'voucher' ? 'on' : '') + '">Подарунковий сертифікат</button>' +
        '</div>' +
        (state.promoTab === 'coupon'
          ? '<div class="k-row-input"><input class="oc-input k-mono" data-coupon value="' + escapeHtml(state.couponDraft) + '" placeholder="Введіть промокод (напр. SALE10)"><button type="button" class="k-apply" data-apply="coupon">Застосувати</button></div>' +
            '<div class="k-note" data-promo-msg>' + (state.promo ? ('✓ ' + escapeHtml(state.promo.code)) : 'Спробуйте <button type="button" data-quick="SALE10">SALE10</button> (−10%) або <button type="button" data-quick="GIFT1000">GIFT1000</button> (−1000)') + '</div>'
          : '<div class="k-row-input"><input class="oc-input k-mono" data-voucher value="' + escapeHtml(state.voucherDraft) + '" placeholder="Код сертифікату (GIFT1000)"><button type="button" class="k-apply" data-apply="voucher">Застосувати</button></div>' +
            '<div class="k-note">Номінал сертифікату буде віднято від суми</div>') +
      '</div>';
    }
    var bar = '';
    if (boot.showFreeBar && ship && Number(ship.freeFrom) > 0) {
      var pct = Math.min(100, Math.round((c.after / Number(ship.freeFrom)) * 100));
      var remain = Math.max(0, Number(ship.freeFrom) - c.after);
      bar = '<div class="k-free"><div class="top"><span>' + (remain === 0 ? '✓ Безкоштовна доставка активована' : ('До безкоштовної доставки: ' + money(remain))) + '</span><b>' + pct + '%</b></div><div class="k-bar"><span style="width:' + pct + '%"></span></div></div>';
    }
    var gift = '';
    if (boot.showGift) {
      gift = '<label class="k-gift"><span class="k-check" style="margin:0;"><input type="checkbox" class="oc-checkbox" data-gift ' + (state.gift ? 'checked' : '') + '> Подарункове пакування</span><span class="k-mono">+ ' + money(boot.giftPrice) + '</span></label>';
    }
    var count = state.cart.reduce(function (a, i) { return a + Number(i.qty); }, 0);
    var totals = '<div class="k-totals">' +
      '<div class="line"><span>Товари (' + count + ')</span><b>' + money(c.gross) + '</b></div>' +
      (c.gift > 0 ? '<div class="line"><span>Подарункове пакування</span><b>+' + money(c.gift) + '</b></div>' : '') +
      (c.discount > 0 ? '<div class="line disc"><span>Знижка (' + escapeHtml(state.promo.code) + ') <button type="button" class="k-x" data-clear-promo>×</button></span><b>−' + money(c.discount) + '</b></div>' : '') +
      '<div class="line"><span>' + (ship ? escapeHtml(ship.title) : 'Доставка') + '</span><b>' + (c.shipping === 0 ? 'Безкоштовно' : money(c.shipping)) + '</b></div>' +
      '<div class="grand"><span>Разом до сплати</span><b>' + money(c.total) + '</b></div></div>';
    return promo + bar + gift + totals;
  }
  function bindExtra(root) {
    if (!root) return;
    root.querySelectorAll('[data-ptab]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        rememberDrafts();
        state.promoTab = btn.getAttribute('data-ptab');
        render();
      });
    });
    root.querySelectorAll('[data-coupon]').forEach(function (input) {
      input.addEventListener('input', function () { state.couponDraft = input.value; });
    });
    root.querySelectorAll('[data-voucher]').forEach(function (input) {
      input.addEventListener('input', function () { state.voucherDraft = input.value; });
    });
    root.querySelectorAll('[data-apply]').forEach(function (btn) {
      btn.addEventListener('click', function () { applyPromo(btn.getAttribute('data-apply')); });
    });
    root.querySelectorAll('[data-quick]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        state.couponDraft = btn.getAttribute('data-quick');
        state.promoTab = 'coupon';
        applyPromo('coupon');
      });
    });
    root.querySelectorAll('[data-gift]').forEach(function (box) {
      box.addEventListener('change', function () { state.gift = box.checked; render(); });
    });
    root.querySelectorAll('[data-clear-promo]').forEach(function (btn) {
      btn.addEventListener('click', function () { state.promo = null; state.couponDraft = ''; render(); });
    });
    root.querySelectorAll('[data-qty]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var key = btn.getAttribute('data-qty');
        var item = state.cart.filter(function (i) { return String(i.key) === String(key); })[0];
        if (!item) return;
        updateCart(key, Number(item.qty) + Number(btn.getAttribute('data-d')));
      });
    });
    root.querySelectorAll('[data-remove]').forEach(function (btn) {
      btn.addEventListener('click', function () { updateCart(btn.getAttribute('data-remove'), 0); });
    });
  }
  function rememberDrafts() {
    document.querySelectorAll('[data-coupon]').forEach(function (el) { state.couponDraft = el.value; });
    document.querySelectorAll('[data-voucher]').forEach(function (el) { state.voucherDraft = el.value; });
  }
  function render() {
    var c = calc();
    var count = state.cart.reduce(function (a, i) { return a + Number(i.qty); }, 0);
    var desk = $('desk-items');
    var mob = $('mobile-items');
    if (desk) { desk.innerHTML = itemsHtml(false); bindExtra(desk); }
    if (mob) { mob.innerHTML = itemsHtml(true); bindExtra(mob); }
    var de = $('desk-extra');
    var me = $('mobile-extra');
    if (de) { de.innerHTML = extraHtml(); bindExtra(de); }
    if (me) { me.innerHTML = extraHtml(); bindExtra(me); }
    var thumbs = $('mobile-thumbs');
    if (thumbs) {
      thumbs.innerHTML = state.cart.slice(0, 3).map(function (item) {
        var src = item.image || 'https://placehold.co/120x150/F4F4F4/111111?text=ITEM';
        return '<img src="' + src + '" alt="">';
      }).join('');
    }
    if ($('mobile-count')) $('mobile-count').textContent = String(count);
    if ($('desk-count')) $('desk-count').textContent = count + ' ' + noun(count);
    if ($('mobile-total')) $('mobile-total').textContent = money(c.total);
    if ($('sticky-total')) $('sticky-total').textContent = money(c.total);
    document.querySelectorAll('[data-ship-price]').forEach(function (el) {
      var id = el.getAttribute('data-ship-price');
      var ship = shipById(id);
      if (!ship) return;
      var free = Number(ship.freeFrom) > 0 && c.after >= Number(ship.freeFrom);
      el.textContent = (Number(ship.cost) === 0 || free) ? 'Безкоштовно' : money(ship.cost);
    });
    syncPayments();
  }
  function syncPayments() {
    var allowed = allowedPayments();
    var ids = allowed.map(function (p) { return p.id; });
    if (!ids.length) state.pay = '';
    else if (ids.indexOf(state.pay) === -1) state.pay = ids[0];
    document.querySelectorAll('[data-pay]').forEach(function (card) {
      var id = card.getAttribute('data-pay');
      var ok = ids.indexOf(id) !== -1;
      card.classList.toggle('k-hidden', !ok);
      card.classList.toggle('active', ok && id === state.pay);
    });
    if ($('pay-count')) $('pay-count').textContent = String(ids.length);
    var invoice = $('invoice-box');
    if (invoice) invoice.classList.toggle('k-hidden', state.pay !== 'invoice');
  }
  function syncShipUI() {
    var ship = activeShip();
    document.querySelectorAll('[data-ship]').forEach(function (card) {
      card.classList.toggle('active', card.getAttribute('data-ship') === state.ship);
    });
    var type = ship ? ship.addressType : 'branch';
    var heading = $('ship-heading');
    if (heading && ship) {
      var map = {
        branch: '2. Дані для доставки Новою Поштою',
        courier: '2. Адреса для кур’єрської доставки',
        postcode: '2. Дані для доставки Укрпоштою',
        pickup: '2. Деталі самовивозу'
      };
      heading.textContent = map[type] || '2. Дані для доставки';
    }
    var city = $('city-block');
    if (city) city.classList.toggle('k-hidden', type === 'pickup');
    ['branch', 'courier', 'postcode', 'pickup'].forEach(function (name) {
      var el = $('fields-' + name);
      if (el) el.classList.toggle('k-hidden', type !== name);
    });
    var addr = $('pickup-address');
    if (addr && ship) addr.textContent = ship.subtitle || '';
    fillBranches();
    syncPayments();
    render();
  }
  function fillBranches() {
    var select = $('select-branch');
    if (!select) return;
    var city = ($('input-city') && $('input-city').value.trim()) || '';
    var q = ($('branch-search') && $('branch-search').value.trim()) || '';
    var apply = function (rows) {
      var html = '<option value="">— Оберіть відділення у м. ' + escapeHtml(city || '...') + ' —</option>';
      rows.forEach(function (row) { html += '<option value="' + escapeHtml(row) + '">' + escapeHtml(row) + '</option>'; });
      select.innerHTML = html;
      if (rows.length) select.selectedIndex = 1;
    };
    var local = localBranches(city).filter(function (row) {
      return !q || row.toLowerCase().indexOf(q.toLowerCase()) !== -1;
    });
    apply(local);
    if (boot.npReal && city) {
      var url = boot.urls.branches + (boot.urls.branches.indexOf('?') === -1 ? '?' : '&') + 'city=' + encodeURIComponent(city) + '&q=' + encodeURIComponent(q);
      fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (data) {
        if (data && data.branches && data.branches.length) apply(data.branches);
      }).catch(function () {});
    }
  }
  function localBranches(city) {
    var map = boot.branches || {};
    var keys = Object.keys(map);
    for (var i = 0; i < keys.length; i++) {
      if (keys[i].toLowerCase() === city.trim().toLowerCase()) return map[keys[i]].slice();
    }
    var label = city || 'ваше місто';
    return [
      'Відділення №1 (до 30 кг): м. ' + label + ', центральне відділення',
      'Відділення №2: м. ' + label + ', вул. Соборна, 12',
      'Поштомат №1024: м. ' + label + ', цілодобовий доступ'
    ];
  }
  function updateCart(key, qty) {
    fetch(boot.urls.cart, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ key: key, qty: qty })
    }).then(function (r) { return r.json(); }).then(function (data) {
      if (data.empty) { window.location = data.cart_url || boot.urls.cartPage; return; }
      state.cart = data.cart || [];
      state.gross = Number(data.gross) || 0;
      if (typeof data.tax === 'number') state.tax = data.tax;
      render();
    }).catch(function () { toast('Не вдалося оновити кошик'); });
  }
  function applyPromo(kind) {
    rememberDrafts();
    var code = kind === 'voucher' ? state.voucherDraft : state.couponDraft;
    fetch(boot.urls.coupon, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ code: code })
    }).then(function (r) { return r.json(); }).then(function (data) {
      if (data.success) {
        state.promo = data.promo;
        state.couponDraft = data.promo.code;
        toast(data.message);
      } else {
        state.promo = null;
        toast(data.message || 'Промокод не знайдено');
      }
      render();
    }).catch(function () { toast('Не вдалося перевірити промокод'); });
  }
  function clearErrors() {
    document.querySelectorAll('.input-error').forEach(function (el) { el.classList.remove('input-error'); });
    document.querySelectorAll('.checkbox-error').forEach(function (el) { el.classList.remove('checkbox-error'); });
    document.querySelectorAll('.k-error').forEach(function (el) { el.classList.remove('on'); el.textContent = ''; });
  }
  function setErr(inputId, errorId, msg) {
    var input = $(inputId);
    var error = $(errorId);
    if (input) input.classList.add('input-error');
    if (error) { error.textContent = msg; error.classList.add('on'); }
  }
  function fieldValue(id) {
    var el = $('field-' + id);
    return el ? el.value.trim() : '';
  }
  function collectFields() {
    var out = {};
    document.querySelectorAll('[data-field]').forEach(function (el) { out[el.getAttribute('data-field')] = el.value.trim(); });
    return out;
  }
  function validate() {
    clearErrors();
    var first = null;
    function bad(el, errorId, msg) {
      if (el) el.classList.add('input-error');
      var error = $(errorId);
      if (error) { error.textContent = msg; error.classList.add('on'); }
      if (!first) first = el || error;
    }
    document.querySelectorAll('[data-field]').forEach(function (el) {
      var id = el.getAttribute('data-field');
      var required = el.getAttribute('data-required') === '1';
      var type = el.getAttribute('data-type');
      var val = el.value.trim();
      if (type === 'tel' || id === 'telephone') {
        var digits = val.replace(/\D/g, '');
        if (required && digits.length < 12) bad(el, 'error-' + id, 'Введіть коректний номер телефону');
        else if (boot.validateOperator && digits.length >= 5) {
          var op = digits.substring(2, 5);
          if (boot.operators.indexOf(op) === -1) bad(el, 'error-' + id, 'Невідомий код оператора (' + op + ')');
        }
        return;
      }
      if (type === 'email' || id === 'email') {
        var ok = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val);
        if (required && !ok) bad(el, 'error-' + id, 'Введіть коректний E-mail');
        else if (val && !ok) bad(el, 'error-' + id, 'Введіть коректний E-mail');
        return;
      }
      if (required && !val) bad(el, 'error-' + id, 'Заповніть поле');
    });
    var ship = activeShip();
    if (ship && ship.addressType !== 'pickup') {
      if (!$('input-city') || !$('input-city').value.trim()) bad($('input-city'), 'error-city', 'Оберіть або введіть місто доставки');
    }
    if (ship && ship.addressType === 'branch' && $('select-branch') && !$('select-branch').value) bad($('select-branch'), 'error-branch', 'Оберіть відділення або поштомат');
    if (ship && ship.addressType === 'courier') {
      if (!$('input-street').value.trim()) bad($('input-street'), 'error-street', 'Вкажіть назву вулиці');
      if (!$('input-house').value.trim()) bad($('input-house'), 'error-house', 'Вкажіть номер будинку');
    }
    if (ship && ship.addressType === 'postcode') {
      if (($('input-postcode').value || '').replace(/\D/g, '').length !== 5) bad($('input-postcode'), 'error-postcode', 'Введіть 5 цифр індексу');
      if (!$('input-up').value.trim()) bad($('input-up'), 'error-up_branch', 'Вкажіть відділення Укрпошти');
    }
    if (state.pay === 'invoice') {
      var ed = ($('input-edrpou').value || '').replace(/\D/g, '');
      if (ed.length !== 8 && ed.length !== 10) bad($('input-edrpou'), 'error-edrpou', 'Введіть 8 або 10 цифр');
      if (!$('input-company').value.trim()) bad($('input-company'), 'error-company', 'Вкажіть назву платника');
    }
    if (boot.requirePrivacy) {
      var agreed = ($('agree-desk') && $('agree-desk').checked) || ($('agree-mobile') && $('agree-mobile').checked);
      if (!agreed) {
        ['agree-desk', 'agree-mobile'].forEach(function (id) {
          var box = $(id);
          if (box) box.classList.add('checkbox-error');
        });
        bad(null, 'error-agree', 'Необхідно прийняти умови публічної оферти');
        var em = $('error-agree-mobile');
        if (em) { em.textContent = 'Необхідно прийняти умови публічної оферти'; em.classList.add('on'); }
      }
    }
    if (first && first.scrollIntoView) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return !first;
  }
  function payload() {
    var ship = activeShip();
    return {
      fields: collectFields(),
      shipping_id: ship ? ship.id : '',
      payment_id: state.pay,
      city: $('input-city') ? $('input-city').value.trim() : '',
      branch: $('select-branch') ? $('select-branch').value : '',
      street: $('input-street') ? $('input-street').value.trim() : '',
      house: $('input-house') ? $('input-house').value.trim() : '',
      apt: $('input-apt') ? $('input-apt').value.trim() : '',
      courier_time: $('select-time') ? $('select-time').value : '',
      postcode: $('input-postcode') ? $('input-postcode').value.trim() : '',
      up_branch: $('input-up') ? $('input-up').value.trim() : '',
      showroom_slot: state.slot,
      edrpou: $('input-edrpou') ? $('input-edrpou').value.trim() : '',
      company: $('input-company') ? $('input-company').value.trim() : '',
      comment: $('input-comment') ? $('input-comment').value.trim() : '',
      no_call: $('input-nocall') ? $('input-nocall').checked : false,
      gift_wrap: state.gift,
      promo: state.promo ? state.promo.code : '',
      agree: boot.requirePrivacy ? (($('agree-desk') && $('agree-desk').checked) || ($('agree-mobile') && $('agree-mobile').checked)) : true
    };
  }
  function submitOrder() {
    if (!state.cart.length) { toast('Додайте хоча б один товар до кошика'); return; }
    if (!validate()) { toast('Перевірте виділені поля форми'); return; }
    var body = payload();
    fetch(boot.urls.confirm, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json(); }).then(function (data) {
      if (data.redirect) { window.location = data.redirect; return; }
      if (data.errors) {
        Object.keys(data.errors).forEach(function (key) {
          var map = { branch: 'select-branch', city: 'input-city', street: 'input-street', house: 'input-house', postcode: 'input-postcode', up_branch: 'input-up', edrpou: 'input-edrpou', company: 'input-company', agree: 'agree-desk' };
          var input = map[key] ? $(map[key]) : $('field-' + key);
          var errorId = key === 'up_branch' ? 'error-up_branch' : ('error-' + key);
          if (input) input.classList.add(key === 'agree' ? 'checkbox-error' : 'input-error');
          var error = $(errorId);
          if (error) { error.textContent = data.errors[key]; error.classList.add('on'); }
        });
        toast(data.message || 'Перевірте виділені поля форми');
        return;
      }
      if (!data.success) { toast(data.message || 'Не вдалося створити замовлення'); return; }
      $('modal-name').textContent = data.firstname || '';
      $('modal-number').textContent = data.order_number || '';
      $('modal-ship').textContent = data.shipping || '';
      $('modal-address').textContent = data.address || '';
      $('modal-pay').textContent = data.payment || '';
      $('modal-total').textContent = data.total || '';
      var note = $('modal-email-note');
      note.textContent = data.email ? (' та на ' + data.email) : '';
      if (data.success_url) $('modal-success-link').setAttribute('href', data.success_url);
      $('success-modal').classList.add('on');
      if (data.analytics && data.analytics.ga4 && window.dataLayer) {
        window.dataLayer.push({ event: 'purchase', ecommerce: { transaction_id: data.order_number, value: data.analytics.value, currency: data.analytics.currency } });
      } else if (data.analytics && data.analytics.ga4) {
        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push({ event: 'purchase', ecommerce: { transaction_id: data.order_number, value: data.analytics.value, currency: data.analytics.currency } });
      }
      if (data.analytics && data.analytics.meta && window.fbq) {
        window.fbq('track', 'Purchase', { value: data.analytics.value, currency: data.analytics.currency });
      }
    }).catch(function () { toast('Помилка з’єднання. Замовлення не створено.'); });
  }

  document.querySelectorAll('[data-ship]').forEach(function (card) {
    card.addEventListener('click', function () {
      state.ship = card.getAttribute('data-ship');
      syncShipUI();
    });
  });
  document.querySelectorAll('[data-pay]').forEach(function (card) {
    card.addEventListener('click', function () {
      state.pay = card.getAttribute('data-pay');
      syncPayments();
    });
  });
  document.querySelectorAll('[data-city]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if ($('input-city')) $('input-city').value = btn.getAttribute('data-city');
      document.querySelectorAll('[data-city]').forEach(function (b) { b.classList.toggle('on', b === btn); });
      fillBranches();
    });
  });
  if ($('input-city')) {
    $('input-city').addEventListener('input', function () {
      var val = this.value.trim().toLowerCase();
      var box = $('city-ac');
      clearTimeout(state.branchTimer);
      state.branchTimer = setTimeout(fillBranches, 250);
      if (!box) return;
      if (!val) { box.classList.remove('on'); return; }
      var matches = (boot.cities || []).filter(function (c) { return c.toLowerCase().indexOf(val) !== -1; });
      if (!matches.length) { box.classList.remove('on'); return; }
      box.innerHTML = matches.map(function (c) {
        return '<button type="button" data-pick="' + escapeHtml(c) + '"><span>' + escapeHtml(c) + '</span><span style="color:#a3a3a3;">Україна</span></button>';
      }).join('');
      box.classList.add('on');
      box.querySelectorAll('[data-pick]').forEach(function (b) {
        b.addEventListener('click', function () {
          $('input-city').value = b.getAttribute('data-pick');
          box.classList.remove('on');
          fillBranches();
        });
      });
    });
  }
  if ($('branch-search')) {
    $('branch-search').addEventListener('input', function () {
      clearTimeout(state.branchTimer);
      state.branchTimer = setTimeout(fillBranches, 250);
    });
  }
  var phone = $('field-telephone');
  if (phone) {
    phone.addEventListener('input', function () {
      var digits = this.value.replace(/\D/g, '');
      if (!digits) { this.value = ''; return; }
      if (digits.indexOf('80') === 0) digits = '3' + digits;
      else if (digits.indexOf('0') === 0) digits = '38' + digits;
      else if (digits.indexOf('380') !== 0) digits = '380' + digits;
      digits = digits.substring(0, 12);
      var f = '+38';
      if (digits.length > 2) f += ' (' + digits.substring(2, 5);
      if (digits.length >= 5) f += ') ' + digits.substring(5, 8);
      if (digits.length >= 8) f += '-' + digits.substring(8, 10);
      if (digits.length >= 10) f += '-' + digits.substring(10, 12);
      this.value = f;
      var hint = $('phone-hint');
      if (hint && boot.validateOperator && digits.length >= 5) {
        var op = digits.substring(2, 5);
        var ok = boot.operators.indexOf(op) !== -1;
        hint.textContent = ok ? ('Код оператора України (' + op + ') підтверджено') : ('Невідомий код оператора (' + op + ')');
        hint.className = 'k-hint ' + (ok ? 'ok' : 'bad');
        this.classList.toggle('input-error', !ok);
      } else if (hint) {
        hint.textContent = '';
      }
    });
  }
  var post = $('input-postcode');
  if (post) post.addEventListener('input', function () { this.value = this.value.replace(/\D/g, '').substring(0, 5); });
  var edr = $('input-edrpou');
  if (edr) edr.addEventListener('input', function () { this.value = this.value.replace(/\D/g, '').substring(0, 10); });

  document.querySelectorAll('.k-slot').forEach(function (btn) {
    btn.addEventListener('click', function () {
      state.slot = btn.getAttribute('data-slot');
      document.querySelectorAll('.k-slot').forEach(function (b) { b.classList.toggle('on', b === btn); });
    });
  });
  if ($('acc-toggle')) {
    $('acc-toggle').addEventListener('click', function () {
      state.accOpen = !state.accOpen;
      $('acc-body').classList.toggle('on', state.accOpen);
    });
  }
  if ($('sticky-jump')) {
    $('sticky-jump').addEventListener('click', function () {
      state.accOpen = true;
      if ($('acc-body')) $('acc-body').classList.add('on');
      var acc = $('mobile-order-accordion');
      if (acc) acc.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  }
  if ($('comment-toggle')) {
    $('comment-toggle').addEventListener('click', function () {
      var box = $('comment-box');
      var hidden = box.classList.toggle('k-hidden');
      this.textContent = hidden ? 'Додати коментар' : 'Згорнути';
    });
  }
  function syncAgree(checked) {
    if ($('agree-desk')) $('agree-desk').checked = checked;
    if ($('agree-mobile')) $('agree-mobile').checked = checked;
  }
  if ($('agree-desk')) $('agree-desk').addEventListener('change', function () { syncAgree(this.checked); });
  if ($('agree-mobile')) $('agree-mobile').addEventListener('change', function () { syncAgree(this.checked); });
  if ($('btn-submit')) $('btn-submit').addEventListener('click', submitOrder);
  if ($('btn-submit-mobile')) $('btn-submit-mobile').addEventListener('click', submitOrder);
  if ($('modal-close')) $('modal-close').addEventListener('click', function () { $('success-modal').classList.remove('on'); });
  if ($('tab-new')) $('tab-new').addEventListener('click', function () {
    $('tab-new').classList.add('on'); $('tab-return').classList.remove('on'); $('login-box').classList.add('k-hidden');
  });
  if ($('tab-return')) $('tab-return').addEventListener('click', function () {
    $('tab-return').classList.add('on'); $('tab-new').classList.remove('on'); $('login-box').classList.remove('k-hidden');
  });
  if ($('login-close')) $('login-close').addEventListener('click', function () {
    if ($('tab-new')) $('tab-new').click();
  });
  if ($('login-submit')) $('login-submit').addEventListener('click', function () {
    fetch(boot.urls.login, {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: $('login-email').value.trim(), password: $('login-password').value })
    }).then(function (r) { return r.json(); }).then(function (data) {
      if (!data.success) { toast(data.message || 'Невірний логін або пароль'); return; }
      ['firstname', 'lastname', 'email', 'telephone'].forEach(function (id) {
        var el = $('field-' + id);
        if (el && data.customer[id]) el.value = data.customer[id];
      });
      toast('Ви увійшли в акаунт. Дані заповнено автоматично.');
      if ($('tab-new')) $('tab-new').click();
    }).catch(function () { toast('Не вдалося увійти'); });
  });

  if (boot.showTimer) {
    setInterval(function () {
      if (state.seconds <= 0) return;
      state.seconds--;
      var m = String(Math.floor(state.seconds / 60)).padStart(2, '0');
      var s = String(state.seconds % 60).padStart(2, '0');
      if ($('reserve-clock')) $('reserve-clock').textContent = m + ':' + s;
    }, 1000);
  }
  if (boot.analytics && boot.analytics.ga4) {
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({ event: 'begin_checkout' });
  }
  if (boot.analytics && boot.analytics.meta && window.fbq) {
    window.fbq('track', 'InitiateCheckout');
  }

  var root = document.getElementById('kontur-onepage');
  if (root && root.className.indexOf('pattern-compact_top') !== -1 && $('acc-body')) {
    $('acc-body').classList.add('on');
  }
  syncShipUI();
})();
