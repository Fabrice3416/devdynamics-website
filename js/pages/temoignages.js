// ============================================
// TEMOIGNAGES PAGE SCRIPT
// ============================================

document.addEventListener('DOMContentLoaded', () => {
  initNavigation();
  loadAllTestimonials();
});

// Navigation
function initNavigation() {
  const hamburger = document.querySelector('.hamburger');
  const navMenu = document.querySelector('.nav-menu');
  const navLinks = document.querySelectorAll('.nav-link');

  hamburger.addEventListener('click', () => {
    navMenu.classList.toggle('active');
  });

  navLinks.forEach(link => {
    link.addEventListener('click', () => {
      navMenu.classList.remove('active');
    });
  });
}

// Load all testimonials
async function loadAllTestimonials() {
  try {
    const response = await api.getTestimonials();
    if (response.success) {
      renderTestimonials(response.data);
    }
  } catch (error) {
    console.error('Erreur chargement témoignages:', error);
    showNotification('Erreur lors du chargement des témoignages', 'error');
  }
}

// Helper function to extract YouTube video ID
function getYouTubeVideoId(url) {
  if (!url) return null;

  // Handle different YouTube URL formats
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

function renderTestimonials(testimonials) {
  const container = document.getElementById('temoignages-grid');
  container.innerHTML = '';

  if (testimonials.length === 0) {
    container.innerHTML = '<p class="text-center text-gray">Aucun témoignage disponible pour le moment</p>';
    return;
  }

  testimonials.forEach(testimonial => {
    const card = document.createElement('article');
    card.className = 'testimonial-card';

    // Extract YouTube video ID
    const videoId = getYouTubeVideoId(testimonial.video_url);

    // Generate stars if rating exists
    const stars = testimonial.rating ? '⭐'.repeat(testimonial.rating) : '';

    card.innerHTML = `
      <div class="testimonial-video">
        ${videoId
          ? `<iframe
              width="100%"
              height="315"
              src="https://www.youtube.com/embed/${videoId}"
              title="${testimonial.student_name} - Témoignage"
              frameborder="0"
              allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
              allowfullscreen>
            </iframe>`
          : `<div class="video-placeholder"> Vidéo non disponible</div>`
        }
      </div>
      <div class="testimonial-info">
        <h3>${testimonial.student_name}</h3>
        ${testimonial.student_title ? `<p class="testimonial-title">${testimonial.student_title}</p>` : ''}
        ${stars ? `<div class="testimonial-rating">${stars}</div>` : ''}
      </div>
    `;
    container.appendChild(card);
  });
}
