// ============================================
// HOME PAGE SCRIPT
// ============================================


document.addEventListener('DOMContentLoaded', async () => {
  // Initialize
  initNavigation();
  loadOrganizationInfo();
  loadPrograms();
  loadCourses();
  loadTestimonials();
  loadTeam();
  loadBlogPosts();
  loadSponsors();
  setupFormHandlers();
  animateCounters();
});

// Navigation
function initNavigation() {
  const hamburger = document.querySelector('.hamburger');
  const navMenu = document.querySelector('.nav-menu');
  const navLinks = document.querySelectorAll('.nav-link');

  // Toggle mobile menu
  hamburger.addEventListener('click', () => {
    navMenu.classList.toggle('active');
  });

  // Close menu on link click
  navLinks.forEach(link => {
    link.addEventListener('click', (e) => {
      navMenu.classList.remove('active');
      
      // Update active state
      navLinks.forEach(l => l.classList.remove('active'));
      link.classList.add('active');
    });
  });

  // Update active link on scroll
  window.addEventListener('scroll', () => {
    updateActiveNavLink();
  });
}

function updateActiveNavLink() {
  const sections = document.querySelectorAll('section[id]');
  const navLinks = document.querySelectorAll('.nav-link');

  let current = '';
  sections.forEach(section => {
    const sectionTop = section.offsetTop;
    if (pageYOffset >= sectionTop - 200) {
      current = section.getAttribute('id');
    }
  });

  navLinks.forEach(link => {
    link.classList.remove('active');
    if (link.getAttribute('href') === `#${current}`) {
      link.classList.add('active');
    }
  });
}

// Load organization info
async function loadOrganizationInfo() {
  try {
    const response = await api.getOrganizationInfo();
    if (response.success) {
      const org = response.data;
      document.getElementById('org-address').textContent = org.address || 'Port-au-Prince, Haïti';
      document.getElementById('org-phone').textContent = org.phone || '+509 XXXX XXXX';
      document.getElementById('org-email').textContent = org.email || 'contact@devdynamics.ht';
      document.getElementById('org-whatsapp').textContent = org.whatsapp_number || '+509 XXXX XXXX';
      document.getElementById('mission-text').textContent = org.mission || '';
    }
  } catch (error) {
    console.error('Erreur chargement organisation:', error);
  }
}

// Load programs
async function loadPrograms() {
  try {
    const response = await api.getPrograms();
    if (response.success) {
      renderPrograms(response.data);
    }
  } catch (error) {
    console.error('Erreur chargement programmes:', error);
  }
}

function renderPrograms(programs) {
  const container = document.getElementById('programs-container');
  container.innerHTML = '';

  const programsToShow = programs.slice(0, 3);

  programsToShow.forEach(program => {
    const card = document.createElement('div');
    card.className = 'card';
    card.innerHTML = `
      <div class="card-image" style="background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%); display: flex; align-items: center; justify-content: center; font-size: 50px;">
        📚
      </div>
      <div class="card-body">
        <h3 class="card-title">${program.title}</h3>
        <p class="card-text">${truncate(program.description, 150)}</p>
        ${program.category ? `<span class="badge badge-primary">${program.category}</span>` : ''}
      </div>
      <div class="card-footer">
        <a href="#" class="btn btn-primary btn-sm">En savoir plus</a>
      </div>
    `;
    container.appendChild(card);
  });
}

// Load courses
async function loadCourses() {
  try {
    const response = await api.getCourses();
    console.log('Courses API response:', response);

    if (response.success) {
      const container = document.getElementById('courses-container');
      container.innerHTML = '';

      // Update courses count in stats
      const coursesCount = response.data.length;
      console.log('Courses count:', coursesCount);

      const coursesCountElement = document.getElementById('courses-count');
      if (coursesCountElement) {
        coursesCountElement.setAttribute('data-count', coursesCount);
        coursesCountElement.textContent = coursesCount;
      }

      if (response.data.length === 0) {
        container.innerHTML = '<p style="text-align: center; color: #666;">Aucun cours disponible pour le moment.</p>';
        return;
      }

      response.data.forEach(course => {
        const card = document.createElement('div');
        card.className = 'card';

        const levelBadge = {
          'debutant': '🟢 Débutant',
          'intermediaire': '🟡 Intermédiaire',
          'avance': '🔴 Avancé'
        };

        card.innerHTML = `
          <div class="card-image" style="background: linear-gradient(135deg, #FF6B6B 0%, #4ECDC4 100%); display: flex; align-items: center; justify-content: center; font-size: 50px;">
            🎓
          </div>
          <div class="card-body">
            <h3 class="card-title">${course.title}</h3>
            <p class="card-text">${truncate(course.description, 120)}</p>
            <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px;">
              <span class="badge badge-primary">${levelBadge[course.level] || course.level}</span>
              ${course.duration ? `<span class="badge badge-secondary">⏱️ ${course.duration}</span>` : ''}
              ${course.price > 0 ? `<span class="badge badge-success">💰 ${course.price} USD</span>` : '<span class="badge badge-success">✨ Gratuit</span>'}
            </div>
          </div>
          <div class="card-footer">
            <a href="pages/course-details.html?id=${course.id}" class="btn btn-primary btn-sm">Voir détails</a>
          </div>
        `;
        container.appendChild(card);
      });
    }
  } catch (error) {
    console.error('Erreur chargement cours:', error);
  }
}

