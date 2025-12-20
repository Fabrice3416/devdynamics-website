// ============================================
// BLOG POST PAGE SCRIPT
// ============================================

let currentPost = null;
let allPosts = [];

document.addEventListener('DOMContentLoaded', async () => {
  initNavigation();
  
  // Get slug from URL
  const params = new URLSearchParams(window.location.search);
  const slug = params.get('slug');

  if (!slug) {
    window.location.href = 'blog.html';
    return;
  }

  await loadPost(slug);
  await loadRelatedPosts();
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

// Load post
async function loadPost(slug) {
  try {
    const response = await api.getBlogPost(slug);
    if (response.success) {
      currentPost = response.data;
      renderPost(currentPost);
      document.title = `${currentPost.title} - DevDynamics`;
    } else {
      showNotification('Article non trouvé', 'error');
      setTimeout(() => {
        window.location.href = 'blog.html';
      }, 2000);
    }
  } catch (error) {
    console.error('Erreur chargement article:', error);
    showNotification('Erreur lors du chargement de l\'article', 'error');
  }
}

function renderPost(post) {
  // Title and meta
  document.getElementById('post-title').textContent = post.title;
  document.getElementById('post-date').textContent = formatDate(post.published_at);

  const categoryBadge = document.getElementById('post-category');
  if (post.category) {
    categoryBadge.textContent = post.category;
  } else {
    categoryBadge.style.display = 'none';
  }

  // Featured Image
  const featuredImageContainer = document.getElementById('post-featured-image-container');
  const featuredImage = document.getElementById('post-featured-image');
  if (post.featured_image) {
    featuredImage.src = post.featured_image;
    featuredImage.alt = post.title;
    featuredImageContainer.style.display = 'block';
  } else {
    featuredImageContainer.style.display = 'none';
  }

  // Content
  document.getElementById('post-body').innerHTML = post.content;

  // Author (placeholder)
  document.getElementById('author-name').textContent = 'Équipe DevDynamics';
  document.getElementById('author-bio').textContent = 'L\'équipe de DevDynamics partage ses connaissances et expériences en technologie et éducation.';

  // Share buttons
  setupShareButtons(post);
}

function setupShareButtons(post) {
  const url = window.location.href;
  const title = post.title;

  const shareButtons = {
    facebook: `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(url)}`,
    twitter: `https://twitter.com/intent/tweet?text=${encodeURIComponent(title)}&url=${encodeURIComponent(url)}`,
    linkedin: `https://www.linkedin.com/sharing/share-offsite/?url=${encodeURIComponent(url)}`,
    whatsapp: `https://wa.me/?text=${encodeURIComponent(title + ' ' + url)}`
  };

  document.querySelector('.share-btn.facebook').href = shareButtons.facebook;
  document.querySelector('.share-btn.twitter').href = shareButtons.twitter;
  document.querySelector('.share-btn.linkedin').href = shareButtons.linkedin;
  document.querySelector('.share-btn.whatsapp').href = shareButtons.whatsapp;
}

// Load related posts
async function loadRelatedPosts() {
  try {
    const response = await api.getBlogPosts(1, 100);
    if (response.success) {
      allPosts = response.data;
      
      // Get related posts (same category or just recent)
      let relatedPosts = [];
      if (currentPost.category) {
        relatedPosts = allPosts
          .filter(p => p.category === currentPost.category && p.id !== currentPost.id)
          .slice(0, 3);
      }
      
      // If not enough, add recent posts
      if (relatedPosts.length < 3) {
        relatedPosts = allPosts
          .filter(p => p.id !== currentPost.id)
          .slice(0, 3 - relatedPosts.length)
          .concat(relatedPosts);
      }

      renderRelatedPosts(relatedPosts);
      setupPostNavigation(allPosts);
    }
  } catch (error) {
    console.error('Erreur chargement articles connexes:', error);
  }
}

function renderRelatedPosts(posts) {
  const container = document.getElementById('related-posts');
  container.innerHTML = '';

  posts.forEach(post => {
    const li = document.createElement('li');
    li.innerHTML = `
      <a href="blog-post.html?slug=${post.slug}">${post.title}</a>
      <div class="related-date">${formatDate(post.published_at)}</div>
    `;
    container.appendChild(li);
  });
}

function setupPostNavigation(posts) {
  const currentIndex = posts.findIndex(p => p.id === currentPost.id);
  
  const prevBtn = document.getElementById('prev-post');
  const nextBtn = document.getElementById('next-post');

  // Previous post
  if (currentIndex > 0) {
    const prevPost = posts[currentIndex - 1];
    prevBtn.href = `blog-post.html?slug=${prevPost.slug}`;
    prevBtn.innerHTML = `<span>← ${prevPost.title}</span>`;
  } else {
    prevBtn.classList.add('disabled');
    prevBtn.innerHTML = '<span>← Aucun article précédent</span>';
  }

  // Next post
  if (currentIndex < posts.length - 1) {
    const nextPost = posts[currentIndex + 1];
    nextBtn.href = `blog-post.html?slug=${nextPost.slug}`;
    nextBtn.innerHTML = `<span>${nextPost.title} →</span>`;
  } else {
    nextBtn.classList.add('disabled');
    nextBtn.innerHTML = '<span>Aucun article suivant →</span>';
  }
}
