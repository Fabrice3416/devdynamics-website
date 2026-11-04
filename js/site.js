// ============================================
// DEVDYNAMICS — comportements communs du site public
// ============================================

function escapeHtml(value) {
  if (value === null || value === undefined) return '';
  return String(value)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

// ---------- Menu mobile ----------

const navToggle = document.querySelector('.nav-toggle');
const siteNav = document.getElementById('site-nav');

function setNavOpen(open) {
  siteNav.classList.toggle('is-open', open);
  navToggle.setAttribute('aria-expanded', String(open));
  navToggle.setAttribute('aria-label', open ? 'Fermer le menu' : 'Ouvrir le menu');
  navToggle.querySelector('i').className = open ? 'ti ti-x' : 'ti ti-menu-2';
}

if (navToggle && siteNav) {
  navToggle.addEventListener('click', () => {
    setNavOpen(navToggle.getAttribute('aria-expanded') !== 'true');
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && navToggle.getAttribute('aria-expanded') === 'true') {
      setNavOpen(false);
      navToggle.focus();
    }
  });
}

// ---------- Apparition au défilement ----------

const reveals = document.querySelectorAll('.reveal');

if ('IntersectionObserver' in window) {
  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        entry.target.classList.add('is-visible');
        observer.unobserve(entry.target);
      }
    });
  }, { rootMargin: '0px 0px -8% 0px' });
  reveals.forEach((element) => observer.observe(element));
} else {
  reveals.forEach((element) => element.classList.add('is-visible'));
}

// ---------- Copie du numéro de compte ----------

document.querySelectorAll('[data-copy]').forEach((button) => {
  button.hidden = !navigator.clipboard;
  button.addEventListener('click', async () => {
    const label = button.querySelector('span');
    const original = label.textContent;
    try {
      await navigator.clipboard.writeText(button.dataset.copy);
      label.textContent = 'Numéro copié';
    } catch (error) {
      label.textContent = 'Copie impossible';
    }
    setTimeout(() => { label.textContent = original; }, 2000);
  });
});

// ---------- Coordonnées ----------

// Les coordonnées écrites dans la page valent si l'API ne répond pas. Celles de
// l'administration les remplacent dès qu'elles arrivent.
const orgElements = document.querySelectorAll('[data-org]');

if (orgElements.length) {
  fetch(new URL('api/organization', document.querySelector('script[src*="js/site.js"]').src.split('js/site.js')[0]))
    .then((response) => (response.ok ? response.json() : null))
    .then((result) => {
      if (!result || !result.data) return;
      const organization = result.data;
      orgElements.forEach((element) => {
        const value = organization[element.dataset.org];
        if (!value) return;
        element.textContent = value;
        if (element.dataset.org === 'email') element.href = `mailto:${value}`;
        if (element.dataset.org === 'phone') element.href = `tel:${value.replace(/[^+0-9]/g, '')}`;
      });
      window.dispatchEvent(new CustomEvent('organization-loaded', { detail: organization }));
    })
    .catch(() => {});
}

// ---------- Témoignages ----------

function testimonialCard(item, extra) {
  return `
    <figure class="card testimonial fade-in">
      ${item.video_id ? `<div class="video-frame"><iframe src="https://www.youtube-nocookie.com/embed/${encodeURIComponent(item.video_id)}" title="Vidéo de ${escapeHtml(item.name)}" loading="lazy" allowfullscreen></iframe></div>` : ''}
      <blockquote>${escapeHtml(item.content)}</blockquote>
      <figcaption><strong>${escapeHtml(item.name)}</strong>${item.role_title ? `, ${escapeHtml(item.role_title)}` : ''}${extra || ''}</figcaption>
    </figure>`;
}

// ---------- Formulaire de contact ----------

const contactForm = document.getElementById('contact-form');

if (contactForm) {
  const status = document.getElementById('contact-status');
  const submit = contactForm.querySelector('button[type="submit"]');
  const submitLabel = submit.textContent;

  const showStatus = (message, type) => {
    status.textContent = message;
    status.className = `form-status is-${type}`;
    status.hidden = false;
  };

  contactForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    status.hidden = true;

    const data = Object.fromEntries(new FormData(contactForm).entries());
    for (const key of Object.keys(data)) data[key] = data[key].trim();

    if (!data.name || !data.email || !data.message) {
      showStatus('Indiquez votre nom, votre e-mail et votre message.', 'error');
      return;
    }

    submit.disabled = true;
    submit.textContent = 'Envoi en cours…';

    try {
      await api.createContactMessage(data);
      contactForm.reset();
      showStatus('Message envoyé. Nous vous répondrons par e-mail.', 'success');
    } catch (error) {
      showStatus("L'envoi a échoué. Réessayez, ou écrivez à contact@dev-dynamics.org.", 'error');
    } finally {
      submit.disabled = false;
      submit.textContent = submitLabel;
    }
  });
}