// Helper function to extract YouTube video ID
function getYouTubeVideoId(url) {
  if (!url) return null;

  const patterns = [
    /(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([^&\n?#]+)/,
    /youtube\.com\/watch\?.*v=([^&\n?#]+)/
  ];

  for (const pattern of patterns) {
    const match = url.match(pattern);
    if (match && match[1]) {
      return match[1];
    }
  }

  return null;
}

// Load testimonials
async function loadTestimonials() {
  try {
    const response = await api.getFeaturedTestimonials();
    if (response.success) {
      const container = document.getElementById('testimonials-container');
      container.innerHTML = '';

      response.data.forEach(testimonial => {
        const card = document.createElement('div');
        card.className = 'testimonial';

        const videoId = getYouTubeVideoId(testimonial.video_url);
        const stars = testimonial.rating ? '⭐'.repeat(testimonial.rating) : '';

        card.innerHTML = `
          <div class="testimonial-video-container">
            ${videoId
              ? `<iframe
                  width="100%"
                  height="250"
                  src="https://www.youtube.com/embed/${videoId}"
                  title="${testimonial.student_name} - Témoignage"
                  frameborder="0"
                  allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                  allowfullscreen
                  style="border-radius: var(--radius-md);">
                </iframe>`
              : `<div style="width: 100%; height: 250px; background: linear-gradient(135deg, #4a5568, #2d3748); display: flex; align-items: center; justify-content: center; color: white; border-radius: var(--radius-md); font-size: 40px;">📹</div>`
            }
          </div>
          <div class="testimonial-info-home" style="margin-top: var(--spacing-md); text-align: center;">
            <div class="testimonial-author" style="font-weight: var(--font-weight-bold); color: var(--color-primary); font-size: var(--font-size-lg);">${testimonial.student_name}</div>
            ${testimonial.student_title ? `<div class="testimonial-role" style="color: var(--color-text-light); font-size: var(--font-size-sm); margin-top: var(--spacing-xs);">${testimonial.student_title}</div>` : ''}
            ${stars ? `<div class="testimonial-rating" style="color: #fbbf24; margin-top: var(--spacing-sm);">${stars}</div>` : ''}
          </div>
        `;
        container.appendChild(card);
      });
    }
  } catch (error) {
    console.error('Erreur chargement témoignages:', error);
  }
}

// Load team
async function loadTeam() {
  try {
    const response = await api.getFounders();
    if (response.success) {
      const container = document.getElementById('team-container');
      container.innerHTML = '';

      response.data.forEach(member => {
        const card = document.createElement('div');
        card.className = 'team-member';
        card.innerHTML = `
          <div class="team-member-image">👤</div>
          <div class="team-member-content">
            <div class="team-member-name">${member.name}</div>
            <div class="team-member-role">${member.role}</div>
            <div class="team-member-bio">${member.bio || 'Cofondateur de DevDynamics'}</div>
          </div>
        `;
        container.appendChild(card);
      });
    }
  } catch (error) {
    console.error('Erreur chargement équipe:', error);
  }
}

// Load blog posts
async function loadBlogPosts() {
  try {
    const response = await api.getBlogPosts(1, 4);
    if (response.success) {
      const container = document.getElementById('blog-container');
      container.innerHTML = '';

      // Backend returns { posts: [], pagination: {} }
      const posts = response.data.posts || response.data || [];
      posts.forEach(post => {
        const card = document.createElement('div');
        card.className = 'blog-card';
        card.innerHTML = `
          <div class="blog-image">📝</div>
          <div class="blog-content">
            ${post.category ? `<span class="badge badge-primary blog-category">${post.category}</span>` : ''}
            <h3 class="blog-title">${post.title}</h3>
            <p class="blog-excerpt">${truncate(post.excerpt || post.content, 120)}</p>
            <div class="blog-meta">
              <span>${formatDate(post.published_at)}</span>
              <a href="pages/blog-post.html?slug=${post.slug}" class="blog-read-more">Lire →</a>
            </div>
          </div>
        `;
        container.appendChild(card);
      });
    }
  } catch (error) {
    console.error('Erreur chargement blog:', error);
  }
}

// Load sponsors
async function loadSponsors() {
  try {
    const response = await api.getSponsors();
    if (response.success) {
      const container = document.getElementById('sponsors-container');
      container.innerHTML = '';

      // Show only first 6 sponsors
      const sponsorsToShow = response.data.slice(0, 6);

      if (sponsorsToShow.length === 0) {
        // Show message when no sponsors
        container.innerHTML = '<p style="text-align: center; color: var(--color-text-light); padding: var(--spacing-2xl);">Aucun sponsor pour le moment. Soutenez notre mission!</p>';
      } else {
        sponsorsToShow.forEach(sponsor => {
          const card = document.createElement('div');
          card.className = 'sponsor-home-card';

          const link = sponsor.website_url ? `<a href="${sponsor.website_url}" target="_blank" rel="noopener noreferrer">` : '';
          const linkClose = sponsor.website_url ? '</a>' : '';

          card.innerHTML = `
            ${link}
              <img src="${sponsor.logo_url}" alt="${sponsor.name}" class="sponsor-home-logo" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 200 100%27%3E%3Crect fill=%27%23e0e0e0%27 width=%27200%27 height=%27100%27/%3E%3Ctext x=%27100%27 y=%2755%27 text-anchor=%27middle%27 fill=%27%23999%27 font-size=%2716%27%3E${sponsor.name}%3C/text%3E%3C/svg%3E'">
              <div class="sponsor-home-name">${sponsor.name}</div>
            ${linkClose}
          `;
          container.appendChild(card);
        });
      }
    }
  } catch (error) {
    console.error('Erreur chargement sponsors:', error);
    // Show message on error instead of hiding section
    const container = document.getElementById('sponsors-container');
    if (container) {
      container.innerHTML = '<p style="text-align: center; color: var(--color-text-light); padding: var(--spacing-2xl);">Aucun sponsor pour le moment.</p>';
    }
  }
}

// Setup form handlers
function setupFormHandlers() {
  // Contact form
  const contactForm = document.getElementById('contact-form');
  if (contactForm) {
    contactForm.addEventListener('submit', handleContactSubmit);
  }

  // Donation form
  const donationForm = document.getElementById('donation-form');
  if (donationForm) {
    donationForm.addEventListener('submit', handleDonationSubmit);
  }

  // Newsletter form
  const newsletterForm = document.getElementById('newsletter-form');
  if (newsletterForm) {
    newsletterForm.addEventListener('submit', handleNewsletterSubmit);
  }
}

async function handleContactSubmit(e) {
  e.preventDefault();
  clearFormErrors(this);

  const data = getFormData(this);

  try {
    const response = await api.createContactMessage(data);
    if (response.success) {
      showNotification('Message envoyé avec succès!', 'success');
      this.reset();
    }
  } catch (error) {
    showNotification('Erreur lors de l\'envoi du message', 'error');
  }
}

async function handleDonationSubmit(e) {
  e.preventDefault();
  clearFormErrors(this);

  const data = getFormData(this);

  try {
    const response = await api.createDonation(data);
    if (response.success) {
      showNotification('Merci pour votre don! Nous vous contacterons bientôt.', 'success');
      this.reset();
    }
  } catch (error) {
    showNotification('Erreur lors du traitement du don', 'error');
  }
}

async function handleNewsletterSubmit(e) {
  e.preventDefault();
  const email = this.querySelector('input[name="email"]').value;

  try {
    // Simulate newsletter signup
    showNotification('Merci de votre inscription!', 'success');
    this.reset();
  } catch (error) {
    showNotification('Erreur lors de l\'inscription', 'error');
  }
}

// Animate counters
function animateCounters() {
  const counters = document.querySelectorAll('.stat-number');

  const observerOptions = {
    threshold: 0.5
  };

  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        const counter = entry.target;
        const target = parseInt(counter.dataset.count);
        animateCounter(counter, target);
        observer.unobserve(counter);
      }
    });
  }, observerOptions);

  counters.forEach(counter => observer.observe(counter));
}

function animateCounter(element, target) {
  let current = 0;
  const increment = target / 50;
  const timer = setInterval(() => {
    current += increment;
    if (current >= target) {
      element.textContent = target;
      clearInterval(timer);
    } else {
      element.textContent = Math.floor(current);
    }
  }, 30);
}

