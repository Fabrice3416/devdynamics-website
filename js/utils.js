// ============================================
// UTILITY FUNCTIONS
// ============================================

// DOM Helpers
function $(selector) {
  return document.querySelector(selector);
}

function $$(selector) {
  return document.querySelectorAll(selector);
}

function createElement(tag, className = '', innerHTML = '') {
  const element = document.createElement(tag);
  if (className) element.className = className;
  if (innerHTML) element.innerHTML = innerHTML;
  return element;
}

// String Utilities
function slugify(text) {
  return text
    .toLowerCase()
    .trim()
    .replace(/[^\w\s-]/g, '')
    .replace(/[\s_]+/g, '-')
    .replace(/^-+|-+$/g, '');
}

function truncate(text, length = 100) {
  if (text.length <= length) return text;
  return text.substring(0, length) + '...';
}

function capitalize(text) {
  return text.charAt(0).toUpperCase() + text.slice(1);
}

// Date Utilities
function formatDate(date, format = 'DD/MM/YYYY') {
  const d = new Date(date);
  const day = String(d.getDate()).padStart(2, '0');
  const month = String(d.getMonth() + 1).padStart(2, '0');
  const year = d.getFullYear();

  return format
    .replace('DD', day)
    .replace('MM', month)
    .replace('YYYY', year);
}

function timeAgo(date) {
  const seconds = Math.floor((new Date() - new Date(date)) / 1000);
  const intervals = {
    année: 31536000,
    mois: 2592000,
    semaine: 604800,
    jour: 86400,
    heure: 3600,
    minute: 60
  };

  for (const [name, secondsInInterval] of Object.entries(intervals)) {
    const interval = Math.floor(seconds / secondsInInterval);
    if (interval >= 1) {
      return `il y a ${interval} ${name}${interval > 1 ? 's' : ''}`;
    }
  }
  return 'à l\'instant';
}

// Validation
function isEmail(email) {
  const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  return regex.test(email);
}

function isPhone(phone) {
  const regex = /^[\d\s\-\+\(\)]+$/;
  return regex.test(phone) && phone.replace(/\D/g, '').length >= 10;
}

function isEmpty(value) {
  return value === null || value === undefined || value === '';
}

// Form Utilities
function getFormData(formElement) {
  const formData = new FormData(formElement);
  const data = {};
  for (let [key, value] of formData.entries()) {
    data[key] = value;
  }
  return data;
}

function setFormErrors(formElement, errors) {
  // Clear previous errors
  $$('.form-error').forEach(el => el.remove());

  // Set new errors
  for (const [field, message] of Object.entries(errors)) {
    const input = formElement.querySelector(`[name="${field}"]`);
    if (input) {
      const errorEl = createElement('div', 'form-error', message);
      input.parentNode.appendChild(errorEl);
      input.classList.add('error');
    }
  }
}

function clearFormErrors(formElement) {
  $$('.form-error').forEach(el => el.remove());
  $$('.error').forEach(el => el.classList.remove('error'));
}

// Notification/Toast
function showNotification(message, type = 'info', duration = 3000) {
  const notification = createElement('div', `alert alert-${type}`, message);
  notification.style.position = 'fixed';
  notification.style.top = '100px';
  notification.style.right = '20px';
  notification.style.zIndex = '9999';
  notification.style.maxWidth = '400px';
  notification.style.boxShadow = '0 4px 6px rgba(0, 0, 0, 0.1)';

  document.body.appendChild(notification);

  setTimeout(() => {
    notification.remove();
  }, duration);
}

// Modal Utilities
function openModal(modalId) {
  const modal = $(`#${modalId}`);
  if (modal) {
    modal.classList.add('active');
  }
}

function closeModal(modalId) {
  const modal = $(`#${modalId}`);
  if (modal) {
    modal.classList.remove('active');
  }
}

// Storage
function setStorage(key, value) {
  localStorage.setItem(key, JSON.stringify(value));
}

function getStorage(key) {
  const value = localStorage.getItem(key);
  return value ? JSON.parse(value) : null;
}

function removeStorage(key) {
  localStorage.removeItem(key);
}

// Debounce
function debounce(func, delay = 300) {
  let timeoutId;
  return function (...args) {
    clearTimeout(timeoutId);
    timeoutId = setTimeout(() => func(...args), delay);
  };
}

// Throttle
function throttle(func, limit = 300) {
  let inThrottle;
  return function (...args) {
    if (!inThrottle) {
      func(...args);
      inThrottle = true;
      setTimeout(() => (inThrottle = false), limit);
    }
  };
}

// Scroll to element
function scrollToElement(selector, offset = 0) {
  const element = $(selector);
  if (element) {
    const top = element.offsetTop - offset;
    window.scrollTo({ top, behavior: 'smooth' });
  }
}

// Check if element is in viewport
function isInViewport(element) {
  const rect = element.getBoundingClientRect();
  return (
    rect.top >= 0 &&
    rect.left >= 0 &&
    rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) &&
    rect.right <= (window.innerWidth || document.documentElement.clientWidth)
  );
}

// Currency formatting
function formatCurrency(amount, currency = 'USD') {
  return new Intl.NumberFormat('fr-HT', {
    style: 'currency',
    currency: currency
  }).format(amount);
}

// Number formatting
function formatNumber(number) {
  return new Intl.NumberFormat('fr-HT').format(number);
}
