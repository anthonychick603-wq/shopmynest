(() => {
  'use strict';

  const config = window.TNMFrontend || {};

  const activateTab = (name) => {
    document.querySelectorAll('[data-tnm-tab]').forEach((button) => {
      button.classList.toggle('is-active', button.dataset.tnmTab === name);
    });
    document.querySelectorAll('[data-tnm-panel]').forEach((panel) => {
      panel.classList.toggle('is-active', panel.dataset.tnmPanel === name);
    });
    try {
      window.sessionStorage.setItem('tnmSellerTab', name);
    } catch (error) {
      // Storage may be disabled; the dashboard still works.
    }
  };

  // Manual L/W/H inputs are only relevant when the seller picks "Custom";
  // a preset fills those dimensions server-side from the shared preset table.
  const togglePackageDimensions = (form) => {
    if (!form) {
      return;
    }
    const select = form.querySelector('[data-tnm-package-size]');
    const dimensions = form.querySelector('[data-tnm-dimensions]');
    if (!select || !dimensions) {
      return;
    }
    dimensions.hidden = select.value !== 'custom';
  };

  const resetProductForm = (form) => {
    if (!form) {
      return;
    }
    const actionField = form.querySelector('[data-tnm-action-field]');
    const productId = form.querySelector('[data-tnm-product-id]');
    const submit = form.querySelector('[data-tnm-submit]');
    const cancel = form.querySelector('[data-tnm-cancel-edit]');
    const title = form.closest('.tnm-card')?.querySelector('[data-tnm-form-title]');
    if (actionField) actionField.value = 'create_product';
    if (productId) productId.value = '';
    if (submit) submit.textContent = 'Create product';
    if (cancel) cancel.hidden = true;
    if (title) title.textContent = 'Add product';
    const gate = form.closest('[data-tnm-listing-gate]');
    if (gate && gate.dataset.tnmListingGate === 'blocked') gate.hidden = true;
    form.reset();
    if (actionField) actionField.value = 'create_product';
    if (productId) productId.value = '';
    togglePackageDimensions(form);
  };

  const fillProductForm = (button) => {
    const form = document.querySelector('[data-tnm-product-form]');
    if (!form) {
      return;
    }
    const data = button.dataset;
    const setField = (name, value) => {
      const field = form.querySelector(`[name="${name}"]`);
      if (field) {
        field.value = value ?? '';
      }
    };
    setField('product_name', data.name);
    setField('product_description', data.description);
    setField('product_price', data.price);
    setField('product_stock', data.stock);
    setField('product_sku', data.sku);
    setField('shipping_weight_oz', data.weightOz);
    setField('shipping_length_in', data.lengthIn);
    setField('shipping_width_in', data.widthIn);
    setField('shipping_height_in', data.heightIn);
    setField('shipping_package_size', data.packageSize || 'custom');
    setField('shipping_processing_time', data.processingTime);
    togglePackageDimensions(form);

    const actionField = form.querySelector('[data-tnm-action-field]');
    const productId = form.querySelector('[data-tnm-product-id]');
    const submit = form.querySelector('[data-tnm-submit]');
    const cancel = form.querySelector('[data-tnm-cancel-edit]');
    const title = form.closest('.tnm-card')?.querySelector('[data-tnm-form-title]');
    if (actionField) actionField.value = 'update_product';
    if (productId) productId.value = data.productId || '';
    if (submit) submit.textContent = 'Update product';
    if (cancel) cancel.hidden = false;
    if (title) title.textContent = 'Edit product';
    // The form is hidden while Stripe onboarding is unfinished, but editing an
    // existing listing is still permitted, so reveal it for the edit.
    const gate = form.closest('[data-tnm-listing-gate]');
    if (gate) gate.hidden = false;
    activateTab('products');
    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };

  const apiRequest = async (path, options = {}) => {
    const root = String(config.restRoot || '/wp-json/').replace(/\/?$/, '/');
    const endpoint = `${root}${String(path).replace(/^\/+/, '')}`;
    const request = {
      method: options.method || 'GET',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
      },
    };

    if (config.restNonce) {
      request.headers['X-WP-Nonce'] = config.restNonce;
    }
    if (options.body !== undefined) {
      request.headers['Content-Type'] = 'application/json';
      request.body = JSON.stringify(options.body);
    }

    let response;
    try {
      response = await fetch(endpoint, request);
    } catch (error) {
      throw new Error('Could not connect to the shipping service. Check your internet connection and try again.');
    }

    let data = {};
    try {
      data = await response.json();
    } catch (error) {
      data = {};
    }

    if (!response.ok) {
      throw new Error(data.message || 'The shipping request failed. Please try again.');
    }

    return data;
  };

  const setShippingMessage = (container, message = '', type = '') => {
    const target = container?.querySelector('[data-tnm-shipping-message]');
    if (!target) {
      return;
    }
    target.textContent = message;
    target.classList.toggle('is-error', type === 'error');
    target.classList.toggle('is-success', type === 'success');
  };

  const setShippingBusy = (container, busy, activeButton = null) => {
    container?.querySelectorAll('button').forEach((button) => {
      button.disabled = Boolean(busy);
    });
    if (activeButton) {
      if (busy) {
        activeButton.dataset.originalText = activeButton.textContent;
        activeButton.textContent = 'Working…';
      } else if (activeButton.dataset.originalText) {
        activeButton.textContent = activeButton.dataset.originalText;
        delete activeButton.dataset.originalText;
      }
    }
  };

  const rateName = (rate) => {
    const provider = String(rate?.provider || 'Carrier');
    const service = String(rate?.servicelevel?.name || rate?.servicelevel?.token || 'Shipping');
    return { provider, service };
  };

  const formatRateAmount = (amount, currency = 'USD') => {
    const number = Number.parseFloat(amount);
    if (!Number.isFinite(number)) {
      return `${config.currencySymbol || '$'}${amount || '0.00'}`;
    }
    try {
      return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency || 'USD',
      }).format(number);
    } catch (error) {
      return `${config.currencySymbol || '$'}${number.toFixed(2)}`;
    }
  };

  const renderRates = (container, rates) => {
    const target = container.querySelector('[data-tnm-rate-results]');
    if (!target) {
      return;
    }

    target.replaceChildren();
    target.hidden = false;

    const heading = document.createElement('h4');
    heading.textContent = 'Choose a shipping service';
    target.appendChild(heading);

    const list = document.createElement('div');
    list.className = 'tnm-rate-list';
    const groupName = `tnm-rate-${container.dataset.tnmShippingOrder || Date.now()}`;

    rates.forEach((rate, index) => {
      const names = rateName(rate);
      const label = document.createElement('label');
      label.className = 'tnm-rate-option';

      const radio = document.createElement('input');
      radio.type = 'radio';
      radio.name = groupName;
      radio.value = String(rate.object_id || '');
      radio.checked = index === 0;
      radio.dataset.provider = names.provider;
      radio.dataset.service = names.service;
      radio.dataset.amount = String(rate.amount || '');
      radio.dataset.currency = String(rate.currency || 'USD');

      const copy = document.createElement('span');
      copy.className = 'tnm-rate-copy';

      const title = document.createElement('strong');
      title.textContent = `${names.provider} ${names.service}`;
      copy.appendChild(title);

      const detail = document.createElement('small');
      const eta = rate.estimated_days
        ? `Estimated ${rate.estimated_days} day${Number(rate.estimated_days) === 1 ? '' : 's'}`
        : String(rate.duration_terms || '').trim();
      detail.textContent = eta || 'Delivery estimate provided by the carrier';
      copy.appendChild(detail);

      const price = document.createElement('strong');
      price.className = 'tnm-rate-price';
      price.textContent = formatRateAmount(rate.amount, rate.currency);

      label.append(radio, copy, price);
      list.appendChild(label);
    });

    target.appendChild(list);

    const note = document.createElement('p');
    note.className = 'tnm-muted tnm-rate-note';
    note.textContent = config.shippoTestMode
      ? 'Test mode: this creates a test label and does not purchase live postage.'
      : 'Live mode: purchasing the selected label can charge the connected Shippo account.';
    target.appendChild(note);

    const actions = document.createElement('div');
    actions.className = 'tnm-form-actions';

    const buy = document.createElement('button');
    buy.type = 'button';
    buy.className = 'tnm-small-button';
    buy.dataset.tnmBuyLabel = '1';
    buy.textContent = config.shippoTestMode ? 'Create test label' : 'Buy selected label';

    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'tnm-small-button tnm-button-secondary';
    cancel.dataset.tnmCancelRates = '1';
    cancel.textContent = 'Cancel';

    actions.append(buy, cancel);
    target.appendChild(actions);
  };

  const renderLabel = (container, label = {}) => {
    const summary = container.querySelector('[data-tnm-label-summary]');
    const rateResults = container.querySelector('[data-tnm-rate-results]');
    if (!summary) {
      return;
    }

    summary.replaceChildren();
    if (rateResults) {
      rateResults.hidden = true;
      rateResults.replaceChildren();
    }

    if (label.label_url) {
      const ready = document.createElement('div');
      ready.className = 'tnm-label-ready';

      const copy = document.createElement('p');
      const heading = document.createElement('strong');
      heading.textContent = 'Label ready';
      copy.appendChild(heading);

      const service = `${label.carrier || ''} ${label.service || ''}`.trim();
      if (service) {
        copy.append(document.createElement('br'));
        const serviceText = document.createElement('span');
        serviceText.textContent = service;
        copy.appendChild(serviceText);
      }
      if (label.tracking_number) {
        copy.append(document.createElement('br'));
        const tracking = document.createElement('span');
        tracking.textContent = `Tracking: ${label.tracking_number}`;
        copy.appendChild(tracking);
      }

      const link = document.createElement('a');
      link.className = 'tnm-small-button';
      link.href = label.label_url;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      link.textContent = 'Open PDF label';

      ready.append(copy, link);
      summary.appendChild(ready);
      setShippingMessage(container, 'The label and tracking number were saved to this order.', 'success');
      return;
    }

    const pending = document.createElement('p');
    pending.className = 'tnm-label-pending';
    const strong = document.createElement('strong');
    strong.textContent = 'Label processing';
    const text = document.createElement('span');
    text.textContent = 'Shippo is still preparing the PDF. Refresh in a moment.';
    pending.append(strong, document.createElement('br'), text);

    const refresh = document.createElement('button');
    refresh.type = 'button';
    refresh.className = 'tnm-small-button tnm-button-secondary';
    refresh.dataset.tnmRefreshLabel = '1';
    refresh.textContent = 'Refresh label';

    summary.append(pending, refresh);
    setShippingMessage(container, 'The label purchase is processing.', 'success');
  };

  const getRates = async (button) => {
    const container = button.closest('[data-tnm-shipping-order]');
    const orderId = container?.dataset.tnmShippingOrder;
    if (!container || !orderId) {
      return;
    }

    if (!config.shippoConfigured) {
      setShippingMessage(container, 'Shippo is not configured. An administrator must add the API token first.', 'error');
      return;
    }

    setShippingMessage(container, 'Requesting current carrier rates…');
    setShippingBusy(container, true, button);
    try {
      const data = await apiRequest(`nest-labels/v1/seller/orders/${encodeURIComponent(orderId)}/rates`, {
        method: 'POST',
        body: {},
      });
      const rates = Array.isArray(data.rates) ? data.rates : [];
      if (!rates.length) {
        throw new Error('No shipping rates were returned. Check the address and package dimensions.');
      }
      renderRates(container, rates);
      setShippingMessage(container, `${rates.length} shipping option${rates.length === 1 ? '' : 's'} found.`, 'success');
    } catch (error) {
      setShippingMessage(container, error.message || 'Could not retrieve shipping rates.', 'error');
    } finally {
      setShippingBusy(container, false, button);
    }
  };

  const buyLabel = async (button) => {
    const container = button.closest('[data-tnm-shipping-order]');
    const orderId = container?.dataset.tnmShippingOrder;
    const selected = container?.querySelector('[data-tnm-rate-results] input[type="radio"]:checked');
    if (!container || !orderId || !selected) {
      setShippingMessage(container, 'Select a shipping service first.', 'error');
      return;
    }

    const displayPrice = formatRateAmount(selected.dataset.amount, selected.dataset.currency);
    if (!config.shippoTestMode) {
      const confirmed = window.confirm(`Purchase this ${selected.dataset.provider} ${selected.dataset.service} label for ${displayPrice}? This may charge the connected Shippo account.`);
      if (!confirmed) {
        return;
      }
    }

    setShippingMessage(container, config.shippoTestMode ? 'Creating the test label…' : 'Purchasing the shipping label…');
    setShippingBusy(container, true, button);
    try {
      const data = await apiRequest(`nest-labels/v1/seller/orders/${encodeURIComponent(orderId)}/label`, {
        method: 'POST',
        body: {
          rate: selected.value,
          provider: selected.dataset.provider || '',
          service: selected.dataset.service || '',
          amount: selected.dataset.amount || '',
          currency: selected.dataset.currency || 'USD',
        },
      });
      renderLabel(container, data.label || {});
    } catch (error) {
      setShippingMessage(container, error.message || 'Could not purchase the label.', 'error');
    } finally {
      setShippingBusy(container, false, button);
    }
  };

  const refreshLabel = async (button) => {
    const container = button.closest('[data-tnm-shipping-order]');
    const orderId = container?.dataset.tnmShippingOrder;
    if (!container || !orderId) {
      return;
    }

    setShippingMessage(container, 'Checking the label status…');
    setShippingBusy(container, true, button);
    try {
      const data = await apiRequest(`nest-labels/v1/seller/orders/${encodeURIComponent(orderId)}/label`);
      renderLabel(container, data.label || {});
    } catch (error) {
      setShippingMessage(container, error.message || 'Could not refresh the label.', 'error');
    } finally {
      setShippingBusy(container, false, button);
    }
  };

  document.addEventListener('click', (event) => {
    const tab = event.target.closest('[data-tnm-tab]');
    if (tab) {
      activateTab(tab.dataset.tnmTab);
    }

    const opener = event.target.closest('[data-tnm-open-tab]');
    if (opener) {
      activateTab(opener.dataset.tnmOpenTab);
      document.querySelector('.tnm-tabs')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    const editButton = event.target.closest('[data-tnm-edit-product]');
    if (editButton) {
      fillProductForm(editButton);
    }

    const cancelButton = event.target.closest('[data-tnm-cancel-edit]');
    if (cancelButton) {
      resetProductForm(cancelButton.closest('[data-tnm-product-form]'));
    }

    const ratesButton = event.target.closest('[data-tnm-get-rates]');
    if (ratesButton) {
      getRates(ratesButton);
    }

    const buyButton = event.target.closest('[data-tnm-buy-label]');
    if (buyButton) {
      buyLabel(buyButton);
    }

    const refreshButton = event.target.closest('[data-tnm-refresh-label]');
    if (refreshButton) {
      refreshLabel(refreshButton);
    }

    const cancelRates = event.target.closest('[data-tnm-cancel-rates]');
    if (cancelRates) {
      const container = cancelRates.closest('[data-tnm-shipping-order]');
      const results = container?.querySelector('[data-tnm-rate-results]');
      if (results) {
        results.hidden = true;
        results.replaceChildren();
      }
      setShippingMessage(container, '');
    }
  });

  document.addEventListener('change', (event) => {
    const packageSize = event.target.closest('[data-tnm-package-size]');
    if (packageSize) {
      togglePackageDimensions(packageSize.closest('[data-tnm-product-form]'));
    }
  });

  document.addEventListener('DOMContentLoaded', () => {
    let saved = '';
    try {
      saved = window.sessionStorage.getItem('tnmSellerTab') || '';
    } catch (error) {
      saved = '';
    }
    if (saved && document.querySelector(`[data-tnm-tab="${CSS.escape(saved)}"]`)) {
      activateTab(saved);
    }
    document.querySelectorAll('[data-tnm-product-form]').forEach(togglePackageDimensions);
  });
})();
